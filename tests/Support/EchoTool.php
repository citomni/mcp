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
 * Test tool: return the "text" argument unchanged.
 *
 * Its schema requires "text" and allows nothing else, so the suites can send
 * arguments the SDK must refuse before execute() runs.
 */
final class EchoTool extends BaseTool {

	public const array DEFINITION = [
		'description' => 'Return the given text unchanged.',
		'inputSchema' => [
			'type'                 => 'object',
			'properties'           => ['text' => ['type' => 'string']],
			'required'             => ['text'],
			'additionalProperties' => false,
		],
		'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
	];


	/**
	 * @param array<string, mixed> $arguments ['text' => string].
	 * @param ClientGateway $gateway Unused.
	 * @return string The text.
	 */
	public function execute(array $arguments, ClientGateway $gateway): string {
		return $arguments['text'];
	}

}
