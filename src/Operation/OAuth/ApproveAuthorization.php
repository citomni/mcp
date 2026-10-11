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

/** ApproveAuthorization: Authorize one owner decision and consume it atomically. */
final class ApproveAuthorization extends BaseOperation {
	/**
	 * Validate browser binding, CSRF and password before completing the grant.
	 *
	 * The adapter callback revalidates protocol parameters and completes League's
	 * authorization request. Protocol failures commit consumed approval state.
	 *
	 * @param OAuthRepository $repository Grant repository shared with the adapter.
	 * @param array<string, string> $form Decoded, scalar form values.
	 * @param string|null $browser Approval cookie value.
	 * @param string $ip Trusted client address.
	 * @param callable(array<string, string>, bool): mixed $complete Protocol adapter.
	 * @return array{outcome: string, result?: mixed}
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
