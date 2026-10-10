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

namespace CitOmni\Mcp\Tests\Support;

/**
 * PHP's built-in web server running a CitOmni app, for end-to-end MCP requests.
 *
 * Behavior:
 * - start() serves the app's public/ on 127.0.0.1 and a free port, with
 *   public/index.php as router, and waits until the port accepts connections.
 *   It returns null when the server cannot run here; $lastFailure says why.
 * - request() sends one raw HTTP/1.1 request and returns status, headers
 *   (lowercased names), and body. A chunked body is decoded.
 * - stop() terminates the process and is idempotent. start() also registers it
 *   as a shutdown function.
 *
 * Notes:
 * - The server runs with the same PHP binary and php.ini as the suite. Only the
 *   -d settings given to start() are added.
 * - Every request is a fresh PHP request in the server process, so nothing but
 *   files survives between requests, as under PHP-FPM.
 */
final class HttpServer {

	/** Why the last start() returned null. */
	public static string $lastFailure = '';

	/** @var resource|null Server process; null once stopped. */
	private $process;


	/**
	 * @param resource $process Server process.
	 * @param int $port Port on 127.0.0.1.
	 * @param string $log File that receives the server's output.
	 */
	private function __construct($process, public readonly int $port, private readonly string $log) {
		$this->process = $process;
	}


	/**
	 * Start the server for an app.
	 *
	 * @param string $appDir App root with public/index.php.
	 * @param array<string, string> $ini INI settings, passed with -d.
	 * @return self|null The running server, or null when it cannot be started.
	 */
	public static function start(string $appDir, array $ini = []): ?self {
		self::$lastFailure = '';
		$public = $appDir . '/public';
		$log = $appDir . '/server.log';

		for ($attempt = 1; $attempt <= 3; ++$attempt) {
			$port = self::freePort();

			if ($port === null) {
				self::$lastFailure = 'no free port on 127.0.0.1';

				return null;
			}

			$command = match (true) {
				\php_ini_loaded_file() !== false   => [\PHP_BINARY, '-c', \php_ini_loaded_file()],
				\php_ini_scanned_files() !== false => [\PHP_BINARY],
				default                            => [\PHP_BINARY, '-n'],
			};
			foreach ($ini as $name => $value) {
				\array_push($command, '-d', $name . '=' . $value);
			}

			\array_push($command, '-S', '127.0.0.1:' . $port, '-t', $public, $public . '/index.php');

			$process = @\proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);

			if (!\is_resource($process)) {
				self::$lastFailure = 'proc_open() failed';

				return null;
			}

			\fclose($pipes[0]);
			$server = new self($process, $port, $log);

			if ($server->awaitPort()) {
				\register_shutdown_function([$server, 'stop']);

				return $server;
			}

			$server->stop();
			self::$lastFailure = 'the server did not start (attempt ' . $attempt . '): ' . \trim(\substr($server->logTail(), -300));
		}

		return null;
	}


	/**
	 * Send one request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Path and query.
	 * @param array<string, string> $headers Request headers; Host defaults to 127.0.0.1:<port>.
	 * @param string $body Request body.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 * @throws \RuntimeException When the server does not answer with an HTTP response.
	 */
	public function request(string $method, string $path, array $headers = [], string $body = ''): array {
		$headers += ['Host' => '127.0.0.1:' . $this->port];
		$raw = "{$method} {$path} HTTP/1.1\r\n";

		foreach ($headers as $name => $value) {
			$raw .= "{$name}: {$value}\r\n";
		}

		$raw .= 'Content-Length: ' . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
		$response = $this->send($raw, 15.0);
		$split = $response === null ? false : \strpos($response, "\r\n\r\n");

		if ($split === false || \preg_match('~^HTTP/1\.[01] (\d{3})~', (string)$response, $m) !== 1) {
			throw new \RuntimeException("No HTTP response.\nResponse:\n" . \substr((string)$response, 0, 2000) . "\nServer log:\n" . $this->logTail());
		}

		$responseHeaders = [];

		foreach (\array_slice(\explode("\r\n", \substr($response, 0, $split)), 1) as $line) {
			[$name, $value] = \explode(':', $line, 2) + ['', ''];
			$name = \strtolower(\trim($name));
			$responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . \trim($value) : \trim($value);
		}

		$responseBody = \substr($response, $split + 4);

		if (\str_contains(\strtolower($responseHeaders['transfer-encoding'] ?? ''), 'chunked')) {
			$responseBody = self::dechunk($responseBody);
		}

		return ['status' => (int)$m[1], 'headers' => $responseHeaders, 'body' => $responseBody];
	}


	/**
	 * Stop the server. Safe to call more than once.
	 *
	 * @return void
	 */
	public function stop(): void {
		if ($this->process === null) {
			return;
		}

		@\proc_terminate($this->process);
		@\proc_close($this->process);
		$this->process = null;
	}


	/**
	 * Return the end of the server log.
	 *
	 * @return string Up to the last 2000 bytes.
	 */
	public function logTail(): string {
		return \is_file($this->log) ? \substr((string)\file_get_contents($this->log), -2000) : '';
	}


	/**
	 * Wait up to 5 seconds for the port to accept connections.
	 *
	 * @return bool True when it does; false when the server exited or never listened.
	 */
	private function awaitPort(): bool {
		$deadline = \hrtime(true) + 5_000_000_000;

		while (\hrtime(true) < $deadline) {
			if (!\proc_get_status($this->process)['running']) {
				return false;
			}

			$socket = @\stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.5);

			if ($socket !== false) {
				\fclose($socket);

				return true;
			}

			\usleep(50_000);
		}

		return false;
	}


	/**
	 * Send a raw request and read the whole response.
	 *
	 * @param string $request Raw HTTP request.
	 * @param float $timeout Connect and read timeout in seconds.
	 * @return string|null Raw response, or null when the connection failed.
	 */
	private function send(string $request, float $timeout): ?string {
		$socket = @\stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, $timeout);

		if ($socket === false) {
			return null;
		}

		\stream_set_timeout($socket, (int)\ceil($timeout));
		$offset = 0;
		$length = \strlen($request);

		while ($offset < $length) {
			$written = @\fwrite($socket, \substr($request, $offset));

			if ($written === false || $written === 0) {
				\fclose($socket);

				return null;
			}

			$offset += $written;
		}

		$response = \stream_get_contents($socket);
		\fclose($socket);

		return $response === false ? null : $response;
	}


	/**
	 * Decode a chunked transfer-encoded body.
	 *
	 * @param string $body Chunked body.
	 * @return string Decoded body.
	 */
	private static function dechunk(string $body): string {
		$out = '';

		while ($body !== '') {
			$eol = \strpos($body, "\r\n");

			if ($eol === false) {
				break;
			}

			$size = \hexdec(\trim(\explode(';', \substr($body, 0, $eol))[0]));

			if ($size === 0) {
				break;
			}

			$out .= \substr($body, $eol + 2, (int)$size);
			$body = (string)\substr($body, $eol + 2 + (int)$size + 2);
		}

		return $out;
	}


	/**
	 * Find a free port on 127.0.0.1.
	 *
	 * @return int|null Port number, or null when no socket can be bound.
	 */
	private static function freePort(): ?int {
		$socket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

		if ($socket === false) {
			return null;
		}

		$name = (string)\stream_socket_get_name($socket, false);
		\fclose($socket);
		$port = (int)\substr($name, (int)\strrpos($name, ':') + 1);

		return $port > 0 ? $port : null;
	}


}
