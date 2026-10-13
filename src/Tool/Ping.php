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
 * Ping: MCP tool that answers "pong" and the server time, to check the endpoint end to end.
 *
 * A successful call proves that the client reaches the endpoint, that its credentials are
 * accepted and that tool calls are dispatched. It reads nothing and changes nothing.
 */
final class Ping extends BaseTool {

	/** MCP definition of the tool: no arguments, read-only, no external systems. */
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
	 * @return string "pong" followed by an ISO 8601 UTC timestamp, such as "pong 2026-10-10T08:52:00Z".
	 */
	public function execute(array $arguments, ClientGateway $gateway): string {
		return 'pong ' . \gmdate('Y-m-d\TH:i:s\Z');
	}


}
