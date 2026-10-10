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

namespace CitOmni\Mcp\Boot;

/**
 * Declare this provider package's boot contributions.
 *
 * CitOmni reads these constants when composing the active HTTP or CLI app.
 * All supported contribution constants are documented below but intentionally
 * left undeclared. Uncomment only those the concrete package actually needs.
 *
 * Behavior:
 * - MAP_COMMON contributes services shared by HTTP and CLI.
 * - MAP_HTTP and MAP_CLI contribute mode-specific services.
 * - CFG_COMMON contributes configuration shared by HTTP and CLI.
 * - CFG_HTTP and CFG_CLI contribute mode-specific configuration.
 * - ROUTES_HTTP contributes HTTP dispatch entries.
 * - COMMANDS_CLI contributes CLI dispatch entries.
 *
 * Notes:
 * - Service definitions may be an FQCN string or an array containing "class"
 *   and optional "options".
 * - Within one provider, mode-specific service definitions override shared
 *   service definitions with the same service ID.
 * - Within one provider, mode-specific configuration is merged after shared
 *   configuration, so mode-specific values win on conflicting associative keys.
 * - HTTP routes and CLI commands are dispatch maps, not configuration values.
 * - Keep SQL in Repositories and transport concerns in Controllers or Commands.
 */
final class Registry {

	/**
	 * Services available in both HTTP and CLI mode.
	 *
	 * Typical entries use either a service class directly or a class/options
	 * definition. Services are resolved lazily through the App service map.
	 *
	 * @var array<string, class-string|array{class: class-string, options?: array<array-key, mixed>}>
	 */
	// public const array MAP_COMMON = [
	// ];

	/**
	 * Services available only in HTTP mode.
	 *
	 * Use this only when a service genuinely depends on the HTTP runtime.
	 * Definitions here take precedence over MAP_COMMON for the same service ID.
	 *
	 * @var array<string, class-string|array{class: class-string, options?: array<array-key, mixed>}>
	 */
	// public const array MAP_HTTP = [
	// ];

	/**
	 * Services available only in CLI mode.
	 *
	 * Use this only when a service genuinely depends on the CLI runtime.
	 * Definitions here take precedence over MAP_COMMON for the same service ID.
	 *
	 * @var array<string, class-string|array{class: class-string, options?: array<array-key, mixed>}>
	 */
	// public const array MAP_CLI = [
	// ];

	/**
	 * Configuration defaults shared by HTTP and CLI mode.
	 *
	 * Keep package-owned defaults under package-specific keys. Concrete host apps
	 * may override provider configuration through the normal CitOmni config flow.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array CFG_COMMON = [
	// ];

	/**
	 * Configuration defaults used only in HTTP mode.
	 *
	 * These values are merged after CFG_COMMON and therefore win on conflicting
	 * associative keys within this provider.
	 *
	 * @var array<string|int, mixed>
	 */
	public const array CFG_HTTP = [
		'mcp' => [
			// Server identity in the initialize and server/discover results. title is
			// the display name and falls back to name when null. instructions is a
			// usage hint that clients may add to the model's context.
			'server' => [
				'name'         => 'citomni-mcp',
				'title'        => null,
				'version'      => '1.0.0',
				'instructions' => null,
			],

			// Tool name => class extending \CitOmni\Mcp\Tool\BaseTool. The map merges
			// like any cfg node, so an app adds its own tools next to these, and null
			// removes a tool that an earlier layer contributed.
			'tools' => [
				'ping' => \CitOmni\Mcp\Tool\Ping::class,
			],

			// Keys in the secrets store (var/secrets/app.secret.<env>.php) whose values
			// are accepted as bearer tokens. Keys missing from the store are skipped,
			// so the second one can stay unset until a token is rotated. The list is
			// replaced, not merged, by the app; [] disables authentication.
			'auth' => [
				'secret_keys' => ['mcp.client_token.1', 'mcp.client_token.2'],
			],

			'http' => [
				// Hosts accepted in Origin or Host besides the host of
				// CITOMNI_PUBLIC_ROOT_URL (DNS rebinding protection).
				'allowed_hosts'  => [],
				// Largest request body read, in bytes. A larger body gets 413.
				'max_body_bytes' => 4_194_304,
			],

			// Sessions of handshake-era clients (initialize, protocol 2025-11-25 and
			// older), one file each. ttl is in seconds since the session's last
			// request. 2026-07-28 clients have no sessions.
			'session' => [
				'dir' => \CITOMNI_APP_PATH . '/var/state/mcp_sessions',
				'ttl' => 3600,
			],

			// How long 2026-07-28 clients may cache tools/list and server/discover, in
			// milliseconds. Handshake-era clients get no caching hints.
			'cache' => [
				'ttl_ms' => 300_000,
			],

			'log' => [
				// File name for log->write(): var/logs/mcp.jsonl.
				'file'      => 'mcp',
				// One line per request: client, JSON-RPC method, tool, status, duration.
				'access'    => true,
				// Lowest PSR-3 level the SDK logs at (debug ... emergency); null logs
				// nothing. From info down, the raw messages of handshake-era clients
				// are logged, tool arguments included.
				'sdk_level' => 'error',
			],
		],
	];

	/**
	 * Configuration defaults used only in CLI mode.
	 *
	 * These values are merged after CFG_COMMON and therefore win on conflicting
	 * associative keys within this provider.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array CFG_CLI = [
	// ];

	/**
	 * HTTP route dispatch entries contributed by this provider.
	 *
	 * Keep route definitions here rather than inside CFG_COMMON or CFG_HTTP.
	 * The concrete route entry contract is owned by the CitOmni HTTP layer.
	 *
	 * @var array<string|int, mixed>
	 */
	public const array ROUTES_HTTP = [
		// POST carries every MCP message; DELETE ends a handshake-era session.
		// The router answers OPTIONS itself and GET with 405.
		'/mcp' => [
			'controller' => \CitOmni\Mcp\Controller\McpController::class,
			'action'     => 'handle',
			'methods'    => ['POST', 'DELETE'],
		],
	];

	/**
	 * CLI command dispatch entries contributed by this provider.
	 *
	 * Keep command definitions here rather than inside MAP_CLI, CFG_COMMON, or
	 * CFG_CLI. The concrete command entry contract is owned by the CitOmni CLI layer.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array COMMANDS_CLI = [
	// ];


}
