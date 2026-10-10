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

namespace CitOmni\Mcp\Controller;

use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Controller\BaseController;
use CitOmni\Mcp\Policy\ClientAuth;
use CitOmni\Mcp\Support\Psr7Bridge;
use CitOmni\Mcp\Support\SdkLogger;
use CitOmni\Mcp\Support\ServerFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * McpController: The Model Context Protocol endpoint, /mcp by default.
 *
 * One endpoint serves both protocol eras: the initialize handshake with
 * sessions (2024-11-05 ... 2025-11-25) and the stateless 2026-07-28 lifecycle.
 * The SDK decides per request which one answers.
 *
 * Behavior:
 * - Authenticates the bearer token before the SDK sees the request (see
 *   ClientAuth). A request without a valid token gets 401 with a
 *   WWW-Authenticate challenge and no body.
 * - Builds the server and its transport from cfg mcp (see ServerFactory), runs
 *   the request through them, and emits the SDK's response.
 * - Writes one access-log line per request when mcp.log.access is true, before
 *   the response is sent.
 * - Ends the script after the response, so nothing the Kernel appends (such as
 *   the ?_perf footer) can corrupt the JSON-RPC body.
 *
 * Notes:
 * - Tool failures never reach the global error handler. The SDK turns them
 *   into JSON-RPC results or errors and logs unexpected exceptions through
 *   SdkLogger (mcp.log.sdk_level).
 * - Configuration errors (McpConfigException) and failures to build the
 *   server bubble to the global error handler, which answers 500.
 * - The route allows POST and DELETE. The router answers OPTIONS itself and
 *   every other method with 405.
 */
final class McpController extends BaseController {

	/** Longest JSON-RPC method or tool name written to the access log. */
	private const int LOG_NAME_MAX = 128;


	/**
	 * Handle one MCP request.
	 *
	 * @return never
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When cfg mcp or the configured secrets are invalid.
	 */
	public function handle(): never {
		$startNs = \hrtime(true);
		$mcp = $this->app->cfg->mcp;
		$bridge = new Psr7Bridge($this->app);

		// -- 1. Authenticate ----------------------------------------------
		// Apache may deliver the header as REDIRECT_HTTP_AUTHORIZATION after an internal rewrite.
		$authorization = $this->app->request->header('Authorization')
			?: $this->app->request->server('REDIRECT_HTTP_AUTHORIZATION');
		$auth = (new ClientAuth($this->app))->evaluate(\is_string($authorization) ? $authorization : null);

		if (!$auth['authorized']) {
			$response = $bridge->factory()->createResponse(401)->withHeader(
				'WWW-Authenticate',
				$auth['reason'] === 'invalid' ? 'Bearer realm="mcp", error="invalid_token"' : 'Bearer realm="mcp"'
			);
			$this->logAccess($mcp, null, '', $response, $startNs);
			$bridge->emit($response);
		}

		// -- 2. Run the request through the SDK ---------------------------
		$body = $bridge->readBody((int)$mcp->http->max_body_bytes);
		$sdkLevel = $mcp->log->sdk_level;
		$logger = new SdkLogger($this->app, (string)$mcp->log->file, $sdkLevel === null ? null : (string)$sdkLevel);
		$factory = new ServerFactory($this->app);
		$transport = $factory->transport($bridge->request($body), $bridge->factory(), $logger);
		$response = $factory->server($logger)->run($transport);

		// -- 3. Log and send ----------------------------------------------
		$this->logAccess($mcp, $auth['client'], $body, $response, $startNs);
		$bridge->emit($response);
	}


	/**
	 * Write the access-log line for this request, when enabled.
	 *
	 * Behavior:
	 * - Records client (the matching secret key), IP, HTTP method, JSON-RPC
	 *   method, tool name, HTTP status, JSON-RPC error code, whether the tool
	 *   reported an error, and the duration in milliseconds.
	 * - Fields that do not apply to the request are left out.
	 *
	 * @param Cfg $mcp The mcp cfg node.
	 * @param string|null $client Secret key that authenticated the request, or null.
	 * @param string $body Raw request body ('' when not read).
	 * @param ResponseInterface $response Response about to be sent.
	 * @param int $startNs hrtime() at the start of the request.
	 * @return void
	 */
	private function logAccess(Cfg $mcp, ?string $client, string $body, ResponseInterface $response, int $startNs): void {
		if (!(bool)$mcp->log->access) {
			return;
		}

		[$rpc, $tool] = self::describeRequest($body);
		[$rpcError, $toolError] = self::describeResponse($response);
		$method = $this->app->request->method();

		$context = \array_filter([
			'client'     => $client,
			'ip'         => $this->app->request->ip(),
			'method'     => $method,
			'rpc'        => $rpc,
			'tool'       => $tool,
			'status'     => $response->getStatusCode(),
			'rpc_error'  => $rpcError,
			'tool_error' => $toolError,
			'ms'         => \round((\hrtime(true) - $startNs) / 1_000_000, 1),
		], static fn(mixed $value): bool => $value !== null);

		$this->app->log->write((string)$mcp->log->file, 'mcp.access', \trim(($rpc ?? $method) . ' ' . ($tool ?? '')), $context);
	}


	/**
	 * Extract the JSON-RPC method and tool name from a request body.
	 *
	 * @param string $body Raw request body.
	 * @return array{0: string|null, 1: string|null} [method or "batch", tool name for tools/call].
	 */
	private static function describeRequest(string $body): array {
		$message = $body === '' ? null : \json_decode($body, true);

		if (!\is_array($message)) {
			return [null, null];
		}

		if (\array_is_list($message)) {
			return ['batch', null];
		}

		$rpc = \is_string($message['method'] ?? null) ? \substr($message['method'], 0, self::LOG_NAME_MAX) : null;
		$tool = $rpc === 'tools/call' && \is_string($message['params']['name'] ?? null)
			? \substr($message['params']['name'], 0, self::LOG_NAME_MAX)
			: null;

		return [$rpc, $tool];
	}


	/**
	 * Extract the JSON-RPC error code and the tool error flag from a JSON response.
	 *
	 * @param ResponseInterface $response Response about to be sent.
	 * @return array{0: int|null, 1: true|null} [error code, true when a tool result has isError].
	 */
	private static function describeResponse(ResponseInterface $response): array {
		$body = $response->getBody();

		if (!\str_starts_with($response->getHeaderLine('Content-Type'), 'application/json') || !$body->isSeekable()) {
			return [null, null];
		}

		$message = \json_decode((string)$body, true);

		if (!\is_array($message) || \array_is_list($message)) {
			return [null, null];
		}

		$code = $message['error']['code'] ?? null;

		return [
			\is_int($code) ? $code : null,
			($message['result']['isError'] ?? false) === true ? true : null,
		];
	}

}
