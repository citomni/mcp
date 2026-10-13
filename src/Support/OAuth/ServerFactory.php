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

use CitOmni\Mcp\Exception\McpConfigException;
use CitOmni\Mcp\Repository\OAuthRepository;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;

/**
 * ServerFactory: Support class that builds the League authorization server for an OAuth endpoint.
 *
 * Keeps the League setup in one place, so that the approval and token endpoints use the same
 * grants, keys and lifetimes. League is an optional dependency of the package, so this class
 * also owns the check that it is installed.
 *
 * Rules:
 * - Enable only the authorization-code and refresh-token grants.
 * - Read keys and lifetimes through Config, never from cfg directly.
 * - Make no network calls and use no discovery.
 */
final class ServerFactory {

	/** Lifetime of an authorization code, as an ISO 8601 duration. */
	private const string AUTH_CODE_TTL = 'PT60S';


	/**
	 * Build the League authorization server with the authorization-code and refresh-token grants.
	 *
	 * Behavior:
	 * - Authorization codes live AUTH_CODE_TTL; access and refresh tokens live for
	 *   mcp.oauth.access_ttl and mcp.oauth.refresh_ttl seconds.
	 * - mcp.oauth.scope is the default scope.
	 *
	 * @param OAuthRepository $repository Repository that serves every League repository interface for this request.
	 * @return AuthorizationServer The configured server.
	 * @throws McpConfigException When the private-key or encryption secret is missing or empty.
	 * @throws \LogicException When the private-key secret is not a readable PEM key (League).
	 * @throws \Defuse\Crypto\Exception\BadFormatException When the encryption secret is not a Defuse key.
	 */
	public static function create(OAuthRepository $repository): AuthorizationServer {
		$config = $repository->config;
		$server = new AuthorizationServer($repository, $repository, $repository,
			new CryptKey($config->secret('private_key'), null, false),
			Key::loadFromAsciiSafeString($config->secret('encryption')));
		$server->setDefaultScope($config->values['scope']);
		$code = new AuthCodeGrant($repository, $repository, new \DateInterval(self::AUTH_CODE_TTL));
		$code->setRefreshTokenTTL(new \DateInterval('PT' . $config->values['refresh_ttl'] . 'S'));
		$refresh = new RefreshTokenGrant($repository);
		$refresh->setRefreshTokenTTL(new \DateInterval('PT' . $config->values['refresh_ttl'] . 'S'));
		$ttl = new \DateInterval('PT' . $config->values['access_ttl'] . 'S');
		$server->enableGrantType($code, $ttl);
		$server->enableGrantType($refresh, $ttl);
		return $server;
	}


	/**
	 * Fail with a configuration error when league/oauth2-server is not installed.
	 *
	 * Notes:
	 * - Call it before the first class that implements or extends a League type is loaded.
	 *   Loading such a class without League is a fatal error, not an exception.
	 *
	 * @return void
	 * @throws McpConfigException When league/oauth2-server is not installed.
	 */
	public static function requireDependency(): void {
		if (!\class_exists(AuthorizationServer::class)) {
			throw new McpConfigException('OAuth requires league/oauth2-server ^9.4.1 in the host application.');
		}
	}


}
