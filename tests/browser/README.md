# Browser suite

Browser checks of the OAuth approval page: a real CitOmni HTTP app with OAuth enabled, used through headless Chrome, Chromium or Edge over real TLS. The cases cover what only a browser produces or enforces, which the raw requests of the `oauth` suite cannot show: `Origin` and `Sec-Fetch-Site` on the approval form, CSP `form-action` across the redirect to the callback, `Referer` at the callback, and the approval cookie. No database and no network beyond 127.0.0.1. No case connects to a real OAuth client such as ChatGPT.

```
composer install
php tests/browser/run.php
```

Expected: `6 passed, 0 failed`

`PASS` lines go to stdout and `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a case fails. `php tests/run.php` runs this suite with the others.

Besides PHP, the suite needs only a browser: no Node.js, no browser automation package and no OpenSSL CLI. It uses the first one it finds:

- Windows: `Google\Chrome\Application\chrome.exe`, then `Chromium\Application\chrome.exe`, then `Microsoft\Edge\Application\msedge.exe`, each under `%ProgramFiles%`, `%ProgramFiles(x86)%` and `%LOCALAPPDATA%`; then `chrome.exe` and `msedge.exe` on `PATH`.
- macOS: Google Chrome, Chromium and Microsoft Edge in `/Applications`; then `google-chrome` and `chromium` on `PATH`.
- Linux and others: `google-chrome`, `google-chrome-stable`, `chromium`, `chromium-browser`, `chrome`, `microsoft-edge` and `microsoft-edge-stable` on `PATH`.

No test variable selects or overrides the browser. Without a browser, the suite stops with the locations it searched and counts as failed; it does not skip. The suite generates RSA keys and a certificate, which on Windows needs an `openssl.cnf`, as the [OAuth suite](../oauth/README.md) describes.

## How it works

The suite writes one app with the same fixture as the `oauth` suite and serves it with `php -S` on 127.0.0.1. A second PHP process opens two HTTPS origins on 127.0.0.1 with a self-signed certificate generated for the run. The issuer origin is a proxy that forwards every request to the app unchanged, apart from `Connection` and `Keep-Alive`, which become `Connection: close`. The callback origin only records what reaches it. Both answer the browser's own `/favicon.ico` requests themselves, and log one line for every other request with method, target, status, `Origin`, `Sec-Fetch-Site` and `Referer`, never the body. The suite points the app's issuer, `http.base_url` and callback URL at these origins, and the app's `public/index.php` reports HTTPS on the issuer's port.

Neither origin asks for a client certificate. PHP's TLS servers do unless `verify_peer` is `false`, and a browser that holds client certificates, as Windows user certificate stores often do, then waits at a certificate prompt that no one answers in headless mode. Before the browser starts, the suite therefore requests a page from each origin with PHP's TLS client while offering a client certificate. An origin that asks for one cannot verify it, fails the handshake and stops the suite.

Both origins also write their connection events to `origins.log` in the run directory: accepted, TLS handshake complete or failed, closed early, and each request with method, path and status. A case that fails with a browser or protocol error, such as a navigation that never loads, ends its message with the last of these events, which show whether the browser connected, finished TLS and sent its request.

The browser runs headless with a throwaway profile below the run directory, without proxy, sync or extensions, and every case gets a browser context of its own, so no cookie or page carries over from another case. It ignores certificate errors for the run's key only, through `--ignore-certificate-errors-spki-list`, so CSP, cookies and origin checks work as in normal use. The suite drives it over the DevTools protocol on its WebSocket: it loads pages, types text, clicks with real mouse events, and reads cookies, CSP messages and script results.

| File | Role |
|---|---|
| `run.php` | Suite. Generates the certificate, starts the app, the origins and the browser, and runs the cases. |
| `../Support/Chromium.php` | Finds, starts and closes the browser, and speaks the DevTools protocol. |
| `../Support/BrowserOrigins.php` | The issuer proxy and the callback origin, with their request log. |
| `../Support/OAuthFixture.php` | The app writer and the authorization parameters, shared with the `oauth` suite. |
| `../Support/HttpServer.php` | Starts and stops `php -S`. |

## Cases

- A wrong password from the approval page gets 403, and the browser sends the issuer as `Origin` and `Sec-Fetch-Site: same-origin`; the callback is not reached.
- Approval redirects to the callback origin with code, state and issuer. The callback receives no `Referer`, and the browser logs no `form-action` violation.
- The approval page sets the `__Host-citomni_mcp_oauth` cookie, and the approval clears it.
- Denial redirects to the callback origin with `error=access_denied`, state and issuer, and no code, and the browser logs no `form-action` violation.
- CSP `form-action` blocks a submission to an origin outside the issuer and the registered callbacks: the page gets a `securitypolicyviolation` and stays.
- The last case fails when the app logged an unexpected exception or PHP diagnostic.

Two earlier regressions fail the first two cases. With `Referrer-Policy: no-referrer` on the approval page, the browser sends `Origin: null`, which fails the first case. A `form-action` without the callback origin blocks the redirect after approval, which fails the second.
