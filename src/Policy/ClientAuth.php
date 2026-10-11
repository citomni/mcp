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
use CitOmni\Kernel\Cfg;
use CitOmni\Mcp\Exception\McpConfigException;

/**
 * ClientAuth: Decide whether an MCP request carries a valid bearer token.
 *
 * The accepted tokens are the values of the secrets named in
 * mcp.auth.secret_keys. They live in the app's secrets store
 * (var/secrets/app.secret.<env>.php), never in cfg.
 *
 * Behavior:
 * - An empty mcp.auth.secret_keys disables fixed-token authentication. Requests
 *   are anonymous only if OAuth is also disabled; otherwise OAuth is required.
 * - Otherwise the configured secrets are resolved first, so a broken
 *   configuration fails on every request, not only on authenticated ones:
 *   1) Keys missing from the store are skipped (a free rotation slot)
 *   2) A key whose value is empty is a configuration error
 *   3) No key present at all is a configuration error
 * - The Authorization header must be "Bearer <token>" (scheme case-insensitive).
 *   The token is compared with each secret by hash_equals(), in the order of
 *   mcp.auth.secret_keys.
 *
 * Notes:
 * - The result names the matching secret key, or a stable OAuth owner/client ID.
 *   Neither the token nor a secret value ever leaves this class, and the header
 *   argument is #[\SensitiveParameter], so stack traces of a configuration error
 *   do not carry it either.
 * - Rotation: put the new token under the free key, switch the client, then
 *   remove the old secret. No cfg change and no downtime.
 *
 * Typical usage:
 *   $auth = (new ClientAuth($this->app))->evaluate($authorizationHeader);
 *   if (!$auth['authorized']) {
 *       // 401, with error="invalid_token" when $auth['reason'] === 'invalid'
 *   }
 */
final class ClientAuth {

	public function __construct(private readonly App $app) {
	}


	/**
	 * Decide whether the request is authorized.
	 *
	 * @param string|null $authorization Raw Authorization header value, or null when absent.
	 * @return array{authorized: bool, client: string|null, reason: 'accepted'|'disabled'|'missing'|'invalid', oauth?: bool}
	 *         client is the matching secret key or stable OAuth identity; null on failure or anonymous access.
	 * @throws McpConfigException When mcp.auth.secret_keys is not a list of strings, names an
	 *                            empty secret, or names no secret that exists.
	 * @throws \InvalidArgumentException When a configured key is padded with whitespace (secrets service).
	 */
	public function evaluate(#[\SensitiveParameter] ?string $authorization): array {

		// -- 1. Resolve the accepted tokens -------------------------------
		$keys = $this->app->cfg->mcp->auth->secret_keys;
		$keys = $keys instanceof Cfg ? $keys->toArray() : $keys;

		if ($keys === [] && !(bool)($this->app->cfg->mcp->oauth->enabled ?? false)) {
			return ['authorized' => true, 'client' => null, 'reason' => 'disabled'];
		}

		if (!\is_array($keys) || !\array_is_list($keys)) {
			throw new McpConfigException('mcp.auth.secret_keys must be a list of secret keys.');
		}

		$secrets = $this->app->secrets;
		$tokens = [];

		foreach ($keys as $key) {
			if (!\is_string($key) || $key === '') {
				throw new McpConfigException('mcp.auth.secret_keys must contain non-empty strings only.');
			}

			if (!$secrets->has($key)) {
				continue;
			}

			$value = $secrets->get($key);

			if ($value === '') {
				throw new McpConfigException("The secret '{$key}' named in mcp.auth.secret_keys is empty.");
			}

			$tokens[$key] = $value;
		}

		if ($tokens === [] && $keys !== []) {
			throw new McpConfigException(
				'None of the secrets named in mcp.auth.secret_keys (' . \implode(', ', $keys) . ') exists '
				. 'in the secrets store for this environment. Add one, or set mcp.auth.secret_keys to [] '
				. 'to disable fixed-token authentication (anonymous only when OAuth is also disabled).'
			);
		}

		// -- 2. Compare the presented token -------------------------------
		if ($authorization === null || \preg_match('/^Bearer +(\S+)$/i', \trim($authorization), $m) !== 1) {
			return ['authorized' => false, 'client' => null, 'reason' => 'missing'];
		}

		foreach ($tokens as $key => $token) {
			if (\hash_equals($token, $m[1])) {
				return ['authorized' => true, 'client' => $key, 'reason' => 'accepted'];
			}
		}

		// Fixed tokens retain their original fast path and client identity.
		if ((bool)($this->app->cfg->mcp->oauth->enabled ?? false)) {
			$client = (new OAuthAccess($this->app))->client($authorization);
			if ($client !== null) {
				return ['authorized' => true, 'client' => $client, 'reason' => 'accepted', 'oauth' => true];
			}
		}

		return ['authorized' => false, 'client' => null, 'reason' => 'invalid'];
	}

}
