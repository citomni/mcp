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

/** Portable subprocess used to release concurrent requests against two workers. */
if (PHP_SAPI !== 'cli' || count($argv) !== 6) {
	exit(1);
}
[, $port, $input, $gate, $ready, $output] = $argv;
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 10;
while (!is_file($gate)) {
	if (microtime(true) > $deadline) {
		exit(2);
	}
	usleep(1000);
}
$body = file_get_contents($input);
$socket = stream_socket_client('tcp://127.0.0.1:' . (int)$port, $errno, $error, 10);
if ($socket === false) {
	exit(3);
}
stream_set_timeout($socket, 15);
$request = "POST /mcp/oauth/token HTTP/1.1\r\nHost: oauth.example.test\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: "
	. strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
fwrite($socket, $request);
file_put_contents($output, stream_get_contents($socket));
fclose($socket);
