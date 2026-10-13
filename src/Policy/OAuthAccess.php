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
 * OAuthAccess: Policy that decides whether a bearer header holds a valid OAuth access token.
 *
 * Validates access tokens locally with the public key and then checks their persisted record,
 * so that the MCP endpoint needs no call to the authorization server. Only tokens for the one
 * configured issuer, resource, owner, client and scope are accepted.
 *
 * Rules:
 * - Accept RS256 tokens only.
 * - Reject a token by returning null; throw only for configuration errors.
 * - Never let a token into an exception argument or a log.
 */
final class OAuthAccess {

	/**
	 * Create the policy for the app whose cfg and secrets describe the OAuth server.
	 *
	 * @param App $app App whose cfg holds mcp.oauth.
	 */
	public function __construct(private readonly App $app) {
	}


	/**
	 * Validate the access token in a bearer header and resolve its stable client identity.
	 *
	 * Behavior:
	 * - Returns oauth:<owner_id>:<client_id> for a token that is signed with the configured key,
	 *   issued by the issuer for the resource, valid now by its iat, nbf and exp, and issued to
	 *   the configured owner, client and scope, and whose record exists in the current
	 *   authorization context and is not revoked.
	 * - Returns null for anything else, including a missing or malformed header, a header longer
	 *   than 16,384 bytes and a public key that cannot be parsed.
	 *
	 * @param string|null $authorization Raw Authorization header value, or null when absent.
	 * @return string|null The identity, or null when the header holds no valid access token.
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When league/oauth2-server is missing, mcp.oauth is invalid, or the public-key secret is missing or empty.
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
			// A malformed token, or a public key that cannot be parsed, fails like a bad signature:
			// the token is rejected.
			return null;
		}

		$claims = $token->claims();
		$id = $claims->get('jti');
		if (!\is_string($id) || \preg_match('/^[a-f0-9]{80}$/D', $id) !== 1
			|| $claims->get('sub') !== $config->values['owner_id']
			|| $claims->get('client_id') !== $config->values['client_id']
			|| $claims->get('scopes') !== [$config->values['scope']]
			|| (new OAuthRepository($this->app))->isAccessTokenRevoked($id)) {
			return null;
		}

		return 'oauth:' . $config->values['owner_id'] . ':' . $config->values['client_id'];
	}


}
