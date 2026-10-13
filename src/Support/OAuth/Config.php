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

use CitOmni\Kernel\App;
use CitOmni\Mcp\Exception\McpConfigException;

/**
 * Config: Support class that validates and holds the OAuth cfg of one request.
 *
 * Gives every OAuth endpoint and the token check on the MCP endpoint one validated view of
 * mcp.oauth, so that they all apply the same rules. Reading mcp.oauth.enabled is part of it, so
 * that the value has one meaning wherever it is read.
 *
 * Rules:
 * - Depend on no OAuth library, so that hosts without one can read mcp.oauth.enabled.
 * - Validate every mcp.oauth value before a member returns it or derives a value from it.
 * - Take the issuer from cfg only, never from request headers.
 * - Name a secret in an error message, never its value.
 */
final class Config {

	/** @var array<string, mixed> mcp.oauth, validated by the constructor. */
	public readonly array $values;

	/** Canonical HTTPS origin of the authorization server, without path. */
	public readonly string $issuer;

	/** Identifier of the protected MCP resource: the issuer followed by /mcp. */
	public readonly string $resource;


	/**
	 * Validate mcp.oauth for this request.
	 *
	 * @param App $app App whose cfg holds mcp.oauth and whose secrets service holds the OAuth secrets.
	 * @throws McpConfigException When OAuth is disabled, or a value of mcp.oauth is missing, malformed or out of range.
	 */
	public function __construct(private readonly App $app) {
		if (!self::enabled($app)) {
			throw new McpConfigException('MCP OAuth is not enabled.');
		}

		$this->values = $app->cfg->mcp->oauth->toArray();
		$values = $this->values;

		foreach (['issuer', 'scope', 'owner_id', 'client_id', 'client_name', 'consent_text', 'state_dir'] as $key) {
			if (!\is_string($values[$key] ?? null) || $values[$key] === '' || \trim($values[$key]) !== $values[$key]) {
				throw new McpConfigException('mcp.oauth.' . $key . ' must be a non-empty, unpadded string.');
			}
		}

		if (!self::httpsUrl($values['issuer']) || (\parse_url($values['issuer'], PHP_URL_PATH) ?? '') !== '') {
			throw new McpConfigException('mcp.oauth.issuer must be an HTTPS origin without path or trailing slash.');
		}

		if (\preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $values['scope']) !== 1
			|| \preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $values['owner_id']) !== 1
			|| \preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $values['client_id']) !== 1) {
			throw new McpConfigException('MCP OAuth scope, owner_id and client_id must use ASCII letters, digits, dots, underscores or hyphens.');
		}

		if (!\is_array($values['redirect_uris'] ?? null) || !\array_is_list($values['redirect_uris']) || $values['redirect_uris'] === []) {
			throw new McpConfigException('mcp.oauth.redirect_uris must contain exact HTTPS callback URLs.');
		}

		foreach ($values['redirect_uris'] as $uri) {
			if (!\is_string($uri) || !self::httpsUrl($uri)) {
				throw new McpConfigException('Every OAuth redirect URI must be an exact HTTPS URL without query, fragment or userinfo.');
			}
		}

		foreach (['access_ttl' => [60, 3600], 'refresh_ttl' => [3600, 2592000], 'approval_ttl' => [60, 600]] as $key => [$min, $max]) {
			if (!\is_int($values[$key] ?? null) || $values[$key] < $min || $values[$key] > $max) {
				throw new McpConfigException('mcp.oauth.' . $key . ' is outside its supported range.');
			}
		}

		$this->issuer = $values['issuer'];
		$this->resource = $values['issuer'] . '/mcp';
		foreach (['lang', 'title', 'heading', 'password', 'approve', 'deny', 'invalid_password'] as $key) {
			if (!\is_string($values['texts'][$key] ?? null) || $values['texts'][$key] === '') {
				throw new McpConfigException('mcp.oauth.texts.' . $key . ' must be a non-empty string.');
			}
		}
	}


	/**
	 * Read whether OAuth is enabled.
	 *
	 * Any value other than true or false is a configuration error, also one that PHP would read
	 * as false, so that a value such as 'false' or 0 can neither enable OAuth nor silently
	 * disable it.
	 *
	 * @param App $app App whose cfg holds mcp.oauth.enabled.
	 * @return bool True when OAuth is enabled.
	 * @throws McpConfigException When mcp.oauth.enabled is not a bool.
	 */
	public static function enabled(App $app): bool {
		$enabled = $app->cfg->mcp->oauth->enabled;

		if (!\is_bool($enabled)) {
			throw new McpConfigException('mcp.oauth.enabled must be true or false.');
		}

		return $enabled;
	}


	/**
	 * List the sources that the approval form may submit to and be redirected to.
	 *
	 * @return string CSP source list: 'self' and the HTTPS origin of each callback, without path or query.
	 */
	public function formActionSources(): string {
		$sources = ["'self'"];
		foreach ($this->values['redirect_uris'] as $uri) {
			$parts = \parse_url($uri);
			$sources[] = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		}

		return \implode(' ', \array_unique($sources));
	}


	/**
	 * Read one OAuth secret from the secrets service.
	 *
	 * @param string $name owner_password, client, private_key, public_key or encryption.
	 * @return string The non-empty secret value.
	 * @throws McpConfigException When no key is configured for it, the key is not in the store, or the value is empty.
	 */
	public function secret(string $name): string {
		$key = $this->values[$name . '_secret_key'] ?? null;
		if (!\is_string($key) || $key === '' || !$this->app->secrets->has($key)) {
			throw new McpConfigException('Missing MCP OAuth secret configuration for ' . $name . '.');
		}

		$value = $this->app->secrets->get($key);
		if ($value === '') {
			throw new McpConfigException('Empty MCP OAuth secret for ' . $name . '.');
		}

		return $value;
	}


	/**
	 * Build the authorization server metadata (RFC 8414).
	 *
	 * Notes:
	 * - Every grant, client authentication method and PKCE method listed here must be one that
	 *   the authorization server enables; a value listed but not enabled is a bug.
	 *
	 * @return array<string, mixed> Metadata with issuer identification (RFC 9207) and S256 as the only PKCE method.
	 * @see \CitOmni\Mcp\Support\OAuth\ServerFactory::create()  The grants that the authorization server enables.
	 */
	public function metadata(): array {
		return [
			'issuer' => $this->issuer,
			'authorization_endpoint' => $this->issuer . '/mcp/oauth/authorize',
			'token_endpoint' => $this->issuer . '/mcp/oauth/token',
			'response_types_supported' => ['code'],
			'grant_types_supported' => ['authorization_code', 'refresh_token'],
			'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
			'code_challenge_methods_supported' => ['S256'],
			'scopes_supported' => [$this->values['scope']],
			'authorization_response_iss_parameter_supported' => true,
		];
	}


	/**
	 * Build the protected-resource metadata of the MCP resource (RFC 9728).
	 *
	 * @return array<string, mixed> Metadata that names the resource, the issuer as its authorization server, the scope and header-only bearer tokens.
	 */
	public function resourceMetadata(): array {
		return [
			'resource' => $this->resource,
			'authorization_servers' => [$this->issuer],
			'scopes_supported' => [$this->values['scope']],
			'bearer_methods_supported' => ['header'],
		];
	}


	/**
	 * Check that a URL is a canonical HTTPS URL.
	 *
	 * @param string $url Candidate URL.
	 * @return bool True for a valid HTTPS URL with a host and without credentials, query, fragment, whitespace, control characters or backslashes.
	 */
	private static function httpsUrl(string $url): bool {
		$parts = \parse_url($url);
		return \filter_var($url, FILTER_VALIDATE_URL) !== false && \is_array($parts)
			&& ($parts['scheme'] ?? null) === 'https' && isset($parts['host'])
			&& !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
			&& \preg_match('/[\x00-\x20\x7f\\\\]/', $url) !== 1;
	}


}
