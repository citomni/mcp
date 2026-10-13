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
 * CrashingTool: Test tool that fails the way a bug does.
 *
 * Throws an unexpected exception whose message holds a server path, so the suites can check that
 * the client never sees it.
 */
final class CrashingTool extends BaseTool {

	/** Message of the exception, with a server path that no client may see. */
	public const string MESSAGE = 'Crashed on purpose: /srv/secret/path';

	/** MCP definition of the tool: no arguments. */
	public const array DEFINITION = [
		'description' => 'Always fail with an unexpected exception.',
		'inputSchema' => ['type' => 'object', 'properties' => []],
	];


	/**
	 * Fail with an unexpected exception.
	 *
	 * @param array<string, mixed> $arguments Unused.
	 * @param ClientGateway $gateway Unused.
	 * @return never
	 * @throws \RuntimeException Always, with MESSAGE.
	 */
	public function execute(array $arguments, ClientGateway $gateway): never {
		throw new \RuntimeException(self::MESSAGE);
	}


}
