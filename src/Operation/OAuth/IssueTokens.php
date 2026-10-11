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

namespace CitOmni\Mcp\Operation\OAuth;

use CitOmni\Kernel\Operation\BaseOperation;
use CitOmni\Mcp\Repository\OAuthRepository;
use CitOmni\Mcp\Support\OAuth\Audit;
use League\OAuth2\Server\Exception\OAuthServerException;

/** IssueTokens: Serialize grant transitions and audit issuance and replay revocation. */
final class IssueTokens extends BaseOperation {
	/**
	 * Run the protocol adapter in one transaction, including replay revocations.
	 *
	 * @param OAuthRepository $repository Repository shared with the League server.
	 * @param callable(): mixed $issue Protocol adapter which redeems the grant.
	 * @param string $grantType Requested grant; normalized before logging.
	 * @param string $ip Trusted client address.
	 * @return mixed Adapter result, or a protocol exception for the controller to render.
	 */
	public function execute(OAuthRepository $repository, callable $issue, string $grantType, string $ip): mixed {
		$result = $repository->state->transaction(static function () use ($issue): mixed {
			try {
				return $issue();
			} catch (OAuthServerException $error) {
				return $error;
			}
		});

		$event = match ($grantType) {
			'authorization_code' => 'token.issue',
			'refresh_token' => 'token.refresh',
			default => 'token.unsupported_grant',
		};
		Audit::record($this->app, $event, $result instanceof OAuthServerException ? $result->getErrorType() : 'issued', $ip);

		if ($repository->replayDetected) {
			Audit::record($this->app, 'grant.replay', 'family_revoked', $ip);
		}

		return $result;
	}
}
