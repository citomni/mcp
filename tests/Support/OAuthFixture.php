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

use CitOmni\Mcp\Tests\Support\ClientTool;
use CitOmni\Mcp\Tests\Support\HttpServer;
use Defuse\Crypto\Key;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

if (PHP_SAPI !== 'cli') {
	throw new RuntimeException('CLI only.');
}
set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
	if ((error_reporting() & $errno) === 0) {
		return false;
	}
	throw new ErrorException($message, 0, $errno, $file, $line);
});
require dirname(__DIR__) . '/bootstrap.php';

const ISSUER = 'https://oauth.example.test';
const CALLBACK = 'https://chatgpt.com/connector_platform_oauth_redirect';
const CLIENT = 'citomni-chatgpt';
const FIXED = 'fixture-fixed-token-not-a-live-credential';
const PASSWORD = 'fixture-owner-password-not-a-live-credential';
const CLIENT_SECRET = 'fixture-client-secret-not-a-live-credential';
const VERIFIER = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

/** Build a real HTTP app. HTTPS server variables emulate trusted TLS termination. */
function oauthApp(array $overlay = []): array {
	static $secrets;
	if ($secrets === null) {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		check($key !== false && openssl_pkey_export($key, $private), 'Generate test signing key');
		$secrets = [
			'mcp.client_token.1' => FIXED,
			'mcp.oauth.owner_password_hash' => password_hash(PASSWORD, PASSWORD_DEFAULT),
			'mcp.oauth.client_secret' => CLIENT_SECRET,
			'mcp.oauth.private_key' => $private,
			'mcp.oauth.public_key' => openssl_pkey_get_details($key)['key'],
			'mcp.oauth.encryption_key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
		];
	}
	$dir = tempDir();
	foreach (['config', 'public', 'var/secrets', 'var/logs'] as $sub) {
		mkdir($dir . '/' . $sub, 0700, true);
	}
	$cfg = array_replace_recursive([
		'http' => ['base_url' => ISSUER],
		'mcp' => [
			'oauth' => ['enabled' => true, 'issuer' => ISSUER, 'client_id' => CLIENT, 'owner_id' => 'fixture-owner', 'scope' => 'repo.read', 'redirect_uris' => [CALLBACK]],
			'tools' => ['client' => ClientTool::class],
		],
	], $overlay);
	// Empty lists must replace, rather than merge with, the defaults.
	if (isset($overlay['mcp']['auth']['secret_keys'])) {
		$cfg['mcp']['auth']['secret_keys'] = $overlay['mcp']['auth']['secret_keys'];
	}
	file_put_contents($dir . '/config/citomni_http_cfg.php', '<?php return ' . var_export($cfg, true) . ';');
	file_put_contents($dir . '/config/providers.php', '<?php return [\\CitOmni\\Infrastructure\\Boot\\Registry::class, \\CitOmni\\Mcp\\Boot\\Registry::class];');
	file_put_contents($dir . '/var/secrets/app.secret.dev.php', '<?php return ' . var_export($secrets, true) . ';');
	file_put_contents($dir . '/public/index.php', '<?php '
		. '$_SERVER["HTTPS"] = isset($_SERVER["HTTP_X_TEST_INSECURE"]) ? "off" : "on"; $_SERVER["SERVER_PORT"] = isset($_SERVER["HTTP_X_TEST_INSECURE"]) ? 80 : 443; '
		. 'define("CITOMNI_START_NS", hrtime(true)); define("CITOMNI_ENVIRONMENT", "dev"); '
		. 'define("CITOMNI_PUBLIC_PATH", __DIR__); define("CITOMNI_APP_PATH", dirname(__DIR__)); '
		. 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; '
		. '\\CitOmni\\Http\\Kernel::run(__DIR__);');
	$server = HttpServer::start($dir) ?? fail(HttpServer::$lastFailure);
	$GLOBALS['oauthApps'][] = $dir;
	return [$server, $dir, $secrets, $cfg];
}

function req(HttpServer $server, string $method, string $path, array $headers = [], string $body = ''): array {
	$r = $server->request($method, $path, $headers + ['Host' => 'oauth.example.test'], $body);
	$r['json'] = json_decode($r['body'], true);
	return $r;
}

function params(array $replace = []): array {
	return array_replace([
		'response_type' => 'code', 'client_id' => CLIENT, 'redirect_uri' => CALLBACK,
		'resource' => ISSUER . '/mcp', 'scope' => 'repo.read', 'state' => 'fixture-state',
		'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', VERIFIER, true)), '+/', '-_'), '='),
		'code_challenge_method' => 'S256',
	], $replace);
}

function begin(HttpServer $server, array $replace = []): array {
	$r = req($server, 'GET', '/mcp/oauth/authorize?' . http_build_query(params($replace), '', '&', PHP_QUERY_RFC3986));
	check($r['status'] === 200, 'Consent GET status ' . $r['status'] . ': ' . $r['body']);
	check(preg_match('/name="request_id" value="([a-f0-9]+)"/', $r['body'], $id) === 1, 'Request ID');
	check(preg_match('/name="csrf" value="([a-f0-9]+)"/', $r['body'], $csrf) === 1, 'CSRF');
	$r['form'] = ['request_id' => $id[1], 'csrf' => $csrf[1], 'password' => PASSWORD, 'decision' => 'approve'];
	$r['cookie'] = explode(';', $r['headers']['set-cookie'], 2)[0];
	return $r;
}

function approve(HttpServer $server, array $pending, array $replace = [], array $headers = []): array {
	return req($server, 'POST', '/mcp/oauth/authorize', $headers + [
		'Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $pending['cookie'], 'Origin' => ISSUER,
	], http_build_query(array_replace($pending['form'], $replace), '', '&', PHP_QUERY_RFC3986));
}

function code(HttpServer $server): string {
	$r = approve($server, begin($server));
	check($r['status'] === 302, 'Consent POST status ' . $r['status'] . ': ' . $r['body']);
	parse_str(parse_url($r['headers']['location'], PHP_URL_QUERY), $query);
	check(($query['iss'] ?? null) === ISSUER && ($query['state'] ?? null) === 'fixture-state', 'Issuer and state preserved');
	return $query['code'];
}

function exchange(HttpServer $server, string $code, array $replace = [], array $headers = []): array {
	$fields = array_replace(['grant_type' => 'authorization_code', 'client_id' => CLIENT, 'client_secret' => CLIENT_SECRET,
		'code' => $code, 'code_verifier' => VERIFIER, 'redirect_uri' => CALLBACK, 'resource' => ISSUER . '/mcp'], $replace);
	$fields = array_filter($fields, static fn($v) => $v !== null);
	return req($server, 'POST', '/mcp/oauth/token', $headers + ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields));
}

function tokens(HttpServer $server): array {
	$r = exchange($server, code($server));
	check($r['status'] === 200 && isset($r['json']['access_token'], $r['json']['refresh_token']), 'Token response ' . $r['status'] . ': ' . $r['body']);
	return $r['json'];
}

function refresh(HttpServer $server, string $token, array $replace = []): array {
	return req($server, 'POST', '/mcp/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(array_replace([
		'grant_type' => 'refresh_token', 'client_id' => CLIENT, 'client_secret' => CLIENT_SECRET,
		'refresh_token' => $token, 'resource' => ISSUER . '/mcp',
	], $replace)));
}

function mcp(HttpServer $server, ?string $token = FIXED, array $headers = []): array {
	return req($server, 'POST', '/mcp', $headers + [
		'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
		'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'client',
	] + ($token === null ? [] : ['Authorization' => 'Bearer ' . $token]), json_encode([
		'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'client', 'arguments' => new stdClass(),
			'_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass()]],
	]));
}

