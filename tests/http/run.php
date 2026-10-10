<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Mcp\Tests;

use CitOmni\Mcp\Tests\Support\CrashingTool;
use CitOmni\Mcp\Tests\Support\EchoTool;
use CitOmni\Mcp\Tests\Support\FailingTool;
use CitOmni\Mcp\Tests\Support\HttpServer;

/*
 * End-to-end checks of the /mcp endpoint: a real CitOmni HTTP app (citomni/kernel,
 * citomni/http, citomni/infrastructure, and this package) served by PHP's built-in
 * web server, driven with raw HTTP requests in both protocol eras.
 *
 * Usage:
 *   php tests/http/run.php
 *
 * Notes:
 * - The suite replaces no framework class, so it loads the real packages and
 *   mcp/sdk through Composer's autoloader (see tests/bootstrap.php).
 * - A server that cannot be started stops the suite; it is not a reason to skip.
 * - See README.md next to this file for what the cases cover.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic, including deprecations. Like the production ErrorHandler,
// leave diagnostics silenced with @ (socket probes, process control) to PHP.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}

	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';


// ----------------------------------------------------------------
// Fixtures
// ----------------------------------------------------------------

/**
 * Write a CitOmni HTTP app that serves /mcp, and return its root.
 *
 * Notes:
 * - $cfg is the app's citomni_http_cfg.php. Pass only the keys to override: cfg
 *   merges last-wins, so an empty array would replace a whole baseline node.
 *
 * @param array<string, mixed> $cfg App cfg overlay.
 * @param array<string, string>|null $secrets Secrets for app.secret.dev.php; null writes no file.
 * @return string App root.
 */
function writeApp(array $cfg, ?array $secrets): string {
	$dir = tempDir();
	$GLOBALS['mcpTestApps'][] = $dir;

	foreach (['config', 'public', 'var/secrets', 'var/logs', 'var/state'] as $sub) {
		\mkdir($dir . '/' . $sub, 0777, true);
	}

	\file_put_contents($dir . '/config/providers.php', "<?php\nreturn [\n\t\\CitOmni\\Infrastructure\\Boot\\Registry::class,\n\t\\CitOmni\\Mcp\\Boot\\Registry::class,\n];\n");
	writeCfg($dir, $cfg);

	if ($secrets !== null) {
		\file_put_contents($dir . '/var/secrets/app.secret.dev.php', "<?php\nreturn " . \var_export($secrets, true) . ";\n");
	}

	\file_put_contents($dir . '/public/index.php', "<?php\ndeclare(strict_types=1);\n"
		. "define('CITOMNI_START_NS', hrtime(true));\n"
		. "define('CITOMNI_ENVIRONMENT', 'dev');\n"
		. "define('CITOMNI_PUBLIC_PATH', __DIR__);\n"
		. "define('CITOMNI_APP_PATH', \\dirname(__DIR__));\n"
		. 'require ' . \var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
		. "\\CitOmni\\Http\\Kernel::run(__DIR__);\n");

	return $dir;
}


/**
 * Write an app's citomni_http_cfg.php. Every request reads it again.
 *
 * @param string $appDir App root.
 * @param array<string, mixed> $cfg App cfg overlay.
 * @return void
 */
function writeCfg(string $appDir, array $cfg): void {
	\file_put_contents($appDir . '/config/citomni_http_cfg.php', "<?php\nreturn " . \var_export($cfg, true) . ";\n");
}


/**
 * Start a server for an app, or stop the suite when that is impossible.
 *
 * @param string $appDir App root.
 * @param array<string, string> $ini INI settings for the server.
 * @return HttpServer Running server.
 */
function serve(string $appDir, array $ini = []): HttpServer {
	return HttpServer::start($appDir, $ini) ?? fail('Cannot start PHP\'s built-in web server: ' . HttpServer::$lastFailure);
}


/**
 * Send one JSON-RPC message to /mcp.
 *
 * @param HttpServer $server Server.
 * @param array<string, mixed>|list<mixed> $message JSON-RPC message.
 * @param array<string, string> $headers Extra or replacing headers; a null-like '' value removes one.
 * @param string $path Request path.
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function rpc(HttpServer $server, array $message, array $headers = [], string $path = '/mcp'): array {
	$headers += [
		'Content-Type'  => 'application/json',
		'Accept'        => 'application/json, text/event-stream',
		'Authorization' => 'Bearer ' . TOKEN_1,
	];
	$headers = \array_filter($headers, static fn(string $value): bool => $value !== '');

	$response = $server->request('POST', $path, $headers, (string)\json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
	$response['json'] = \json_decode($response['body'], true);

	return $response;
}


/**
 * Send one modern-era (2026-07-28) request: envelope in params._meta, standard headers.
 *
 * @param HttpServer $server Server.
 * @param string $method JSON-RPC method.
 * @param array<string, mixed> $params Params without _meta.
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function modern(HttpServer $server, string $method, array $params = []): array {
	$params['_meta'] = [
		'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
		'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
		'io.modelcontextprotocol/clientInfo'         => ['name' => 'citomni-mcp-tests', 'version' => '1.0.0'],
	];
	$headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];

	if (isset($params['name']) && \is_string($params['name'])) {
		$headers['Mcp-Name'] = $params['name'];
	}

	return rpc($server, ['jsonrpc' => '2.0', 'id' => \random_int(1, 1_000_000), 'method' => $method, 'params' => $params], $headers);
}


/**
 * Open a handshake-era session and return its id.
 *
 * @param HttpServer $server Server.
 * @return string Mcp-Session-Id.
 */
function openSession(HttpServer $server): string {
	$init = rpc($server, initializeMessage());
	check($init['status'] === 200, 'initialize answered ' . $init['status'] . ': ' . $init['body']);
	$session = $init['headers']['mcp-session-id'] ?? '';
	check($session !== '', 'initialize set no Mcp-Session-Id');

	$ready = rpc($server, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25']);
	check($ready['status'] === 202, 'notifications/initialized answered ' . $ready['status'] . ': ' . $ready['body']);

	return $session;
}


/**
 * openSession() for a server whose base URL is auto-detected: send Host localhost.
 *
 * @param HttpServer $server Server.
 * @return string Mcp-Session-Id.
 */
function openSessionOn(HttpServer $server): string {
	$host = ['Host' => 'localhost:' . $server->port];
	$init = rpc($server, initializeMessage(), $host);
	check($init['status'] === 200, 'initialize answered ' . $init['status'] . ': ' . $init['body']);
	$session = $init['headers']['mcp-session-id'] ?? '';
	rpc($server, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $host + ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25']);

	return $session;
}


/**
 * The initialize request of a 2025-11-25 client.
 *
 * @return array<string, mixed>
 */
function initializeMessage(): array {
	return [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-11-25',
			'capabilities'    => new \stdClass(),
			'clientInfo'      => ['name' => 'citomni-mcp-tests', 'version' => '1.0.0'],
		],
	];
}


/**
 * Call a tool in an open handshake-era session.
 *
 * @param HttpServer $server Server.
 * @param string $session Mcp-Session-Id.
 * @param string $name Tool name.
 * @param array<string, mixed> $arguments Tool arguments.
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function callTool(HttpServer $server, string $session, string $name, array $arguments = []): array {
	return rpc($server, [
		'jsonrpc' => '2.0',
		'id'      => \random_int(1, 1_000_000),
		'method'  => 'tools/call',
		'params'  => ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments],
	], ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25']);
}


/**
 * Read a JSONL log file of an app.
 *
 * @param string $appDir App root.
 * @param string $file File name below var/logs.
 * @return list<array<string, mixed>> Decoded lines.
 */
function logLines(string $appDir, string $file = 'mcp.jsonl'): array {
	$path = $appDir . '/var/logs/' . $file;

	if (!\is_file($path)) {
		return [];
	}

	$lines = [];

	foreach (\file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
		$lines[] = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
	}

	return $lines;
}


/**
 * Concatenate every file below an app's var/logs.
 *
 * @param string $appDir App root.
 * @return string All log content.
 */
function allLogs(string $appDir): string {
	$all = '';

	foreach (\glob($appDir . '/var/logs/*') ?: [] as $file) {
		$all .= (string)\file_get_contents($file);
	}

	return $all;
}


// ----------------------------------------------------------------
// Apps
// ----------------------------------------------------------------

/** @var list<string> $mcpTestApps Root of every app written, for the final diagnostics check. */
$mcpTestApps = [];

\define(__NAMESPACE__ . '\TOKEN_1', \bin2hex(\random_bytes(32)));
\define(__NAMESPACE__ . '\TOKEN_2', \bin2hex(\random_bytes(32)));

$mainCfg = [
	'server' => ['name' => 'citomni-mcp-test', 'title' => 'CitOmni MCP test', 'version' => '9.9.9', 'instructions' => 'Test server.'],
	'tools'  => ['echo' => EchoTool::class, 'fail' => FailingTool::class, 'crash' => CrashingTool::class],
	'http'   => ['allowed_hosts' => ['localhost'], 'max_body_bytes' => 65_536],
	'cache'  => ['ttl_ms' => 12_345],
	'log'    => ['sdk_level' => 'debug'],
];

$mainApp = writeApp(['mcp' => $mainCfg], ['mcp.client_token.1' => TOKEN_1, 'mcp.client_token.2' => TOKEN_2]);
$main = serve($mainApp);

// The absolute base_url pins the allowed host to 127.0.0.1; without it, dev
// auto-detects the base URL from the request's own Host header.
writeCfg($mainApp, ['mcp' => $mainCfg, 'http' => ['base_url' => 'http://127.0.0.1:' . $main->port]]);


// ----------------------------------------------------------------
// Authentication
// ----------------------------------------------------------------

test('a request without a token gets 401 with a Bearer challenge and no body', static function () use ($main): void {
	$response = rpc($main, initializeMessage(), ['Authorization' => '']);
	check($response['status'] === 401, 'status ' . $response['status']);
	check(($response['headers']['www-authenticate'] ?? '') === 'Bearer realm="mcp"', 'challenge: ' . ($response['headers']['www-authenticate'] ?? '(none)'));
	check($response['body'] === '', 'body: ' . $response['body']);
});

test('a wrong token gets 401 with error="invalid_token"', static function () use ($main): void {
	$response = rpc($main, initializeMessage(), ['Authorization' => 'Bearer ' . \str_repeat('0', 64)]);
	check($response['status'] === 401, 'status ' . $response['status']);
	check(($response['headers']['www-authenticate'] ?? '') === 'Bearer realm="mcp", error="invalid_token"', 'challenge: ' . ($response['headers']['www-authenticate'] ?? '(none)'));
});

test('a token in another scheme is not accepted', static function () use ($main): void {
	$response = rpc($main, initializeMessage(), ['Authorization' => 'Basic ' . \base64_encode('x:' . TOKEN_1)]);
	check($response['status'] === 401, 'status ' . $response['status']);
});

test('both token slots are accepted, the scheme case-insensitively', static function () use ($main): void {
	foreach (['Bearer ' . TOKEN_1, 'bearer ' . TOKEN_2] as $authorization) {
		$response = rpc($main, initializeMessage(), ['Authorization' => $authorization]);
		check($response['status'] === 200, \substr($authorization, 0, 10) . '... answered ' . $response['status'] . ': ' . $response['body']);
	}
});


// ----------------------------------------------------------------
// Routing and transport
// ----------------------------------------------------------------

test('GET gets 405 from the router, with POST and DELETE allowed', static function () use ($main): void {
	$response = $main->request('GET', '/mcp', ['Authorization' => 'Bearer ' . TOKEN_1, 'Accept' => 'application/json']);
	check($response['status'] === 405, 'status ' . $response['status']);
	$allow = $response['headers']['allow'] ?? '';
	check(\str_contains($allow, 'POST') && \str_contains($allow, 'DELETE'), 'Allow: ' . $allow);
});

test('a foreign Host is refused with 403', static function () use ($main): void {
	$response = rpc($main, initializeMessage(), ['Host' => 'evil.example']);
	check($response['status'] === 403, 'status ' . $response['status'] . ': ' . $response['body']);
});

test('a foreign Origin is refused with 403', static function () use ($main): void {
	$response = rpc($main, initializeMessage(), ['Origin' => 'https://evil.example']);
	check($response['status'] === 403, 'status ' . $response['status'] . ': ' . $response['body']);
});

test('a host from mcp.http.allowed_hosts is accepted', static function () use ($main): void {
	$response = rpc($main, initializeMessage(), ['Host' => 'localhost:' . $main->port]);
	check($response['status'] === 200, 'status ' . $response['status'] . ': ' . $response['body']);
});

test('a body above mcp.http.max_body_bytes gets 413', static function () use ($main): void {
	$message = initializeMessage();
	$message['params']['padding'] = \str_repeat('x', 70_000);
	$response = rpc($main, $message);
	check($response['status'] === 413, 'status ' . $response['status']);
});

test('?_perf does not reach the JSON-RPC body', static function () use ($main): void {
	$response = rpc($main, initializeMessage(), [], '/mcp?_perf=1');
	check($response['status'] === 200, 'status ' . $response['status']);
	check(\is_array($response['json']), 'body is not JSON: ' . \substr($response['body'], -200));
});


// ----------------------------------------------------------------
// Handshake era (initialize, sessions)
// ----------------------------------------------------------------

test('initialize returns the configured server and a session id', static function () use ($main): void {
	$response = rpc($main, initializeMessage());
	check($response['status'] === 200, 'status ' . $response['status'] . ': ' . $response['body']);
	$result = $response['json']['result'] ?? [];
	check(($result['protocolVersion'] ?? '') === '2025-11-25', 'protocolVersion: ' . \json_encode($result['protocolVersion'] ?? null));
	check(($result['serverInfo']['name'] ?? '') === 'citomni-mcp-test', 'serverInfo: ' . \json_encode($result['serverInfo'] ?? null));
	check(($result['serverInfo']['version'] ?? '') === '9.9.9', 'serverInfo.version: ' . \json_encode($result['serverInfo'] ?? null));
	check(($result['serverInfo']['title'] ?? '') === 'CitOmni MCP test', 'serverInfo.title: ' . \json_encode($result['serverInfo'] ?? null));
	check(($result['instructions'] ?? '') === 'Test server.', 'instructions: ' . \json_encode($result['instructions'] ?? null));
	check(isset($result['capabilities']['tools']), 'capabilities: ' . \json_encode($result['capabilities'] ?? null));
	check(\preg_match('/^[0-9a-f-]{36}$/', $response['headers']['mcp-session-id'] ?? '') === 1, 'Mcp-Session-Id: ' . ($response['headers']['mcp-session-id'] ?? '(none)'));
});

test('tools/list lists ping from the baseline and the app\'s tools', static function () use ($main): void {
	$session = openSession($main);
	$response = rpc($main, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25']);
	$tools = [];

	foreach ($response['json']['result']['tools'] ?? [] as $tool) {
		$tools[$tool['name']] = $tool;
	}

	\ksort($tools);
	check(\array_keys($tools) === ['crash', 'echo', 'fail', 'ping'], 'tools: ' . \implode(', ', \array_keys($tools)) . ' ' . $response['body']);
	check(($tools['ping']['annotations']['readOnlyHint'] ?? null) === true, 'ping annotations: ' . \json_encode($tools['ping']['annotations'] ?? null));
	check(($tools['ping']['title'] ?? '') === 'Ping', 'ping title: ' . \json_encode($tools['ping']['title'] ?? null));
	check(($tools['echo']['inputSchema']['required'] ?? null) === ['text'], 'echo inputSchema: ' . \json_encode($tools['echo']['inputSchema'] ?? null));
	check(\str_contains($response['body'], '"properties":{}'), 'an empty properties map is not sent as {}: ' . $response['body']);
});

test('tools/call ping answers pong with the UTC time', static function () use ($main): void {
	$response = callTool($main, openSession($main), 'ping');
	$text = $response['json']['result']['content'][0]['text'] ?? '';
	check(\preg_match('/^pong \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $text) === 1, 'result: ' . $response['body']);
	check(($response['json']['result']['isError'] ?? false) === false, 'isError: ' . $response['body']);
});

test('tools/call echo returns the text unchanged, UTF-8 included', static function () use ($main): void {
	$response = callTool($main, openSession($main), 'echo', ['text' => 'Grüße, æøå "quoted" \\ back']);
	check(($response['json']['result']['content'][0]['text'] ?? null) === 'Grüße, æøå "quoted" \\ back', 'result: ' . $response['body']);
});

test('arguments that break the input schema get -32602 before the tool runs', static function () use ($main): void {
	$session = openSession($main);

	foreach ([[], ['text' => 42], ['text' => 'a', 'extra' => true]] as $arguments) {
		$response = callTool($main, $session, 'echo', $arguments);
		check(($response['json']['error']['code'] ?? null) === -32602, \json_encode($arguments) . ' answered: ' . $response['body']);
	}
});

test('an unknown tool gets -32602', static function () use ($main): void {
	$response = callTool($main, openSession($main), 'nope');
	check(($response['json']['error']['code'] ?? null) === -32602, 'answer: ' . $response['body']);
});

test('a ToolCallException becomes a result with isError and the message', static function () use ($main): void {
	$response = callTool($main, openSession($main), 'fail');
	check(($response['json']['result']['isError'] ?? null) === true, 'answer: ' . $response['body']);
	check(($response['json']['result']['content'][0]['text'] ?? null) === FailingTool::MESSAGE, 'text: ' . $response['body']);
});

test('an unexpected exception becomes -32603 without details, and is logged', static function () use ($main, $mainApp): void {
	$response = callTool($main, openSession($main), 'crash');
	check(($response['json']['error']['code'] ?? null) === -32603, 'answer: ' . $response['body']);
	check(!\str_contains($response['body'], '/srv/secret/path'), 'the exception message reached the client: ' . $response['body']);

	$logged = false;

	foreach (logLines($mainApp) as $line) {
		if (($line['category'] ?? '') === 'mcp.sdk' && ($line['context']['level'] ?? '') === 'error'
			&& ($line['context']['exception']['message'] ?? '') === CrashingTool::MESSAGE
			&& ($line['context']['exception']['class'] ?? '') === \RuntimeException::class) {
			$logged = true;
		}
	}

	check($logged, 'no mcp.sdk error line with the exception in var/logs/mcp.jsonl');
});

test('an unknown session id gets 404', static function () use ($main): void {
	$response = rpc($main, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], ['Mcp-Session-Id' => '0199c1d2-3e4f-7a5b-8c6d-7e8f9a0b1c2d', 'MCP-Protocol-Version' => '2025-11-25']);
	check($response['status'] === 404, 'status ' . $response['status'] . ': ' . $response['body']);
});

test('DELETE ends the session; the session id is unknown afterwards', static function () use ($main): void {
	$session = openSession($main);
	$deleted = $main->request('DELETE', '/mcp', ['Authorization' => 'Bearer ' . TOKEN_1, 'Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25']);
	check($deleted['status'] === 200, 'DELETE answered ' . $deleted['status'] . ': ' . $deleted['body']);

	$after = callTool($main, $session, 'ping');
	check($after['status'] === 404, 'after DELETE: ' . $after['status'] . ': ' . $after['body']);
});

test('sessions are files below mcp.session.dir', static function () use ($mainApp): void {
	$files = \glob($mainApp . '/var/state/mcp_sessions/*') ?: [];
	check($files !== [], 'no session files in var/state/mcp_sessions');
});


// ----------------------------------------------------------------
// Modern era (2026-07-28, stateless)
// ----------------------------------------------------------------

test('server/discover answers with caching hints from mcp.cache.ttl_ms', static function () use ($main): void {
	$response = modern($main, 'server/discover');
	check($response['status'] === 200, 'status ' . $response['status'] . ': ' . $response['body']);
	$result = $response['json']['result'] ?? [];
	check(\in_array('2026-07-28', $result['supportedVersions'] ?? [], true), 'supportedVersions: ' . $response['body']);
	check(($result['ttlMs'] ?? null) === 12_345 && ($result['cacheScope'] ?? null) === 'private', 'hints: ' . $response['body']);
	check(!isset($response['headers']['mcp-session-id']), 'a modern request got a session id');
});

test('tools/list answers with the tools and caching hints', static function () use ($main): void {
	$response = modern($main, 'tools/list');
	$names = \array_column($response['json']['result']['tools'] ?? [], 'name');
	\sort($names);
	check($names === ['crash', 'echo', 'fail', 'ping'], 'tools: ' . $response['body']);
	check(($response['json']['result']['ttlMs'] ?? null) === 12_345, 'ttlMs: ' . $response['body']);
});

test('tools/call ping answers pong without a session', static function () use ($main): void {
	$response = modern($main, 'tools/call', ['name' => 'ping', 'arguments' => new \stdClass()]);
	$text = $response['json']['result']['content'][0]['text'] ?? '';
	check(\str_starts_with($text, 'pong '), 'answer: ' . $response['body']);
});

test('a tool error and a crash are answered as in the handshake era', static function () use ($main): void {
	$fail = modern($main, 'tools/call', ['name' => 'fail', 'arguments' => new \stdClass()]);
	check(($fail['json']['result']['isError'] ?? null) === true, 'fail: ' . $fail['body']);

	$crash = modern($main, 'tools/call', ['name' => 'crash', 'arguments' => new \stdClass()]);
	check(isset($crash['json']['error']) && !\str_contains($crash['body'], '/srv/secret/path'), 'crash: ' . $crash['body']);
});


// ----------------------------------------------------------------
// Logging
// ----------------------------------------------------------------

test('every request gets one access line with client, method, tool, and status', static function () use ($main, $mainApp): void {
	callTool($main, openSession($main), 'echo', []);
	$lines = \array_values(\array_filter(logLines($mainApp), static fn(array $line): bool => ($line['category'] ?? '') === 'mcp.access'));
	check($lines !== [], 'no mcp.access lines');

	$unauthorized = null;
	$invalid = null;

	foreach ($lines as $line) {
		$context = $line['context'] ?? [];

		if (($context['status'] ?? null) === 401) {
			$unauthorized ??= $context;
		}

		if (($context['tool'] ?? null) === 'echo' && ($context['rpc_error'] ?? null) === -32602) {
			$invalid ??= $context;
		}
	}

	check($unauthorized !== null && !isset($unauthorized['client']), '401 line: ' . \json_encode($unauthorized));
	check($invalid !== null, 'no line for the invalid echo call');
	check(($invalid['client'] ?? null) === 'mcp.client_token.1', 'client: ' . \json_encode($invalid));
	check(($invalid['rpc'] ?? null) === 'tools/call' && ($invalid['status'] ?? null) === 200, 'line: ' . \json_encode($invalid));
	check(\is_int($invalid['ms'] ?? null) || \is_float($invalid['ms'] ?? null), 'ms: ' . \json_encode($invalid));
});

test('no log contains a token, even at sdk_level debug', static function () use ($mainApp): void {
	$all = allLogs($mainApp);
	check($all !== '', 'no logs written');
	check(!\str_contains($all, TOKEN_1) && !\str_contains($all, TOKEN_2), 'a token was logged');
});


// ----------------------------------------------------------------
// Configuration
// ----------------------------------------------------------------

test('secret_keys [] disables authentication', static function (): void {
	$server = serve(writeApp(['mcp' => ['auth' => ['secret_keys' => []]]], null));

	try {
		$response = rpc($server, initializeMessage(), ['Authorization' => '', 'Host' => 'localhost:' . $server->port]);
		check($response['status'] === 200, 'status ' . $response['status'] . ': ' . $response['body']);
	} finally {
		$server->stop();
	}
});

test('no configured secret in the store fails with 500, also without a token', static function (): void {
	$appDir = writeApp([], null);
	$server = serve($appDir);

	try {
		$response = rpc($server, initializeMessage(), ['Authorization' => '', 'Host' => 'localhost:' . $server->port]);
		check($response['status'] === 500, 'status ' . $response['status'] . ': ' . $response['body']);
		$logs = allLogs($appDir);
		check(\str_contains($logs, 'mcp.auth.secret_keys'), 'the McpConfigException is not in var/logs: ' . \substr($logs, 0, 1500));
	} finally {
		$server->stop();
	}
});

test('a configuration error does not log the token, even with exception arguments recorded', static function (): void {
	$appDir = writeApp([], null);
	$server = serve($appDir, ['zend.exception_ignore_args' => '0']);

	try {
		$response = rpc($server, initializeMessage(), ['Host' => 'localhost:' . $server->port]);
		check($response['status'] === 500, 'status ' . $response['status'] . ': ' . $response['body']);
		$logs = allLogs($appDir);
		check(\str_contains($logs, 'ClientAuth->evaluate'), 'no trace frame of ClientAuth::evaluate() in var/logs: ' . \substr($logs, 0, 1500));
		check(!\str_contains($logs, \substr(TOKEN_1, 0, 8)), 'the token reached var/logs');
	} finally {
		$server->stop();
	}
});

test('an empty secret fails with 500', static function (): void {
	$server = serve(writeApp([], ['mcp.client_token.1' => '']));

	try {
		$response = rpc($server, initializeMessage(), ['Host' => 'localhost:' . $server->port]);
		check($response['status'] === 500, 'status ' . $response['status'] . ': ' . $response['body']);
	} finally {
		$server->stop();
	}
});

test('a tool can be removed with null, and an invalid tool class fails with 500', static function (): void {
	$secrets = ['mcp.client_token.1' => TOKEN_1];
	$removed = serve(writeApp(['mcp' => ['tools' => ['ping' => null, 'echo' => EchoTool::class]]], $secrets));
	$invalid = serve(writeApp(['mcp' => ['tools' => ['bad' => \stdClass::class]]], $secrets));

	try {
		$session = openSessionOn($removed);
		$list = rpc($removed, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25', 'Host' => 'localhost:' . $removed->port]);
		check(\array_column($list['json']['result']['tools'] ?? [], 'name') === ['echo'], 'tools: ' . $list['body']);

		$response = rpc($invalid, initializeMessage(), ['Host' => 'localhost:' . $invalid->port]);
		check($response['status'] === 500, 'status ' . $response['status'] . ': ' . $response['body']);
	} finally {
		$removed->stop();
		$invalid->stop();
	}
});



// ----------------------------------------------------------------
// PHP diagnostics in the apps
// ----------------------------------------------------------------

// The package runs inside the servers, under CitOmni's ErrorHandler, which logs
// warnings and deprecations instead of failing. A diagnostic there fails here.
test('no app logged a PHP diagnostic', static function () use ($mcpTestApps): void {
	foreach ($mcpTestApps as $appDir) {
		foreach (['http_err_phperror.jsonl', 'http_err_shutdown.jsonl'] as $file) {
			$path = $appDir . '/var/logs/' . $file;
			check(!\is_file($path), $file . ': ' . \substr((string)@\file_get_contents($path), 0, 1500));
		}
	}
});

$main->stop();
done();
