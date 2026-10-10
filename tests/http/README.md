# HTTP suite

End-to-end checks of the `/mcp` endpoint: a real CitOmni HTTP app with `citomni/kernel`, `citomni/http`, `citomni/infrastructure`, and this package, served by PHP's built-in web server and driven with raw HTTP requests in both protocol eras. No database and no network beyond 127.0.0.1.

```
composer install
php tests/http/run.php
```

Expected: `34 passed, 0 failed`

`PASS` lines go to stdout and `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a case fails.

## How it works

The suite writes small apps below its run directory: `config/providers.php` with the Infrastructure and MCP providers, `config/citomni_http_cfg.php` with the case's `mcp` overrides, `var/secrets/app.secret.dev.php` with two random client tokens, and `public/index.php`, which loads the package's `vendor/autoload.php` and runs `\CitOmni\Http\Kernel::run()` in `dev`. Each app runs in its own `php -S 127.0.0.1:<free port>` with `public/index.php` as router, the same PHP binary and php.ini as the suite. Every request is a fresh PHP request in that process, so only files survive between requests, as under PHP-FPM.

The main app registers four test tools from `tests/Support/` next to the baseline `ping`: `echo` returns its `text` argument, `fail` throws `ToolCallException`, `crash` throws `RuntimeException`, and `client` returns the secret key that authenticated the request. It sets an absolute `http.base_url`, so the DNS rebinding check has a fixed host, and logs the SDK at `debug`, so the token check covers everything the SDK can log.

| File | Role |
|---|---|
| `run.php` | Suite. Writes the apps, starts the servers, sends the requests, and checks responses, session files, and logs. |
| `../Support/HttpServer.php` | Starts and stops `php -S` and sends raw HTTP/1.1 requests. |
| `../Support/EchoTool.php`, `FailingTool.php`, `CrashingTool.php`, `ClientTool.php` | Test tools. |

## Cases

Authentication:

- No token gets 401 with `WWW-Authenticate: Bearer realm="mcp"` and no body; a wrong token adds `error="invalid_token"`; a token in another scheme is refused.
- Both secret slots are accepted, and the scheme matches case-insensitively.
- A tool receives the secret key that authenticated the request, and null when authentication is disabled.

Routing and transport:

- `GET` gets 405 from the router with `POST` and `DELETE` in `Allow`.
- A foreign `Host` or `Origin` gets 403, and a host from `mcp.http.allowed_hosts` is accepted.
- A body above `mcp.http.max_body_bytes` gets 413.
- `?_perf` does not append the Kernel's footer to the JSON-RPC body.

Handshake era (`initialize`, sessions):

- `initialize` returns the configured name, title, version, and instructions, the tools capability, and an `Mcp-Session-Id`.
- `tools/list` lists `ping` from the baseline and the app's tools, with their titles, annotations, and schemas; an empty `properties` map is sent as `{}`.
- `ping` answers `pong` with a UTC timestamp, and `echo` returns UTF-8 text unchanged.
- Arguments that break the input schema, and an unknown tool, get -32602 before any tool runs.
- A `ToolCallException` becomes a result with `isError` and the message. Any other exception becomes -32603 without the message, and the SDK logs it at `error` in `var/logs/mcp.jsonl`.
- An unknown session id gets 404; `DELETE` ends a session; sessions are files below `mcp.session.dir`.

Modern era (2026-07-28, stateless):

- `server/discover` and `tools/list` carry `ttlMs` from `mcp.cache.ttl_ms` and `cacheScope: private`, and no session id.
- `tools/call` works without a session, and tool errors and crashes are answered as in the handshake era.

Logging:

- Every request gets one `mcp.access` line: no client on a 401, the matching secret key otherwise, and the JSON-RPC method, tool, status, and error code.
- No log file contains a token.
- A configuration error does not put the token into the logged stack trace, even with `zend.exception_ignore_args=0`, which records arguments.

Configuration:

- `mcp.auth.secret_keys` `[]` disables authentication.
- No configured secret in the store fails with 500 through the global error handler, also for a request without a token, and logs the reason; an empty secret fails with 500 too.
- `null` removes a baseline tool, and a tool class that does not extend `BaseTool` fails with 500.

PHP diagnostics in the apps:

- The package runs inside the servers under CitOmni's ErrorHandler, which logs warnings and deprecations instead of failing. The last case fails when any app wrote `http_err_phperror.jsonl` or `http_err_shutdown.jsonl`.
