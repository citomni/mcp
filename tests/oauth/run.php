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

use CitOmni\Mcp\Tests\Support\HttpServer;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/*
 * End-to-end checks of the optional OAuth authorization server and of OAuth access
 * tokens on /mcp: real CitOmni HTTP apps with league/oauth2-server, served by PHP's
 * built-in web server behind simulated TLS termination, driven with raw HTTP requests.
 *
 * Usage:
 *   php tests/oauth/run.php
 *
 * Notes:
 * - The two redemption races start worker processes and run only with
 *   CITOMNI_TEST_PARALLEL=1; otherwise they report SKIP.
 * - A server that cannot be started stops the suite; it is not a reason to skip.
 * - See README.md next to this file for what the cases cover.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic, including deprecations. Leave diagnostics silenced with @
// (socket probes, process control) to PHP.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}

	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';
require \dirname(__DIR__) . '/Support/OAuthFixture.php';

test('OAuth disabled returns 404 on every new endpoint and preserves fixed tokens', function (): void {
	[$server] = oauthApp(['mcp' => ['oauth' => ['enabled' => false]]]);
	foreach (['/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp', '/mcp/oauth/authorize'] as $path) {
		check(req($server, 'GET', $path)['status'] === 404, $path);
	}
	check(req($server, 'POST', '/mcp/oauth/token')['status'] === 404, 'Token endpoint disabled');
	check(mcp($server)['status'] === 200, 'Fixed token');
});

test('Discovery advertises exact resource, PKCE, fixed client authentication and issuer identification', function (): void {
	[$server] = oauthApp();
	$response = req($server, 'GET', '/.well-known/oauth-protected-resource/mcp');
	check($response['status'] === 200 && $response['json']['resource'] === ISSUER . '/mcp', 'Resource');
	check($response['json']['authorization_servers'] === [ISSUER], 'Issuer');
	$metadata = req($server, 'GET', '/.well-known/oauth-authorization-server')['json'];
	check($metadata['code_challenge_methods_supported'] === ['S256'] && $metadata['authorization_response_iss_parameter_supported'] === true, 'PKCE and issuer');
	check(!isset($metadata['registration_endpoint']) && $metadata['grant_types_supported'] === ['authorization_code', 'refresh_token'], 'No unimplemented grants or dynamic registration');
	$unauthorized = mcp($server, null);
	check($unauthorized['status'] === 401 && str_contains($unauthorized['headers']['www-authenticate'], 'resource_metadata="' . ISSUER), 'MCP discovery challenge');
});

test('OAuth endpoints reject cleartext and foreign hosts', function (): void {
	[$server] = oauthApp();
	check(req($server, 'GET', '/.well-known/oauth-authorization-server', ['X-Test-Insecure' => '1'])['status'] === 403, 'HTTP rejected');
	check(req($server, 'GET', '/.well-known/oauth-authorization-server', ['Host' => 'attacker.test'])['status'] === 403, 'Host rejected');
});

test('Fixed token fast path works even when optional OAuth configuration is broken', function (): void {
	[$server] = oauthApp(['mcp' => ['oauth' => ['issuer' => 'invalid']]]);
	$response = mcp($server);
	check($response['status'] === 200 && $response['json']['result']['content'][0]['text'] === 'mcp.client_token.1', 'Original fixed identity');
});

test('A string enabled value is a configuration error and never enables OAuth', function (): void {
	[$server, $dir] = oauthApp(['mcp' => ['oauth' => ['enabled' => 'false']]]);
	check(req($server, 'GET', '/mcp/oauth/authorize')['status'] === 500, 'Authorization refused');
	$log = $dir . '/var/logs/http_err_exception.jsonl';
	check(is_file($log) && str_contains((string)file_get_contents($log), 'mcp.oauth.enabled must be true or false.'), 'The error names the setting');
	// Only the expected exception is removed; any other diagnostic stays for the last case.
	unlink($log);
	check(mcp($server)['status'] === 200, 'Fixed token unaffected');
});

test('A non-boolean enabled value that reads as false is a configuration error, not disabled OAuth', function (): void {
	[$server, $dir] = oauthApp(['mcp' => ['auth' => ['secret_keys' => []], 'oauth' => ['enabled' => 0]]]);
	check(mcp($server, null)['status'] === 500, 'No anonymous access');
	check(req($server, 'GET', '/.well-known/oauth-authorization-server')['status'] === 500, 'OAuth route refused');
	$log = $dir . '/var/logs/http_err_exception.jsonl';
	$lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
	check(count($lines) === 2, 'Two logged errors, got ' . count($lines));
	foreach ($lines as $line) {
		check(str_contains($line, 'mcp.oauth.enabled must be true or false.'), 'The error names the setting');
	}
	// Only the expected exceptions are removed; any other diagnostic stays for the last case.
	unlink($log);
});

test('A public key that cannot be used is a configuration error, not a rejected token', function (): void {
	// The client secret is no PEM key, while the private key still signs valid access tokens.
	[$server, $dir] = oauthApp(['mcp' => ['oauth' => ['public_key_secret_key' => 'mcp.oauth.client_secret']]]);
	$accessToken = tokens($server)['access_token'];
	check(mcp($server, 'a.b.c')['status'] === 401, 'A malformed token is still rejected');
	check(mcp($server, $accessToken)['status'] === 500, 'A well-formed token gets 500');
	$log = $dir . '/var/logs/http_err_exception.jsonl';
	$logged = is_file($log) ? (string)file_get_contents($log) : '';
	check(str_contains($logged, 'MCP OAuth secret for public_key is not a usable RSA public key'), 'The error names the secret');
	check(!str_contains($logged, explode('.', $accessToken)[1]), 'The error holds no token');
	// Only the expected exception is removed; any other diagnostic stays for the last case.
	unlink($log);
	check(mcp($server)['status'] === 200, 'Fixed token unaffected');
});

test('OAuth bearer tokens cannot be used over cleartext or a different host', function (): void {
	[$server] = oauthApp();
	$tokens = tokens($server);
	check(mcp($server, $tokens['access_token'], ['X-Test-Insecure' => '1'])['status'] === 403, 'TLS required on MCP');
	check(mcp($server, $tokens['access_token'], ['Host' => 'attacker.test'])['status'] === 403, 'Canonical host required on MCP');
});

test('Expired code and refresh records are rejected even with intact encrypted tokens', function (): void {
	[$server, $dir] = oauthApp();
	$code = code($server);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	foreach ($ledger['records'] as $id => &$record) {
		if (str_starts_with($id, 'code:')) {
			$record['expires'] = time() - 1;
		}
	}
	unset($record);
	file_put_contents($file, json_encode($ledger, JSON_THROW_ON_ERROR));
	check(exchange($server, $code)['status'] === 400, 'Expired code');
	$tokens = tokens($server);
	$ledger = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	foreach ($ledger['records'] as $id => &$record) {
		if (str_starts_with($id, 'refresh:')) {
			$record['expires'] = time() - 1;
		}
	}
	unset($record);
	file_put_contents($file, json_encode($ledger, JSON_THROW_ON_ERROR));
	check(refresh($server, $tokens['refresh_token'])['status'] === 400, 'Expired refresh');
});

test('Grant records from a different authorization context fail closed', function (): void {
	[$server, $dir] = oauthApp();
	$tokens = tokens($server);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	foreach ($ledger['records'] as &$record) {
		if (isset($record['context'])) {
			$record['context'] = hash('sha256', 'old-resource');
		}
	}
	unset($record);
	file_put_contents($file, json_encode($ledger, JSON_THROW_ON_ERROR));
	check(mcp($server, $tokens['access_token'])['status'] === 401, 'Old resource access');
	check(refresh($server, $tokens['refresh_token'])['status'] === 400, 'Old resource refresh');
});

test('Empty fixed-token list with OAuth enabled does not create anonymous access', function (): void {
	[$server] = oauthApp(['mcp' => ['auth' => ['secret_keys' => []]]]);
	check(mcp($server, null)['status'] === 401 && mcp($server)['status'] === 401, 'Anonymous and fixed token rejected');
	$tokens = tokens($server);
	check(mcp($server, $tokens['access_token'])['status'] === 200, 'OAuth-only connection');
});

foreach ([
	'foreign callback' => ['redirect_uri' => 'https://attacker.test/callback'],
	'callback prefix attack' => ['redirect_uri' => CALLBACK . '/extra'],
	'callback with query' => ['redirect_uri' => CALLBACK . '?next=evil'],
	'foreign resource' => ['resource' => 'https://attacker.test/mcp'],
	'unknown client' => ['client_id' => 'other'],
	'plain PKCE' => ['code_challenge_method' => 'plain'],
	'missing PKCE' => ['code_challenge' => ''],
	'invalid scope' => ['scope' => 'repo.write'],
	'implicit flow' => ['response_type' => 'token'],
] as $name => $replace) {
	test('Authorization rejects ' . $name, function () use ($replace): void {
		[$server] = oauthApp();
		$response = req($server, 'GET', '/mcp/oauth/authorize?' . http_build_query(params($replace)));
		check($response['status'] !== 200 && !isset($response['headers']['set-cookie']), 'Rejected before password form');
		if (isset($response['headers']['location'])) {
			check(str_starts_with($response['headers']['location'], CALLBACK . '?') && str_contains($response['headers']['location'], 'iss='), 'Only registered callback with issuer');
		}
	});
}

test('Duplicate and array parameters are rejected without PHP coercion', function (): void {
	[$server] = oauthApp();
	$query = http_build_query(params());
	check(req($server, 'GET', '/mcp/oauth/authorize?' . $query . '&client_id=other')['status'] === 400, 'Duplicate client');
	check(req($server, 'GET', '/mcp/oauth/authorize?' . $query . '&foo[]=bar')['status'] === 400, 'Array parameter');
});

test('Consent cookie and page have browser security headers; HEAD creates no state', function (): void {
	[$server, $dir] = oauthApp();
	$response = req($server, 'HEAD', '/mcp/oauth/authorize?' . http_build_query(params()));
	check($response['status'] === 200 && !isset($response['headers']['set-cookie']) && !is_file($dir . '/var/state/mcp_oauth/ledger.json'), 'HEAD side effects');
	$pending = begin($server);
	foreach (['Secure', 'HttpOnly', 'SameSite=Lax', 'Path=/'] as $flag) {
		check(str_contains($pending['headers']['set-cookie'], $flag), $flag);
	}
	check($pending['headers']['cache-control'] === 'no-store' && $pending['headers']['referrer-policy'] === 'same-origin', 'No cache/referrer');
	check(str_contains($pending['headers']['content-security-policy'], "frame-ancestors 'none'"), 'No framing');
});

test('Consent requires password, CSRF, browser binding and same-origin POST', function (): void {
	[$server] = oauthApp();
	$pending = begin($server);
	check(approve($server, $pending, ['password' => 'wrong'])['status'] === 403, 'Wrong password');
	check(approve($server, $pending, ['csrf' => 'wrong'])['status'] === 400, 'Wrong CSRF');
	check(approve($server, $pending, [], ['Cookie' => ''])['status'] === 400, 'Missing browser');
	check(approve($server, $pending, [], ['Cookie' => '__Host-citomni_mcp_oauth=' . str_repeat('0', 64)])['status'] === 400, 'Other browser');
	check(approve($server, $pending, [], ['Origin' => 'https://attacker.test'])['status'] === 403, 'Foreign origin');
	check(approve($server, $pending, [], ['Sec-Fetch-Site' => 'cross-site'])['status'] === 403, 'Cross-site metadata');
	check(approve($server, $pending)['status'] === 302, 'Valid consent remains usable');
	check(approve($server, $pending)['status'] === 400, 'Consent cannot be replayed');
});

test('Denial preserves state and issuer without issuing a code or requiring a password', function (): void {
	[$server] = oauthApp();
	$response = approve($server, begin($server), ['decision' => 'deny', 'password' => '']);
	check($response['status'] === 302, 'Denial redirect');
	parse_str(parse_url($response['headers']['location'], PHP_URL_QUERY), $query);
	check(($query['error'] ?? null) === 'access_denied' && !isset($query['code']) && $query['iss'] === ISSUER && $query['state'] === 'fixture-state', 'Denied callback');
});

test('Owner password attempts are limited', function (): void {
	[$server] = oauthApp();
	$pending = begin($server);
	for ($i = 0; $i < 10; ++$i) {
		check(approve($server, $pending, ['password' => 'wrong'])['status'] === 403, 'Failed password ' . $i);
	}
	check(approve($server, $pending)['status'] === 429, 'Rate limit');
});

test('Expired approval is refused', function (): void {
	[$server, $dir] = oauthApp();
	$pending = begin($server);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true);
	$ledger['records']['approval:' . $pending['form']['request_id']]['expires'] = time() - 1;
	file_put_contents($file, json_encode($ledger));
	check(approve($server, $pending)['status'] === 400, 'Expired approval');
});

test('Authorization code grants access with an unchanged fixed-token path', function (): void {
	[$server] = oauthApp();
	$tokens = tokens($server);
	check($tokens['token_type'] === 'Bearer' && $tokens['expires_in'] <= 900 && $tokens['expires_in'] > 0, 'Token response');
	$response = mcp($server, $tokens['access_token']);
	check($response['status'] === 200 && $response['json']['result']['content'][0]['text'] === 'oauth:fixture-owner:' . CLIENT, 'Stable OAuth identity');
	check(mcp($server)['json']['result']['content'][0]['text'] === 'mcp.client_token.1', 'Claude identity unchanged');
});

foreach ([
	'wrong verifier' => ['code_verifier' => str_repeat('x', 64)],
	'missing verifier' => ['code_verifier' => null],
	'wrong secret' => ['client_secret' => 'wrong'],
	'wrong client' => ['client_id' => 'other'],
	'wrong callback' => ['redirect_uri' => 'https://attacker.test/callback'],
	'wrong resource' => ['resource' => 'https://attacker.test/mcp'],
	'missing resource' => ['resource' => null],
] as $name => $replace) {
	test('Code exchange rejects ' . $name, function () use ($replace): void {
		[$server] = oauthApp();
		$code = code($server);
		$response = exchange($server, $code, $replace);
		check($response['status'] >= 400 && !isset($response['json']['access_token']), 'Rejected grant');
		check(exchange($server, $code)['status'] === 200, 'Failed validation did not consume the valid code');
	});
}

test('HTTP Basic client authentication works and mixed methods are refused', function (): void {
	[$server] = oauthApp();
	$code = code($server);
	$headers = ['Authorization' => 'Basic ' . base64_encode(CLIENT . ':' . CLIENT_SECRET)];
	check(exchange($server, $code, [], $headers)['status'] === 400, 'Mixed authentication');
	check(exchange($server, $code, ['client_secret' => null], $headers)['status'] === 200, 'Basic authentication');
});

test('Authorization-code replay revokes tokens issued from that code', function (): void {
	[$server] = oauthApp();
	$code = code($server);
	$tokens = exchange($server, $code)['json'];
	check(exchange($server, $code)['status'] === 400, 'Replay rejected');
	check(mcp($server, $tokens['access_token'])['status'] === 401, 'Family access revoked');
	check(refresh($server, $tokens['refresh_token'])['status'] === 400, 'Family refresh revoked');
});

test('Refresh rotates tokens, preserves identity and rejects replay with family revocation', function (): void {
	[$server] = oauthApp();
	$tokens = tokens($server);
	$response = refresh($server, $tokens['refresh_token']);
	check($response['status'] === 200 && $response['json']['refresh_token'] !== $tokens['refresh_token'], 'Refresh rotation');
	check(mcp($server, $tokens['access_token'])['status'] === 401, 'Old access token revoked');
	$next = $response['json'];
	check(mcp($server, $next['access_token'])['json']['result']['content'][0]['text'] === 'oauth:fixture-owner:' . CLIENT, 'Stable identity');
	check(refresh($server, $tokens['refresh_token'])['status'] === 400, 'Refresh replay rejected');
	check(mcp($server, $next['access_token'])['status'] === 401 && refresh($server, $next['refresh_token'])['status'] === 400, 'Descendants revoked');
});

test('Refresh refuses scope escalation and wrong resource or client secret', function (): void {
	[$server] = oauthApp();
	$tokens = tokens($server);
	foreach ([['scope' => 'repo.write'], ['resource' => 'https://other.test/mcp'], ['client_secret' => 'wrong']] as $replace) {
		check(refresh($server, $tokens['refresh_token'], $replace)['status'] >= 400, 'Invalid refresh');
	}
	check(refresh($server, $tokens['refresh_token'])['status'] === 200, 'Original refresh remains valid');
});

test('Malformed and tampered bearer tokens fail closed', function (): void {
	[$server] = oauthApp();
	$accessToken = tokens($server)['access_token'];
	foreach (['bad', 'a.b.c', str_repeat('a', 18000), substr($accessToken, 0, -8) . 'AAAAAAAA'] as $bad) {
		check(mcp($server, $bad)['status'] === 401, 'Rejected malformed token');
	}
});

test('JWT validation requires issuer, audience, owner, client, scope and all time claims', function (): void {
	[$server, , $secrets] = oauthApp();
	$accessToken = tokens($server)['access_token'];
	$jwt = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($secrets['mcp.oauth.private_key']), InMemory::plainText($secrets['mcp.oauth.public_key']));
	$id = $jwt->parser()->parse($accessToken)->claims()->get('jti');
	foreach (['issuer', 'audience', 'owner', 'client', 'scope', 'expired', 'future', 'missing-exp', 'missing-iat', 'missing-nbf', 'unknown-id'] as $bad) {
		$now = new \DateTimeImmutable();
		$builder = $jwt->builder()->issuedBy($bad === 'issuer' ? 'https://other.test' : ISSUER)
			->permittedFor($bad === 'audience' ? CLIENT : ISSUER . '/mcp')
			->relatedTo($bad === 'owner' ? 'other' : 'fixture-owner')
			->identifiedBy($bad === 'unknown-id' ? str_repeat('a', 80) : $id)
			->withClaim('client_id', $bad === 'client' ? 'other' : CLIENT)
			->withClaim('scopes', $bad === 'scope' ? ['repo.write'] : ['repo.read']);
		if ($bad !== 'missing-iat') {
			$builder = $builder->issuedAt($now->modify('-2 seconds'));
		}
		if ($bad !== 'missing-nbf') {
			$builder = $builder->canOnlyBeUsedAfter($now->modify($bad === 'future' ? '+1 hour' : '-2 seconds'));
		}
		if ($bad !== 'missing-exp') {
			$builder = $builder->expiresAt($now->modify($bad === 'expired' ? '-1 second' : '+10 minutes'));
		}
		check(mcp($server, $builder->getToken($jwt->signer(), $jwt->signingKey())->toString())['status'] === 401, 'Rejected ' . $bad);
	}
});

test('Deleting the ledger revokes OAuth access without affecting fixed tokens', function (): void {
	[$server, $dir] = oauthApp();
	$tokens = tokens($server);
	unlink($dir . '/var/state/mcp_oauth/ledger.json');
	check(mcp($server, $tokens['access_token'])['status'] === 401 && refresh($server, $tokens['refresh_token'])['status'] === 400, 'Missing records fail closed');
	check(mcp($server)['status'] === 200, 'Fixed token unaffected');
});

test('Token endpoint rejects JSON, excessive bodies and unimplemented grants', function (): void {
	[$server] = oauthApp();
	check(req($server, 'POST', '/mcp/oauth/token', ['Content-Type' => 'application/json'], '{}')['status'] === 415, 'JSON refused');
	check(req($server, 'POST', '/mcp/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], 'x=' . str_repeat('x', 17000))['status'] === 413, 'Body limit');
	check(exchange($server, 'unused', ['grant_type' => 'client_credentials'])['status'] === 400, 'Machine grant refused');
});

test('OAuth credentials do not enter application logs or persisted state', function (): void {
	[$server, $dir] = oauthApp();
	$tokens = tokens($server);
	mcp($server, $tokens['access_token']);
	$all = '';
	foreach (glob($dir . '/var/logs/*') ?: [] as $file) {
		$all .= file_get_contents($file);
	}
	$all .= file_get_contents($dir . '/var/state/mcp_oauth/ledger.json');
	foreach ([PASSWORD, CLIENT_SECRET, FIXED, $tokens['access_token'], $tokens['refresh_token']] as $secret) {
		check(!str_contains($all, $secret), 'Credential not logged or stored');
	}
});

test('Handshake-era OAuth discovery and calls use the same authenticated tools', function (): void {
	[$server] = oauthApp();
	$accessToken = tokens($server)['access_token'];
	$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $accessToken];
	$response = req($server, 'POST', '/mcp', $headers, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
		'protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'oauth-test', 'version' => '1'], 'capabilities' => new \stdClass(),
	]]));
	check($response['status'] === 200 && isset($response['headers']['mcp-session-id']), 'Initialize');
	$headers['Mcp-Session-Id'] = $response['headers']['mcp-session-id'];
	$headers['MCP-Protocol-Version'] = '2025-03-26';
	check(req($server, 'POST', '/mcp', $headers, '{"jsonrpc":"2.0","method":"notifications/initialized"}')['status'] === 202, 'Initialized');
	$response = req($server, 'POST', '/mcp', $headers, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
	check($response['status'] === 200 && count($response['json']['result']['tools']) === 2, 'Tools listed');
	foreach ($response['json']['result']['tools'] as $tool) {
		check(($tool['securitySchemes'] ?? null) === [['type' => 'oauth2', 'scopes' => ['repo.read']]], 'OAuth tool metadata');
	}
	$response = req($server, 'POST', '/mcp', $headers, '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"client","arguments":{}}}');
	check($response['json']['result']['content'][0]['text'] === 'oauth:fixture-owner:' . CLIENT, 'Authenticated call');
});

foreach (['authorization_code', 'refresh_token'] as $grant) {
	parallel('Concurrent ' . $grant . ' redemption permits only one grant', function () use ($grant): void {
		[$server, $dir] = oauthApp();
		$second = HttpServer::start($dir) ?? fail('Cannot start PHP\'s built-in web server: ' . HttpServer::$lastFailure);
		$fields = ['grant_type' => $grant, 'client_id' => CLIENT, 'client_secret' => CLIENT_SECRET, 'resource' => ISSUER . '/mcp'];
		if ($grant === 'authorization_code') {
			$fields += ['code' => code($server), 'code_verifier' => VERIFIER, 'redirect_uri' => CALLBACK];
		} else {
			$fields['refresh_token'] = tokens($server)['refresh_token'];
		}
		$input = $dir . '/race-input';
		$gate = $dir . '/race-gate';
		file_put_contents($input, http_build_query($fields));
		$processes = [];
		foreach ([$server->port, $second->port] as $i => $port) {
			$processes[] = proc_open([...HttpServer::phpCommand(), \dirname(__DIR__) . '/Support/OAuthRaceWorker.php', (string)$port, $input, $gate, $dir . '/ready-' . $i, $dir . '/out-' . $i],
				[0 => ['pipe', 'r'], 1 => ['file', $dir . '/worker.log', 'a'], 2 => ['file', $dir . '/worker.log', 'a']], $pipes);
			check(is_resource($processes[$i]), 'Worker process');
			fclose($pipes[0]);
		}
		$deadline = microtime(true) + 10;
		while (!is_file($dir . '/ready-0') || !is_file($dir . '/ready-1')) {
			check(microtime(true) < $deadline, 'Workers became ready');
			usleep(1000);
		}
		file_put_contents($gate, 'go');
		$statuses = [];
		foreach ($processes as $i => $process) {
			$exit = proc_close($process);
			check($exit === 0, 'Worker ' . $i . ' exit code ' . $exit . ': ' . (is_file($dir . '/worker.log') ? file_get_contents($dir . '/worker.log') : ''));
			check(preg_match('/^HTTP\/1\.[01] (\d{3})/', file_get_contents($dir . '/out-' . $i), $m) === 1, 'Worker response');
			$statuses[] = (int)$m[1];
		}
		sort($statuses);
		check($statuses === [200, 400], 'Single grant, statuses ' . implode(',', $statuses));
	});
}

test('Refresh without resource remains bound to the original resource', function (): void {
	[$server] = oauthApp();
	$tokens = tokens($server);
	$response = refresh($server, $tokens['refresh_token'], ['resource' => null]);
	check($response['status'] === 200, 'Omitted refresh resource');
	$payload = json_decode(base64_decode(strtr(explode('.', $response['json']['access_token'])[1], '-_', '+/')), true);
	check((array)$payload['aud'] === [ISSUER . '/mcp'], 'Original audience preserved');
	check(refresh($server, $response['json']['refresh_token'], ['resource' => ''])['status'] === 400, 'Present but empty resource refused');
});

test('Public admission capacity leaves room for active refresh and recovers after expiry', function (): void {
	[$server, $dir] = oauthApp();
	$tokens = tokens($server);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true);
	for ($i = count($ledger['records']); $i < 2048; ++$i) {
		$ledger['records']['approval:capacity-' . $i] = ['expires' => time() + 600];
	}
	file_put_contents($file, json_encode($ledger));
	check(req($server, 'GET', '/mcp/oauth/authorize?' . http_build_query(params()))['status'] === 503, 'Admission is retryable');
	check(refresh($server, $tokens['refresh_token'])['status'] === 200, 'Existing refresh has reserved capacity');
	$ledger = json_decode(file_get_contents($file), true);
	foreach ($ledger['records'] as $id => &$record) {
		if (str_starts_with($id, 'approval:capacity-')) {
			$record['expires'] = time() - 1;
		}
	}
	unset($record);
	file_put_contents($file, json_encode($ledger));
	check(begin($server)['status'] === 200, 'Expired entries are collected automatically');
});

test('Grant capacity returns 503 without consuming the authorization code', function (): void {
	[$server, $dir] = oauthApp();
	$code = code($server);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true);
	for ($i = count($ledger['records']); $i < 4096; ++$i) {
		$ledger['records']['code:capacity-' . $i] = ['expires' => time() + 600];
	}
	file_put_contents($file, json_encode($ledger));
	check(exchange($server, $code)['status'] === 503, 'Retryable capacity response');
	$ledger = json_decode(file_get_contents($file), true);
	foreach ($ledger['records'] as $id => &$record) {
		if (str_starts_with($id, 'code:capacity-')) {
			$record['expires'] = time() - 1;
		}
	}
	unset($record);
	file_put_contents($file, json_encode($ledger));
	check(exchange($server, $code)['status'] === 200, 'Code remains usable after capacity recovery');
});

test('Refresh state stays bounded over repeated rotations and old replay revokes the newest token', function (): void {
	[$server, $dir] = oauthApp();
	$first = $current = tokens($server);
	for ($i = 0; $i < 100; ++$i) {
		$response = refresh($server, $current['refresh_token']);
		check($response['status'] === 200, 'Rotation ' . $i);
		$current = $response['json'];
	}
	$records = json_decode(file_get_contents($dir . '/var/state/mcp_oauth/ledger.json'), true)['records'];
	check(count($records) <= 6, 'One family has constant state, found ' . count($records));
	check(refresh($server, $first['refresh_token'])['status'] === 400, 'Old rotated token replay');
	check(mcp($server, $current['access_token'])['status'] === 401, 'Latest descendant revoked');
});

test('Successful approvals do not accumulate a password lockout, and no global counter blocks the owner', function (): void {
	[$server, $dir] = oauthApp();
	$pending = begin($server);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true);
	foreach (['password-global', 'authorize-global'] as $key) {
		$ledger['records']['limit:' . $key] = ['expires' => time() + 900, 'count' => 10000];
	}
	file_put_contents($file, json_encode($ledger));
	check(approve($server, $pending)['status'] === 302, 'No global password gate');
	for ($i = 0; $i < 11; ++$i) {
		check(approve($server, begin($server))['status'] === 302, 'Repeated successful approval ' . $i);
	}
});

test('Audit records decisions, password failures, issue, refresh and replay without secrets', function (): void {
	[$server, $dir] = oauthApp();
	$pending = begin($server);
	approve($server, $pending, ['password' => 'wrong-audit-password']);
	approve($server, $pending, ['decision' => 'deny']);
	$tokens = tokens($server);
	refresh($server, $tokens['refresh_token']);
	refresh($server, $tokens['refresh_token']);
	$raw = file_get_contents($dir . '/var/logs/mcp.jsonl');
	foreach (['authorization.begin', 'authorization.decision', 'invalid_password', 'approved', 'access_denied', 'token.issue', 'token.refresh', 'grant.replay', 'family_revoked'] as $event) {
		check(str_contains($raw, $event), 'Audit ' . $event);
	}
	foreach ([PASSWORD, CLIENT_SECRET, $tokens['access_token'], $tokens['refresh_token'], 'wrong-audit-password', $pending['form']['csrf']] as $secret) {
		check(!str_contains($raw, $secret), 'No secret in audit');
	}
});

test('Consent policy permits only configured callback origins and keeps unsafe Origin rejected', function (): void {
	[$server] = oauthApp();
	$pending = begin($server);
	check(str_contains($pending['headers']['content-security-policy'], "form-action 'self' https://chatgpt.com;"), 'Exact configured origin in CSP');
	check(!str_contains($pending['headers']['content-security-policy'], '*'), 'No wildcard source');
	check(approve($server, $pending, [], ['Origin' => 'null'])['status'] === 403, 'Opaque Origin remains rejected');
	check(str_contains($pending['body'], '<html lang="en">') && str_contains($pending['body'], 'Approve access'), 'English defaults');
});

test('No OAuth fixture logged an unexpected exception or PHP diagnostic', function (): void {
	// No app is registered when every fixture failed during setup.
	foreach ($GLOBALS['oauthApps'] ?? [] as $dir) {
		foreach (glob($dir . '/var/logs/http_err_*') ?: [] as $file) {
			check(trim(file_get_contents($file)) === '', basename($file) . ': ' . file_get_contents($file));
		}
	}
});

done();
