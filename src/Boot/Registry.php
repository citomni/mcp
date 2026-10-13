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
 * Registry: Boot contributions of citomni/mcp, the MCP endpoint.
 *
 * Declares what the package adds to a host app as constant arrays: the HTTP cfg baseline, and
 * the HTTP routes of the MCP endpoint and of the optional OAuth authorization server. Merging
 * them with the host app's own maps, and deciding what wins, belongs to the kernel.
 *
 * Rules:
 * - Constants only: No IO, no runtime branching and no environment reads.
 * - Ship a baseline for every cfg path the package reads, with a comment on what each key
 *   controls and its unit.
 * - Ship defaults that are safe in every app. OAuth stays off until the host app enables it,
 *   and no OAuth route may serve anything while it is off.
 * - Keep names recognizable as the package's own: cfg under mcp and routes under /mcp, except
 *   the OAuth discovery paths that RFC 8414 and RFC 9728 place under /.well-known.
 */
final class Registry {

	// Services. Not used: tools, policies and helpers are instantiated explicitly.
	// public const array MAP_COMMON = [];
	// public const array MAP_HTTP = [];
	// public const array MAP_CLI = [];


	// Cfg baseline for both modes. Not used: only the HTTP endpoint reads mcp.
	// public const array CFG_COMMON = [];


	/** Cfg baseline for HTTP. */
	public const array CFG_HTTP = [

		'mcp' => [
			// Server identity in the initialize and server/discover results.
			'server' => [
				'name'         => 'citomni-mcp',  // Programmatic server name.
				'title'        => null,           // Display name; null falls back to name.
				'version'      => '1.0.0',        // Version of the app's MCP server, as reported to clients.
				// Usage hint that clients may add to the model's context, such as which tool to
				// start with; null sends none.
				'instructions' => null,
			],


			// Tool name => class extending \CitOmni\Mcp\Tool\BaseTool. The name is what clients
			// see. The map merges like any cfg node, so an app adds its own tools next to these,
			// and null removes a tool that an earlier layer contributed.
			'tools' => [
				'ping' => \CitOmni\Mcp\Tool\Ping::class,  // Built-in end-to-end connection check.
			],


			'auth' => [
				// Keys in the secrets service whose values are accepted as bearer tokens. Keys
				// missing from the store are skipped, so the second one can stay unset until a
				// token is rotated. A list, so the app replaces it rather than merging. []
				// disables fixed tokens; access is anonymous only if OAuth is disabled too.
				'secret_keys' => ['mcp.client_token.1', 'mcp.client_token.2'],
			],


			// Optional single-owner OAuth authorization server for clients that cannot send a
			// fixed bearer token. Fixed-token requests never load it. Enabling it requires
			// league/oauth2-server in the host app. The *_secret_key entries name keys in the
			// secrets service; the secret values never live in cfg.
			'oauth' => [

				// true serves the OAuth routes and accepts OAuth access tokens at /mcp. Must be
				// true or false.
				'enabled'                   => false,

				// HTTPS origin of the app without path or trailing slash, such as
				// https://www.example.com. It is the issuer, and <issuer>/mcp is the protected
				// resource. OAuth requests must arrive with its scheme, host and port. Required
				// when OAuth is enabled.
				'issuer'                    => null,

				// scope, owner_id and client_id: ASCII letters, digits, ".", "_" and "-", at most
				// 100 characters each.
				'scope'                     => 'mcp.access',  // The one OAuth scope; it grants every tool in mcp.tools.
				'owner_id'                  => 'owner',       // The one resource owner; part of the client identity oauth:<owner_id>:<client_id>.
				'client_id'                 => 'chatgpt',     // Client ID of the one pre-registered confidential client.
				'client_name'               => 'ChatGPT',     // Client name shown on the approval page.

				// Exact HTTPS callback URLs of the client, without query or fragment, compared
				// byte for byte. A list, so the app replaces it. At least one is required when
				// OAuth is enabled.
				'redirect_uris'             => [],

				// What approval grants, shown on the approval page.
				'consent_text'              => 'Allow this client to use the tools exposed by this MCP server.',

				// Labels of the approval page, so a host can translate it.
				'texts'                     => [
					'lang'             => 'en',                                 // HTML lang attribute of the page.
					'title'            => 'Approve access',                     // Page title.
					'heading'          => 'Allow {client} to connect?',         // Heading; {client} becomes client_name.
					'password'         => 'Your connection approval password',  // Label of the password field.
					'approve'          => 'Approve access',                     // Approve button.
					'deny'             => 'Deny',                               // Deny button.
					'invalid_password' => 'The password was not accepted.',     // Message after a rejected password.
				],

				// Keys in the secrets service. Each one holds the value named in its comment.
				'owner_password_secret_key' => 'mcp.oauth.owner_password_hash',  // password_hash() of the owner's approval password.
				'client_secret_key'         => 'mcp.oauth.client_secret',        // The client secret.
				'private_key_secret_key'    => 'mcp.oauth.private_key',          // PEM RSA key that signs access tokens (RS256).
				'public_key_secret_key'     => 'mcp.oauth.public_key',           // Matching PEM public key that verifies them.
				'encryption_secret_key'     => 'mcp.oauth.encryption_key',       // Defuse key that encrypts codes and refresh tokens.

				// Directory of the OAuth ledger and its lock file. It must be outside the public
				// directory, writable by PHP and kept across deployments.
				'state_dir'                 => \CITOMNI_APP_PATH . '/var/state/mcp_oauth',
				'access_ttl'                => 900,        // Access token lifetime in seconds, 60 to 3,600.
				'refresh_ttl'               => 2_592_000,  // Refresh token lifetime in seconds, renewed on every rotation, 3,600 to 2,592,000.
				'approval_ttl'              => 300,        // Seconds an approval page stays usable, 60 to 600.
			],


			'http' => [
				// Hosts accepted in Origin or Host besides the host of CITOMNI_PUBLIC_ROOT_URL
				// (DNS rebinding protection), without port. A list, so the app replaces it.
				'allowed_hosts'  => [],
				'max_body_bytes' => 4_194_304,  // Largest request body read, in bytes. A larger body gets 413.
			],


			// Sessions of handshake-era clients (initialize, protocol 2025-11-25 and older), one
			// file each. 2026-07-28 clients have no sessions.
			'session' => [
				'dir' => \CITOMNI_APP_PATH . '/var/state/mcp_sessions',  // Directory of the session files.
				'ttl' => 3600,                                           // Seconds a session lives after its last request.
			],


			'cache' => [
				// How long 2026-07-28 clients may cache tools/list and server/discover, in
				// milliseconds. Handshake-era clients get no caching hints.
				'ttl_ms' => 300_000,
			],


			'log' => [
				'file'      => 'mcp',  // Log file for access, SDK and OAuth audit lines: var/logs/<file>.jsonl.
				'access'    => true,   // true writes one access line per request.

				// Lowest PSR-3 level the SDK logs at (debug ... emergency); null logs nothing. From
				// info down, the raw messages of handshake-era clients are logged, tool arguments
				// included.
				'sdk_level' => 'error',
			],
		],
	];


	/** HTTP routes. */
	public const array ROUTES_HTTP = [

		// MCP endpoint. POST carries every MCP message; DELETE ends a handshake-era session.
		'/mcp' => [
			'controller' => \CitOmni\Mcp\Controller\McpController::class,
			'action'     => 'handle',
			'methods'    => ['POST', 'DELETE'],
		],


		// OAuth discovery, approval and token endpoints. They must serve nothing while
		// mcp.oauth.enabled is false.

		// Protected-resource metadata of /mcp (RFC 9728).
		'/.well-known/oauth-protected-resource/mcp' => [
			'controller' => \CitOmni\Mcp\Controller\OAuthController::class,
			'action'     => 'resourceMetadata',
			'methods'    => ['GET'],
		],

		// The same metadata at the root, which MCP clients request when the path-specific URL
		// fails and the 401 named none. A client that follows RFC 9728 alone never requests it
		// for this resource, so the resource value <issuer>/mcp is correct here too.
		'/.well-known/oauth-protected-resource' => [
			'controller' => \CitOmni\Mcp\Controller\OAuthController::class,
			'action'     => 'resourceMetadata',
			'methods'    => ['GET'],
		],

		// Authorization server metadata (RFC 8414).
		'/.well-known/oauth-authorization-server' => [
			'controller' => \CitOmni\Mcp\Controller\OAuthController::class,
			'action'     => 'metadata',
			'methods'    => ['GET'],
		],

		// Owner approval: GET shows the page, POST takes the decision.
		'/mcp/oauth/authorize' => [
			'controller' => \CitOmni\Mcp\Controller\OAuthController::class,
			'action'     => 'authorize',
			'methods'    => ['GET', 'POST'],
		],

		// Code exchange and refresh rotation.
		'/mcp/oauth/token' => [
			'controller' => \CitOmni\Mcp\Controller\OAuthController::class,
			'action'     => 'token',
			'methods'    => ['POST'],
		],
	];


	// Cfg baseline for CLI. Not used: the package has no CLI commands.
	// public const array CFG_CLI = [];


	// CLI commands. Not used: the package contributes none.
	// public const array COMMANDS_CLI = [];


}
