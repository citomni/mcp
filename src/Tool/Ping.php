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

namespace CitOmni\Mcp\Tool;

use Mcp\Server\ClientGateway;

/**
 * Ping: Answer "pong" with the server time, to check the endpoint end to end.
 *
 * Registered in the package baseline as mcp.tools.ping. A successful call proves
 * that the client reaches the endpoint, that its token is accepted, and that the
 * SDK dispatches tool calls. Remove it with 'ping' => null in the app's mcp.tools.
 *
 * Typical usage:
 *   tools/call {"name": "ping", "arguments": {}}  ->  "pong 2026-10-10T08:52:00Z"
 */
final class Ping extends BaseTool {

	public const array DEFINITION = [
		'title'       => 'Ping',
		'description' => 'Check that this MCP server is reachable. Returns "pong" and the server time in UTC.',
		'inputSchema' => [
			'type'                 => 'object',
			'properties'           => [],
			'additionalProperties' => false,
		],
		'annotations' => [
			'readOnlyHint'  => true,
			'openWorldHint' => false,
		],
	];


	/**
	 * Return "pong" and the current UTC time.
	 *
	 * @param array<string, mixed> $arguments None expected.
	 * @param ClientGateway $gateway Unused.
	 * @return string "pong" followed by an ISO 8601 UTC timestamp.
	 */
	public function execute(array $arguments, ClientGateway $gateway): string {
		return 'pong ' . \gmdate('Y-m-d\TH:i:s\Z');
	}

}
