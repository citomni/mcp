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

/** Construct League only for an OAuth endpoint; no discovery or network calls. */
final class ServerFactory {
	/**
	 * Construct the League authorization server with the two supported grants.
	 *
	 * @param OAuthRepository $repository Repository shared by the League grant adapters.
	 * @return AuthorizationServer Validated result.
	 */
	public static function create(OAuthRepository $repository): AuthorizationServer {
		self::requireDependency();
		$config = $repository->config;
		$server = new AuthorizationServer($repository, $repository, $repository,
			new CryptKey($config->secret('private_key'), null, false),
			Key::loadFromAsciiSafeString($config->secret('encryption')));
		$server->setDefaultScope($config->values['scope']);
		$code = new AuthCodeGrant($repository, $repository, new \DateInterval('PT60S'));
		$code->setRefreshTokenTTL(new \DateInterval('PT' . $config->values['refresh_ttl'] . 'S'));
		$refresh = new RefreshTokenGrant($repository);
		$refresh->setRefreshTokenTTL(new \DateInterval('PT' . $config->values['refresh_ttl'] . 'S'));
		$ttl = new \DateInterval('PT' . $config->values['access_ttl'] . 'S');
		$server->enableGrantType($code, $ttl);
		$server->enableGrantType($refresh, $ttl);
		return $server;
	}

	/**
	 * Fail explicitly when the optional OAuth dependency is unavailable.
	 *
	 * @return void
	 */
	public static function requireDependency(): void {
		if (!\class_exists(AuthorizationServer::class)) {
			throw new McpConfigException('OAuth requires league/oauth2-server ^9.4.1 in the host application.');
		}
	}
}
