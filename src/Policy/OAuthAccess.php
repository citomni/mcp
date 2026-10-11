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

namespace CitOmni\Mcp\Policy;

use CitOmni\Kernel\App;
use CitOmni\Mcp\Repository\OAuthRepository;
use CitOmni\Mcp\Support\OAuth\Config;
use CitOmni\Mcp\Support\OAuth\ServerFactory;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;

/**
 * Validate OAuth JWTs locally, then check the persisted revocation record.
 *
 * A token must match issuer, resource, owner, client and scope. Only RS256 is
 * accepted. The stable result identity is independent of token rotation.
 */
final class OAuthAccess {
	/**
	 * Retain the explicit dependencies and immutable entity values.
	 *
	 * @param App $app Application configuration and services.
	 */
	public function __construct(private readonly App $app) {
	}

	/**
	 * Validate the signed access token and resolve its stable owner/client identity.
	 *
	 * @param string|null $authorization Raw bearer header, excluded from exception arguments.
	 * @return ?string Validated result.
	 */
	public function client(#[\SensitiveParameter] ?string $authorization): ?string {
		if ($authorization === null || \strlen($authorization) > 16384
			|| \preg_match('/^Bearer +(\S+)$/iD', \trim($authorization), $m) !== 1) {
			return null;
		}
		ServerFactory::requireDependency();
		$config = new Config($this->app);
		$signer = new Sha256();
		$key = InMemory::plainText($config->secret('public_key'));
		$jwt = Configuration::forAsymmetricSigner($signer, InMemory::plainText('unused'), $key);
		$clock = new class implements ClockInterface {
			public function now(): \DateTimeImmutable {
				return new \DateTimeImmutable();
			}
		};
		try {
			$token = $jwt->parser()->parse($m[1]);
			if (!$token instanceof UnencryptedToken || !$jwt->validator()->validate($token,
				new SignedWith($signer, $key), new StrictValidAt($clock),
				new IssuedBy($config->issuer), new PermittedFor($config->resource))) {
				return null;
			}
		} catch (JwtException) {
			return null;
		}

		$c = $token->claims();
		$id = $c->get('jti');
		if (!\is_string($id) || \preg_match('/^[a-f0-9]{80}$/D', $id) !== 1
			|| $c->get('sub') !== $config->values['owner_id']
			|| $c->get('client_id') !== $config->values['client_id']
			|| $c->get('scopes') !== [$config->values['scope']]
			|| (new OAuthRepository($this->app))->isAccessTokenRevoked($id)) {
			return null;
		}

		return 'oauth:' . $config->values['owner_id'] . ':' . $config->values['client_id'];
	}
}
