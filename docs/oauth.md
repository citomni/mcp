# Optional OAuth for hosted MCP clients

This package can accept a pre-registered OAuth client alongside the existing fixed
Bearer tokens. OAuth is disabled by default. It uses `league/oauth2-server` for
authorization codes, PKCE, token encryption, refresh rotation and protocol errors.

The built-in approval page is for **one owner**. Its separate password authorizes
the client to use all tools exposed by this host under one scope. It does not
create user accounts, log into GitHub, or filter repositories by ChatGPT user.
The host's existing GitHub credentials and repository policy still decide what
can be read. Only register a client you intend to give that access.

## Install and configure

The host must require `league/oauth2-server:^9.4.1` in its production dependencies.
The provider lists it as a suggestion and a test dependency, so fixed-token-only
hosts do not need it. Composer conflicts enforce the supported League 9.4.1–9.x,
Lcobucci 5.6–5.x and Defuse 2.4–2.x ranges even when the host supplies them. The
selected League/JWT releases support PHP through 8.5; PHP 8.6 requires compatible
upstream releases. Do not bypass their platform checks. Install normally with Composer; do not ignore platform
requirements. PHP 8.5+, OpenSSL and the dependency extensions must be available.

```sh
composer require league/oauth2-server:^9.4.1
php vendor/citomni/mcp/bin/oauth-setup.php var/secrets/mcp.oauth.secret.php
```

Run setup in a private terminal. It creates a new file and prints the owner
approval password and OAuth client secret once. Store both in a password manager.
It refuses to overwrite a file. The file contains a password hash, client secret,
RSA signing key pair and a Defuse encryption key; never commit it.

Copy the five generated `mcp.oauth.*` entries directly into the existing array
in the environment's secrets store, preserving their values and all existing
fixed tokens and backend credentials. Keep this store self-contained and
returning a flat `array<string,string>`; the generated file alone is not loaded.
Do not leave a `require` dependency on `mcp.oauth.secret.php` when using CitOmni
DevKit deployment: it transfers `app.secret.<env>.php`, not included files.
Keep both files out of Git and never regenerate active keys just to move them.

Add an HTTP environment overlay:

```php
'mcp' => [
	'oauth' => [
		'enabled' => true,
		'issuer' => 'https://www.example.com',
		'owner_id' => 'owner',
		'client_id' => 'chatgpt',
		'client_name' => 'ChatGPT',
		'scope' => 'mcp.access',
		'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
		'consent_text' => 'Allow ChatGPT to read the repositories exposed by this server.',
	],
],
```

Use an explicit HTTPS origin without a path or trailing slash. This implementation
serves one resource, `<issuer>/mcp`, and requires `resource` on authorization and
code exchange. Refresh may omit `resource` and keeps the original audience; an
explicitly different or empty resource is rejected. Subdirectory installs and multiple resource servers are
not supported by this OAuth adapter. Callback URLs must match the configured
allowlist exactly, without queries, fragments or wildcard matching.

The metadata advertises RFC 9207 issuer identification; successful and denied
authorization redirects include `iss`. This enables ChatGPT's stable
`https://chatgpt.com/connector_platform_oauth_redirect` callback. Verify the actual
callback displayed for the connection. If it differs, configure that exact HTTPS
URL. There is no dynamic client registration endpoint or CIMD client lookup.

The HTTP request seen by PHP must have the issuer's host, HTTPS scheme and port.
Configure CitOmni's trusted proxy settings for your actual proxy, if present;
do not trust arbitrary forwarded headers. Preserve the Authorization header.

## Routes and ChatGPT connection

| Method | Path | Purpose |
| --- | --- | --- |
| GET/HEAD | `/.well-known/oauth-protected-resource/mcp` | Discovery for `/mcp` |
| GET/HEAD | `/.well-known/oauth-protected-resource` | Root discovery alias |
| GET/HEAD | `/.well-known/oauth-authorization-server` | Authorization server metadata |
| GET/HEAD, POST | `/mcp/oauth/authorize` | PKCE validation, owner approval, callback |
| POST | `/mcp/oauth/token` | Code exchange and refresh rotation |

These endpoints return 404 when OAuth is disabled. Permit the exact discovery
paths through the web server's dotfile rules; do not open all dotfiles. They are
virtual application routes, not files to create inside `.well-known`.

Verify that the selected ChatGPT connection surface exposes a provided OAuth
client ID and secret. The official plugin manifest/portal flow supports a
`provided` client, but that does not establish which fields exist in every
account's private custom-MCP dialog. This implementation does not claim a live
account test. When those fields are available, supply the HTTPS `/mcp` URL, choose
OAuth, and enter the configured `client_id` and generated client secret. Both `client_secret_post`
and `client_secret_basic` are supported. Connect, enter the **owner approval
password on your own server's page**, and approve. Then verify `ping`, `tools/list`
and a repository read. No GitHub token belongs in the ChatGPT configuration.
Refresh the custom MCP definition after changing auth or tool metadata.

The entire MCP endpoint is protected: an unauthenticated request gets HTTP 401
with `WWW-Authenticate` resource discovery. Authenticated OAuth tool definitions
also include `securitySchemes` and its `_meta` mirror. This implementation does
not provide anonymous tool listing or a separate in-conversation sign-in widget.

## Compatibility and identity

Fixed secrets are checked first and retain their original client identity and
tool metadata. A successful fixed-token request does not load OAuth keys or the
optional library. Existing Claude configuration requires no change.

OAuth tools and access logs receive `oauth:<owner_id>:<client_id>`, which remains
stable across refresh. Cursors bound to that identity can continue after refresh;
fixed-token clients cannot use them. Never bind state to the raw access token.

`mcp.auth.secret_keys = []` means OAuth-only when OAuth is enabled. It means
anonymous access only when OAuth is disabled too. If fixed keys remain configured,
at least one must exist in the secrets store, exactly as before this patch.

## State, lifetimes and operations

The default `var/state/mcp_oauth` directory must be writable by PHP, protected
from HTTP access, outside `public`, and persistent across releases. Use one host
with local filesystem locking and atomic replacement. Multiple PHP workers on
that host are supported; distributed instances and shared network filesystems
are outside this implementation's scope.

The JSON ledger contains identifiers, expiration times, revocation status,
authorization-context hashes and hashed browser/CSRF values, never raw bearer
tokens or passwords. An exclusive transaction surrounds grant redemption, so
parallel workers cannot redeem a code or refresh token twice. Replay revokes the
remaining tokens in that grant family. Refresh identifiers carry their family
inside League's authenticated encryption. Only the current identifier is retained
in the family record, so an old unexpired identifier still detects replay without
one tombstone per rotation. Revoked access records are deleted and fail closed.
The lock file stays at a stable path.

| Setting | Default | Allowed range |
| --- | --- | --- |
| Authorization code lifetime | 60 seconds | Fixed |
| `access_ttl` | 900 seconds | 60–3,600 |
| `refresh_ttl` | 2,592,000 seconds | 3,600–2,592,000 |
| `approval_ttl` | 300 seconds | 60–600 |

Refresh tokens rotate. The previous access token is revoked during refresh.
Refresh lifetime is renewed on rotation; there is no absolute connection lifetime.
Replaying a consumed grant intentionally invalidates descendants, so a client
that retries a successful exchange after losing its response may need to reconnect.
Changing issuer, resource, owner, client ID or scope invalidates the stored grant
context. Keep server time synchronized for JWT validation.

Owner approval uses a Secure, HttpOnly, SameSite=Lax cookie, explicit CSRF checks,
an optional Origin/Fetch-Metadata check, and no persistent owner-login session.
`Referrer-Policy: same-origin` permits browser-generated Origin on the form POST
without forwarding a Referer to the external callback. CSP permits form actions to
self and only the exact HTTPS origins from configured callbacks. The server still
checks the complete callback URL exactly. Origin `null` is not accepted.

Starting a second approval replaces the browser cookie for the first. Form
creation is limited to 20/IP/minute. Password verification is limited to ten
attempts/IP/15 minutes; a successful approval clears that IP's failed-attempt
counter. There is no global password or form-creation lockout which an unrelated
IP can consume. Shared-IP users can still share an IP limit; distributed traffic
can exhaust server resources, so this is not DDoS protection.

Public approval/rate records stop being admitted at 2,048 total records or 2 MiB,
reserving the remaining capacity for existing grants. Grant state is bounded at
4,096 records/4 MiB. Expired records are collected at transaction start. Capacity
returns retryable 503 with Retry-After and rolls the transaction back; IP limits
return 429. Live grants and replay state are never evicted to make room. One
continuously refreshed connection has a constant number of records.

`BeginAuthorization`, `ApproveAuthorization` and `IssueTokens` own admission,
password decisions and atomic transitions. The controller handles HTTP and League
protocol adaptation. `ConsentPage` renders a small escaped view without the host
TemplateEngine or theme; `mcp.oauth.texts` overrides its English labels. The host
can supply Danish labels without changing the provider.

Audit events use the existing log service and `mcp.jsonl`, category `mcp.oauth`.
They record start/admission, owner decisions, password failures, code issuance,
refresh, protocol failure and replay-family revocation, with stable owner/client
and trusted IP. Raw tokens, authorization codes, passwords, CSRF values, request
bodies and exception messages are never included. Audit uses the existing log
rotation policy. A log-storage failure follows the host's normal error handling.

To roll back, disable OAuth and rebuild the host's HTTP configuration cache. Fixed
tokens keep working. To revoke all OAuth connections, disable OAuth, drain active
requests, remove `ledger.json` in the configured state directory, and enable OAuth
again. Keep `oauth.lock`. There is no public revocation endpoint. Losing the ledger
also invalidates existing OAuth tokens. Never restore a stale ledger backup:
doing so can restore consumed grants. Rotating the signing/encryption keys and
starting a fresh ledger forces all clients to reconnect.

Rebuild the HTTP config/routes cache and refresh web-server OPcache when deploying
new routes or settings. Do not place OAuth state under a directory that deployment
or cache cleanup removes.

## Tests

```sh
composer install
php tests/run.php
```

The PHP OAuth suite uses the real CitOmni HTTP kernel and League library,
ephemeral keys, local HTTP servers with simulated trusted TLS termination, and
two worker processes for redemption races. Also run the real TLS/Chromium suite
in [tests/browser](../tests/browser/README.md) before releasing OAuth changes. It
exercises browser-generated headers, CSP redirects, cookies and denial. Neither
suite connects to a real ChatGPT account.

If an earlier experimental OAuth patch already issued tokens, reconnect clients
when adopting the compact family identifiers. Old refresh identifiers fail closed;
there is no token migration from the unreleased prototype.

## References

- [OpenAI: Authentication](https://developers.openai.com/plugins/build/auth)
- [OpenAI: Connect a custom MCP server](https://developers.openai.com/plugins/deploy/connect-chatgpt)
- [League: Authorization code grant](https://oauth2.thephpleague.com/authorization-server/auth-code-grant/)

Protocol documentation checked on 2026-10-11. UI labels and available account
features can change independently of this server.

- [OpenAI: Provided OAuth clients in plugin manifests](https://developers.openai.com/plugins/build/plugins)
- [OpenAI: Client-secret entry in the plugin portal](https://developers.openai.com/plugins/deploy/submission)
