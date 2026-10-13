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
use CitOmni\Mcp\Support\OAuth\Config;
use CitOmni\Mcp\Support\Psr7Bridge;
use CitOmni\Mcp\Support\SdkLogger;
use CitOmni\Mcp\Support\ServerFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * McpController: HTTP adapter for the Model Context Protocol endpoint.
 *
 * Connects the CitOmni HTTP runtime to the MCP SDK, which serves both protocol eras and tells
 * them apart per request. The controller authenticates the request and sends the SDK's
 * response; what a tool does belongs to the tool.
 *
 * Rules:
 * - Authenticate before the SDK sees the request, and never give the SDK the Authorization
 *   header.
 * - Send every response through Psr7Bridge::emit(), so that nothing the Kernel appends can
 *   corrupt a JSON-RPC body.
 * - Let configuration errors reach the global error handler.
 */
final class McpController extends BaseController {

	/** Longest JSON-RPC method or tool name written to the access log. */
	private const int LOG_NAME_MAX = 128;


	/**
	 * Handle one MCP request.
	 *
	 * Authenticates the request, runs it through the SDK and sends the SDK's response.
	 *
	 * Behavior:
	 * - 401 with a WWW-Authenticate challenge and no body when the request carries no accepted
	 *   token:
	 *     - error="invalid_token" when it carries a token that was not accepted.
	 *     - With OAuth enabled, the challenge also names the protected-resource metadata and the
	 *       scope.
	 * - 403 when an OAuth access token arrives over another scheme, host or port than the
	 *   issuer's.
	 * - Otherwise the SDK's response to the request.
	 * - One access-log line per request when mcp.log.access is true, written before the response
	 *   is sent.
	 * - Ends the script after the response.
	 *
	 * Notes:
	 * - Assumes that the SDK answers a failing tool itself. Such a failure then reaches the log
	 *   through SdkLogger, never the global error handler.
	 *
	 * @return never
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When cfg mcp, the configured secrets or a tool definition are invalid, or OAuth is enabled and league/oauth2-server is missing.
	 * @see \CitOmni\Mcp\Policy\ClientAuth::evaluate()  Which tokens are accepted.
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
		if ($auth['oauth'] ?? false) {
			$issuer = (string)$mcp->oauth->issuer;
			if (!$this->app->request->isHttps() || $this->app->request->host() !== \parse_url($issuer, PHP_URL_HOST)
				|| $this->app->request->port() !== (\parse_url($issuer, PHP_URL_PORT) ?? 443)) {
				$response = $bridge->factory()->createResponse(403);
				$this->logAccess($mcp, $auth['client'], '', $response, $startNs);
				$bridge->emit($response);
			}
		}

		if (!$auth['authorized']) {
			$challenge = $auth['reason'] === 'invalid' ? 'Bearer realm="mcp", error="invalid_token"' : 'Bearer realm="mcp"';
			if (Config::enabled($this->app)) {
				$oauth = new Config($this->app);
				$challenge .= ', resource_metadata="' . $oauth->issuer . '/.well-known/oauth-protected-resource/mcp", scope="' . $oauth->values['scope'] . '"';
			}
			$response = $bridge->factory()->createResponse(401)->withHeader(
				'WWW-Authenticate',
				$challenge
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
		$response = $factory->server($logger, $auth['client'], $auth['oauth'] ?? false)->run($transport);

		// -- 3. Log and send ----------------------------------------------
		$this->logAccess($mcp, $auth['client'], $body, $response, $startNs);
		$bridge->emit($response);
	}


	/**
	 * Write the access-log line for this request, when enabled.
	 *
	 * Behavior:
	 * - Writes nothing when mcp.log.access is false.
	 * - Records the client, IP, HTTP method, JSON-RPC method, tool name, HTTP status, JSON-RPC
	 *   error code, whether the tool reported an error, and the duration in milliseconds.
	 * - Leaves out the fields that do not apply to the request.
	 *
	 * @param Cfg $mcp The mcp cfg node.
	 * @param string|null $client Secret key or OAuth identity that authenticated the request, or null.
	 * @param string $body Raw request body; '' when it was not read.
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
	 * @return array{0: string|null, 1: string|null} [method or "batch", tool name for tools/call], each cut to LOG_NAME_MAX bytes.
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
	 * @return array{0: int|null, 1: true|null} [error code, true when a tool result has isError]; both null for a body that is not seekable JSON.
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
