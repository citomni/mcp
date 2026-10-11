# OAuth browser regression

This separate integration test uses a real Chromium browser and two local HTTPS
origins. PHP runs the real CitOmni kernel; the local TLS proxy forwards headers
unchanged. It does not replace Origin, Referer, CSP, cookies or callback redirects.
No live account or external callback receives credentials or codes.

## Run

Install the provider's Composer development dependencies first, then:

```sh
cd tests/browser
npm install
npx playwright install chromium --only-shell
npm test
```

PHP 8.5+, Node.js, OpenSSL CLI and Chromium's platform dependencies are required.
This suite is separate from `php tests/run.php` so the ordinary PHP test workflow
does not silently acquire a Node/browser dependency. Run both suites before
releasing OAuth changes. Missing tools fail the browser suite; no checks are skipped.

Optional environment variables are `PHP_BINARY`, `OPENSSL_BINARY`, and
`CHROMIUM_EXECUTABLE`. For Windows CMD, set these to absolute paths before `npm test`.
The browser can be installed by Playwright or supplied explicitly. The reviewed
regression was reproduced and fixed with Chromium 141.0.7390.54; the runner prints
the version actually used. `ignoreHTTPSErrors` is limited to the ephemeral local
test certificate. It does not turn off CSP or origin checks.

The five checks cover browser-generated same-origin headers, wrong passwords,
successful approval/callback with code/state/issuer, approval-cookie clearing,
denial callbacks, and CSP refusal of a destination outside the configured origins.
The first two historical failures can be reproduced by using `no-referrer` or
removing the callback origin from `form-action`, respectively.
