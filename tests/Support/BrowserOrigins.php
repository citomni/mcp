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

/*
 * The two HTTPS origins that a browser test sees, run as one process on 127.0.0.1: the issuer,
 * a TLS proxy that forwards every request unchanged to an app on PHP's built-in web server, and
 * a client callback that only records what reaches it.
 *
 * An OAuth issuer must be an HTTPS origin, and Secure cookies need one, while PHP's built-in web
 * server speaks plain HTTP. The proxy replaces only the Connection and Keep-Alive headers, with
 * Connection: close, so that the app receives what the browser sent.
 *
 * Usage:
 *   php BrowserOrigins.php <cert file> <key file> <backend port> <ready file> <log file>
 *
 * Notes:
 * - Neither origin asks the browser for a client certificate. A browser that holds client
 *   certificates, as Windows user stores often do, would stop at a certificate prompt that no one
 *   answers in headless mode.
 * - Writes {"proxy": <port>, "callback": <port>} to the ready file once both origins listen.
 * - Appends one JSON line per request to the log file: role (proxy or callback), method, target,
 *   status, and the Origin, Sec-Fetch-Site and Referer headers. Request bodies are never logged,
 *   since an approval carries the owner password.
 * - Both origins answer GET /favicon.ico with 204 and log nothing for it, so that the browser's
 *   own icon requests reach neither the app nor the log, whenever the browser makes them.
 * - The proxy answers 502 when the app cannot be reached or the exchange with it fails.
 * - Writes one line per connection event to standard error, with milliseconds since the origins
 *   started listening: accepted, TLS handshake complete or failed, closed before a complete
 *   request, and each request with method, path and response status.
 * - Runs until it is terminated or the ready file is removed, and for 10 minutes at most, so
 *   that no orphan outlives a test run that was killed.
 * - Exit codes: 0 stopped, 1 invalid arguments, 2 an origin cannot listen.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}

	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

if ($argc !== 6 || !\is_file($argv[1]) || !\is_file($argv[2]) || \preg_match('/^\d+$/', $argv[3]) !== 1) {
	\fwrite(\STDERR, "Usage: php BrowserOrigins.php <cert file> <key file> <backend port> <ready file> <log file>\n");
	exit(1);
}

[, $certFile, $keyFile, $backendPort, $readyFile, $logFile] = $argv;


// ----------------------------------------------------------------
// Request handling
// ----------------------------------------------------------------

/**
 * Write one connection event to standard error.
 *
 * @param string $text Event, such as "proxy #12: accepted".
 * @return void
 */
function event(string $text): void {
	static $start = null;
	$start ??= \hrtime(true);
	\fwrite(\STDERR, \sprintf("%6d ms  %s\n", \intdiv(\hrtime(true) - $start, 1_000_000), \str_replace(["\r", "\n"], ' ', $text)));
}


/**
 * Tell whether a raw request has its headers and its whole body.
 *
 * @param string $request Raw request received so far.
 * @return bool True when the request is complete.
 */
function isRequestComplete(string $request): bool {
	$end = \strpos($request, "\r\n\r\n");

	if ($end === false) {
		return false;
	}

	$length = \preg_match('/^Content-Length:\s*(\d+)\s*$/mi', \substr($request, 0, $end), $m) === 1 ? (int)$m[1] : 0;

	return \strlen($request) - $end - 4 >= $length;
}


/**
 * Forward a request to the app and return the app's response.
 *
 * @param string $request Complete raw request from the browser.
 * @param int $backendPort Port of the app on 127.0.0.1.
 * @param string $logFile Request log.
 * @return string Raw response for the browser.
 */
function proxy(string $request, int $backendPort, string $logFile): string {
	[$method, $target, $headers] = parseRequest($request);

	// One request per connection on both sides; every other header stays as the browser sent it.
	$end = \strpos($request, "\r\n\r\n");
	$head = (string)\preg_replace('/^(?:Connection|Keep-Alive):[^\r\n]*\r\n/mi', '', \substr($request, 0, $end + 2));
	$forward = $head . "Connection: close\r\n\r\n" . \substr($request, $end + 4);
	$response = '';
	$backend = @\stream_socket_client('tcp://127.0.0.1:' . $backendPort, $errno, $error, 10);

	// Socket errors are silenced and end in a 502, so that one failed request leaves the origins running.
	if ($backend !== false) {
		\stream_set_timeout($backend, 30);

		for ($offset = 0, $length = \strlen($forward); $offset < $length; $offset += $written) {
			$written = @\fwrite($backend, \substr($forward, $offset));

			if ($written === false || $written === 0) {
				break;
			}
		}

		$response = $offset < $length ? '' : (string)@\stream_get_contents($backend);
		\fclose($backend);
	}

	if (\preg_match('~^HTTP/1\.[01] (\d{3})~', $response, $m) === 1) {
		$status = (int)$m[1];
	} else {
		$status = 502;
		$response = "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
	}

	logRequest($logFile, 'proxy', $method, $target, $status, $headers);

	return $response;
}


/**
 * Record a callback request and answer it with a small page.
 *
 * @param string $request Complete raw request from the browser.
 * @param string $logFile Request log.
 * @return string Raw response for the browser.
 */
function callback(string $request, string $logFile): string {
	[$method, $target, $headers] = parseRequest($request);
	logRequest($logFile, 'callback', $method, $target, 200, $headers);
	$body = "<!doctype html>\n<title>Callback</title>\n<h1>Callback received</h1>\n";

	return "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: " . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
}


/**
 * Split a raw request into method, target and headers.
 *
 * @param string $request Complete raw request.
 * @return array{0: string, 1: string, 2: array<string, string>} Method, target, and headers with lowercased names.
 */
function parseRequest(string $request): array {
	$lines = \explode("\r\n", \substr($request, 0, (int)\strpos($request, "\r\n\r\n")));
	[$method, $target] = \explode(' ', \array_shift($lines), 3) + ['', ''];
	$headers = [];

	foreach ($lines as $line) {
		[$name, $value] = \explode(':', $line, 2) + ['', ''];
		$headers[\strtolower(\trim($name))] = \trim($value);
	}

	return [$method, $target, $headers];
}


/**
 * Append one request to the log.
 *
 * @param string $logFile Request log.
 * @param string $role proxy or callback.
 * @param string $method HTTP method.
 * @param string $target Request target.
 * @param int $status Status of the response.
 * @param array<string, string> $headers Request headers with lowercased names.
 * @return void
 */
function logRequest(string $logFile, string $role, string $method, string $target, int $status, array $headers): void {
	\file_put_contents($logFile, \json_encode([
		'role'       => $role,
		'method'     => $method,
		'target'     => $target,
		'status'     => $status,
		'origin'     => $headers['origin'] ?? null,
		'fetch_site' => $headers['sec-fetch-site'] ?? null,
		'referer'    => $headers['referer'] ?? null,
	], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES) . "\n", \FILE_APPEND);
}


// -- 1. Listen --------------------------------------------------------

// PHP's TLS servers ask for a client certificate unless verify_peer is false.
$tls = ['local_cert' => $certFile, 'local_pk' => $keyFile, 'verify_peer' => false];
$listeners = [];
$ports = [];

foreach (['proxy', 'callback'] as $role) {
	$listener = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $error, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, \stream_context_create(['ssl' => $tls]));

	if ($listener === false) {
		\fwrite(\STDERR, "Cannot listen for the {$role} origin: {$error}\n");
		exit(2);
	}

	\stream_set_blocking($listener, false);
	$listeners[$role] = $listener;
	$name = (string)\stream_socket_get_name($listener, false);
	$ports[$role] = (int)\substr($name, (int)\strrpos($name, ':') + 1);
}

// Write and rename, so that the suite never reads a partial file.
\file_put_contents($readyFile . '.tmp', \json_encode($ports, \JSON_THROW_ON_ERROR));
\rename($readyFile . '.tmp', $readyFile);
event('listening: proxy on port ' . $ports['proxy'] . ', callback on port ' . $ports['callback']);


// -- 2. Serve ---------------------------------------------------------

/** @var array<int, array{socket: resource, role: string, secure: bool, request: string}> $connections Open browser connections by stream id. */
$connections = [];
$stopAt = \hrtime(true) + 600_000_000_000;

while (\hrtime(true) < $stopAt) {
	\clearstatcache(true, $readyFile);

	if (!\is_file($readyFile)) {
		break;
	}

	$read = \array_values($listeners);

	foreach ($connections as $connection) {
		$read[] = $connection['socket'];
	}

	$write = null;
	$except = null;

	// An interrupted select is retried; the timeout lets the loop notice that the run is over.
	if (!@\stream_select($read, $write, $except, 0, 500_000)) {
		continue;
	}

	foreach ($read as $stream) {
		$role = \array_search($stream, $listeners, true);

		if ($role !== false) {
			$socket = @\stream_socket_accept($stream, 0);

			if ($socket !== false) {
				\stream_set_blocking($socket, false);
				$connections[(int)$socket] = ['socket' => $socket, 'role' => $role, 'secure' => false, 'request' => ''];
				event($role . ' #' . (int)$socket . ': accepted');
			}

			continue;
		}

		$id = (int)$stream;
		$name = $connections[$id]['role'] . ' #' . $id;

		// The TLS handshake runs in steps, so that a connection the browser opens ahead of
		// time and leaves idle blocks nobody.
		if (!$connections[$id]['secure']) {
			\error_clear_last();
			$handshake = @\stream_socket_enable_crypto($stream, true, \STREAM_CRYPTO_METHOD_TLS_SERVER);

			if ($handshake === 0) {
				continue;
			}

			if ($handshake === false) {
				event($name . ': TLS handshake failed: ' . \trim((string)(\error_get_last()['message'] ?? 'no reason given')));
				\fclose($stream);
				unset($connections[$id]);
				continue;
			}

			$connections[$id]['secure'] = true;
			event($name . ': TLS handshake complete');
		}

		// Read everything TLS has decrypted; select does not see data buffered inside OpenSSL.
		while (($chunk = @\fread($stream, 65536)) !== false && $chunk !== '') {
			$connections[$id]['request'] .= $chunk;
		}

		if (!isRequestComplete($connections[$id]['request'])) {
			if (\feof($stream)) {
				event($name . ': closed before a complete request');
				\fclose($stream);
				unset($connections[$id]);
			}

			continue;
		}

		$request = $connections[$id]['request'];
		$role = $connections[$id]['role'];
		unset($connections[$id]);
		\stream_set_blocking($stream, true);
		$response = match (true) {
			\str_starts_with($request, 'GET /favicon.ico ') => "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n",
			$role === 'proxy'                              => proxy($request, (int)$backendPort, $logFile),
			default                                        => callback($request, $logFile),
		};

		for ($offset = 0, $length = \strlen($response); $offset < $length; $offset += $written) {
			$written = @\fwrite($stream, \substr($response, $offset));

			if ($written === false || $written === 0) {
				break;
			}
		}

		@\fclose($stream);
		// The path without its query, so that no authorization code reaches the events.
		[$method, $target] = \explode(' ', (string)\strstr($request, "\r\n", true), 3) + ['', ''];
		event($name . ': ' . $method . ' ' . \explode('?', $target, 2)[0] . ' -> ' . \substr($response, 9, 3));
	}
}

exit(0);
