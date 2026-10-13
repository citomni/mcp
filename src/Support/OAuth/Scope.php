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

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

/**
 * Scope: League entity for the one configured OAuth scope.
 */
final class Scope implements ScopeEntityInterface {
	use EntityTrait;
	use ScopeTrait;


	/**
	 * Describe the configured scope.
	 *
	 * @param string $id Scope identifier.
	 */
	public function __construct(string $id) {
		$this->identifier = $id;
	}


}
