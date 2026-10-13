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

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * AccessToken: League entity that serializes an access token as an RS256 JWT for the MCP resource.
 *
 * MCP requires the protected resource as the audience of an access token, so this entity signs
 * its own JWT instead of using League's serialization: the issuer, the resource as audience,
 * the owner as subject and the client in an explicit client_id claim.
 *
 * Rules:
 * - Sign with RS256 only.
 * - Put no secret into a token.
 */
final class AccessToken implements AccessTokenEntityInterface {
	use EntityTrait;
	use TokenEntityTrait;

	/** Private key that signs the token; set by League before it serializes the token. */
	private CryptKeyInterface $privateKey;


	/**
	 * Bind the token to the issuer and to the MCP resource it is issued for.
	 *
	 * @param string $issuer Canonical authorization server origin.
	 * @param string $resource MCP resource identifier, used as the audience.
	 */
	public function __construct(private readonly string $issuer, private readonly string $resource) {
	}


	/**
	 * Keep the signing key for the serialization that follows.
	 *
	 * @param CryptKeyInterface $privateKey Private signing key.
	 * @return void
	 */
	public function setPrivateKey(#[\SensitiveParameter] CryptKeyInterface $privateKey): void {
		$this->privateKey = $privateKey;
	}


	/**
	 * Serialize the token as a signed JWT.
	 *
	 * Behavior:
	 * - Claims: iss (the issuer), aud (the resource), jti (the token identifier), sub (the owner),
	 *   iat and nbf (now), exp (the expiry League set), client_id and scopes.
	 *
	 * @return string The compact JWT.
	 */
	public function toString(): string {
		$jwt = Configuration::forAsymmetricSigner(new Sha256(),
			InMemory::plainText($this->privateKey->getKeyContents(), $this->privateKey->getPassPhrase() ?? ''),
			InMemory::plainText('unused'));
		$now = new \DateTimeImmutable();
		return $jwt->builder()->issuedBy($this->issuer)->permittedFor($this->resource)
			->identifiedBy($this->identifier)->relatedTo((string)$this->userIdentifier)
			->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($this->expiryDateTime)
			->withClaim('client_id', $this->client->getIdentifier())
			->withClaim('scopes', $this->getScopes())
			->getToken($jwt->signer(), $jwt->signingKey())->toString();
	}


}
