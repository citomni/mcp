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

namespace CitOmni\Mcp\Support;

use CitOmni\Kernel\App;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Psr7Bridge: Support class that translates between the CitOmni HTTP runtime and PSR-7.
 *
 * Gives the libraries that speak PSR-7 the request they need, built from the Request service,
 * and sends their responses itself. It uses nyholm/psr7 directly, so that
 * php-http/discovery never has to look for a PSR-17 implementation.
 *
 * Rules:
 * - Never put the Authorization or Cookie header into a request for a library.
 * - Read the body from php://input only, bounded by the caller's limit.
 *
 * Typical usage:
 *   $bridge  = new Psr7Bridge($this->app);
 *   $request = $bridge->request($bridge->readBody(4_194_304));
 *   $bridge->emit($response);
 */
final class Psr7Bridge {

	/** Bytes per read when emitting a body. */
	private const int CHUNK_BYTES = 8192;

	/** PSR-17 factory for every message of this request. */
	private readonly Psr17Factory $factory;


	/**
	 * Create the bridge for the app whose Request service describes the request.
	 *
	 * @param App $app App with the Request service.
	 */
	public function __construct(private readonly App $app) {
		$this->factory = new Psr17Factory();
	}


	/**
	 * Return the PSR-17 factory used for every message of this request.
	 *
	 * @return Psr17Factory Response, stream, request and URI factory.
	 */
	public function factory(): Psr17Factory {
		return $this->factory;
	}


	/**
	 * Read the raw request body.
	 *
	 * Reads at most one byte more than the limit, so that a reader can still tell that a body
	 * is too large.
	 *
	 * @param int $limit Largest body accepted, in bytes.
	 * @return string The body, at most $limit + 1 bytes.
	 * @throws \RuntimeException When php://input cannot be read.
	 */
	public function readBody(int $limit): string {
		$body = \file_get_contents('php://input', false, null, 0, \min($limit, \PHP_INT_MAX - 1) + 1);

		if ($body === false) {
			throw new \RuntimeException('Cannot read the request body from php://input.');
		}

		return $body;
	}


	/**
	 * Build the PSR-7 server request from the Request service.
	 *
	 * @param string $body Raw request body from readBody().
	 * @return ServerRequestInterface The request with method, full URL, headers and protocol version, without the Authorization and Cookie headers.
	 */
	public function request(string $body): ServerRequestInterface {
		$request = $this->app->request;

		$headers = $request->headers();
		unset($headers['authorization'], $headers['cookie']);

		$protocol = (string)$request->server('SERVER_PROTOCOL', 'HTTP/1.1');
		$version = \str_starts_with($protocol, 'HTTP/') ? \substr($protocol, 5) : '1.1';

		return new ServerRequest($request->method(), $request->fullUrl(), $headers, $body, $version);
	}


	/**
	 * Send a PSR-7 response and end the script.
	 *
	 * Behavior:
	 * - Discards every removable output buffer first, so that anything printed earlier in the
	 *   request, such as a rendered notice, cannot corrupt the body.
	 * - Sends the headers, then the status, then the body. A streamed body, such as an SSE
	 *   stream, writes and flushes itself while it is read.
	 *
	 * @param ResponseInterface $response Response to send.
	 * @return never
	 * @throws \RuntimeException When output was already sent, so status and headers cannot be.
	 */
	public function emit(ResponseInterface $response): never {
		if (\headers_sent($file, $line)) {
			throw new \RuntimeException("Cannot send the MCP response: output started at {$file}:{$line}.");
		}

		// Pop every removable buffer. A non-removable one (zlib.output_compression, or a
		// buffer started without PHP_OUTPUT_HANDLER_REMOVABLE) cannot be popped: discard
		// its content when possible and stop.
		while (($level = \ob_get_level()) > 0) {
			if (@\ob_end_clean() && \ob_get_level() < $level) {
				continue;
			}

			@\ob_clean();
			break;
		}

		foreach ($response->getHeaders() as $name => $values) {
			$replace = true;

			foreach ($values as $value) {
				\header($name . ': ' . $value, $replace);
				$replace = false;
			}
		}

		// After the headers, so a Location header cannot change the status.
		\http_response_code($response->getStatusCode());

		$body = $response->getBody();

		if ($body->isSeekable()) {
			$body->rewind();
		}

		while (!$body->eof()) {
			echo $body->read(self::CHUNK_BYTES);
		}

		exit;
	}


}
