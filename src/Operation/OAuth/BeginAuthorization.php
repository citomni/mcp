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

/**
 * BeginAuthorization: Operation that admits a validated authorization request and creates its pending approval.
 *
 * Owns the admission of authorization requests, so that the per-IP limit and the ledger's
 * capacity apply to every approval page. Validating the request belongs to the adapter and
 * League.
 *
 * Rules:
 * - Store only hashes of the CSRF and browser values, never the values.
 * - Audit every outcome.
 */
final class BeginAuthorization extends BaseOperation {

	/**
	 * Apply the per-IP admission limit and persist a short-lived pending approval.
	 *
	 * Counts the request against the per-IP limit and stores the approval in one ledger
	 * transaction, so that the limit holds across parallel PHP workers.
	 *
	 * Behavior:
	 * - created, with the approval ID, the CSRF value and the browser value, when the request is
	 *   admitted. The approval expires after mcp.oauth.approval_ttl seconds.
	 * - rate_limited after 20 requests per IP within one minute.
	 * - capacity when the ledger is full.
	 * - Only the parameters needed to validate and complete the grant are stored.
	 *
	 * @param OAuthRepository $repository Grant repository for this request.
	 * @param array<string, string> $params Validated authorization parameters.
	 * @param string $ip Client address resolved by the Request service.
	 * @return array{outcome: string, id?: string, csrf?: string, browser?: string}
	 */
	public function execute(OAuthRepository $repository, array $params, string $ip): array {
		// Persist only fields used when validating and completing this grant.
		$params = \array_intersect_key($params, \array_flip([
			'response_type', 'client_id', 'redirect_uri', 'resource', 'scope', 'state', 'code_challenge', 'code_challenge_method',
		]));
		$id = \bin2hex(\random_bytes(24));
		$csrf = \bin2hex(\random_bytes(32));
		$browser = \bin2hex(\random_bytes(32));

		try {
			$result = $repository->state->transaction(static function () use ($repository, $params, $id, $csrf, $browser, $ip): array {
				if (!$repository->state->countAttempt('authorize-' . \hash('sha256', $ip), 60, 20)) {
					return ['outcome' => 'rate_limited'];
				}

				$repository->state->put('approval:' . $id, [
					'expires' => \time() + $repository->config->values['approval_ttl'],
					'params'  => $params,
					'csrf'    => \hash('sha256', $csrf),
					'browser' => \hash('sha256', $browser),
				]);

				return ['outcome' => 'created', 'id' => $id, 'csrf' => $csrf, 'browser' => $browser];
			});
		} catch (OAuthCapacityException) {
			$result = ['outcome' => 'capacity'];
		}

		Audit::record($this->app, 'authorization.begin', $result['outcome'], $ip);

		return $result;
	}


}
