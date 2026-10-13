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

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

/**
 * RefreshToken: League entity for a refresh token that carries its grant family.
 *
 * League encrypts refresh tokens; the repository enforces their rotation. The identifier starts
 * with the grant family, so that a replayed token can be traced to the family it belongs to
 * without one record per rotation.
 */
final class RefreshToken implements RefreshTokenEntityInterface {
	use EntityTrait;
	use RefreshTokenTrait;


	/**
	 * Bind the token to its grant family before League sets its identifier.
	 *
	 * @param string $family Grant family: the identifier of the authorization code that started the grant.
	 */
	public function __construct(private readonly string $family) {
	}


	/**
	 * Set the identifier as the grant family, a dot and the random identifier from League.
	 *
	 * @param string $identifier Cryptographically random identifier from League.
	 * @return void
	 */
	public function setIdentifier(string $identifier): void {
		$this->identifier = $this->family . '.' . $identifier;
	}


}
