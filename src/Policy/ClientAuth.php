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
use CitOmni\Mcp\Support\OAuth\Config;

/**
 * ClientAuth: Policy that decides whether an MCP request carries an accepted bearer token.
 *
 * Accepts the fixed tokens whose values live in the app's secrets service and, when OAuth is
 * enabled, OAuth access tokens. It decides only; answering the request belongs to the
 * controller.
 *
 * Rules:
 * - Never let a token or a secret value leave the class: a result names the secret key or the
 *   OAuth identity, and a parameter that carries a token is #[\SensitiveParameter].
 * - Check fixed tokens before OAuth, so that a request with a fixed token never loads the OAuth
 *   library or its keys.
 */
final class ClientAuth {

	/**
	 * Create the policy for the app whose cfg and secrets hold the accepted tokens.
	 *
	 * @param App $app App whose cfg holds mcp.auth and mcp.oauth.
	 */
	public function __construct(private readonly App $app) {
	}


	/**
	 * Decide whether the request is authorized.
	 *
	 * The configured secrets are resolved before the header is looked at, so that a broken
	 * configuration fails on every request, not only on requests with a token.
	 *
	 * Behavior:
	 * - Authorized with reason disabled and no client when mcp.auth.secret_keys is [] and OAuth
	 *   is disabled.
	 * - Configuration errors, whatever the header holds:
	 *     - A key whose secret is empty.
	 *     - A non-empty mcp.auth.secret_keys of which no key exists in the store. Keys missing
	 *       from the store are skipped otherwise.
	 * - Reason missing without a "Bearer <token>" header; the scheme matches case-insensitively.
	 * - Authorized with reason accepted when the token equals a secret, compared with
	 *   hash_equals() in the order of mcp.auth.secret_keys. client is that secret's key.
	 * - With OAuth enabled, a token that equals no secret is validated as an OAuth access token.
	 *   A valid one is authorized with reason accepted, oauth true and client
	 *   oauth:<owner_id>:<client_id>.
	 * - Reason invalid for any other token.
	 *
	 * Typical usage:
	 *   $auth = (new ClientAuth($this->app))->evaluate($authorizationHeader);
	 *   if (!$auth['authorized']) {
	 *       // 401, with error="invalid_token" when $auth['reason'] === 'invalid'
	 *   }
	 *
	 * @param string|null $authorization Raw Authorization header value, or null when absent.
	 * @return array{authorized: bool, client: string|null, reason: 'accepted'|'disabled'|'missing'|'invalid', oauth?: bool}
	 *         client is the matching secret key or OAuth identity; null on failure or anonymous access.
	 * @throws McpConfigException When mcp.auth.secret_keys is not a list of non-empty strings, names an empty
	 *                            secret, or names no secret that exists; when mcp.oauth.enabled is read and is
	 *                            not a bool; or, for a token that equals no secret while OAuth is enabled, when
	 *                            league/oauth2-server is missing, mcp.oauth is invalid, or the public-key secret
	 *                            is missing or empty.
	 * @throws \InvalidArgumentException When a configured key is padded with whitespace (secrets service).
	 * @see \CitOmni\Mcp\Policy\OAuthAccess::client()  How an OAuth access token is validated.
	 */
	public function evaluate(#[\SensitiveParameter] ?string $authorization): array {

		// -- 1. Resolve the accepted tokens -------------------------------
		$keys = $this->app->cfg->mcp->auth->secret_keys;
		$keys = $keys instanceof Cfg ? $keys->toArray() : $keys;

		if ($keys === [] && !Config::enabled($this->app)) {
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

		if (Config::enabled($this->app)) {
			$client = (new OAuthAccess($this->app))->client($authorization);
			if ($client !== null) {
				return ['authorized' => true, 'client' => $client, 'reason' => 'accepted', 'oauth' => true];
			}
		}

		return ['authorized' => false, 'client' => null, 'reason' => 'invalid'];
	}


}
