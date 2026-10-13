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
 * OAuthRepository: Repository that serves League's repository interfaces from the OAuth ledger.
 *
 * Answers League's client, scope, code, access-token and refresh-token lookups for the one
 * configured owner and client, and keeps the grant records in the ledger. Validating protocol
 * parameters belongs to League and the controller. A grant family is all records that stem
 * from one authorization code; a family keeps only its current refresh identifier, yet
 * recognizes the replay of every older, unexpired one.
 *
 * Rules:
 * - Bind every record to its grant family and to the authorization context, so that a grant
 *   from another issuer, resource, owner, client or scope fails closed.
 * - Treat a record that is unknown, expired, revoked or from another context as revoked.
 * - Revoke the whole grant family when a used code or refresh token is presented again.
 * - Store identifiers only, never a token, code, password or secret.
 * - Change records only inside the caller's ledger transaction.
 */
final class OAuthRepository extends BaseRepository implements AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, ClientRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface {

	/** Ledger that holds the grant records. */
	public readonly OAuthStateRepository $state;

	/** Validated OAuth cfg of this request. */
	public readonly Config $config;

	/** Set when this request revoked a grant family because a used code or refresh token was presented again. */
	public bool $replayDetected = false;

	/** Grant family of this request: the identifier of its authorization code; null until a code is issued or a record is selected. */
	private ?string $family = null;

	/** Hash of issuer, resource, owner, client and scope that every record of the current context carries. */
	private string $context;


	/**
	 * Load the validated OAuth cfg and the ledger, and compute the authorization context.
	 *
	 * @return void
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When OAuth is disabled or mcp.oauth is invalid.
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


	/**
	 * Return the configured client.
	 *
	 * @param string $clientIdentifier Client ID from the request.
	 * @return ClientEntityInterface|null The configured client, or null for any other client ID.
	 */
	public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface {
		$values = $this->config->values;
		return $clientIdentifier === $values['client_id'] ? new Client($values['client_id'], $values['client_name'], $values['redirect_uris']) : null;
	}


	/**
	 * Authenticate the configured confidential client for a supported grant.
	 *
	 * @param string $clientIdentifier Client ID from the request.
	 * @param string|null $clientSecret Presented client secret.
	 * @param string|null $grantType Requested grant type.
	 * @return bool True when the configured client presented its secret for the authorization-code or refresh-token grant.
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When the client secret is missing or empty in the secrets store.
	 */
	public function validateClient(string $clientIdentifier, #[\SensitiveParameter] ?string $clientSecret, ?string $grantType): bool {
		return $clientIdentifier === $this->config->values['client_id'] && $clientSecret !== null
			&& \in_array($grantType, ['authorization_code', 'refresh_token'], true)
			&& \hash_equals($this->config->secret('client'), $clientSecret);
	}


	/**
	 * Return the configured scope.
	 *
	 * @param string $identifier Scope from the request.
	 * @return ScopeEntityInterface|null The configured scope, or null for any other scope.
	 */
	public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface {
		return $identifier === $this->config->values['scope'] ? new Scope($identifier) : null;
	}


	/**
	 * Require the configured owner, client and single scope on every grant.
	 *
	 * @param ScopeEntityInterface[] $scopes Requested scope entities.
	 * @param string $grantType Requested grant type.
	 * @param ClientEntityInterface $clientEntity Authenticated client.
	 * @param string|null $userIdentifier Resource owner identifier.
	 * @param string|null $authCodeId Authorization code identifier, when League passes one.
	 * @return ScopeEntityInterface[] The requested scopes, unchanged.
	 * @throws OAuthServerException When the owner, client or scope is not the configured one.
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


	/**
	 * Create an access token bound to the issuer and the MCP resource.
	 *
	 * @param ClientEntityInterface $clientEntity Client the token is issued to.
	 * @param ScopeEntityInterface[] $scopes Granted scopes.
	 * @param string|null $userIdentifier Resource owner identifier.
	 * @return AccessTokenEntityInterface The unsaved token.
	 */
	public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface {
		$token = new AccessToken($this->config->issuer, $this->config->resource);
		$token->setClient($clientEntity);
		$token->setUserIdentifier((string)$userIdentifier);
		foreach ($scopes as $scope) {
			$token->addScope($scope);
		}

		return $token;
	}


	/**
	 * Record a new access token in the current grant family.
	 *
	 * Stores the token's identifier and expiry with the family and the authorization context,
	 * so that the token is revoked with its family and fails closed in another context.
	 *
	 * @param AccessTokenEntityInterface $accessTokenEntity Token to record.
	 * @return void
	 * @throws UniqueTokenIdentifierConstraintViolationException When the identifier exists; League retries with a new one.
	 * @throws \LogicException When no grant family is selected or no transaction is open.
	 * @throws \CitOmni\Mcp\Exception\OAuthCapacityException When the ledger has no room for a new grant record.
	 */
	public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void {
		$this->persist('access', $accessTokenEntity->getIdentifier(), $accessTokenEntity->getExpiryDateTime()->getTimestamp());
	}


	/**
	 * Revoke an access token by deleting its record.
	 *
	 * A token without a record counts as revoked, so deleting the record revokes the token and
	 * keeps the ledger small.
	 *
	 * @param string $tokenId Token identifier.
	 * @return void
	 * @throws \LogicException When no transaction is open.
	 */
	public function revokeAccessToken(string $tokenId): void {
		$this->state->delete('access:' . $tokenId);
	}


	/**
	 * Tell whether an access token is revoked.
	 *
	 * @param string $tokenId Token identifier.
	 * @return bool True when the record is missing, expired, revoked or from another authorization context.
	 */
	public function isAccessTokenRevoked(string $tokenId): bool {
		$record = $this->state->get('access:' . $tokenId);
		return $record === null || ($record['revoked'] ?? true) !== false || ($record['context'] ?? null) !== $this->context;
	}


	/**
	 * Create an authorization code.
	 *
	 * @return AuthCodeEntityInterface The unsaved code.
	 */
	public function getNewAuthCode(): AuthCodeEntityInterface {
		return new AuthCode();
	}


	/**
	 * Record a new authorization code and start its grant family.
	 *
	 * Every token later issued from the code carries the code's identifier as its family, so
	 * that a replay of the code can revoke them all.
	 *
	 * @param AuthCodeEntityInterface $authCodeEntity Code to record; its identifier becomes the family.
	 * @return void
	 * @throws UniqueTokenIdentifierConstraintViolationException When the identifier exists; League retries with a new one.
	 * @throws \LogicException When no transaction is open.
	 * @throws \CitOmni\Mcp\Exception\OAuthCapacityException When the ledger has no room for a new grant record.
	 */
	public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void {
		$this->family = $authCodeEntity->getIdentifier();
		$this->persist('code', $authCodeEntity->getIdentifier(), $authCodeEntity->getExpiryDateTime()->getTimestamp());
	}


	/**
	 * Mark an authorization code as used.
	 *
	 * Keeps the record until it expires, so that a replay of the code is recognized.
	 *
	 * @param string $codeId Code identifier.
	 * @return void
	 * @throws \LogicException When no transaction is open.
	 */
	public function revokeAuthCode(string $codeId): void {
		$this->revoke('code', $codeId);
	}


	/**
	 * Tell whether an authorization code is revoked, and select its grant family.
	 *
	 * Selecting the family lets the tokens issued from the code join it.
	 *
	 * Behavior:
	 * - True when the record is unknown or from another authorization context.
	 * - True when the code was used before; the whole grant family is then revoked.
	 *
	 * @param string $codeId Code identifier.
	 * @return bool False only for an unused code of the current context.
	 * @throws \LogicException When a revocation is needed and no transaction is open.
	 */
	public function isAuthCodeRevoked(string $codeId): bool {
		return $this->selectGrant('code', $codeId);
	}


	/**
	 * Create a refresh token in the current grant family.
	 *
	 * @return RefreshTokenEntityInterface|null The unsaved token.
	 * @throws \LogicException When no grant family is selected.
	 */
	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		if ($this->family === null) {
			throw new \LogicException('No OAuth grant family was selected.');
		}

		return new RefreshToken($this->family);
	}


	/**
	 * Record a refresh token as its family's current one.
	 *
	 * Replaces the family's previous refresh record rather than keeping a tombstone per
	 * rotation, and renews the family's expiry to the new token's.
	 *
	 * @param RefreshTokenEntityInterface $refreshTokenEntity Token to record.
	 * @return void
	 * @throws UniqueTokenIdentifierConstraintViolationException When the identifier exists; League retries with a new one.
	 * @throws \LogicException When no grant family is selected or no transaction is open.
	 * @throws \CitOmni\Mcp\Exception\OAuthCapacityException When the ledger has no room for a new grant record.
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


	/**
	 * Mark a refresh token as used.
	 *
	 * The mark lasts until the family's next refresh token replaces the record.
	 *
	 * @param string $tokenId Token identifier.
	 * @return void
	 * @throws \LogicException When no transaction is open.
	 */
	public function revokeRefreshToken(string $tokenId): void {
		$this->revoke('refresh', $tokenId);
	}


	/**
	 * Tell whether a refresh token is revoked, and select its grant family.
	 *
	 * Comparing the token with its family's current identifier recognizes the replay of any
	 * older one, although only the current identifier is kept. Selecting the family lets the
	 * new tokens join it.
	 *
	 * Behavior:
	 * - True when the identifier is malformed, or its family is unknown, revoked or from another
	 *   authorization context.
	 * - True when it is not its family's current token; the whole family is then revoked.
	 * - Otherwise as for an authorization code: true when the record is unknown, from another
	 *   context or used, and a used record revokes the family.
	 *
	 * @param string $tokenId Refresh token identifier, decrypted and expiry-checked by League.
	 * @return bool False only for the current, unused refresh token of a live family.
	 * @throws \LogicException When a revocation is needed and no transaction is open.
	 */
	public function isRefreshTokenRevoked(string $tokenId): bool {
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
	 * Insert one record bound to the current grant family and authorization context.
	 *
	 * @param string $kind Record kind: access, code or refresh.
	 * @param string $id Server-generated identifier.
	 * @param int $expires Unix expiry time.
	 * @return void
	 * @throws UniqueTokenIdentifierConstraintViolationException When the identifier exists; League retries with a new one.
	 * @throws \LogicException When no grant family is selected or no transaction is open.
	 * @throws \CitOmni\Mcp\Exception\OAuthCapacityException When the ledger has no room for a new grant record.
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
	 * @param string $kind Record kind: code or refresh.
	 * @param string $id Record identifier.
	 * @return void
	 * @throws \LogicException When no transaction is open.
	 */
	private function revoke(string $kind, string $id): void {
		$record = $this->state->get($kind . ':' . $id);
		if ($record !== null) {
			$record['revoked'] = true;
			$this->state->put($kind . ':' . $id, $record);
		}
	}


	/**
	 * Select the grant family of a code or refresh record, and revoke the family on replay.
	 *
	 * @param string $kind Record kind: code or refresh.
	 * @param string $id Record identifier.
	 * @return bool True when the record is unknown, from another context or already revoked; a revoked record revokes its whole family.
	 * @throws \LogicException When a revocation is needed and no transaction is open.
	 */
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
