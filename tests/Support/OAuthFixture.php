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

use CitOmni\Mcp\Tests\Support\ClientTool;
use CitOmni\Mcp\Tests\Support\HttpServer;
use Defuse\Crypto\Key;

/*
 * Fixture for suites that test the OAuth authorization server: a real CitOmni HTTP app with
 * OAuth enabled, and helpers that walk its flows with raw HTTP requests.
 *
 * A suite requires this file after tests/bootstrap.php, which provides check(), fail() and
 * tempDir().
 *
 * Notes:
 * - The app runs behind simulated TLS termination: public/index.php reports HTTPS on port 443
 *   unless a request carries an X-Test-Insecure header.
 * - Every credential is a fixture value, fixed or generated here, never a live one.
 */


// ----------------------------------------------------------------
// Fixture values
// ----------------------------------------------------------------

/** Issuer of the fixture app; the helpers send its host. */
const ISSUER = 'https://oauth.example.test';

/** The one registered callback URL of the client. */
const CALLBACK = 'https://chatgpt.com/connector_platform_oauth_redirect';

/** Client ID of the pre-registered client. */
const CLIENT = 'citomni-chatgpt';

/** Fixed bearer token, stored as secret mcp.client_token.1. */
const FIXED = 'fixture-fixed-token-not-a-live-credential';

/** Owner approval password; the app stores only its hash. */
const PASSWORD = 'fixture-owner-password-not-a-live-credential';

/** Client secret of the pre-registered client. */
const CLIENT_SECRET = 'fixture-client-secret-not-a-live-credential';

/** PKCE code verifier of every authorization the helpers begin. */
const VERIFIER = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';


// ----------------------------------------------------------------
// App
// ----------------------------------------------------------------

/**
 * Write and serve a CitOmni HTTP app with the OAuth authorization server enabled.
 *
 * Behavior:
 * - Enables mcp.oauth for ISSUER, CLIENT, owner fixture-owner, scope repo.read and CALLBACK,
 *   and adds the tool client (ClientTool).
 * - Stores the fixture secrets, with an RSA key pair and an encryption key generated once per
 *   process.
 * - Applies $overlay last with array_replace_recursive(). mcp.auth.secret_keys in $overlay
 *   replaces the default list instead of merging with it.
 * - Adds the app root to $GLOBALS['oauthApps'], so that a suite can check every app's logs.
 * - Stops the suite through fail() when the server cannot be started.
 *
 * @param array<string, mixed> $overlay Cfg overlay.
 * @return array{0: HttpServer, 1: string, 2: array<string, string>, 3: array<string, mixed>} Server, app root, secrets and the cfg written.
 * @throws \CheckFailed When the RSA key pair cannot be generated.
 */
function oauthApp(array $overlay = []): array {
	static $secrets;

	if ($secrets === null) {
		$key = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA] + opensslOptions());
		$generated = $key !== false && \openssl_pkey_export($key, $private, null, opensslOptions());
		check($generated, 'Generate test signing key: ' . opensslErrors());
		$secrets = [
			'mcp.client_token.1'            => FIXED,
			'mcp.oauth.owner_password_hash' => \password_hash(PASSWORD, \PASSWORD_DEFAULT),
			'mcp.oauth.client_secret'       => CLIENT_SECRET,
			'mcp.oauth.private_key'         => $private,
			'mcp.oauth.public_key'          => \openssl_pkey_get_details($key)['key'],
			'mcp.oauth.encryption_key'      => Key::createNewRandomKey()->saveToAsciiSafeString(),
		];
	}

	$dir = tempDir();

	foreach (['config', 'public', 'var/secrets', 'var/logs'] as $sub) {
		\mkdir($dir . '/' . $sub, 0700, true);
	}

	$cfg = \array_replace_recursive([
		'http' => ['base_url' => ISSUER],
		'mcp'  => [
			'oauth' => ['enabled' => true, 'issuer' => ISSUER, 'client_id' => CLIENT, 'owner_id' => 'fixture-owner', 'scope' => 'repo.read', 'redirect_uris' => [CALLBACK]],
			'tools' => ['client' => ClientTool::class],
		],
	], $overlay);

	// Empty lists must replace, rather than merge with, the defaults.
	if (isset($overlay['mcp']['auth']['secret_keys'])) {
		$cfg['mcp']['auth']['secret_keys'] = $overlay['mcp']['auth']['secret_keys'];
	}

	writeOAuthCfg($dir, $cfg);
	\file_put_contents($dir . '/config/providers.php', '<?php return [\\CitOmni\\Infrastructure\\Boot\\Registry::class, \\CitOmni\\Mcp\\Boot\\Registry::class];');
	\file_put_contents($dir . '/var/secrets/app.secret.dev.php', '<?php return ' . \var_export($secrets, true) . ';');
	\file_put_contents($dir . '/public/index.php', '<?php '
		. '$_SERVER["HTTPS"] = isset($_SERVER["HTTP_X_TEST_INSECURE"]) ? "off" : "on"; $_SERVER["SERVER_PORT"] = isset($_SERVER["HTTP_X_TEST_INSECURE"]) ? 80 : 443; '
		. 'define("CITOMNI_START_NS", hrtime(true)); define("CITOMNI_ENVIRONMENT", "dev"); '
		. 'define("CITOMNI_PUBLIC_PATH", __DIR__); define("CITOMNI_APP_PATH", dirname(__DIR__)); '
		. 'require ' . \var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; '
		. '\\CitOmni\\Http\\Kernel::run(__DIR__);');
	$server = HttpServer::start($dir) ?? fail('Cannot start PHP\'s built-in web server: ' . HttpServer::$lastFailure);
	$GLOBALS['oauthApps'][] = $dir;

	return [$server, $dir, $secrets, $cfg];
}


/**
 * Write an app's citomni_http_cfg.php.
 *
 * Notes:
 * - Rewrite it only before the app's first request. OPcache in the server may serve an earlier
 *   version for a while.
 *
 * @param string $appDir App root.
 * @param array<string, mixed> $cfg App cfg.
 * @return void
 */
function writeOAuthCfg(string $appDir, array $cfg): void {
	\file_put_contents($appDir . '/config/citomni_http_cfg.php', '<?php return ' . \var_export($cfg, true) . ';');
}


/**
 * Return the options that OpenSSL needs to generate keys and certificates here.
 *
 * @return array<string, string> On Windows, config with the openssl.cnf in the extras\ssl directory of the running PHP, when that file exists; otherwise none.
 */
function opensslOptions(): array {
	// PHP for Windows ships the openssl.cnf that key generation needs, but OpenSSL does not look
	// for it there. Elsewhere OpenSSL finds its own.
	$file = \dirname(\PHP_BINARY) . '/extras/ssl/openssl.cnf';

	return \PHP_OS_FAMILY === 'Windows' && \is_file($file) ? ['config' => $file] : [];
}


/**
 * Collect and clear OpenSSL's error queue.
 *
 * @return string The errors joined with "; ", and a hint for Windows.
 */
function opensslErrors(): string {
	$errors = [];

	while (($error = \openssl_error_string()) !== false) {
		$errors[] = $error;
	}

	return \implode('; ', $errors) . ' (on Windows without extras\\ssl\\openssl.cnf next to php.exe, set OPENSSL_CONF to an openssl.cnf)';
}


// ----------------------------------------------------------------
// Requests
// ----------------------------------------------------------------

/**
 * Send one request with the issuer's host and decode a JSON body.
 *
 * @param HttpServer $server Server.
 * @param string $method HTTP method.
 * @param string $path Path and query.
 * @param array<string, string> $headers Request headers; Host defaults to the host of ISSUER.
 * @param string $body Request body.
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed} The response; json is null when the body is not JSON.
 */
function req(HttpServer $server, string $method, string $path, array $headers = [], string $body = ''): array {
	$response = $server->request($method, $path, $headers + ['Host' => (string)\parse_url(ISSUER, \PHP_URL_HOST)], $body);
	$response['json'] = \json_decode($response['body'], true);

	return $response;
}


/**
 * Build the parameters of an authorization request.
 *
 * @param array<string, string> $replace Parameters to replace.
 * @return array<string, string> Code flow for CLIENT and CALLBACK with resource ISSUER/mcp, scope repo.read, state fixture-state and the S256 challenge of VERIFIER.
 */
function params(array $replace = []): array {
	return \array_replace([
		'response_type'         => 'code',
		'client_id'             => CLIENT,
		'redirect_uri'          => CALLBACK,
		'resource'              => ISSUER . '/mcp',
		'scope'                 => 'repo.read',
		'state'                 => 'fixture-state',
		'code_challenge'        => \rtrim(\strtr(\base64_encode(\hash('sha256', VERIFIER, true)), '+/', '-_'), '='),
		'code_challenge_method' => 'S256',
	], $replace);
}


/**
 * Open the approval page and collect what a decision needs.
 *
 * @param HttpServer $server Server.
 * @param array<string, string> $replace Authorization parameters to replace.
 * @return array<string, mixed> The response of req(), plus form (request_id, csrf, the owner password and decision approve) and cookie (the approval cookie as name=value).
 * @throws \CheckFailed When the response is not a 200 approval page.
 */
function begin(HttpServer $server, array $replace = []): array {
	$response = req($server, 'GET', '/mcp/oauth/authorize?' . \http_build_query(params($replace), '', '&', \PHP_QUERY_RFC3986));
	check($response['status'] === 200, 'Consent GET status ' . $response['status'] . ': ' . $response['body']);
	check(\preg_match('/name="request_id" value="([a-f0-9]+)"/', $response['body'], $id) === 1, 'Request ID');
	check(\preg_match('/name="csrf" value="([a-f0-9]+)"/', $response['body'], $csrf) === 1, 'CSRF');
	$response['form'] = ['request_id' => $id[1], 'csrf' => $csrf[1], 'password' => PASSWORD, 'decision' => 'approve'];
	$response['cookie'] = \explode(';', $response['headers']['set-cookie'], 2)[0];

	return $response;
}


/**
 * Post a decision from the approval page.
 *
 * @param HttpServer $server Server.
 * @param array<string, mixed> $pending Result of begin().
 * @param array<string, string> $replace Form fields to replace.
 * @param array<string, string> $headers Headers to add or replace; Origin defaults to ISSUER.
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function approve(HttpServer $server, array $pending, array $replace = [], array $headers = []): array {
	return req($server, 'POST', '/mcp/oauth/authorize', $headers + [
		'Content-Type' => 'application/x-www-form-urlencoded',
		'Cookie'       => $pending['cookie'],
		'Origin'       => ISSUER,
	], \http_build_query(\array_replace($pending['form'], $replace), '', '&', \PHP_QUERY_RFC3986));
}


/**
 * Approve an authorization and return its code.
 *
 * @param HttpServer $server Server.
 * @return string Authorization code.
 * @throws \CheckFailed When the approval does not redirect with issuer ISSUER and state fixture-state.
 */
function code(HttpServer $server): string {
	$response = approve($server, begin($server));
	check($response['status'] === 302, 'Consent POST status ' . $response['status'] . ': ' . $response['body']);
	\parse_str((string)\parse_url($response['headers']['location'], \PHP_URL_QUERY), $query);
	check(($query['iss'] ?? null) === ISSUER && ($query['state'] ?? null) === 'fixture-state', 'Issuer and state preserved');

	return $query['code'];
}


/**
 * Exchange an authorization code at the token endpoint.
 *
 * @param HttpServer $server Server.
 * @param string $code Authorization code.
 * @param array<string, string|null> $replace Fields to replace; null leaves a field out.
 * @param array<string, string> $headers Headers to add.
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function exchange(HttpServer $server, string $code, array $replace = [], array $headers = []): array {
	$fields = \array_replace([
		'grant_type'    => 'authorization_code',
		'client_id'     => CLIENT,
		'client_secret' => CLIENT_SECRET,
		'code'          => $code,
		'code_verifier' => VERIFIER,
		'redirect_uri'  => CALLBACK,
		'resource'      => ISSUER . '/mcp',
	], $replace);
	$fields = \array_filter($fields, static fn(?string $value): bool => $value !== null);

	return req($server, 'POST', '/mcp/oauth/token', $headers + ['Content-Type' => 'application/x-www-form-urlencoded'], \http_build_query($fields));
}


/**
 * Run the code flow and return the token response.
 *
 * @param HttpServer $server Server.
 * @return array<string, mixed> Token response with access_token and refresh_token.
 * @throws \CheckFailed When the exchange does not return both tokens.
 */
function tokens(HttpServer $server): array {
	$response = exchange($server, code($server));
	check($response['status'] === 200 && isset($response['json']['access_token'], $response['json']['refresh_token']), 'Token response ' . $response['status'] . ': ' . $response['body']);

	return $response['json'];
}


/**
 * Redeem a refresh token at the token endpoint.
 *
 * @param HttpServer $server Server.
 * @param string $token Refresh token.
 * @param array<string, string|null> $replace Fields to replace; null leaves a field out.
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function refresh(HttpServer $server, string $token, array $replace = []): array {
	return req($server, 'POST', '/mcp/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], \http_build_query(\array_replace([
		'grant_type'    => 'refresh_token',
		'client_id'     => CLIENT,
		'client_secret' => CLIENT_SECRET,
		'refresh_token' => $token,
		'resource'      => ISSUER . '/mcp',
	], $replace)));
}


/**
 * Call the tool client at /mcp as a 2026-07-28 client.
 *
 * @param HttpServer $server Server.
 * @param string|null $token Bearer token; null sends no Authorization header.
 * @param array<string, string> $headers Headers to add or replace.
 * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
 */
function mcp(HttpServer $server, ?string $token = FIXED, array $headers = []): array {
	$headers += [
		'Content-Type'         => 'application/json',
		'Accept'               => 'application/json, text/event-stream',
		'MCP-Protocol-Version' => '2026-07-28',
		'Mcp-Method'           => 'tools/call',
		'Mcp-Name'             => 'client',
	];

	if ($token !== null) {
		$headers += ['Authorization' => 'Bearer ' . $token];
	}

	return req($server, 'POST', '/mcp', $headers, (string)\json_encode([
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'tools/call',
		'params'  => [
			'name'      => 'client',
			'arguments' => new \stdClass(),
			'_meta'     => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new \stdClass()],
		],
	]));
}
