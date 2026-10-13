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
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * FailingTool: Test tool that fails the way a tool reports an error to the model.
 *
 * Throws a tool error, so the suites can check that the client receives its message unchanged.
 */
final class FailingTool extends BaseTool {

	/** Message of the tool error, meant for the model. */
	public const string MESSAGE = 'The file src/Missing.php does not exist at this commit.';

	/** MCP definition of the tool: no arguments. */
	public const array DEFINITION = [
		'description' => 'Always fail with a tool error.',
		'inputSchema' => ['type' => 'object', 'properties' => []],
	];


	/**
	 * Fail with a tool error.
	 *
	 * @param array<string, mixed> $arguments Unused.
	 * @param ClientGateway $gateway Unused.
	 * @return never
	 * @throws \Mcp\Exception\ToolCallException Always, with MESSAGE.
	 */
	public function execute(array $arguments, ClientGateway $gateway): never {
		throw new ToolCallException(self::MESSAGE);
	}


}
