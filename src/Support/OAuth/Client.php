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

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/** A single pre-registered confidential OAuth client. */
final class Client implements ClientEntityInterface {
	use ClientTrait;
	use EntityTrait;

	/**
	 * Retain the explicit dependencies and immutable entity values.
	 *
	 * @param string $id Server-generated token or family identifier.
	 * @param string $name User-facing client name.
	 * @param array $redirectUris Exact registered HTTPS callbacks.
	 */
	public function __construct(string $id, string $name, array $redirectUris) {
		$this->identifier = $id;
		$this->name = $name;
		$this->redirectUri = $redirectUris;
		$this->isConfidential = true;
	}
}
