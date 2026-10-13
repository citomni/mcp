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
use CitOmni\Mcp\Exception\McpConfigException;
use CitOmni\Mcp\Exception\OAuthCapacityException;
use CitOmni\Mcp\Repository\OAuthRepository;
use CitOmni\Mcp\Support\OAuth\Audit;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * ApproveAuthorization: Operation that takes the owner's decision on a pending authorization.
 *
 * Owns the decision whether a pending authorization is approved, denied or refused, so that a
 * decision is checked against the browser, the CSRF value and the password in one place. The
 * protocol work of completing the authorization belongs to the adapter's callback.
 *
 * Rules:
 * - Read and change approval and rate-limit state only inside one ledger transaction.
 * - Audit every outcome.
 */
final class ApproveAuthorization extends BaseOperation {

	/**
	 * Validate the browser binding, the CSRF value and, for an approval, the password, then complete the grant.
	 *
	 * A decision consumes the pending approval in the same transaction that completes the
	 * authorization, so that one approval page yields at most one decision.
	 *
	 * Behavior:
	 * - invalid_request when the request ID or the cookie is malformed, the approval is unknown
	 *   or expired, the CSRF value or the cookie does not match, or the decision is neither
	 *   approve nor deny. A pending approval stays pending.
	 * - rate_limited after ten password attempts per IP within 15 minutes; the attempt counts.
	 * - invalid_password for a wrong password; the approval stays pending.
	 * - approved or denied, with the callback's result, when the callback completes. The
	 *   approval is consumed, and a correct password clears the IP's attempt counter.
	 * - The OAuth error type, with the exception as result, when the callback throws
	 *   OAuthServerException. The approval is consumed all the same.
	 * - capacity, without a result, when the ledger is full. Nothing is saved.
	 *
	 * @template T
	 * @param OAuthRepository $repository Grant repository shared with the adapter.
	 * @param array<string, string> $form Decoded form values.
	 * @param string|null $browser Value of the approval cookie, or null when absent.
	 * @param string $ip Client address resolved by the Request service.
	 * @param callable(array<string, string>, bool): T $complete Completes the authorization with the stored parameters and whether it was approved.
	 * @return array{outcome: string, result?: T|OAuthServerException}
	 * @throws McpConfigException When the owner password secret is missing, empty or not a password_hash() result.
	 */
	public function execute(OAuthRepository $repository, #[\SensitiveParameter] array $form, #[\SensitiveParameter] ?string $browser, string $ip, callable $complete): array {
		$id = $form['request_id'] ?? '';
		$csrf = $form['csrf'] ?? '';

		if (\preg_match('/^[a-f0-9]{48}$/D', $id) !== 1 || $browser === null || \preg_match('/^[a-f0-9]{64}$/D', $browser) !== 1) {
			Audit::record($this->app, 'authorization.decision', 'invalid_request', $ip);

			return ['outcome' => 'invalid_request'];
		}

		try {
			$result = $repository->state->transaction(static function () use ($repository, $form, $id, $csrf, $browser, $ip, $complete): array {
				$pending = $repository->state->get('approval:' . $id);

				if ($pending === null || !\hash_equals($pending['csrf'], \hash('sha256', $csrf)) || !\hash_equals($pending['browser'], \hash('sha256', $browser))) {
					return ['outcome' => 'invalid_request'];
				}

				$decision = $form['decision'] ?? '';

				if (!\in_array($decision, ['approve', 'deny'], true)) {
					return ['outcome' => 'invalid_request'];
				}

				if ($decision === 'approve') {
					$limitKey = 'password-' . \hash('sha256', $ip);

					if (!$repository->state->countAttempt($limitKey, 900, 10)) {
						return ['outcome' => 'rate_limited'];
					}

					$hash = $repository->config->secret('owner_password');

					if ((\password_get_info($hash)['algoName'] ?? 'unknown') === 'unknown') {
						throw new McpConfigException('The MCP OAuth owner password must be a password_hash() result.');
					}

					$password = $form['password'] ?? '';

					if (\strlen($password) > 1024 || !\password_verify($password, $hash)) {
						return ['outcome' => 'invalid_password'];
					}

					// A successful owner login does not consume the failed-attempt budget.
					$repository->state->delete('limit:' . $limitKey);
				}

				$repository->state->delete('approval:' . $id);

				try {
					return ['outcome' => $decision === 'approve' ? 'approved' : 'denied', 'result' => $complete($pending['params'], $decision === 'approve')];
				} catch (OAuthServerException $error) {
					return ['outcome' => $error->getErrorType(), 'result' => $error];
				}
			});
		} catch (OAuthCapacityException) {
			$result = ['outcome' => 'capacity'];
		}

		Audit::record($this->app, 'authorization.decision', $result['outcome'], $ip);

		return $result;
	}


}
