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
 * Test tool: return the secret key that authenticated the request, or "none".
 */
final class ClientTool extends BaseTool {

	public const array DEFINITION = [
		'description' => 'Return the secret key that authenticated the request.',
		'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
		'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
	];


	/**
	 * @param array<string, mixed> $arguments Unused.
	 * @param ClientGateway $gateway Unused.
	 * @return string The secret key, or "none" when authentication is disabled.
	 */
	public function execute(array $arguments, ClientGateway $gateway): string {
		return $this->client ?? 'none';
	}

}
