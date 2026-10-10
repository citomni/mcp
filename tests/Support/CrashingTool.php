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
 * Test tool: fail the way a bug does, with an exception the client must not see.
 */
final class CrashingTool extends BaseTool {

	public const string MESSAGE = 'Crashed on purpose: /srv/secret/path';

	public const array DEFINITION = [
		'description' => 'Always fail with an unexpected exception.',
		'inputSchema' => ['type' => 'object', 'properties' => []],
	];


	/**
	 * @param array<string, mixed> $arguments Unused.
	 * @param ClientGateway $gateway Unused.
	 * @return never
	 * @throws \RuntimeException Always.
	 */
	public function execute(array $arguments, ClientGateway $gateway): never {
		throw new \RuntimeException(self::MESSAGE);
	}

}
