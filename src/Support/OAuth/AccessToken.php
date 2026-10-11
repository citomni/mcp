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
 * Issue an RS256 JWT bound to the MCP resource, issuer, owner and OAuth client.
 *
 * League's default audience is the client ID. MCP requires the resource as
 * audience, so signing uses Lcobucci's builder with an explicit client_id claim.
 */
final class AccessToken implements AccessTokenEntityInterface {
	use EntityTrait;
	use TokenEntityTrait;

	private CryptKeyInterface $privateKey;

	/**
	 * Retain the explicit dependencies and immutable entity values.
	 *
	 * @param string $issuer Canonical authorization server origin.
	 * @param string $resource MCP resource audience.
	 */
	public function __construct(private readonly string $issuer, private readonly string $resource) {
	}

	/**
	 * Retain the signing key for the subsequent token serialization.
	 *
	 * @param CryptKeyInterface $privateKey Private signing key.
	 * @return void
	 */
	public function setPrivateKey(#[\SensitiveParameter] CryptKeyInterface $privateKey): void {
		$this->privateKey = $privateKey;
	}

	/** {@inheritDoc} */
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
