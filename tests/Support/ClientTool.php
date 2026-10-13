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

use CitOmni\Mcp\Tool\BaseTool;
use Mcp\Server\ClientGateway;

/**
 * ClientTool: Test tool that reports which credential authenticated the request.
 *
 * Lets the suites check the client identity a tool receives: the secret key of a fixed token,
 * the OAuth identity, or none when authentication is disabled.
 */
final class ClientTool extends BaseTool {

	/** MCP definition of the tool: no arguments, read-only, no external systems. */
	public const array DEFINITION = [
		'description' => 'Return the secret key or OAuth identity that authenticated the request.',
		'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
		'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
	];


	/**
	 * Return the identity that authenticated the request.
	 *
	 * @param array<string, mixed> $arguments None expected.
	 * @param ClientGateway $gateway Unused.
	 * @return string The secret key or OAuth identity, or "none" when authentication is disabled.
	 */
	public function execute(array $arguments, ClientGateway $gateway): string {
		return $this->client ?? 'none';
	}


}
