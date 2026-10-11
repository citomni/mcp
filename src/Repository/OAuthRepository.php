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

namespace CitOmni\Mcp\Repository;

use CitOmni\Kernel\Repository\BaseRepository;
use CitOmni\Mcp\Support\OAuth\AccessToken;
use CitOmni\Mcp\Support\OAuth\AuthCode;
use CitOmni\Mcp\Support\OAuth\Client;
use CitOmni\Mcp\Support\OAuth\Config;
use CitOmni\Mcp\Support\OAuth\RefreshToken;
use CitOmni\Mcp\Support\OAuth\Scope;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * League's entity/persistence adapter for one configured owner and OAuth client.
 *
 * Token transitions use the same ledger transaction as the surrounding grant.
 * Consumed codes remain until expiry; a family retains only its current refresh
 * identifier and recognizes replay of every older, unexpired family token.
 */
final class OAuthRepository extends BaseRepository implements AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, ClientRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface {
	public readonly OAuthStateRepository $state;
	public readonly Config $config;
	private ?string $family = null;
	private string $context;
	public bool $replayDetected = false;

	/**
	 * Initialize request-local state from application configuration.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->config = new Config($this->app);
		$this->state = new OAuthStateRepository($this->app);
		// An old grant must not become valid for a different resource or owner.
		$this->context = \hash('sha256', \json_encode([
			$this->config->issuer, $this->config->resource, $this->config->values['owner_id'],
			$this->config->values['client_id'], $this->config->values['scope'],
		], JSON_THROW_ON_ERROR));
	}

	/** {@inheritDoc} */
	public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface {
		$v = $this->config->values;
		return $clientIdentifier === $v['client_id'] ? new Client($v['client_id'], $v['client_name'], $v['redirect_uris']) : null;
	}

	/**
	 * Authenticate the registered confidential client for a supported grant.
	 *
	 * @param string $clientIdentifier Configured client identifier.
	 * @param string|null $clientSecret Presented client secret, excluded from exception arguments.
	 * @param string|null $grantType Requested OAuth grant type.
	 * @return bool Validated result.
	 */
	public function validateClient(string $clientIdentifier, #[\SensitiveParameter] ?string $clientSecret, ?string $grantType): bool {
		return $clientIdentifier === $this->config->values['client_id'] && $clientSecret !== null
			&& \in_array($grantType, ['authorization_code', 'refresh_token'], true)
			&& \hash_equals($this->config->secret('client'), $clientSecret);
	}

	/** {@inheritDoc} */
	public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface {
		return $identifier === $this->config->values['scope'] ? new Scope($identifier) : null;
	}

	/**
	 * Require the configured owner, client and single scope on every grant.
	 *
	 * @param array $scopes Requested scope entities.
	 * @param string $grantType Requested OAuth grant type.
	 * @param ClientEntityInterface $clientEntity Authenticated client entity.
	 * @param string|null $userIdentifier Resource owner identifier.
	 * @param string|null $authCodeId Authorization code identifier when supplied by League.
	 * @return array Validated result.
	 */
	public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array {
		if ($userIdentifier !== $this->config->values['owner_id'] || $clientEntity->getIdentifier() !== $this->config->values['client_id']) {
			throw OAuthServerException::invalidGrant('The owner or client is no longer authorized.');
		}

		if (\count($scopes) !== 1 || $scopes[0]->getIdentifier() !== $this->config->values['scope']) {
			throw OAuthServerException::invalidScope($this->config->values['scope']);
		}

		return $scopes;
	}

	/** {@inheritDoc} */
	public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface {
		$token = new AccessToken($this->config->issuer, $this->config->resource);
		$token->setClient($clientEntity);
		$token->setUserIdentifier((string)$userIdentifier);
		foreach ($scopes as $scope) {
			$token->addScope($scope);
		}

		return $token;
	}

	/** {@inheritDoc} */
	public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void {
		$this->persist('access', $accessTokenEntity->getIdentifier(), $accessTokenEntity->getExpiryDateTime()->getTimestamp());
	}

	/** {@inheritDoc} */
	public function revokeAccessToken(string $tokenId): void {
		// Unknown access tokens already fail closed; no access tombstone is needed.
		$this->state->delete('access:' . $tokenId);
	}

	/** {@inheritDoc} */
	public function isAccessTokenRevoked(string $tokenId): bool {
		$record = $this->state->get('access:' . $tokenId);
		return $record === null || ($record['revoked'] ?? true) !== false || ($record['context'] ?? null) !== $this->context;
	}

	/** {@inheritDoc} */
	public function getNewAuthCode(): AuthCodeEntityInterface {
		return new AuthCode();
	}

	/** {@inheritDoc} */
	public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void {
		$this->family = $authCodeEntity->getIdentifier();
		$this->persist('code', $authCodeEntity->getIdentifier(), $authCodeEntity->getExpiryDateTime()->getTimestamp());
	}

	/** {@inheritDoc} */
	public function revokeAuthCode(string $codeId): void {
		$this->revoke('code', $codeId);
	}

	/** {@inheritDoc} */
	public function isAuthCodeRevoked(string $codeId): bool {
		return $this->selectGrant('code', $codeId);
	}

	/** {@inheritDoc} */
	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		if ($this->family === null) {
			throw new \LogicException('No OAuth grant family was selected.');
		}

		return new RefreshToken($this->family);
	}

	/**
	 * Replace the family's current refresh record without accumulating tombstones.
	 *
	 * @param RefreshTokenEntityInterface $refreshTokenEntity New refresh token entity.
	 * @return void
	 */
	public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void {
		$familyKey = 'family:' . $this->family;
		$previous = $this->state->get($familyKey);
		if (isset($previous['current_refresh'])) {
			$this->state->delete('refresh:' . $previous['current_refresh']);
		}

		$this->persist('refresh', $refreshTokenEntity->getIdentifier(), $refreshTokenEntity->getExpiryDateTime()->getTimestamp());
		$this->state->put($familyKey, [
			'expires' => $refreshTokenEntity->getExpiryDateTime()->getTimestamp(),
			'family' => $this->family,
			'context' => $this->context,
			'revoked' => false,
			'current_refresh' => $refreshTokenEntity->getIdentifier(),
		]);
	}

	/** {@inheritDoc} */
	public function revokeRefreshToken(string $tokenId): void {
		$this->revoke('refresh', $tokenId);
	}

	/**
	 * Recognize old family identifiers and revoke descendants on replay.
	 *
	 * @param string $tokenId Refresh token identifier decrypted and expiry-checked by League.
	 * @return bool Validated result.
	 */
	public function isRefreshTokenRevoked(string $tokenId): bool {
		// League has already authenticated/decrypted this identifier and checked expiry.
		if (\preg_match('/^([a-f0-9]{80})\.[a-f0-9]{80}$/D', $tokenId, $match) !== 1) {
			return true;
		}

		$family = $this->state->get('family:' . $match[1]);
		if ($family === null || ($family['context'] ?? null) !== $this->context || ($family['revoked'] ?? true) !== false) {
			return true;
		}

		if (($family['current_refresh'] ?? null) !== $tokenId) {
			$this->state->revokeFamily($match[1]);
			$this->replayDetected = true;
			return true;
		}

		return $this->selectGrant('refresh', $tokenId);
	}

	/**
	 * Insert one token record bound to the current family and authorization context.
	 *
	 * @param string $kind Token record kind.
	 * @param string $id Server-generated token or family identifier.
	 * @param int $expires Unix expiry time.
	 * @return void
	 */
	private function persist(string $kind, string $id, int $expires): void {
		if ($this->state->get($kind . ':' . $id) !== null) {
			throw UniqueTokenIdentifierConstraintViolationException::create();
		}

		if ($this->family === null) {
			throw new \LogicException('No OAuth grant family was selected.');
		}

		$this->state->put($kind . ':' . $id, ['expires' => $expires, 'family' => $this->family, 'revoked' => false, 'context' => $this->context]);
	}

	/**
	 * Mark an existing record revoked without extending its lifetime.
	 *
	 * @param string $kind Token record kind.
	 * @param string $id Server-generated token or family identifier.
	 * @return void
	 */
	private function revoke(string $kind, string $id): void {
		$record = $this->state->get($kind . ':' . $id);
		if ($record !== null) {
			$record['revoked'] = true;
			$this->state->put($kind . ':' . $id, $record);
		}
	}

	/** Select a grant family, or invalidate its descendants on replay. */
	private function selectGrant(string $kind, string $id): bool {
		$record = $this->state->get($kind . ':' . $id);
		if ($record === null || ($record['context'] ?? null) !== $this->context) {
			return true;
		}

		$this->family = $record['family'];
		if (($record['revoked'] ?? true) !== false) {
			$this->state->revokeFamily($this->family);
			$this->replayDetected = true;
			return true;
		}

		return false;
	}
}
