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
 * Test tool: fail the way a tool reports an error the model should see.
 */
final class FailingTool extends BaseTool {

	public const string MESSAGE = 'The file src/Missing.php does not exist at this commit.';

	public const array DEFINITION = [
		'description' => 'Always fail with a tool error.',
		'inputSchema' => ['type' => 'object', 'properties' => []],
	];


	/**
	 * @param array<string, mixed> $arguments Unused.
	 * @param ClientGateway $gateway Unused.
	 * @return never
	 * @throws ToolCallException Always.
	 */
	public function execute(array $arguments, ClientGateway $gateway): never {
		throw new ToolCallException(self::MESSAGE);
	}

}
