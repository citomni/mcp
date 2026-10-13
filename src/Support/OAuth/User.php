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

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * User: League entity for the one configured resource owner.
 *
 * Rules:
 * - Create it only after the owner's decision has been validated, and for an approval only after
 *   the owner's password has been verified.
 */
final class User implements UserEntityInterface {
	use EntityTrait;


	/**
	 * Describe the configured owner.
	 *
	 * @param string $id Owner identifier.
	 */
	public function __construct(string $id) {
		$this->identifier = $id;
	}


}
