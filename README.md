# CitOmni MCP

Model Context Protocol (MCP) server endpoint for CitOmni applications. Exposes tools registered explicitly in config to AI clients over authenticated Streamable HTTP. Built on the official MCP PHP SDK.

`citomni/mcp` adds the MCP endpoint `/mcp` to a CitOmni app. An MCP client such as Claude or ChatGPT connects to it with a fixed bearer token or, when the app enables it, through OAuth, lists the tools the app has registered, and calls them. The package owns the endpoint, the authentication, and the wiring of the SDK. The app owns the tools.

---

## What this package is

- The MCP endpoint, `POST /mcp` (and `DELETE /mcp` to end a session), contributed through the provider Registry.
- Fixed bearer-token authentication against the app's secrets store, checked before the SDK sees the request.
- Optional single-owner OAuth for clients that cannot send a fixed token, such as ChatGPT, alongside fixed tokens. Its discovery, approval, and token routes are registered too and answer 404 until the app enables OAuth: see [OAuth setup](docs/oauth.md).
- Both protocol eras on the same endpoint, decided per request by the SDK:
  - the `initialize` handshake with sessions (revisions 2024-11-05 to 2025-11-25), with sessions stored as files;
  - the stateless 2026-07-28 lifecycle, with caching hints for `tools/list` and `server/discover`.
- A tool map in cfg: tool name => class. A tool is a class that extends `CitOmni\Mcp\Tool\BaseTool` and carries its MCP definition as a constant.
- One built-in tool, `ping`, to check the connection end to end.
- An access log line per request, the SDK's own errors, and OAuth audit events, in `var/logs/mcp.jsonl`.

## What this package does not do

- Multi-user account administration, public client registration, or an external identity provider.
- Resources, prompts, and completions. Only tools are registered.
- Server-initiated messages: no `GET` event stream, no subscriptions, no multi round-trip input requests.
- General MCP quotas or tool rate limiting. The optional owner approval flow has its own attempt limits.

---

## Relationship to other CitOmni packages

```text
citomni/kernel
      ↑
citomni/http   citomni/infrastructure   mcp/sdk   nyholm/psr7
      ↑                  ↑                  ↑           ↑
      └──────────────── citomni/mcp ────────────────────┘
                             ↑
                application (the tools)
```

- `citomni/http` runs the request: Kernel, Router, and the Request service.
- `citomni/infrastructure` provides the `secrets` service (client tokens) and the `log` service. A Composer dependency is not a service registration: the app must list the Infrastructure provider (see [Installation](#installation)).
- `mcp/sdk` is the official PHP SDK for MCP. It parses JSON-RPC, validates tool arguments, keeps sessions, and serves both protocol eras. `nyholm/psr7` supplies its PSR-7 messages.
- `league/oauth2-server` runs the optional OAuth authorization server. It is not a dependency of the package: an app that enables OAuth installs it (see [OAuth setup](docs/oauth.md)).
- Tools are transport adapters, like controllers and commands. They call Operations, Services, and Repositories and shape the result for the model. SQL stays in Repositories.

---

## Requirements

- PHP **8.5+**
- `citomni/http`, `citomni/infrastructure`, `mcp/sdk`, and `nyholm/psr7`, which Composer installs with the package. `composer.json` holds the version constraints.
- `mcp/sdk` is not at 1.0 yet, so each of its minor releases may break. The package allows one SDK minor release at a time and follows them deliberately.
- `ext-intl` (required by `citomni/http`) and `ext-fileinfo` (required by `mcp/sdk`)
- For OAuth only: `league/oauth2-server` in the app; see [OAuth setup](docs/oauth.md).

---

## Installation

`mcp/sdk` depends on `php-http/discovery`, which is a Composer plugin. This package passes its PSR-17 factories explicitly, so the plugin is not needed. Say so in the app before requiring the package. Without an entry, an interactive Composer run asks whether to trust the plugin, and a non-interactive run skips it:

```bash
composer config allow-plugins.php-http/discovery false
composer require citomni/mcp
```

Register both providers in the app's `config/providers.php`. Infrastructure provides the `secrets` and `log` services that the endpoint uses:

```php
<?php
declare(strict_types=1);

return [
	\CitOmni\Infrastructure\Boot\Registry::class,
	\CitOmni\Mcp\Boot\Registry::class,
];
```

Create a client token and put it in the secrets store of each environment, for example `var/secrets/app.secret.prod.php`:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

```php
<?php
return [
	'mcp.client_token.1' => '<64 hex characters>',
];
```

The secrets file must stay out of version control. Without any configured token the endpoint answers every request with 500 and logs why; see [Authentication](#authentication).

Check the endpoint with `ping`. This is one stateless 2026-07-28 request, so no session is needed:

```bash
curl -sS https://www.example.com/mcp \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/call' \
  -H 'Mcp-Name: ping' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"ping","arguments":{},
       "_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28",
                "io.modelcontextprotocol/clientCapabilities":{}}}}'
```

The answer contains `"text":"pong 2026-10-10T08:52:00Z"`. A 401 means the token was not accepted, a 403 that the host is not allowed (see [`mcp.http`](#configuration)), and a 500 is explained in the app's error log.

---

## Connecting a client

The endpoint URL is the app's base URL plus `/mcp`, for example `https://www.example.com/mcp`. Use the host of `http.base_url`; another host gets 403 unless it is listed in `mcp.http.allowed_hosts`. Behind a reverse proxy that rewrites `Host`, list the host that PHP receives.

**Claude (claude.ai, Desktop, mobile).** Add a custom connector with the endpoint URL. Under **Request headers**, add `authorization` with the value `Bearer <token>`, including `Bearer ` and the space. Claude sends the value exactly as entered. Request headers are a beta that not every organization has. Without them, the dialog shows no **Request headers** section; connect through OAuth instead, see [OAuth setup](docs/oauth.md). Headers cannot be edited later: remove the connector and add it again.

**ChatGPT.** Enable the optional OAuth server and configure a custom MCP connection with the pre-registered client ID and secret. See [OAuth setup](docs/oauth.md). Fixed-token clients are not affected.

**Claude Code.**

```bash
claude mcp add --transport http citomni https://www.example.com/mcp \
  --header "Authorization: Bearer <token>"
```

**MCP Inspector.** Run `npx @modelcontextprotocol/inspector`, choose the Streamable HTTP transport, enter the endpoint URL, and add the `Authorization` header.

---

## Configuration

The package ships this baseline in `CFG_HTTP`, shown here without the `oauth` node, which [OAuth setup](docs/oauth.md) covers. `src/Boot/Registry.php` holds the complete baseline with a comment on every key. Override any key in the app's `config/citomni_http_cfg.php` or an environment overlay. Cfg merges last-wins: maps merge key by key, lists are replaced, and an explicit null replaces what is below it. An empty array replaces a list, such as `secret_keys`, and leaves a map unchanged.

```php
'mcp' => [
	'server' => [
		'name'         => 'citomni-mcp',
		'title'        => null,
		'version'      => '1.0.0',
		'instructions' => null,
	],
	'tools' => [
		'ping' => \CitOmni\Mcp\Tool\Ping::class,
	],
	'auth' => [
		'secret_keys' => ['mcp.client_token.1', 'mcp.client_token.2'],
	],
	'http' => [
		'allowed_hosts'  => [],
		'max_body_bytes' => 4_194_304,
	],
	'session' => [
		'dir' => CITOMNI_APP_PATH . '/var/state/mcp_sessions',
		'ttl' => 3600,
	],
	'cache' => [
		'ttl_ms' => 300_000,
	],
	'log' => [
		'file'      => 'mcp',
		'access'    => true,
		'sdk_level' => 'error',
	],
],
```

| Key | Meaning |
|---|---|
| `server.name`, `server.version` | Server identity in the `initialize` and `server/discover` results. |
| `server.title` | Display name; null falls back to `name`. |
| `server.instructions` | A usage hint that clients may add to the model's context, for example which tool to start with. null sends none. |
| `tools` | Tool name => tool class. The map merges like any cfg map, so the app's tools come in addition to `ping`. `'ping' => null` removes a tool that an earlier layer added. |
| `auth.secret_keys` | Keys in the secrets store whose values are accepted as bearer tokens. Keys missing from the store are skipped. `[]` disables fixed tokens; access is anonymous only when OAuth is also disabled. |
| `oauth` | The optional OAuth authorization server, disabled by default. See [OAuth setup](docs/oauth.md). |
| `http.allowed_hosts` | Hosts accepted in `Origin` or `Host` besides the host of `CITOMNI_PUBLIC_ROOT_URL`. Without port. |
| `http.max_body_bytes` | Largest request body read. A larger body gets 413. |
| `session.dir`, `session.ttl` | Where handshake-era sessions are stored (one file each) and how many seconds a session lives after its last request. |
| `cache.ttl_ms` | How long 2026-07-28 clients may cache `tools/list` and `server/discover`, in milliseconds. The scope is always `private`. |
| `log.file` | File name for the `log` service: `var/logs/mcp.jsonl` by default. Access, SDK, and OAuth audit lines all go there. |
| `log.access` | One access line per request. |
| `log.sdk_level` | Lowest PSR-3 level the SDK logs at, `debug` to `emergency`; null logs nothing. From `info` down, the SDK logs every raw message of a handshake-era client, tool arguments included; `debug` adds the arguments of 2026-07-28 tool calls and structured results. |

The package's cfg is HTTP-only: the endpoint is the only consumer.

---

## Writing tools

A tool extends `CitOmni\Mcp\Tool\BaseTool`, declares its MCP definition in `DEFINITION`, and implements `execute()`. Put app tools where the other transport adapters live, for example `src/Mcp/Tool/`. The Operation in the example is illustrative:

```php
<?php
declare(strict_types=1);

namespace App\Mcp\Tool;

use CitOmni\Mcp\Tool\BaseTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

final class ReadFile extends BaseTool {

	public const array DEFINITION = [
		'title'       => 'Read file',
		'description' => 'Read one file of a repository at its current default branch.',
		'inputSchema' => [
			'type'                 => 'object',
			'properties'           => [
				'repo' => ['type' => 'string', 'description' => 'owner/name'],
				'path' => ['type' => 'string'],
			],
			'required'             => ['repo', 'path'],
			'additionalProperties' => false,
		],
		'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
	];

	public function execute(array $arguments, ClientGateway $gateway): string {
		$file = (new \App\Operation\ReadRepositoryFile($this->app))->execute($arguments['repo'], $arguments['path']);

		if ($file === null) {
			throw new ToolCallException("{$arguments['path']} does not exist in {$arguments['repo']}.");
		}

		return $file['content'];
	}

}
```

Register it under the name the client will see:

```php
'mcp' => [
	'tools' => [
		'read_file' => \App\Mcp\Tool\ReadFile::class,
	],
],
```

The contract:

- **Name.** The cfg key. Use letters, digits, `_`, `-`, and `.`. `DEFINITION` must not contain `name`.
- **Definition.** `DEFINITION` has the wire shape of an MCP tool: `inputSchema` (required, a JSON Schema of type `object`), and optionally `title`, `description`, `annotations`, `outputSchema`, `_meta`, and `icons`. An empty `properties` map is sent as `{}`.
- **Arguments.** The SDK validates the arguments against `inputSchema` before `execute()` runs. A violation never reaches the tool; the client gets JSON-RPC -32602 with the reason.
- **Result.** A string becomes one text block. An array or object becomes one text block of pretty-printed JSON and the same data as `structuredContent`. `Mcp\Schema\Content\*` objects and a `CallToolResult` are sent as they are.
- **Errors the model should see.** Throw `Mcp\Exception\ToolCallException`. The client gets a result with `isError: true` and the message, and the model can react, for example by correcting a path.
- **Other exceptions.** Any other throwable is a server error. The client gets JSON-RPC -32603 without details, and the SDK logs the exception at `error` in `var/logs/mcp.jsonl`. These failures never reach the global error handler, because the SDK answers the client itself.
- **Construction.** Every request that builds the server constructs every registered tool as `new $class($app, $client)`, also for `tools/list`. Keep the constructor and an optional `init()` cheap; resolve services in `execute()`.
- **Client.** `$this->client` is the secret key whose token authenticated the request, for example `mcp.client_token.1`, `oauth:<owner_id>:<client_id>` for OAuth, or null when authentication is disabled. Use it to tell clients apart, for example to bind server-side state to the client that created it. It is never the token.
- **Annotations are hints.** `readOnlyHint` tells the client what the tool does; nothing enforces it. A tool that must not write must not be given the means to.

A tool class that does not extend `BaseTool`, or a `DEFINITION` the SDK rejects, throws `McpConfigException` with the tool's name on every request. The global error handler answers 500 and logs it.

---

## Authentication

By default, every request needs `Authorization: Bearer <token>`, where the token is the value of one of the secrets named in `mcp.auth.secret_keys`. The scheme matches case-insensitively, the token exactly, compared with `hash_equals()`.

| Situation | Answer |
|---|---|
| No `Authorization` header, or another scheme | 401, `WWW-Authenticate: Bearer realm="mcp"`, no body |
| A token that matches no secret | 401, `WWW-Authenticate: Bearer realm="mcp", error="invalid_token"`, no body |
| A secret named in `secret_keys` is empty | 500, `McpConfigException` |
| None of the named secrets exists in the store | 500, `McpConfigException`, for every request, with or without a token |
| `secret_keys` is `[]`, OAuth disabled | Every request is accepted |
| `secret_keys` is `[]`, OAuth enabled | A valid OAuth access token is required |

With OAuth enabled, unmatched fixed tokens are also checked as signed OAuth access tokens. A 401 includes protected-resource discovery metadata. The access log names the matching secret key (for example `mcp.client_token.1`) or stable OAuth identity, never the token.

**Rotation.** The baseline names two keys, and a missing key is skipped. Put the new token under the free key, switch the client to it, then remove the old secret. No cfg change and no downtime.

**One token per client.** To tell clients apart in the access log, give each its own key, for example `'secret_keys' => ['mcp.client_token.claude', 'mcp.client_token.inspector']`. The list replaces the baseline.

**Apache and PHP-FPM.** Some setups do not pass the `Authorization` header to PHP. The `public/.htaccess` of the CitOmni scaffold copies it into the environment (`RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`). After an internal rewrite Apache may deliver it as `REDIRECT_HTTP_AUTHORIZATION`; the controller reads both.

---

## Requests and responses

A request runs through these steps:

1. The Router matches `/mcp`. It answers `OPTIONS` itself and every method other than `POST` and `DELETE` with 405.
2. `McpController` checks the bearer token (`ClientAuth`). A request that an OAuth access token authenticated must also arrive over HTTPS on the issuer's host and port.
3. `Psr7Bridge` reads the body and builds a PSR-7 request without the `Authorization` and `Cookie` headers.
4. `ServerFactory` builds the SDK server from cfg and wraps the request in the SDK's Streamable HTTP transport, with DNS rebinding protection as its only middleware.
5. The SDK classifies the request by protocol era, validates it, and runs the tool.
6. The controller writes the access line, discards everything buffered so far, sends the response, and ends the script.

| Status | When |
|---|---|
| 200 | A result; in the handshake era also a JSON-RPC error, such as invalid arguments |
| 202 | A notification, which has no response |
| 400 | A malformed or inconsistent request; in the 2026-07-28 era also a JSON-RPC error, such as invalid arguments or a crashed tool |
| 401 | No valid bearer token; with OAuth enabled, the challenge names the protected-resource metadata and the scope |
| 403 | `Origin` or `Host` is not an allowed host, or an OAuth access token arrives over another scheme, host, or port than the issuer's |
| 404 | An unknown or expired session id, after which the client must send `initialize` again; in the 2026-07-28 era also an unknown method |
| 405 | `GET` or another method the route does not allow |
| 413 | A body above `mcp.http.max_body_bytes`, or above PHP's `post_max_size` |
| 500 | A configuration error, through the global error handler, or an SDK failure outside a tool |

**Sessions.** A handshake-era client gets an `Mcp-Session-Id` from `initialize` and sends it with every later request. The SDK stores the session as a file in `mcp.session.dir` and deletes it on `DELETE`, after `mcp.session.ttl` seconds without requests, or by its garbage collection, which runs on 1 % of the requests. 2026-07-28 clients have no sessions: every request carries its protocol version and capabilities.

---

## Logging

All three kinds of line go to `var/logs/mcp.jsonl` through the `log` service, which rotates the file.

**Access lines** (`mcp.access`), one per request when `mcp.log.access` is true:

| Field | Content |
|---|---|
| `client` | The matching secret key or stable OAuth identity; absent on a 401 and when authentication is disabled |
| `ip` | The public client IP from the Request service, or `unknown` |
| `method` | The HTTP method |
| `rpc` | The JSON-RPC method, or `batch` |
| `tool` | The tool name of a `tools/call` |
| `status` | The HTTP status |
| `rpc_error` | The JSON-RPC error code, for example -32602 for invalid arguments |
| `tool_error` | `true` when the tool returned a result with `isError` |
| `ms` | Time from the start of the controller to the response, in milliseconds |

**SDK lines** (`mcp.sdk`) at or above `mcp.log.sdk_level`, with the level in the context. Exceptions are reduced to class, message, code, file, line, trace, and previous exceptions. At the default level `error` they are mainly tool exceptions other than `ToolCallException`, and unknown tool names.

**OAuth audit lines** (`mcp.oauth`), when OAuth is enabled: the outcome of every approval step, token request, and detected replay, with owner, client, and IP. They never contain tokens, codes, passwords, or request bodies. See [OAuth setup](docs/oauth.md).

Configuration errors and failures to build the server do not appear here. They reach the global error handler, which logs them in `var/logs/http_err_exception.jsonl`.

---

## Security

- **Tokens.** Use 32 random bytes or more, keep them only in the secrets store, and give each client its own. The token never reaches the SDK or a log: the bridge removes the `Authorization` header before the SDK sees the request.
- **DNS rebinding.** The SDK refuses a request whose `Origin`, or `Host` when there is no `Origin`, is not the host of `CITOMNI_PUBLIC_ROOT_URL` or listed in `mcp.http.allowed_hosts`. The check reads the raw `Host` header, not the proxy-aware host of the Request service, so behind a proxy that rewrites `Host` the rewritten host must be listed. In `dev` without an absolute `http.base_url`, the Kernel derives `CITOMNI_PUBLIC_ROOT_URL` from the request's own `Host`, so the check only bites with an absolute `http.base_url`, which `stage` and `prod` require anyway.
- **Network.** Claude connects from Anthropic's [published outbound range](https://platform.claude.com/docs/en/api/ip-addresses), `160.79.104.0/21` at the time of writing. A firewall rule can limit the endpoint to it, in addition to the token.
- **Tools are your code.** The package exposes exactly the tools in `mcp.tools`. Annotations such as `readOnlyHint` are hints for the client; a tool is read-only only if its code is.
- **Arguments in logs.** Tool arguments are logged from `sdk_level` `info` down, and structured results at `debug`. Keep the default `error` in production if they can carry personal data. With `zend.exception_ignore_args` off, the exception traces logged at `error` carry string arguments too, cut to 15 bytes by PHP; the Authorization header is a `#[\SensitiveParameter]` and never among them.
- **Request size.** `mcp.http.max_body_bytes` caps the body the SDK reads, and the Kernel answers a body above `post_max_size` with 413 before routing.

---

## Current limitations

- OAuth is optional and limited to one owner, one pre-registered confidential client, one resource and one scope; state must reside on one host. See [OAuth setup](docs/oauth.md).
- Only tools; no resources, prompts, or completions.
- No server-initiated messages: no `GET` event stream, no `subscriptions/listen`, no multi round-trip input requests (`setRequestState()` is not configured).
- The server and every tool are built for each request. For a handful of tools this costs a few milliseconds; a very large tool map would call for lazy construction.
- The SDK is pre-1.0, and the package follows its minor releases deliberately.

---

## Testing

The tests follow the [CitOmni testing guide](https://github.com/citomni/docs/blob/main/contribute/testing-guide.md): standalone suites in plain PHP, one per area, each run in its own process, without a test framework. No suite replaces a framework class with a `class_alias` double. The suites use the real packages, `league/oauth2-server` from `require-dev` included, so they load Composer's autoloader from the package's `vendor/`. Install the dependencies first:

```bash
composer install
php tests/run.php
```

`composer test` runs the same command, and `php tests/http/run.php` runs one suite.

A suite reports every case as `PASS <case>` or `SKIP <case>: <reason>` on stdout, or as `FAIL <case> - <reason>` on stderr, and a failed case does not stop the suite. The last line holds the totals, `N passed, M failed[, K skipped]`, and the exit code is 1 when a case failed. `tests/run.php` runs `tests/*/run.php` and then `tests/*/database.php`, prints each suite under `== <suite>/<script>`, and ends with the summed totals under `== total`. The package has no database suites, so `CITOMNI_TEST_PASSWORD` changes nothing. The only optional cases are the multi-process ones, which run only with `CITOMNI_TEST_PARALLEL=1` and report `SKIP` otherwise. Every unsuppressed diagnostic in a suite fails the case, including deprecations. The package itself runs inside the web servers under CitOmni's ErrorHandler, which only logs diagnostics, so the last case of each suite fails when any app logged one.

| Suite | Covers |
|---|---|
| `http` | End to end through PHP's built-in web server: authentication, routing, DNS rebinding, body limit, both protocol eras, sessions, tool results and errors, caching hints, access and SDK logs, tokens kept out of logs and traces, and configuration errors |
| `oauth` | The OAuth server end to end through PHP's built-in web server, with simulated TLS termination and ephemeral RSA keys: discovery, the approval page, PKCE and exact callbacks, code exchange, refresh rotation, replay and family revocation, access-token validation, capacity and rate limits, and audit lines. With `CITOMNI_TEST_PARALLEL=1`, two of its cases start two worker processes that race to redeem the same code or refresh token. |
| `browser` | The approval page in headless Chrome, Chromium, or Edge over real local TLS: browser-generated `Origin` and `Sec-Fetch-Site`, CSP `form-action` across the callback redirect, `Referer` at the callback, and the approval cookie. It needs one of those browsers and fails without one. |

The READMEs of [browser](tests/browser/README.md), [http](tests/http/README.md), and [oauth](tests/oauth/README.md) list their cases and expected totals; the browser README also lists where the suite looks for a browser. The `browser` and `oauth` suites generate RSA keys, which on Windows needs an `openssl.cnf`; the [oauth README](tests/oauth/README.md) says which one they use. `tests/` is excluded from the Composer dist archive, so the suites need a source checkout.

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:

[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni MCP** is open-source under the **MIT License**. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.

You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.

Do not register or use "citomni" or confusingly similar terms in company names, domains, social handles, or top-level vendor/package names.

For details, see [TRADEMARKS.md](TRADEMARKS.md).

---

## Author

Developed by Lars Grove Mortensen (c) 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.
