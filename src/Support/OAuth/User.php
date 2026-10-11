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

use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/** The configured owner, created only after password and consent validation. */
final class User implements UserEntityInterface {
	use EntityTrait;

	/**
	 * Retain the explicit dependencies and immutable entity values.
	 *
	 * @param string $id Server-generated token or family identifier.
	 */
	public function __construct(string $id) {
		$this->identifier = $id;
	}
}
