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

use CitOmni\Mcp\Tests\Support\Chromium;
use CitOmni\Mcp\Tests\Support\HttpServer;

/*
 * Browser checks of the OAuth approval page: a real CitOmni HTTP app with OAuth enabled, used
 * through headless Chrome, Chromium or Edge. The browser reaches the app through a local HTTPS
 * issuer origin, and the client's callback is a second HTTPS origin. The cases cover what only
 * a browser produces or enforces: Origin and Sec-Fetch-Site on the approval form, CSP
 * form-action across the redirect to the callback, Referer at the callback, and the approval
 * cookie.
 *
 * Usage:
 *   php tests/browser/run.php
 *
 * Notes:
 * - Needs Chrome, Chromium or Edge in its standard install location or on PATH. Without one the
 *   suite fails; it does not skip.
 * - Both origins use a certificate generated for the run, and the browser ignores certificate
 *   errors for that key only. CSP, cookies and origin checks stay on.
 * - Before the browser starts, each origin must answer a TLS client that offers a client
 *   certificate. An origin that asks for one stops the suite, since a browser holding client
 *   certificates would wait at a certificate prompt.
 * - A server or browser that cannot be started stops the suite; it is not a reason to skip.
 * - A case that fails with a browser or protocol error reports the last connection events of the
 *   origins, which show how far the browser got.
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


// ----------------------------------------------------------------
// Fixtures
// ----------------------------------------------------------------

/**
 * Write a self-signed certificate for 127.0.0.1, or stop the suite when that is impossible.
 *
 * @param string $dir Directory that receives cert.pem and key.pem.
 * @return string Base64 SHA-256 of the certificate's SubjectPublicKeyInfo.
 */
function writeCertificate(string $dir): string {
	$options = ['digest_alg' => 'sha256'] + opensslOptions();
	$key = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA] + $options);
	$csr = $key === false ? false : \openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
	$cert = $csr instanceof \OpenSSLCertificateSigningRequest ? \openssl_csr_sign($csr, null, $key, 1, $options) : false;

	if ($cert === false || !\openssl_x509_export($cert, $certPem) || !\openssl_pkey_export($key, $keyPem, null, $options)) {
		fail('Cannot generate the test certificate: ' . opensslErrors());
	}

	\file_put_contents($dir . '/cert.pem', $certPem);
	\file_put_contents($dir . '/key.pem', $keyPem);
	$spki = \base64_decode((string)\preg_replace('/-----[^-]+-----|\s+/', '', \openssl_pkey_get_details($key)['key']), true);

	return \base64_encode(\hash('sha256', (string)$spki, true));
}


/**
 * Start the issuer and callback origins in front of an app, or stop the suite when that is impossible.
 *
 * Behavior:
 * - Runs tests/Support/BrowserOrigins.php with the certificate in $dir, and its request log in
 *   $dir/origins.jsonl.
 * - Registers the returned stop function as a shutdown function.
 *
 * @param string $dir Directory with cert.pem and key.pem.
 * @param int $backendPort Port of the app on 127.0.0.1.
 * @return array{0: \Closure(): void, 1: int, 2: int} Idempotent stop function, issuer port and callback port.
 */
function startOrigins(string $dir, int $backendPort): array {
	$ready = $dir . '/ready.json';
	$output = $dir . '/origins.log';
	$process = \proc_open([...HttpServer::phpCommand(), \dirname(__DIR__) . '/Support/BrowserOrigins.php', $dir . '/cert.pem', $dir . '/key.pem', (string)$backendPort, $ready, $dir . '/origins.jsonl'],
		[0 => ['pipe', 'r'], 1 => ['file', $output, 'a'], 2 => ['file', $output, 'a']], $pipes);

	if (!\is_resource($process)) {
		fail('Cannot start the HTTPS origins: proc_open() failed');
	}

	\fclose($pipes[0]);
	$stopped = false;
	$stop = static function () use ($process, &$stopped): void {
		if (!$stopped) {
			$stopped = true;
			@\proc_terminate($process);
			@\proc_close($process);
		}
	};
	\register_shutdown_function($stop);
	$deadline = \hrtime(true) + 10_000_000_000;

	while (!\is_file($ready)) {
		if (!\proc_get_status($process)['running'] || \hrtime(true) > $deadline) {
			$stop();
			fail('Cannot start the HTTPS origins: ' . \trim((string)@\file_get_contents($output)));
		}

		\usleep(20_000);
	}

	$ports = \json_decode((string)\file_get_contents($ready), true, 2, \JSON_THROW_ON_ERROR);

	return [$stop, $ports['proxy'], $ports['callback']];
}


/**
 * Return the requests the origins have logged.
 *
 * @param string $dir Directory with origins.jsonl.
 * @param int $offset Number of earlier requests to leave out.
 * @return list<array{role: string, method: string, target: string, status: int, origin: string|null, fetch_site: string|null, referer: string|null}>
 */
function originRequests(string $dir, int $offset = 0): array {
	$file = $dir . '/origins.jsonl';
	$lines = \is_file($file) ? \file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) : [];

	return \array_map(static fn(string $line): array => \json_decode($line, true, 4, \JSON_THROW_ON_ERROR), \array_slice($lines, $offset));
}


/**
 * Return the last connection events of the origins, for a failure message.
 *
 * @param string $dir Directory with origins.log.
 * @return string Up to the last 12 events, joined with " | ".
 */
function originEvents(string $dir): string {
	$file = $dir . '/origins.log';
	$lines = \is_file($file) ? \file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) : [];

	return \implode(' | ', \array_map('trim', \array_slice($lines, -12)));
}


/**
 * Request a path from an origin over TLS while offering a client certificate, or stop the suite.
 *
 * A browser that holds client certificates stops at a certificate prompt when an origin asks for
 * one, and no one answers it in headless mode. The probe offers the run's certificate, which the
 * origins cannot verify, so an origin that asks for a client certificate fails the probe.
 *
 * @param string $dir Directory with cert.pem, key.pem and origins.log.
 * @param int $port Port of the origin on 127.0.0.1.
 * @param string $path Path to request; the response must be 200.
 * @return void
 */
function probeOrigin(string $dir, int $port, string $path): void {
	$context = \stream_context_create(['ssl' => [
		'verify_peer'      => false,
		'verify_peer_name' => false,
		'local_cert'       => $dir . '/cert.pem',
		'local_pk'         => $dir . '/key.pem',
	]]);
	$socket = @\stream_socket_client('tls://127.0.0.1:' . $port, $errno, $error, 10, \STREAM_CLIENT_CONNECT, $context);
	$response = '';

	if ($socket !== false) {
		\stream_set_timeout($socket, 10);
		@\fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n");
		$response = (string)@\stream_get_contents($socket);
		\fclose($socket);
	}

	if (\preg_match('~^HTTP/1\.[01] 200 ~', $response) !== 1) {
		$answer = match (true) {
			$socket === false => 'no TLS connection (' . $error . ')',
			$response === ''  => 'no response',
			default           => (string)\strstr($response . "\r\n", "\r\n", true),
		};
		fail('The HTTPS origin on port ' . $port . ' did not answer GET ' . $path . ' with 200 to a client that offers a certificate: ' . $answer . '. Origins: ' . originEvents($dir));
	}
}


/**
 * Wrap a case so that it runs in a page of its own, which it closes again.
 *
 * Behavior:
 * - A browser or protocol error from the case gets the last connection events of the origins
 *   appended to its message. A failed check is passed on unchanged.
 *
 * @param Chromium $browser Browser.
 * @param string $dir Directory with origins.log.
 * @param \Closure(string): void $case The case; it receives the page session.
 * @return \Closure(): void The case for test().
 */
function inOwnPage(Chromium $browser, string $dir, \Closure $case): \Closure {
	return static function () use ($browser, $dir, $case): void {
		$page = $browser->newPage();

		try {
			$case($page);
		} catch (\CheckFailed $e) {
			throw $e;
		} catch (\RuntimeException $e) {
			// What the origins saw shows whether the browser connected, finished TLS and sent a request.
			throw new \RuntimeException($e->getMessage() . ' Origins: ' . originEvents($dir), 0, $e);
		} finally {
			$browser->closePage($page);
		}
	};
}


/**
 * Click a submit button and wait for the document that the submission loads.
 *
 * @param Chromium $browser Browser.
 * @param string $page Page session.
 * @param string $selector CSS selector of the button.
 * @return string URL of the loaded document.
 * @throws \RuntimeException When no document loads in time; the message includes the form-action violations logged.
 */
function submit(Chromium $browser, string $page, string $selector): string {
	$browser->click($page, $selector);

	try {
		$browser->awaitNewDocument($page);
	} catch (\RuntimeException $e) {
		// A navigation that CSP blocks loads nothing; its message says why.
		throw new \RuntimeException($e->getMessage() . ' Form-action violations: ' . \implode(' | ', takeFormActionViolations($browser, $page)), 0, $e);
	}

	return (string)$browser->evaluate($page, 'location.href');
}


/**
 * Tell whether the browser holds the approval cookie for the issuer.
 *
 * @param Chromium $browser Browser.
 * @param string $page Page session.
 * @param string $issuer Issuer origin.
 * @return bool True when the cookie is set.
 * @throws \RuntimeException When the browser does not answer in time.
 */
function hasApprovalCookie(Chromium $browser, string $page, string $issuer): bool {
	foreach ($browser->send('Network.getCookies', ['urls' => [$issuer . '/mcp/oauth/authorize']], $page)['cookies'] ?? [] as $cookie) {
		if ($cookie['name'] === '__Host-citomni_mcp_oauth') {
			return true;
		}
	}

	return false;
}


/**
 * Remove and return the CSP form-action violations that the browser has logged for a page.
 *
 * @param Chromium $browser Browser.
 * @param string $page Page session.
 * @return list<string> Message texts.
 * @throws \RuntimeException When the browser does not answer in time.
 */
function takeFormActionViolations(Chromium $browser, string $page): array {
	// Assumes that the browser sends a page's log entries before the answer to a later command.
	$browser->evaluate($page, '0');
	$messages = [];

	foreach ($browser->takeEvents('Log.entryAdded', $page) as $event) {
		$text = (string)($event['entry']['text'] ?? '');

		if (\str_contains($text, 'Content Security Policy') && \str_contains($text, 'form-action')) {
			$messages[] = $text;
		}
	}

	return $messages;
}


/**
 * Read the query of a URL.
 *
 * @param string $url URL.
 * @return array<string, mixed> Query parameters.
 */
function queryOf(string $url): array {
	\parse_str((string)\parse_url($url, \PHP_URL_QUERY), $query);

	return $query;
}


// ----------------------------------------------------------------
// Setup
// ----------------------------------------------------------------

$dir = tempDir();
$spkiHash = writeCertificate($dir);
[$server, $appDir, , $cfg] = oauthApp();
[$stopOrigins, $proxyPort, $callbackPort] = startOrigins($dir, $server->port);
$issuer = 'https://127.0.0.1:' . $proxyPort;
$callbackUrl = 'https://127.0.0.1:' . $callbackPort . '/callback';

// The app must see what the browser sees: the issuer origin, and HTTPS on the proxy's port.
$cfg['http']['base_url'] = $issuer;
$cfg['mcp']['oauth']['issuer'] = $issuer;
$cfg['mcp']['oauth']['redirect_uris'] = [$callbackUrl];
writeOAuthCfg($appDir, $cfg);
$entry = \str_replace('? 80 : 443;', '? 80 : ' . $proxyPort . ';', (string)\file_get_contents($appDir . '/public/index.php'), $replaced);

if ($replaced !== 1) {
	fail('The OAuth fixture\'s public/index.php no longer sets port 443 the way this suite replaces it.');
}

\file_put_contents($appDir . '/public/index.php', $entry);
probeOrigin($dir, $proxyPort, '/.well-known/oauth-authorization-server');
probeOrigin($dir, $callbackPort, '/callback');
$authorize = $issuer . '/mcp/oauth/authorize?' . \http_build_query(params([
	'redirect_uri' => $callbackUrl,
	'resource'     => $issuer . '/mcp',
	'state'        => 'browser-state',
]), '', '&', \PHP_QUERY_RFC3986);
$browser = Chromium::start(tempDir(), $spkiHash) ?? fail('Cannot start a browser: ' . Chromium::$lastFailure);


// ----------------------------------------------------------------
// Cases
// ----------------------------------------------------------------

// Every case runs in a browser context of its own, so no cookie, page or event carries over.
try {
	test('A wrong password gets 403, and the browser sends the issuer Origin and Sec-Fetch-Site same-origin', inOwnPage($browser, $dir, function (string $page) use ($browser, $authorize, $issuer, $dir): void {
		$seen = \count(originRequests($dir));
		$browser->navigate($page, $authorize);
		$browser->type($page, '#password', 'wrong-browser-password');
		submit($browser, $page, 'button[value="approve"]');
		$posts = \array_values(\array_filter(originRequests($dir, $seen), static fn(array $request): bool => $request['method'] === 'POST'));
		check(\count($posts) === 1 && $posts[0]['role'] === 'proxy', 'One approval POST: ' . \json_encode($posts));
		check($posts[0]['status'] === 403, 'Status ' . $posts[0]['status']);
		check($posts[0]['origin'] === $issuer, 'Origin ' . \var_export($posts[0]['origin'], true));
		check($posts[0]['fetch_site'] === 'same-origin', 'Sec-Fetch-Site ' . \var_export($posts[0]['fetch_site'], true));
		check(\array_filter(originRequests($dir, $seen), static fn(array $request): bool => $request['role'] === 'callback') === [], 'The callback was not reached');
	}));

	test('Approval redirects to the callback origin with code, state and issuer, no Referer and no form-action violation', inOwnPage($browser, $dir, function (string $page) use ($browser, $authorize, $issuer, $callbackUrl, $dir): void {
		$seen = \count(originRequests($dir));
		$browser->navigate($page, $authorize);
		$browser->type($page, '#password', PASSWORD);
		$url = submit($browser, $page, 'button[value="approve"]');
		check(\str_starts_with($url, $callbackUrl . '?'), 'Page after approval: ' . $url);
		$query = queryOf($url);
		check(\is_string($query['code'] ?? null) && $query['code'] !== '', 'Code');
		check(($query['state'] ?? null) === 'browser-state', 'State');
		check(($query['iss'] ?? null) === $issuer, 'Issuer');
		$callbacks = \array_values(\array_filter(originRequests($dir, $seen), static fn(array $request): bool => $request['role'] === 'callback'));
		check(\count($callbacks) === 1, 'One callback request: ' . \json_encode($callbacks));
		check($callbacks[0]['referer'] === null, 'Referer at the callback: ' . $callbacks[0]['referer']);
		$violations = takeFormActionViolations($browser, $page);
		check($violations === [], 'Form-action violations: ' . \implode(' | ', $violations));
	}));

	test('The approval cookie is set by the approval page and cleared by the approval', inOwnPage($browser, $dir, function (string $page) use ($browser, $authorize, $issuer, $callbackUrl): void {
		check(!hasApprovalCookie($browser, $page, $issuer), 'No cookie before the approval page');
		$browser->navigate($page, $authorize);
		check(hasApprovalCookie($browser, $page, $issuer), 'Cookie set');
		$browser->type($page, '#password', PASSWORD);
		$url = submit($browser, $page, 'button[value="approve"]');
		check(\str_starts_with($url, $callbackUrl . '?'), 'Page after approval: ' . $url);
		check(!hasApprovalCookie($browser, $page, $issuer), 'Cookie cleared');
	}));

	test('Denial redirects to the callback origin with access_denied, state and issuer, and no code', inOwnPage($browser, $dir, function (string $page) use ($browser, $authorize, $issuer, $callbackUrl): void {
		$browser->navigate($page, $authorize);
		$url = submit($browser, $page, 'button[value="deny"]');
		check(\str_starts_with($url, $callbackUrl . '?'), 'Page after denial: ' . $url);
		$query = queryOf($url);
		check(($query['error'] ?? null) === 'access_denied', 'Error ' . \var_export($query['error'] ?? null, true));
		check(($query['state'] ?? null) === 'browser-state', 'State');
		check(($query['iss'] ?? null) === $issuer, 'Issuer');
		check(!isset($query['code']), 'No code');
		$violations = takeFormActionViolations($browser, $page);
		check($violations === [], 'Form-action violations: ' . \implode(' | ', $violations));
	}));

	test('CSP form-action blocks a submission to an origin outside the issuer and the callbacks', inOwnPage($browser, $dir, function (string $page) use ($browser, $authorize, $issuer, $server): void {
		$browser->navigate($page, $authorize);

		// The app's plain-HTTP port is local and outside the HTTPS sources of form-action.
		$violation = $browser->evaluate($page, '(url => new Promise(resolve => {'
			. 'document.addEventListener("securitypolicyviolation", event => resolve({directive: event.effectiveDirective, blocked: event.blockedURI}), {once: true});'
			. 'setTimeout(() => resolve(null), 5000);'
			. 'const form = document.querySelector("form"); form.action = url; form.requestSubmit();'
			. '}))(' . \json_encode('http://127.0.0.1:' . $server->port . '/forbidden', \JSON_UNESCAPED_SLASHES) . ')');
		check(\is_array($violation) && $violation['directive'] === 'form-action', 'Violation ' . \json_encode($violation));
		$url = (string)$browser->evaluate($page, 'location.href');
		check(\str_starts_with($url, $issuer . '/mcp/oauth/authorize?'), 'Page after the blocked submission: ' . $url);
	}));

	test('No app logged an unexpected exception or PHP diagnostic', function (): void {
		foreach ($GLOBALS['oauthApps'] ?? [] as $appDir) {
			foreach (\glob($appDir . '/var/logs/http_err_*') ?: [] as $file) {
				check(\trim((string)\file_get_contents($file)) === '', \basename($file) . ': ' . \file_get_contents($file));
			}
		}
	});
} finally {
	$browser->close();
	$stopOrigins();
}

done();
