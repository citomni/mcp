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
use CitOmni\Kernel\Cfg;
use CitOmni\Mcp\Exception\McpConfigException;
use CitOmni\Mcp\Support\OAuth\ToolDefinition;
use CitOmni\Mcp\Tool\BaseTool;
use Mcp\Exception\InvalidArgumentException as SdkInvalidArgumentException;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Wire\CachePolicy;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * ServerFactory: Support class that builds the MCP SDK server and its HTTP transport from cfg mcp.
 *
 * Keeps the SDK wiring in one place, so that every request gets a server with the same identity,
 * capabilities, session store, caching hints and tools. Both are built per request.
 *
 * Rules:
 * - Advertise only the capability the package offers: tools.
 * - Name each tool by its mcp.tools key, and take everything else from the class's DEFINITION.
 * - Fail with McpConfigException, naming the cfg key or the tool, for invalid cfg.
 *
 * Typical usage:
 *   $factory   = new ServerFactory($this->app);
 *   $transport = $factory->transport($request, $psr17Factory, $logger);
 *   $response  = $factory->server($logger, $client, $oauth)->run($transport);
 */
final class ServerFactory {

	/**
	 * Create the factory for the app whose cfg mcp describes the server.
	 *
	 * @param App $app App whose cfg holds mcp.
	 */
	public function __construct(private readonly App $app) {
	}


	/**
	 * Build the MCP server with every configured tool.
	 *
	 * Behavior:
	 * - The server answers both protocol eras: the initialize handshake with file sessions and
	 *   the stateless 2026-07-28 lifecycle with caching hints for tools/list and server/discover.
	 * - Registers each mcp.tools entry, skipping null ones, with its handler constructed as
	 *   new $class($app, $client).
	 * - For a request that OAuth authenticated, each definition carries securitySchemes with
	 *   mcp.oauth.scope.
	 *
	 * @param LoggerInterface $logger Logger for the SDK.
	 * @param string|null $client Authenticated identity; null when authentication is disabled.
	 * @param bool $oauth Whether OAuth authenticated this request.
	 * @return Server Server ready for run().
	 * @throws McpConfigException When mcp.tools is invalid or a tool definition is rejected.
	 * @throws \Mcp\Exception\RuntimeException When the session directory cannot be created or written.
	 */
	public function server(LoggerInterface $logger, ?string $client = null, bool $oauth = false): Server {
		$cfg = $this->app->cfg->mcp;
		$ttlMs = (int)$cfg->cache->ttl_ms;

		$builder = Server::builder()
			->setServerInfo(
				name: (string)$cfg->server->name,
				version: (string)$cfg->server->version,
				title: self::optionalString($cfg->server->title),
			)
			->setInstructions(self::optionalString($cfg->server->instructions))
			// Explicit, so that no capability the package does not offer, such as logging, is advertised.
			->setCapabilities(new ServerCapabilities(tools: true, resources: false, prompts: false))
			->setSession(new FileSessionStore((string)$cfg->session->dir, (int)$cfg->session->ttl))
			->setCachePolicy(CachePolicy::none()
				->withMethod('tools/list', $ttlMs)
				->withMethod('server/discover', $ttlMs))
			->setLogger($logger);

		$oauthScope = $oauth ? (string)$cfg->oauth->scope : null;
		foreach ($this->tools() as $name => $class) {
			$definition = $this->definition($name, $class);
			if ($oauthScope !== null) {
				$definition = new ToolDefinition($definition, $oauthScope);
			}
			$builder->add($definition, new $class($this->app, $client));
		}

		return $builder->build();
	}


	/**
	 * Wrap a PSR-7 request in the SDK's Streamable HTTP transport.
	 *
	 * Behavior:
	 * - DNS rebinding protection is the only middleware. It accepts the hosts in
	 *   mcp.http.allowed_hosts and the host of CITOMNI_PUBLIC_ROOT_URL.
	 * - mcp.http.max_body_bytes is the transport's body limit.
	 *
	 * @param ServerRequestInterface $request The MCP request.
	 * @param ResponseFactoryInterface&StreamFactoryInterface $factory PSR-17 factory for responses and bodies.
	 * @param LoggerInterface $logger Logger for the SDK.
	 * @return StreamableHttpTransport Transport for Server::run().
	 * @throws McpConfigException When mcp.http.allowed_hosts is not a list of non-empty strings.
	 */
	public function transport(ServerRequestInterface $request, ResponseFactoryInterface&StreamFactoryInterface $factory, LoggerInterface $logger): StreamableHttpTransport {
		return new StreamableHttpTransport(
			request: $request,
			responseFactory: $factory,
			streamFactory: $factory,
			logger: $logger,
			middleware: [new DnsRebindingProtectionMiddleware($this->allowedHosts(), $factory, $factory)],
			maxBodyBytes: (int)$this->app->cfg->mcp->http->max_body_bytes,
		);
	}


	/**
	 * Read and check the tool map.
	 *
	 * @return array<string, class-string<BaseTool>> Tool name => class, without null entries.
	 * @throws McpConfigException When mcp.tools is not a map of names to BaseTool classes.
	 */
	private function tools(): array {
		$map = $this->app->cfg->mcp->tools;
		$map = $map instanceof Cfg ? $map->toArray() : $map;

		if (!\is_array($map) || ($map !== [] && \array_is_list($map))) {
			throw new McpConfigException('mcp.tools must map tool names to tool classes.');
		}

		$tools = [];

		foreach ($map as $name => $class) {
			if ($class === null) {
				continue;
			}

			if (!\is_string($name) || $name === '') {
				throw new McpConfigException('mcp.tools must be keyed by non-empty tool names.');
			}

			if (!\is_string($class) || !\is_subclass_of($class, BaseTool::class)) {
				throw new McpConfigException("mcp.tools.{$name} must name a class that extends " . BaseTool::class . '.');
			}

			$tools[$name] = $class;
		}

		return $tools;
	}


	/**
	 * Build the SDK tool definition from the class's DEFINITION.
	 *
	 * @param string $name Tool name: the mcp.tools key.
	 * @param class-string<BaseTool> $class Tool class.
	 * @return Tool SDK tool definition.
	 * @throws McpConfigException When DEFINITION contains "name" or the SDK rejects it.
	 */
	private function definition(string $name, string $class): Tool {
		$definition = $class::DEFINITION;

		if (\array_key_exists('name', $definition)) {
			throw new McpConfigException(
				"{$class}::DEFINITION must not contain \"name\"; the tool is named by its mcp.tools key \"{$name}\"."
			);
		}

		try {
			return Tool::fromArray(['name' => $name] + $definition);
		} catch (SdkInvalidArgumentException $e) {
			throw new McpConfigException("Invalid DEFINITION for tool \"{$name}\" ({$class}): " . $e->getMessage(), 0, $e);
		}
	}


	/**
	 * List the hosts that the DNS rebinding check accepts.
	 *
	 * @return list<string> mcp.http.allowed_hosts and the host of CITOMNI_PUBLIC_ROOT_URL when it is defined, without port and without duplicates.
	 * @throws McpConfigException When mcp.http.allowed_hosts is not a list of non-empty strings.
	 */
	private function allowedHosts(): array {
		$hosts = $this->app->cfg->mcp->http->allowed_hosts;
		$hosts = $hosts instanceof Cfg ? $hosts->toArray() : $hosts;

		if (!\is_array($hosts) || !\array_is_list($hosts)) {
			throw new McpConfigException('mcp.http.allowed_hosts must be a list of host names.');
		}

		if (\defined('CITOMNI_PUBLIC_ROOT_URL')) {
			$host = \parse_url((string)\CITOMNI_PUBLIC_ROOT_URL, \PHP_URL_HOST);

			if (\is_string($host) && $host !== '') {
				$hosts[] = $host;
			}
		}

		foreach ($hosts as $host) {
			if (!\is_string($host) || $host === '') {
				throw new McpConfigException('mcp.http.allowed_hosts must contain non-empty strings only.');
			}
		}

		return \array_values(\array_unique($hosts));
	}


	/**
	 * Map an optional cfg string to null when unset or empty.
	 *
	 * @param mixed $value Cfg value.
	 * @return string|null The string, or null for null and ''.
	 */
	private static function optionalString(mixed $value): ?string {
		return $value === null || $value === '' ? null : (string)$value;
	}


}
