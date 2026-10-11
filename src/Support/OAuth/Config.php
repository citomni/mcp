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
 * Validate the optional single-owner OAuth configuration when it is used.
 *
 * No OAuth dependency or secret is loaded on the fixed-token happy path.
 * The issuer is an explicit HTTPS origin; request headers never determine it.
 */
final class Config {

	public readonly array $values;
	public readonly string $issuer;
	public readonly string $resource;

	/**
	 * Retain the explicit dependencies and immutable entity values.
	 *
	 * @param App $app Application configuration and services.
	 */
	public function __construct(private readonly App $app) {
		$this->values = $app->cfg->mcp->oauth->toArray();
		$v = $this->values;
		if (($v['enabled'] ?? false) !== true) {
			throw new McpConfigException('MCP OAuth is not enabled.');
		}

		foreach (['issuer', 'scope', 'owner_id', 'client_id', 'client_name', 'consent_text', 'state_dir'] as $key) {
			if (!\is_string($v[$key] ?? null) || $v[$key] === '' || \trim($v[$key]) !== $v[$key]) {
				throw new McpConfigException('mcp.oauth.' . $key . ' must be a non-empty, unpadded string.');
			}
		}

		if (!self::httpsUrl($v['issuer']) || (\parse_url($v['issuer'], PHP_URL_PATH) ?? '') !== '') {
			throw new McpConfigException('mcp.oauth.issuer must be an HTTPS origin without path or trailing slash.');
		}

		if (\preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $v['scope']) !== 1
			|| \preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $v['owner_id']) !== 1
			|| \preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $v['client_id']) !== 1) {
			throw new McpConfigException('MCP OAuth scope, owner_id and client_id must use ASCII letters, digits, dots, underscores or hyphens.');
		}

		if (!\is_array($v['redirect_uris'] ?? null) || !\array_is_list($v['redirect_uris']) || $v['redirect_uris'] === []) {
			throw new McpConfigException('mcp.oauth.redirect_uris must contain exact HTTPS callback URLs.');
		}

		foreach ($v['redirect_uris'] as $uri) {
			if (!\is_string($uri) || !self::httpsUrl($uri)) {
				throw new McpConfigException('Every OAuth redirect URI must be an exact HTTPS URL without query, fragment or userinfo.');
			}
		}

		foreach (['access_ttl' => [60, 3600], 'refresh_ttl' => [3600, 2592000], 'approval_ttl' => [60, 600]] as $key => [$min, $max]) {
			if (!\is_int($v[$key] ?? null) || $v[$key] < $min || $v[$key] > $max) {
				throw new McpConfigException('mcp.oauth.' . $key . ' is outside its supported range.');
			}
		}

		$this->issuer = $v['issuer'];
		$this->resource = $v['issuer'] . '/mcp';
		foreach (['lang', 'title', 'heading', 'password', 'approve', 'deny', 'invalid_password'] as $key) {
			if (!\is_string($v['texts'][$key] ?? null) || $v['texts'][$key] === '') {
				throw new McpConfigException('mcp.oauth.texts.' . $key . ' must be a non-empty string.');
			}
		}
	}

	/**
	 * Permit form submission and redirects only to configured HTTPS origins.
	 *
	 * @return string CSP source list without callback paths or query values.
	 */
	public function formActionSources(): string {
		$sources = ["'self'"];
		foreach ($this->values['redirect_uris'] as $uri) {
			$parts = \parse_url($uri);
			$sources[] = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		}

		return \implode(' ', \array_unique($sources));
	}

	/** Read a configured secret without exposing its value in diagnostics. */
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

	/** Discovery advertises only the grants and client authentication we implement. */
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
	 * Describe the protected MCP resource and its authorization server.
	 *
	 * @return array Validated result.
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
	 * Check a canonical HTTPS URL without credentials, query or fragment.
	 *
	 * @param string $url Candidate HTTPS URL.
	 * @return bool Validated result.
	 */
	private static function httpsUrl(string $url): bool {
		$p = \parse_url($url);
		return \filter_var($url, FILTER_VALIDATE_URL) !== false && \is_array($p)
			&& ($p['scheme'] ?? null) === 'https' && isset($p['host'])
			&& !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment'])
			&& \preg_match('/[\x00-\x20\x7f\\\\]/', $url) !== 1;
	}
}
