# OAuth suite

End-to-end checks of the optional OAuth authorization server and of OAuth access tokens on `/mcp`: a real CitOmni HTTP app with `citomni/kernel`, `citomni/http`, `citomni/infrastructure`, this package and `league/oauth2-server`, served by PHP's built-in web server and driven with raw HTTP requests. No database and no network beyond 127.0.0.1. No case connects to a real OAuth client such as ChatGPT.

```
composer install
php tests/oauth/run.php
```

Expected: `51 passed, 0 failed, 2 skipped`

With multi-process checks:

```
set "CITOMNI_TEST_PARALLEL=1"
php tests/oauth/run.php
```

Expected: `53 passed, 0 failed`

In PowerShell, set the variable with `$env:CITOMNI_TEST_PARALLEL = '1'`; in a POSIX shell, run `CITOMNI_TEST_PARALLEL=1 php tests/oauth/run.php`. `PASS` and `SKIP` lines go to stdout and `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a case fails.

The suite generates RSA keys, which on Windows needs an `openssl.cnf`. The suite passes the `extras\ssl\openssl.cnf` next to `php.exe` to OpenSSL, so the official PHP builds need no setup. With a PHP build that lacks the file, set `OPENSSL_CONF` to an `openssl.cnf` once, as a permanent user variable.

## How it works

Every case except the last writes its own small app below the suite's run directory: `config/providers.php` with the Infrastructure and MCP providers, `config/citomni_http_cfg.php` with OAuth enabled for the issuer `https://oauth.example.test` and the case's overrides, `var/secrets/app.secret.dev.php` with a fixed token and the OAuth secrets, and `public/index.php`, which runs `\CitOmni\Http\Kernel::run()` in `dev`. The entry point sets `HTTPS` and the port to emulate TLS termination by a trusted proxy; the header `X-Test-Insecure` turns it off for one request. Each app runs in its own `php -S 127.0.0.1:<free port>` with the same PHP binary and php.ini as the suite.

The signing key pair, the encryption key and the owner password hash are generated once per run and shared by all apps. The callback is ChatGPT's stable callback URL, but no request leaves 127.0.0.1: the suite reads the code and the issuer from the `Location` header.

| File | Role |
|---|---|
| `run.php` | Suite. Writes the apps, runs the authorization flow, and checks responses, ledger files and logs. |
| `../Support/OAuthFixture.php` | The app writer and the flow helpers: authorization request, approval, code exchange, refresh and MCP call. The `browser` suite uses it too. |
| `../Support/OAuthRaceWorker.php` | Worker process for the redemption races. |
| `../Support/HttpServer.php` | Starts and stops `php -S` and sends raw HTTP/1.1 requests. |
| `../Support/ClientTool.php` | Test tool that returns the identity that authenticated the request. |

## Cases

Configuration and transport:

- With OAuth disabled every OAuth route answers 404, and fixed tokens work.
- A fixed token works even when the OAuth configuration is broken.
- An `enabled` value that is not a bool is a configuration error: the string `'false'` never enables OAuth, and `0` neither disables OAuth nor opens anonymous access.
- An empty `mcp.auth.secret_keys` with OAuth enabled requires an OAuth token.
- OAuth endpoints and OAuth bearer tokens on `/mcp` refuse cleartext and foreign hosts.

Discovery and approval:

- The metadata names the exact resource and issuer, S256 PKCE, issuer identification and only the two implemented grants, and no dynamic registration; a 401 on `/mcp` names the resource metadata.
- An authorization request is refused for a foreign callback, a callback prefix or query, a foreign resource, an unknown client, plain or missing PKCE, an invalid scope and the implicit flow.
- Repeated and array parameters are refused without PHP's coercion.
- The approval page and its cookie carry the browser security headers, and `HEAD` creates no state.
- Approval requires the password, the CSRF value, the browser cookie and a same-origin POST; denial keeps state and issuer and issues no code.
- Password attempts are limited per IP, a successful approval clears the counter, and an expired approval is refused.
- The approval page allows form actions only to the configured callback origins.

Tokens:

- A code grants access, and the fixed-token path is unchanged.
- Code exchange refuses a wrong or missing verifier, a wrong secret, client or callback, and a wrong or missing resource; HTTP Basic works, and two authentication methods at once are refused.
- A replayed code revokes the tokens issued from it.
- Refresh rotates tokens, keeps the identity, refuses scope escalation, a wrong resource and a wrong secret, keeps the original resource when it is omitted, and revokes the family on replay, also when an old token is replayed after many rotations.
- Malformed and tampered tokens are refused, and validation requires issuer, audience, owner, client, scope and all time claims.
- Expired records and records from another authorization context fail closed, also when the encrypted token is intact; deleting the ledger revokes OAuth access.
- The token endpoint refuses JSON, oversized bodies and unimplemented grants.
- Handshake-era clients get the same OAuth tools, with `securitySchemes`.

Capacity, races and logs:

- Public admission records leave room for live grants and recover after expiry; a full ledger answers 503 without consuming the code.
- Two worker processes that redeem the same code, or the same refresh token, at the same moment get one grant between them. These two cases run only with `CITOMNI_TEST_PARALLEL=1` and report `SKIP` otherwise.
- Audit lines record decisions, password failures, issue, refresh and replay; no log and no ledger contains a credential.
- The last case fails when any app logged an unexpected exception or PHP diagnostic.
