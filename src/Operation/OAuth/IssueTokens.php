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
use CitOmni\Mcp\Exception\OAuthCapacityException;
use CitOmni\Mcp\Repository\OAuthRepository;
use CitOmni\Mcp\Support\OAuth\Audit;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * IssueTokens: Operation that redeems an authorization code or a refresh token.
 *
 * Owns the atomicity of grant redemption, so that parallel PHP workers cannot redeem one code
 * or refresh token twice, and so that a detected replay revokes its grant family even though
 * the redemption fails. The grant itself belongs to League, through the adapter's callback.
 *
 * Rules:
 * - Run every redemption inside one ledger transaction.
 * - Audit every outcome, and a saved replay revocation separately.
 */
final class IssueTokens extends BaseOperation {

	/**
	 * Redeem a grant through the adapter's callback in one ledger transaction.
	 *
	 * A protocol failure from the callback is an outcome, not an exception, so that the
	 * transaction still saves what the grant changed, such as a replay revocation.
	 *
	 * Behavior:
	 * - issued, with the callback's result, when the grant succeeds.
	 * - The OAuth error type, with the exception as result, when the callback throws
	 *   OAuthServerException. Changes such as a replay revocation are saved.
	 * - capacity, without a result, when the ledger is full. Nothing is saved, and a detected
	 *   replay revoked nothing.
	 * - Every outcome is audited under token.issue, token.refresh or token.unsupported_grant; a
	 *   saved replay revocation also as grant.replay.
	 *
	 * @template T
	 * @param OAuthRepository $repository Repository shared with the League server.
	 * @param callable(): T $issue Redeems the grant.
	 * @param string $grantType Requested grant type; used only to name the audit event.
	 * @param string $ip Client address resolved by the Request service.
	 * @return array{outcome: string, result?: T|OAuthServerException}
	 */
	public function execute(OAuthRepository $repository, callable $issue, string $grantType, string $ip): array {
		$event = match ($grantType) {
			'authorization_code' => 'token.issue',
			'refresh_token' => 'token.refresh',
			default => 'token.unsupported_grant',
		};

		try {
			$result = $repository->state->transaction(static function () use ($issue): array {
				// Returned rather than thrown, so the transaction still saves replay revocations.
				try {
					return ['outcome' => 'issued', 'result' => $issue()];
				} catch (OAuthServerException $error) {
					return ['outcome' => $error->getErrorType(), 'result' => $error];
				}
			});
		} catch (OAuthCapacityException) {
			// Nothing was saved, so a detected replay revoked nothing and is not audited.
			Audit::record($this->app, $event, 'capacity', $ip);

			return ['outcome' => 'capacity'];
		}

		Audit::record($this->app, $event, $result['outcome'], $ip);

		if ($repository->replayDetected) {
			Audit::record($this->app, 'grant.replay', 'family_revoked', $ip);
		}

		return $result;
	}


}
