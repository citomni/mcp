# Optional OAuth for hosted MCP clients

This package can accept a pre-registered OAuth client alongside the fixed bearer tokens. OAuth is disabled by default. It uses `league/oauth2-server` for authorization codes, PKCE, token encryption, refresh rotation, and protocol errors.

The built-in approval page is for **one owner**. Its separate password authorizes the client to use every tool the app exposes, under one scope. It creates no user accounts and does not tell the users of a client apart; what a tool can reach is decided by the tool's own code. Only register a client you intend to give that access.

## Install and configure

The host app must require `league/oauth2-server` in its production dependencies. The package lists it only as a suggestion and a development dependency, so hosts that use fixed tokens alone do not need it. Conflict rules in the package's `composer.json` keep League, `lcobucci/jwt`, and `defuse/php-encryption` within the versions this adapter is written for, also when the host requires them for other reasons. Install normally with Composer and do not ignore platform requirements. OpenSSL and the extensions those libraries require must be available.

```sh
composer require league/oauth2-server:^9.4.1
php vendor/citomni/mcp/bin/oauth-setup.php var/secrets/mcp.oauth.secret.php
```

Run setup in a private terminal. It creates a new file and prints the owner approval password and the OAuth client secret once. Store both in a password manager. It refuses to overwrite a file. The file contains a password hash, the client secret, an RSA signing key pair, and a Defuse encryption key; never commit it.

On Windows, PHP generates keys only when it finds an `openssl.cnf`. Set `OPENSSL_CONF` to the `extras\ssl\openssl.cnf` of the PHP installation in the terminal that runs the setup. The test suites pass that file to OpenSSL themselves.

Copy the five generated `mcp.oauth.*` entries directly into the existing array in the environment's secrets store, preserving their values and all existing fixed tokens and backend credentials. Keep the store self-contained and returning a flat `array<string,string>`; the generated file alone is not loaded. Do not leave a `require` of `mcp.oauth.secret.php` in it when using CitOmni DevKit deployment: DevKit transfers `app.secret.<env>.php`, not included files. Keep both files out of Git, and never regenerate active keys just to move them.

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
		'consent_text' => 'Allow ChatGPT to use the tools exposed by this server.',
	],
],
```

The `Registry` baseline documents every `mcp.oauth` key, including the approval page labels in `mcp.oauth.texts` and the names of the five secrets. `enabled` must be `true` or `false`: any other value, also one that PHP reads as false such as `0`, is a configuration error that every request reading it answers with 500.

Use an explicit HTTPS origin without a path or trailing slash. This implementation serves one resource, `<issuer>/mcp`, and requires `resource` on authorization and code exchange. Refresh may omit `resource` and keeps the original audience; an explicitly different or empty resource is rejected. Subdirectory installs and multiple resource servers are not supported by this OAuth adapter. Callback URLs must match the configured list exactly, without queries, fragments, or wildcard matching.

The metadata advertises RFC 9207 issuer identification; successful and denied authorization redirects include `iss`. This enables ChatGPT's stable `https://chatgpt.com/connector_platform_oauth_redirect` callback. Verify the callback that ChatGPT displays for the connection. If it differs, configure that exact HTTPS URL. There is no dynamic client registration endpoint and no CIMD client lookup.

The HTTP request seen by PHP must have the issuer's host, HTTPS scheme, and port. Configure CitOmni's trusted proxy settings for your actual proxy, if there is one; do not trust arbitrary forwarded headers. Preserve the `Authorization` header.

## Routes and ChatGPT connection

| Method | Path | Purpose |
| --- | --- | --- |
| GET/HEAD | `/.well-known/oauth-protected-resource/mcp` | Discovery for `/mcp` |
| GET/HEAD | `/.well-known/oauth-protected-resource` | The same metadata at the root, the fallback location of MCP clients |
| GET/HEAD | `/.well-known/oauth-authorization-server` | Authorization server metadata |
| GET/HEAD, POST | `/mcp/oauth/authorize` | PKCE validation, owner approval, callback |
| POST | `/mcp/oauth/token` | Code exchange and refresh rotation |

These endpoints return 404 when OAuth is disabled. Permit the exact discovery paths through the web server's dotfile rules; do not open all dotfiles. They are virtual application routes, not files to create inside `.well-known`.

In ChatGPT, create a custom MCP connection with the HTTPS `/mcp` URL, choose OAuth with a pre-registered client, and enter the configured `client_id` and the generated client secret. Both `client_secret_post` and `client_secret_basic` are supported. Connect, enter the **owner approval password on your own server's page**, and approve. Then check the connection with `ping` and `tools/list`. Refresh the custom MCP definition after changing auth or tool metadata.

The entire MCP endpoint is protected: an unauthenticated request gets HTTP 401 with `WWW-Authenticate` resource discovery. Tool definitions sent to an OAuth client also include `securitySchemes` and its `_meta` mirror. This implementation does not provide anonymous tool listing or a separate in-conversation sign-in widget.

## Compatibility and identity

Fixed secrets are checked first and keep their client identity and tool metadata. A successful fixed-token request does not load OAuth keys or the optional library, so fixed-token clients need no change when OAuth is enabled.

OAuth tools and access logs receive `oauth:<owner_id>:<client_id>`, which stays stable across refresh. State that a tool binds to that identity survives a refresh; a fixed-token client has another identity and cannot use it. Never bind state to the raw access token.

`mcp.auth.secret_keys = []` means OAuth-only when OAuth is enabled. It means anonymous access only when OAuth is disabled too. If fixed keys remain configured, at least one must exist in the secrets store.

## State, lifetimes and operations

The default `var/state/mcp_oauth` directory must be writable by PHP, protected from HTTP access, outside `public`, and persistent across releases. Use one host with local filesystem locking and atomic replacement. Multiple PHP workers on that host are supported; distributed instances and shared network filesystems are outside this implementation's scope.

The JSON ledger contains identifiers, expiration times, revocation status, authorization-context hashes, and hashed browser and CSRF values, never raw bearer tokens or passwords. An exclusive transaction surrounds grant redemption, so parallel workers cannot redeem a code or refresh token twice. Replay revokes the remaining tokens in that grant family. Refresh identifiers carry their family inside League's authenticated encryption. Only the current identifier is retained in the family record, so an old unexpired identifier still detects replay without one tombstone per rotation. Revoked access records are deleted and fail closed. The lock file stays at a stable path.

| Setting | Default | Allowed range |
| --- | --- | --- |
| Authorization code lifetime | 60 seconds | Fixed |
| `access_ttl` | 900 seconds | 60 to 3,600 |
| `refresh_ttl` | 2,592,000 seconds | 3,600 to 2,592,000 |
| `approval_ttl` | 300 seconds | 60 to 600 |

Refresh tokens rotate. The previous access token is revoked during refresh. Refresh lifetime is renewed on rotation; there is no absolute connection lifetime. Replaying a consumed grant intentionally invalidates its descendants, so a client that retries a successful exchange after losing its response may need to reconnect. Changing issuer, resource, owner, client ID, or scope invalidates the stored grant context. Keep server time synchronized for JWT validation.

Owner approval uses a Secure, HttpOnly, SameSite=Lax cookie, explicit CSRF checks, an optional Origin and Fetch-Metadata check, and no persistent owner login session. `Referrer-Policy: same-origin` lets the browser send `Origin` on the form POST without forwarding a `Referer` to the external callback. CSP permits form actions to self and only the exact HTTPS origins of the configured callbacks. The server still checks the complete callback URL exactly. Origin `null` is not accepted.

Starting a second approval replaces the browser cookie of the first. Form creation is limited to 20 per IP per minute. Password verification is limited to ten attempts per IP per 15 minutes; a successful approval clears that IP's failed-attempt counter. There is no global password or form-creation lockout that an unrelated IP can consume. Users behind a shared IP still share its limit, and distributed traffic can exhaust server resources, so this is not DDoS protection.

Public approval and rate-limit records stop being admitted at 2,048 records or 2 MiB in total, reserving the remaining capacity for existing grants. Grant state is bounded at 4,096 records or 4 MiB. Expired records are collected at transaction start. A full ledger returns a retryable 503 with `Retry-After` and rolls the transaction back; IP limits return 429. Live grants and replay state are never evicted to make room. One continuously refreshed connection has a constant number of records.

`BeginAuthorization`, `ApproveAuthorization`, and `IssueTokens` own admission, password decisions, and atomic transitions. The controller handles HTTP and League protocol adaptation. `ConsentPage` renders a small escaped view without the host's TemplateEngine or theme; `mcp.oauth.texts` overrides its English labels, so a host can translate the page without changing the provider.

Audit events use the log service and `mcp.jsonl`, category `mcp.oauth`. They record the start and admission of an approval, owner decisions, password failures, code issuance, refresh, protocol failures, and replay-family revocation, with the stable owner and client and the trusted IP. Raw tokens, authorization codes, passwords, CSRF values, request bodies, and exception messages are never included. Audit lines follow the log service's rotation. A log-storage failure follows the host's normal error handling.

To turn OAuth off, set `mcp.oauth.enabled` to false and rebuild the host's HTTP configuration cache. Fixed tokens keep working. To revoke all OAuth connections, disable OAuth, drain active requests, remove `ledger.json` in the configured state directory, and enable OAuth again. Keep `oauth.lock`. There is no public revocation endpoint. Losing the ledger also invalidates existing OAuth tokens. Never restore a stale ledger backup: doing so can restore consumed grants. Rotating the signing and encryption keys and starting a fresh ledger forces all clients to reconnect.

Rebuild the HTTP config and routes cache and refresh the web server's OPcache when deploying new routes or settings. Do not place OAuth state under a directory that deployment or cache cleanup removes.

## Tests

```sh
composer install
php tests/run.php
```

The `oauth` suite uses the real CitOmni HTTP kernel and League library, ephemeral keys, local HTTP servers with simulated trusted TLS termination, and, with `CITOMNI_TEST_PARALLEL=1`, two worker processes for redemption races. The `browser` suite runs the approval page in headless Chrome, Chromium, or Edge over real local TLS, for browser-generated headers, CSP redirects, cookies, and denial; it needs one of those browsers and fails without one. Their READMEs, [oauth](../tests/oauth/README.md) and [browser](../tests/browser/README.md), list the cases. Before releasing OAuth changes, also run `tests/run.php` with `CITOMNI_TEST_PARALLEL=1` for the redemption races. Neither suite connects to a real ChatGPT account. Both generate RSA keys; on Windows they use the `openssl.cnf` of the PHP installation.

## References

- [OpenAI: Authentication](https://developers.openai.com/plugins/build/auth)
- [OpenAI: Connect a custom MCP server](https://developers.openai.com/plugins/deploy/connect-chatgpt)
- [League: Authorization code grant](https://oauth2.thephpleague.com/authorization-server/auth-code-grant/)
- [OpenAI: Provided OAuth clients in plugin manifests](https://developers.openai.com/plugins/build/plugins)
- [OpenAI: Client-secret entry in the plugin portal](https://developers.openai.com/plugins/deploy/submission)

Protocol documentation checked on 2026-10-11. UI labels and available account features can change independently of this server.
