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

namespace CitOmni\Mcp\Support\OAuth;

use Mcp\Schema\Tool;

/** Add OpenAI's per-tool OAuth metadata without changing the SDK or tool classes. */
final class ToolDefinition extends Tool {
	/**
	 * Retain the explicit dependencies and immutable entity values.
	 *
	 * @param Tool $tool Original SDK tool definition.
	 * @param string $scope Required OAuth scope.
	 */
	public function __construct(Tool $tool, private readonly string $scope) {
		parent::__construct($tool->name, $tool->title, $tool->inputSchema, $tool->description,
			$tool->annotations, $tool->icons, $tool->meta, $tool->outputSchema);
	}

	/**
	 * Extend the existing tool definition with its OAuth requirement.
	 *
	 * @return array Validated result.
	 */
	public function jsonSerialize(): array {
		$data = parent::jsonSerialize();
		$data['securitySchemes'] = [['type' => 'oauth2', 'scopes' => [$this->scope]]];
		$data['_meta']['securitySchemes'] = $data['securitySchemes'];
		return $data;
	}
}
