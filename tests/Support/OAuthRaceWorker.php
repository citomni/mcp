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
 * Worker process of the OAuth redemption races: sends one token request when a gate opens, so
 * that two workers redeem the same grant at two servers of one app at the same moment.
 *
 * Usage:
 *   php OAuthRaceWorker.php <port> <input file> <gate file> <ready file> <output file>
 *
 * Notes:
 * - Reads the form body from the input file, writes the ready file, and waits up to 10 seconds
 *   for the gate file. It then posts the body to /mcp/oauth/token on 127.0.0.1:<port> and writes
 *   the raw response to the output file.
 * - The body is passed as a file, so that no grant appears on a command line.
 * - Exit codes: 0 response written, 1 invalid arguments, 2 no gate within 10 seconds,
 *   3 no connection.
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

if ($argc !== 6 || \preg_match('/^\d+$/', $argv[1]) !== 1 || !\is_file($argv[2])) {
	\fwrite(\STDERR, "Usage: php OAuthRaceWorker.php <port> <input file> <gate file> <ready file> <output file>\n");
	exit(1);
}

[, $port, $input, $gate, $ready, $output] = $argv;

// Read the body before reporting ready, so that nothing but the request follows the gate.
$body = (string)\file_get_contents($input);
\file_put_contents($ready, 'ready');
$deadline = \hrtime(true) + 10_000_000_000;

while (!\is_file($gate)) {
	if (\hrtime(true) > $deadline) {
		\fwrite(\STDERR, "No gate within 10 seconds.\n");
		exit(2);
	}

	\usleep(1000);
}

$socket = @\stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 10);

if ($socket === false) {
	\fwrite(\STDERR, "Cannot connect to 127.0.0.1:{$port}: {$error}\n");
	exit(3);
}

// Assumes that the app's issuer host is oauth.example.test.
\stream_set_timeout($socket, 15);
\fwrite($socket, "POST /mcp/oauth/token HTTP/1.1\r\nHost: oauth.example.test\r\nContent-Type: application/x-www-form-urlencoded\r\n"
	. 'Content-Length: ' . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
\file_put_contents($output, (string)\stream_get_contents($socket));
\fclose($socket);
