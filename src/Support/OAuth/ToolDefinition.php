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

/**
 * ToolDefinition: Support class that adds the OAuth security scheme to an SDK tool definition.
 *
 * OpenAI clients read securitySchemes on each tool to learn which tools need OAuth. This copy of
 * an SDK tool definition adds them, so that neither the SDK nor the tool classes need to know
 * about OAuth.
 */
final class ToolDefinition extends Tool {

	/**
	 * Copy an SDK tool definition and keep the scope that OAuth clients need for it.
	 *
	 * @param Tool $tool SDK tool definition to copy.
	 * @param string $scope OAuth scope that the tool requires.
	 */
	public function __construct(Tool $tool, private readonly string $scope) {
		parent::__construct($tool->name, $tool->title, $tool->inputSchema, $tool->description,
			$tool->annotations, $tool->icons, $tool->meta, $tool->outputSchema);
	}


	/**
	 * Serialize the definition with its OAuth requirement.
	 *
	 * @return array<string, mixed> The SDK's serialization with securitySchemes, mirrored in _meta.
	 */
	public function jsonSerialize(): array {
		$data = parent::jsonSerialize();
		$data['securitySchemes'] = [['type' => 'oauth2', 'scopes' => [$this->scope]]];
		$data['_meta']['securitySchemes'] = $data['securitySchemes'];
		return $data;
	}


}
