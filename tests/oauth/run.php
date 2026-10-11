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

use CitOmni\Mcp\Tests\Support\HttpServer;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

require dirname(__DIR__) . '/Support/OAuthFixture.php';

test('OAuth disabled returns 404 on every new endpoint and preserves fixed tokens', function (): void {
	[$s] = oauthApp(['mcp' => ['oauth' => ['enabled' => false]]]);
	foreach (['/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp', '/mcp/oauth/authorize'] as $path) {
		check(req($s, 'GET', $path)['status'] === 404, $path);
	}
	check(req($s, 'POST', '/mcp/oauth/token')['status'] === 404, 'Token endpoint disabled');
	check(mcp($s)['status'] === 200, 'Fixed token');
});

test('Discovery advertises exact resource, PKCE, fixed client authentication and issuer identification', function (): void {
	[$s] = oauthApp();
	$r = req($s, 'GET', '/.well-known/oauth-protected-resource/mcp');
	check($r['status'] === 200 && $r['json']['resource'] === ISSUER . '/mcp', 'Resource');
	check($r['json']['authorization_servers'] === [ISSUER], 'Issuer');
	$a = req($s, 'GET', '/.well-known/oauth-authorization-server')['json'];
	check($a['code_challenge_methods_supported'] === ['S256'] && $a['authorization_response_iss_parameter_supported'] === true, 'PKCE and issuer');
	check(!isset($a['registration_endpoint']) && $a['grant_types_supported'] === ['authorization_code', 'refresh_token'], 'No unimplemented grants or dynamic registration');
	$u = mcp($s, null);
	check($u['status'] === 401 && str_contains($u['headers']['www-authenticate'], 'resource_metadata="' . ISSUER), 'MCP discovery challenge');
});

test('OAuth endpoints reject cleartext and foreign hosts', function (): void {
	[$s] = oauthApp();
	check(req($s, 'GET', '/.well-known/oauth-authorization-server', ['X-Test-Insecure' => '1'])['status'] === 403, 'HTTP rejected');
	check(req($s, 'GET', '/.well-known/oauth-authorization-server', ['Host' => 'attacker.test'])['status'] === 403, 'Host rejected');
});

test('Fixed token fast path works even when optional OAuth configuration is broken', function (): void {
	[$s] = oauthApp(['mcp' => ['oauth' => ['issuer' => 'invalid']]]);
	$r = mcp($s);
	check($r['status'] === 200 && $r['json']['result']['content'][0]['text'] === 'mcp.client_token.1', 'Original fixed identity');
});

test('OAuth bearer tokens cannot be used over cleartext or a different host', function (): void {
	[$s] = oauthApp();
	$t = tokens($s);
	check(mcp($s, $t['access_token'], ['X-Test-Insecure' => '1'])['status'] === 403, 'TLS required on MCP');
	check(mcp($s, $t['access_token'], ['Host' => 'attacker.test'])['status'] === 403, 'Canonical host required on MCP');
});

test('Expired code and refresh records are rejected even with intact encrypted tokens', function (): void {
	[$s, $dir] = oauthApp();
	$code = code($s);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	foreach ($ledger['records'] as $id => &$record) {
		if (str_starts_with($id, 'code:')) {
			$record['expires'] = time() - 1;
		}
	}
	unset($record);
	file_put_contents($file, json_encode($ledger, JSON_THROW_ON_ERROR));
	check(exchange($s, $code)['status'] === 400, 'Expired code');
	$t = tokens($s);
	$ledger = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	foreach ($ledger['records'] as $id => &$record) {
		if (str_starts_with($id, 'refresh:')) {
			$record['expires'] = time() - 1;
		}
	}
	unset($record);
	file_put_contents($file, json_encode($ledger, JSON_THROW_ON_ERROR));
	check(refresh($s, $t['refresh_token'])['status'] === 400, 'Expired refresh');
});

test('Grant records from a different authorization context fail closed', function (): void {
	[$s, $dir] = oauthApp();
	$t = tokens($s);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	foreach ($ledger['records'] as &$record) {
		if (isset($record['context'])) {
			$record['context'] = hash('sha256', 'old-resource');
		}
	}
	unset($record);
	file_put_contents($file, json_encode($ledger, JSON_THROW_ON_ERROR));
	check(mcp($s, $t['access_token'])['status'] === 401, 'Old resource access');
	check(refresh($s, $t['refresh_token'])['status'] === 400, 'Old resource refresh');
});

test('Empty fixed-token list with OAuth enabled does not create anonymous access', function (): void {
	[$s] = oauthApp(['mcp' => ['auth' => ['secret_keys' => []]]]);
	check(mcp($s, null)['status'] === 401 && mcp($s)['status'] === 401, 'Anonymous and fixed token rejected');
	$t = tokens($s);
	check(mcp($s, $t['access_token'])['status'] === 200, 'OAuth-only connection');
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
		[$s] = oauthApp();
		$r = req($s, 'GET', '/mcp/oauth/authorize?' . http_build_query(params($replace)));
		check($r['status'] !== 200 && !isset($r['headers']['set-cookie']), 'Rejected before password form');
		if (isset($r['headers']['location'])) {
			check(str_starts_with($r['headers']['location'], CALLBACK . '?') && str_contains($r['headers']['location'], 'iss='), 'Only registered callback with issuer');
		}
	});
}

test('Duplicate and array parameters are rejected without PHP coercion', function (): void {
	[$s] = oauthApp();
	$q = http_build_query(params());
	check(req($s, 'GET', '/mcp/oauth/authorize?' . $q . '&client_id=other')['status'] === 400, 'Duplicate client');
	check(req($s, 'GET', '/mcp/oauth/authorize?' . $q . '&foo[]=bar')['status'] === 400, 'Array parameter');
});

test('Consent cookie and page have browser security headers; HEAD creates no state', function (): void {
	[$s, $dir] = oauthApp();
	$r = req($s, 'HEAD', '/mcp/oauth/authorize?' . http_build_query(params()));
	check($r['status'] === 200 && !isset($r['headers']['set-cookie']) && !is_file($dir . '/var/state/mcp_oauth/ledger.json'), 'HEAD side effects');
	$p = begin($s);
	foreach (['Secure', 'HttpOnly', 'SameSite=Lax', 'Path=/'] as $flag) {
		check(str_contains($p['headers']['set-cookie'], $flag), $flag);
	}
	check($p['headers']['cache-control'] === 'no-store' && $p['headers']['referrer-policy'] === 'same-origin', 'No cache/referrer');
	check(str_contains($p['headers']['content-security-policy'], "frame-ancestors 'none'"), 'No framing');
});

test('Consent requires password, CSRF, browser binding and same-origin POST', function (): void {
	[$s] = oauthApp();
	$p = begin($s);
	check(approve($s, $p, ['password' => 'wrong'])['status'] === 403, 'Wrong password');
	check(approve($s, $p, ['csrf' => 'wrong'])['status'] === 400, 'Wrong CSRF');
	check(approve($s, $p, [], ['Cookie' => ''])['status'] === 400, 'Missing browser');
	check(approve($s, $p, [], ['Cookie' => '__Host-citomni_mcp_oauth=' . str_repeat('0', 64)])['status'] === 400, 'Other browser');
	check(approve($s, $p, [], ['Origin' => 'https://attacker.test'])['status'] === 403, 'Foreign origin');
	check(approve($s, $p, [], ['Sec-Fetch-Site' => 'cross-site'])['status'] === 403, 'Cross-site metadata');
	check(approve($s, $p)['status'] === 302, 'Valid consent remains usable');
	check(approve($s, $p)['status'] === 400, 'Consent cannot be replayed');
});

test('Denial preserves state and issuer without issuing a code or requiring a password', function (): void {
	[$s] = oauthApp();
	$r = approve($s, begin($s), ['decision' => 'deny', 'password' => '']);
	check($r['status'] === 302, 'Denial redirect');
	parse_str(parse_url($r['headers']['location'], PHP_URL_QUERY), $q);
	check(($q['error'] ?? null) === 'access_denied' && !isset($q['code']) && $q['iss'] === ISSUER && $q['state'] === 'fixture-state', 'Denied callback');
});

test('Owner password attempts are limited', function (): void {
	[$s] = oauthApp();
	$p = begin($s);
	for ($i = 0; $i < 10; ++$i) {
		check(approve($s, $p, ['password' => 'wrong'])['status'] === 403, 'Failed password ' . $i);
	}
	check(approve($s, $p)['status'] === 429, 'Rate limit');
});

test('Expired approval is refused', function (): void {
	[$s, $dir] = oauthApp();
	$p = begin($s);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$d = json_decode(file_get_contents($file), true);
	$d['records']['approval:' . $p['form']['request_id']]['expires'] = time() - 1;
	file_put_contents($file, json_encode($d));
	check(approve($s, $p)['status'] === 400, 'Expired approval');
});

test('Authorization code grants access with an unchanged fixed-token path', function (): void {
	[$s] = oauthApp();
	$t = tokens($s);
	check($t['token_type'] === 'Bearer' && $t['expires_in'] <= 900 && $t['expires_in'] > 0, 'Token response');
	$r = mcp($s, $t['access_token']);
	check($r['status'] === 200 && $r['json']['result']['content'][0]['text'] === 'oauth:fixture-owner:' . CLIENT, 'Stable OAuth identity');
	check(mcp($s)['json']['result']['content'][0]['text'] === 'mcp.client_token.1', 'Claude identity unchanged');
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
		[$s] = oauthApp();
		$c = code($s);
		$r = exchange($s, $c, $replace);
		check($r['status'] >= 400 && !isset($r['json']['access_token']), 'Rejected grant');
		check(exchange($s, $c)['status'] === 200, 'Failed validation did not consume the valid code');
	});
}

test('HTTP Basic client authentication works and mixed methods are refused', function (): void {
	[$s] = oauthApp();
	$c = code($s);
	$h = ['Authorization' => 'Basic ' . base64_encode(CLIENT . ':' . CLIENT_SECRET)];
	check(exchange($s, $c, [], $h)['status'] === 400, 'Mixed authentication');
	check(exchange($s, $c, ['client_secret' => null], $h)['status'] === 200, 'Basic authentication');
});

test('Authorization-code replay revokes tokens issued from that code', function (): void {
	[$s] = oauthApp();
	$c = code($s);
	$t = exchange($s, $c)['json'];
	check(exchange($s, $c)['status'] === 400, 'Replay rejected');
	check(mcp($s, $t['access_token'])['status'] === 401, 'Family access revoked');
	check(refresh($s, $t['refresh_token'])['status'] === 400, 'Family refresh revoked');
});

test('Refresh rotates tokens, preserves identity and rejects replay with family revocation', function (): void {
	[$s] = oauthApp();
	$t = tokens($s);
	$r = refresh($s, $t['refresh_token']);
	check($r['status'] === 200 && $r['json']['refresh_token'] !== $t['refresh_token'], 'Refresh rotation');
	check(mcp($s, $t['access_token'])['status'] === 401, 'Old access token revoked');
	$n = $r['json'];
	check(mcp($s, $n['access_token'])['json']['result']['content'][0]['text'] === 'oauth:fixture-owner:' . CLIENT, 'Stable identity');
	check(refresh($s, $t['refresh_token'])['status'] === 400, 'Refresh replay rejected');
	check(mcp($s, $n['access_token'])['status'] === 401 && refresh($s, $n['refresh_token'])['status'] === 400, 'Descendants revoked');
});

test('Refresh refuses scope escalation and wrong resource or client secret', function (): void {
	[$s] = oauthApp();
	$t = tokens($s);
	foreach ([['scope' => 'repo.write'], ['resource' => 'https://other.test/mcp'], ['client_secret' => 'wrong']] as $replace) {
		check(refresh($s, $t['refresh_token'], $replace)['status'] >= 400, 'Invalid refresh');
	}
	check(refresh($s, $t['refresh_token'])['status'] === 200, 'Original refresh remains valid');
});

test('Malformed and tampered bearer tokens fail closed', function (): void {
	[$s] = oauthApp();
	$t = tokens($s)['access_token'];
	foreach (['bad', 'a.b.c', str_repeat('a', 18000), substr($t, 0, -8) . 'AAAAAAAA'] as $bad) {
		check(mcp($s, $bad)['status'] === 401, 'Rejected malformed token');
	}
});

test('JWT validation requires issuer, audience, owner, client, scope and all time claims', function (): void {
	[$s, , $secrets] = oauthApp();
	$t = tokens($s)['access_token'];
	$jwt = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($secrets['mcp.oauth.private_key']), InMemory::plainText($secrets['mcp.oauth.public_key']));
	$id = $jwt->parser()->parse($t)->claims()->get('jti');
	foreach (['issuer', 'audience', 'owner', 'client', 'scope', 'expired', 'future', 'missing-exp', 'missing-iat', 'missing-nbf', 'unknown-id'] as $bad) {
		$now = new DateTimeImmutable();
		$b = $jwt->builder()->issuedBy($bad === 'issuer' ? 'https://other.test' : ISSUER)
			->permittedFor($bad === 'audience' ? CLIENT : ISSUER . '/mcp')
			->relatedTo($bad === 'owner' ? 'other' : 'fixture-owner')
			->identifiedBy($bad === 'unknown-id' ? str_repeat('a', 80) : $id)
			->withClaim('client_id', $bad === 'client' ? 'other' : CLIENT)
			->withClaim('scopes', $bad === 'scope' ? ['repo.write'] : ['repo.read']);
		if ($bad !== 'missing-iat') {
			$b = $b->issuedAt($now->modify('-2 seconds'));
		}
		if ($bad !== 'missing-nbf') {
			$b = $b->canOnlyBeUsedAfter($now->modify($bad === 'future' ? '+1 hour' : '-2 seconds'));
		}
		if ($bad !== 'missing-exp') {
			$b = $b->expiresAt($now->modify($bad === 'expired' ? '-1 second' : '+10 minutes'));
		}
		check(mcp($s, $b->getToken($jwt->signer(), $jwt->signingKey())->toString())['status'] === 401, 'Rejected ' . $bad);
	}
});

test('Deleting the ledger revokes OAuth access without affecting fixed tokens', function (): void {
	[$s, $dir] = oauthApp();
	$t = tokens($s);
	unlink($dir . '/var/state/mcp_oauth/ledger.json');
	check(mcp($s, $t['access_token'])['status'] === 401 && refresh($s, $t['refresh_token'])['status'] === 400, 'Missing records fail closed');
	check(mcp($s)['status'] === 200, 'Fixed token unaffected');
});

test('Token endpoint rejects JSON, excessive bodies and unimplemented grants', function (): void {
	[$s] = oauthApp();
	check(req($s, 'POST', '/mcp/oauth/token', ['Content-Type' => 'application/json'], '{}')['status'] === 415, 'JSON refused');
	check(req($s, 'POST', '/mcp/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], 'x=' . str_repeat('x', 17000))['status'] === 413, 'Body limit');
	check(exchange($s, 'unused', ['grant_type' => 'client_credentials'])['status'] === 400, 'Machine grant refused');
});

test('OAuth credentials do not enter application logs or persisted state', function (): void {
	[$s, $dir] = oauthApp();
	$t = tokens($s);
	mcp($s, $t['access_token']);
	$all = '';
	foreach (glob($dir . '/var/logs/*') ?: [] as $file) {
		$all .= file_get_contents($file);
	}
	$all .= file_get_contents($dir . '/var/state/mcp_oauth/ledger.json');
	foreach ([PASSWORD, CLIENT_SECRET, FIXED, $t['access_token'], $t['refresh_token']] as $secret) {
		check(!str_contains($all, $secret), 'Credential not logged or stored');
	}
});

test('Handshake-era OAuth discovery and calls use the same authenticated tools', function (): void {
	[$s] = oauthApp();
	$t = tokens($s)['access_token'];
	$h = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $t];
	$r = req($s, 'POST', '/mcp', $h, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
		'protocolVersion' => '2025-03-26', 'clientInfo' => ['name' => 'oauth-test', 'version' => '1'], 'capabilities' => new stdClass(),
	]]));
	check($r['status'] === 200 && isset($r['headers']['mcp-session-id']), 'Initialize');
	$h['Mcp-Session-Id'] = $r['headers']['mcp-session-id'];
	$h['MCP-Protocol-Version'] = '2025-03-26';
	check(req($s, 'POST', '/mcp', $h, '{"jsonrpc":"2.0","method":"notifications/initialized"}')['status'] === 202, 'Initialized');
	$r = req($s, 'POST', '/mcp', $h, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
	check($r['status'] === 200 && count($r['json']['result']['tools']) === 2, 'Tools listed');
	foreach ($r['json']['result']['tools'] as $tool) {
		check(($tool['securitySchemes'] ?? null) === [['type' => 'oauth2', 'scopes' => ['repo.read']]], 'OAuth tool metadata');
	}
	$r = req($s, 'POST', '/mcp', $h, '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"client","arguments":{}}}');
	check($r['json']['result']['content'][0]['text'] === 'oauth:fixture-owner:' . CLIENT, 'Authenticated call');
});

foreach (['authorization_code', 'refresh_token'] as $grant) {
	test('Concurrent ' . $grant . ' redemption permits only one grant', function () use ($grant): void {
		[$s, $dir] = oauthApp();
		$second = HttpServer::start($dir) ?? fail(HttpServer::$lastFailure);
		$fields = ['grant_type' => $grant, 'client_id' => CLIENT, 'client_secret' => CLIENT_SECRET, 'resource' => ISSUER . '/mcp'];
		if ($grant === 'authorization_code') {
			$fields += ['code' => code($s), 'code_verifier' => VERIFIER, 'redirect_uri' => CALLBACK];
		} else {
			$fields['refresh_token'] = tokens($s)['refresh_token'];
		}
		$input = $dir . '/race-input';
		$gate = $dir . '/race-gate';
		file_put_contents($input, http_build_query($fields));
		$processes = [];
		foreach ([$s->port, $second->port] as $i => $port) {
			$processes[] = proc_open([PHP_BINARY, __DIR__ . '/../Support/OAuthRaceWorker.php', (string)$port, $input, $gate, $dir . '/ready-' . $i, $dir . '/out-' . $i],
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
			check(proc_close($process) === 0, 'Worker exit');
			check(preg_match('/^HTTP\/1\.[01] (\d{3})/', file_get_contents($dir . '/out-' . $i), $m) === 1, 'Worker response');
			$statuses[] = (int)$m[1];
		}
		sort($statuses);
		check($statuses === [200, 400], 'Single grant, statuses ' . implode(',', $statuses));
	});
}

test('Refresh without resource remains bound to the original resource', function (): void {
	[$s] = oauthApp();
	$t = tokens($s);
	$r = refresh($s, $t['refresh_token'], ['resource' => null]);
	check($r['status'] === 200, 'Omitted refresh resource');
	$payload = json_decode(base64_decode(strtr(explode('.', $r['json']['access_token'])[1], '-_', '+/')), true);
	check((array)$payload['aud'] === [ISSUER . '/mcp'], 'Original audience preserved');
	check(refresh($s, $r['json']['refresh_token'], ['resource' => ''])['status'] === 400, 'Present but empty resource refused');
});

test('Public admission capacity leaves room for active refresh and recovers after expiry', function (): void {
	[$s, $dir] = oauthApp();
	$t = tokens($s);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true);
	for ($i = count($ledger['records']); $i < 2048; ++$i) {
		$ledger['records']['approval:capacity-' . $i] = ['expires' => time() + 600];
	}
	file_put_contents($file, json_encode($ledger));
	check(req($s, 'GET', '/mcp/oauth/authorize?' . http_build_query(params()))['status'] === 503, 'Admission is retryable');
	check(refresh($s, $t['refresh_token'])['status'] === 200, 'Existing refresh has reserved capacity');
	$ledger = json_decode(file_get_contents($file), true);
	foreach ($ledger['records'] as $id => &$record) {
		if (str_starts_with($id, 'approval:capacity-')) $record['expires'] = time() - 1;
	}
	unset($record);
	file_put_contents($file, json_encode($ledger));
	check(begin($s)['status'] === 200, 'Expired entries are collected automatically');
});

test('Grant capacity returns 503 without consuming the authorization code', function (): void {
	[$s, $dir] = oauthApp();
	$c = code($s);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true);
	for ($i = count($ledger['records']); $i < 4096; ++$i) {
		$ledger['records']['code:capacity-' . $i] = ['expires' => time() + 600];
	}
	file_put_contents($file, json_encode($ledger));
	check(exchange($s, $c)['status'] === 503, 'Retryable capacity response');
	$ledger = json_decode(file_get_contents($file), true);
	foreach ($ledger['records'] as $id => &$record) {
		if (str_starts_with($id, 'code:capacity-')) $record['expires'] = time() - 1;
	}
	unset($record);
	file_put_contents($file, json_encode($ledger));
	check(exchange($s, $c)['status'] === 200, 'Code remains usable after capacity recovery');
});

test('Refresh state stays bounded over repeated rotations and old replay revokes the newest token', function (): void {
	[$s, $dir] = oauthApp();
	$first = $current = tokens($s);
	for ($i = 0; $i < 100; ++$i) {
		$r = refresh($s, $current['refresh_token']);
		check($r['status'] === 200, 'Rotation ' . $i);
		$current = $r['json'];
	}
	$records = json_decode(file_get_contents($dir . '/var/state/mcp_oauth/ledger.json'), true)['records'];
	check(count($records) <= 6, 'One family has constant state, found ' . count($records));
	check(refresh($s, $first['refresh_token'])['status'] === 400, 'Old rotated token replay');
	check(mcp($s, $current['access_token'])['status'] === 401, 'Latest descendant revoked');
});

test('Successful approvals do not accumulate a password lockout and legacy global counters do not block the owner', function (): void {
	[$s, $dir] = oauthApp();
	$pending = begin($s);
	$file = $dir . '/var/state/mcp_oauth/ledger.json';
	$ledger = json_decode(file_get_contents($file), true);
	foreach (['password-global', 'authorize-global'] as $key) {
		$ledger['records']['limit:' . $key] = ['expires' => time() + 900, 'count' => 10000];
	}
	file_put_contents($file, json_encode($ledger));
	check(approve($s, $pending)['status'] === 302, 'No global password gate');
	for ($i = 0; $i < 11; ++$i) {
		check(approve($s, begin($s))['status'] === 302, 'Repeated successful approval ' . $i);
	}
});

test('Audit records decisions, password failures, issue, refresh and replay without secrets', function (): void {
	[$s, $dir] = oauthApp();
	$p = begin($s);
	approve($s, $p, ['password' => 'wrong-audit-password']);
	approve($s, $p, ['decision' => 'deny']);
	$t = tokens($s);
	refresh($s, $t['refresh_token']);
	refresh($s, $t['refresh_token']);
	$raw = file_get_contents($dir . '/var/logs/mcp.jsonl');
	foreach (['authorization.begin', 'authorization.decision', 'invalid_password', 'approved', 'access_denied', 'token.issue', 'token.refresh', 'grant.replay', 'family_revoked'] as $event) {
		check(str_contains($raw, $event), 'Audit ' . $event);
	}
	foreach ([PASSWORD, CLIENT_SECRET, $t['access_token'], $t['refresh_token'], 'wrong-audit-password', $p['form']['csrf']] as $secret) {
		check(!str_contains($raw, $secret), 'No secret in audit');
	}
});

test('Consent policy permits only configured callback origins and keeps unsafe Origin rejected', function (): void {
	[$s] = oauthApp();
	$p = begin($s);
	check(str_contains($p['headers']['content-security-policy'], "form-action 'self' https://chatgpt.com;"), 'Exact configured origin in CSP');
	check(!str_contains($p['headers']['content-security-policy'], '*'), 'No wildcard source');
	check(approve($s, $p, [], ['Origin' => 'null'])['status'] === 403, 'Opaque Origin remains rejected');
	check(str_contains($p['body'], '<html lang="en">') && str_contains($p['body'], 'Approve access'), 'English defaults');
});

test('No OAuth fixture logged an unexpected exception or PHP diagnostic', function (): void {
	foreach ($GLOBALS['oauthApps'] as $dir) {
		foreach (glob($dir . '/var/logs/http_err_*') ?: [] as $file) {
			check(trim(file_get_contents($file)) === '', basename($file) . ': ' . file_get_contents($file));
		}
	}
});

done();
