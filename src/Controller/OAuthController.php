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

namespace CitOmni\Mcp\Controller;

use CitOmni\Kernel\Controller\BaseController;
use CitOmni\Mcp\Operation\OAuth\ApproveAuthorization;
use CitOmni\Mcp\Operation\OAuth\BeginAuthorization;
use CitOmni\Mcp\Operation\OAuth\IssueTokens;
use CitOmni\Mcp\Repository\OAuthRepository;
use CitOmni\Mcp\Support\OAuth\Audit;
use CitOmni\Mcp\Support\OAuth\Config;
use CitOmni\Mcp\Support\OAuth\ConsentPage;
use CitOmni\Mcp\Support\OAuth\ServerFactory;
use CitOmni\Mcp\Support\OAuth\User;
use CitOmni\Mcp\Support\Psr7Bridge;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * OAuthController: HTTP adapter for the optional single-owner OAuth authorization server.
 *
 * Serves the discovery metadata, the owner approval page and the token endpoint. League handles
 * authorization codes, PKCE, refresh tokens and protocol errors, and the OAuth Operations own
 * admission, password decisions and atomic state transitions; this class translates between
 * HTTP and the two.
 *
 * Rules:
 * - Serve nothing while mcp.oauth.enabled is false, and nothing over another scheme, host or
 *   port than the issuer's.
 * - Never start the host's PHP session.
 * - Send every response through emit(), so that each carries the no-store, CSP and framing
 *   headers.
 * - Decode request parameters without PHP's array and duplicate-key coercion.
 */
final class OAuthController extends BaseController {

	/** Name of the cookie that binds a pending approval to the browser that started it. */
	private const string COOKIE = '__Host-citomni_mcp_oauth';

	/** Largest query string or form body that is decoded, in bytes. */
	private const int MAX_BODY = 16384;

	/** Validated OAuth cfg of this request; null until guard() has run. */
	private ?Config $oauthConfig = null;


	/**
	 * Send the authorization server metadata (RFC 8414).
	 *
	 * @return never
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When mcp.oauth is invalid.
	 */
	public function metadata(): never {
		$config = $this->guard();
		$this->emit($this->json($config->metadata()));
	}


	/**
	 * Send the protected-resource metadata of the MCP resource (RFC 9728).
	 *
	 * @return never
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When mcp.oauth is invalid.
	 */
	public function resourceMetadata(): never {
		$config = $this->guard();
		$this->emit($this->json($config->resourceMetadata()));
	}


	/**
	 * Show the owner's approval page, or process the owner's decision.
	 *
	 * Runs the owner's side of the authorization-code flow: it shows the approval page for a
	 * valid authorization request and turns the owner's decision into League's redirect to the
	 * client's callback.
	 *
	 * Behavior:
	 * - GET: 200 with the approval page and a browser-binding cookie for a valid authorization
	 *   request with the configured resource, an exact callback and S256 PKCE. HEAD: 200 without
	 *   state or cookie.
	 * - POST: a redirect to the callback with the code, or with access_denied for a denial, and
	 *   iss; the cookie is cleared. Only a same-origin POST with the matching cookie and CSRF
	 *   value is decided, and an approval also needs the owner's password.
	 * - 403 for a POST from another origin, and the page again with 403 for a wrong password.
	 * - 400 invalid_request when ApproveAuthorization refuses the decision as invalid_request.
	 * - A protocol error gets League's error response: a redirect to the callback when the error
	 *   carries one, such as invalid_scope or access_denied, and JSON otherwise.
	 * - Admission failures get 429 or 503 with Retry-After.
	 *
	 * @return never
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When mcp.oauth is invalid, an OAuth secret is missing or empty, the owner password secret is not a password_hash() result, or league/oauth2-server is missing.
	 * @throws \LogicException When the private-key secret is not a readable PEM key.
	 * @throws \Defuse\Crypto\Exception\BadFormatException When the encryption secret is not a Defuse key.
	 */
	public function authorize(): never {
		$config = $this->guard();
		ServerFactory::requireDependency();
		$repository = new OAuthRepository($this->app);
		$server = ServerFactory::create($repository);
		try {
			if ($this->app->request->method() === 'GET' || $this->app->request->method() === 'HEAD') {
				$params = $this->parameters($this->app->request->queryString());
				$this->validateAuthorizationParameters($params, $config);
				$server->validateAuthorizationRequest((new ServerRequest('GET', $config->issuer . '/mcp/oauth/authorize'))->withQueryParams($params));
				// HEAD has no side effects, including no pending approval or cookie.
				if ($this->app->request->method() === 'HEAD') {
					$this->emit(new Response(200));
				}
				$pending = (new BeginAuthorization($this->app))->execute($repository, $params, $this->app->request->ip());
				if ($pending['outcome'] !== 'created') {
					$this->unavailable($pending['outcome'], 60);
				}
				$setCookie = self::COOKIE . '=' . $pending['browser'] . '; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=' . $config->values['approval_ttl'];
				$this->emit(ConsentPage::response($config, $pending['id'], $pending['csrf'])->withHeader('Set-Cookie', $setCookie));
			}

			$this->checkBrowserOrigin($config);
			$form = $this->formParameters();
			$cookie = $this->app->request->cookie(self::COOKIE);
			$complete = function (array $params, bool $approved) use ($server, $config): ResponseInterface {
				$this->validateAuthorizationParameters($params, $config);
				$auth = $server->validateAuthorizationRequest((new ServerRequest('GET', $config->issuer . '/mcp/oauth/authorize'))->withQueryParams($params));
				$auth->setUser(new User($config->values['owner_id']));
				$auth->setAuthorizationApproved($approved);
				return $server->completeAuthorizationRequest($auth, new Response());
			};
			$result = (new ApproveAuthorization($this->app))->execute($repository, $form, \is_string($cookie) ? $cookie : null, $this->app->request->ip(), $complete);
			if (\in_array($result['outcome'], ['rate_limited', 'capacity'], true)) {
				$this->unavailable($result['outcome'], 900);
			}
			if ($result['outcome'] === 'invalid_password') {
				$this->emit(ConsentPage::response($config, $form['request_id'], $form['csrf'], $config->values['texts']['invalid_password'], 403));
			}
			if (!isset($result['result'])) {
				$this->emit($this->json(['error' => 'invalid_request'], 400));
			}
			$response = $result['result'] instanceof OAuthServerException ? $result['result']->generateHttpResponse(new Response()) : $result['result'];
			if ($response->hasHeader('Location')) {
				$response = $response->withHeader('Set-Cookie', self::COOKIE . '=; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=0');
			}
			$this->emit($this->withIssuer($response, $config));
		} catch (OAuthServerException $e) {
			$this->emit($this->withIssuer($e->generateHttpResponse(new Response()), $config));
		}
	}


	/**
	 * Exchange an authorization code, or rotate a refresh token.
	 *
	 * Redeems a grant for the client, so that each code and each refresh token is redeemed at
	 * most once, also by parallel PHP workers.
	 *
	 * Behavior:
	 * - 400 invalid_target when resource does not name the MCP resource; only a refresh may
	 *   omit it.
	 * - The client authenticates with HTTP Basic or with client_secret in the body, not both.
	 * - League's token response, or its protocol error.
	 * - A full ledger gets 503 with Retry-After.
	 *
	 * @return never
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When mcp.oauth is invalid, an OAuth secret is missing or empty, or league/oauth2-server is missing.
	 * @throws \LogicException When the private-key secret is not a readable PEM key.
	 * @throws \Defuse\Crypto\Exception\BadFormatException When the encryption secret is not a Defuse key.
	 */
	public function token(): never {
		$config = $this->guard();
		ServerFactory::requireDependency();
		try {
			$params = $this->formParameters();
			if ((isset($params['resource']) && $params['resource'] !== $config->resource)
				|| (!isset($params['resource']) && ($params['grant_type'] ?? '') !== 'refresh_token')) {
				Audit::record($this->app, 'token.request', 'invalid_target', $this->app->request->ip());
				$this->emit($this->json(['error' => 'invalid_target'], 400));
			}
			$header = $this->app->request->header('Authorization') ?: $this->app->request->server('REDIRECT_HTTP_AUTHORIZATION');
			$hasHeader = \is_string($header) && $header !== '';
			if ($hasHeader && isset($params['client_secret'])) {
				throw OAuthServerException::invalidRequest('client_secret', 'Use one client authentication method.');
			}
			$request = (new ServerRequest('POST', $config->issuer . '/mcp/oauth/token', $hasHeader ? ['Authorization' => $header] : []))->withParsedBody($params);
			$repository = new OAuthRepository($this->app);
			$server = ServerFactory::create($repository);
			$issue = static fn(): ResponseInterface => $server->respondToAccessTokenRequest($request, new Response());
			$result = (new IssueTokens($this->app))->execute($repository, $issue, $params['grant_type'] ?? '', $this->app->request->ip());
			if ($result['outcome'] === 'capacity') {
				$this->unavailable('capacity', 60);
			}
			$this->emit($result['result'] instanceof OAuthServerException ? $result['result']->generateHttpResponse(new Response()) : $result['result']);
		} catch (OAuthServerException $e) {
			$this->emit($e->generateHttpResponse(new Response()));
		}
	}


	/**
	 * Require enabled OAuth, HTTPS and the issuer's host and port.
	 *
	 * Ends the request unless the OAuth server may serve it, so that every OAuth route applies
	 * the same conditions before it reads anything.
	 *
	 * Behavior:
	 * - 404 while OAuth is disabled.
	 * - 403 for another scheme, host or port than the issuer's.
	 * - Keeps the validated cfg for the CSP that emit() sends.
	 *
	 * @return Config The validated OAuth cfg.
	 * @throws \CitOmni\Mcp\Exception\McpConfigException When mcp.oauth.enabled is not a bool, or mcp.oauth is invalid.
	 */
	private function guard(): Config {
		if (!Config::enabled($this->app)) {
			$this->emit(new Response(404));
		}

		$config = $this->oauthConfig = new Config($this->app);
		$request = $this->app->request;
		if (!$request->isHttps() || $request->host() !== \parse_url($config->issuer, PHP_URL_HOST)
			|| $request->port() !== (\parse_url($config->issuer, PHP_URL_PORT) ?? 443)) {
			$this->emit(new Response(403));
		}

		return $config;
	}


	/**
	 * Enforce the resource, exact callback and S256 PKCE policy.
	 *
	 * @param array<string, string> $params Decoded protocol parameters.
	 * @param Config $config Validated OAuth cfg.
	 * @return void
	 * @throws OAuthServerException When a parameter breaks the policy.
	 */
	private function validateAuthorizationParameters(array $params, Config $config): void {
		if (($params['resource'] ?? null) !== $config->resource) {
			throw OAuthServerException::invalidRequest('resource');
		}

		if (($params['response_type'] ?? null) !== 'code' || !isset($params['client_id'], $params['redirect_uri'])) {
			throw OAuthServerException::invalidRequest('response_type');
		}
		// Require S256 even for confidential clients; League also accepts plain.
		if (($params['code_challenge_method'] ?? null) !== 'S256'
			|| \preg_match('/^[A-Za-z0-9_-]{43}$/D', $params['code_challenge'] ?? '') !== 1) {
			throw OAuthServerException::invalidRequest('code_challenge');
		}

		if (isset($params['state']) && \strlen($params['state']) > 1024) {
			throw OAuthServerException::invalidRequest('state');
		}
		// Enforce byte-exact callbacks before the OAuth library sees the request.
		if (!\in_array($params['redirect_uri'], $config->values['redirect_uris'], true)) {
			throw OAuthServerException::invalidRequest('redirect_uri');
		}
	}


	/**
	 * Reject a consent POST from a foreign or opaque origin.
	 *
	 * Behavior:
	 * - 403 when Origin is present and is not the issuer, null included.
	 * - 403 when Sec-Fetch-Site is present and is neither same-origin nor none.
	 *
	 * @param Config $config Validated OAuth cfg.
	 * @return void
	 */
	private function checkBrowserOrigin(Config $config): void {
		$origin = $this->app->request->header('Origin');
		$fetchSite = $this->app->request->header('Sec-Fetch-Site');
		if (($origin !== null && $origin !== $config->issuer) || ($fetchSite !== null && !\in_array($fetchSite, ['same-origin', 'none'], true))) {
			$this->emit(new Response(403));
		}
	}


	/**
	 * Read a URL-encoded request body without PHP's key coercion.
	 *
	 * Accepts only the one content type that the OAuth endpoints define for forms, and decodes
	 * it by the same rules as a query string.
	 *
	 * Behavior:
	 * - 415 for a body of another content type.
	 * - 413 for a body longer than MAX_BODY.
	 *
	 * @return array<string, string> The decoded body parameters.
	 * @throws OAuthServerException When parameters repeat, are malformed or are too many.
	 */
	private function formParameters(): array {
		$type = \strtolower(\trim(\explode(';', $this->app->request->header('Content-Type') ?? '', 2)[0]));
		if ($type !== 'application/x-www-form-urlencoded') {
			$this->emit($this->json(['error' => 'invalid_request'], 415));
		}

		return $this->parameters((new Psr7Bridge($this->app))->readBody(self::MAX_BODY));
	}


	/**
	 * Decode form or query parameters without PHP's duplicate-key coercion.
	 *
	 * Refuses every input that PHP would coerce, so that a parameter cannot mean one thing to
	 * this controller and another to League.
	 *
	 * Behavior:
	 * - 413 for input longer than MAX_BODY.
	 *
	 * @param string $raw Raw query string or URL-encoded body.
	 * @return array<string, string> Parameters by name.
	 * @throws OAuthServerException When a name repeats or is not 1 to 64 lowercase letters and underscores, a value holds NUL, or there are more than 32.
	 */
	private function parameters(#[\SensitiveParameter] string $raw): array {
		if (\strlen($raw) > self::MAX_BODY) {
			$this->emit($this->json(['error' => 'invalid_request'], 413));
		}

		$params = [];
		foreach ($raw === '' ? [] : \explode('&', $raw) as $part) {
			[$key, $value] = \array_pad(\explode('=', $part, 2), 2, '');
			$key = \urldecode($key);
			$value = \urldecode($value);
			if (\count($params) >= 32 || \preg_match('/^[a-z_]{1,64}$/D', $key) !== 1 || isset($params[$key])
				|| \str_contains($value, "\0")) {
				throw OAuthServerException::invalidRequest('parameters', 'Duplicate, malformed or excessive parameters.');
			}
			$params[$key] = $value;
		}

		return $params;
	}


	/**
	 * Add issuer identification (RFC 9207) to a redirect to the callback.
	 *
	 * @param ResponseInterface $response League's response; only a redirect is changed.
	 * @param Config $config Validated OAuth cfg.
	 * @return ResponseInterface The response, with iss added to its Location.
	 */
	private function withIssuer(ResponseInterface $response, Config $config): ResponseInterface {
		if (!$response->hasHeader('Location')) {
			return $response;
		}

		$uri = $response->getHeaderLine('Location');
		return $response->withHeader('Location', $uri . (\str_contains($uri, '?') ? '&' : '?')
			. 'iss=' . \rawurlencode($config->issuer));
	}


	/**
	 * Answer an admission failure with a retryable status.
	 *
	 * @param string $outcome rate_limited for 429; anything else gives 503.
	 * @param int $retryAfter Seconds for Retry-After.
	 * @return never
	 */
	private function unavailable(string $outcome, int $retryAfter): never {
		$this->emit($this->json(['error' => 'temporarily_unavailable'], $outcome === 'rate_limited' ? 429 : 503)
			->withHeader('Retry-After', (string)$retryAfter));
	}


	/**
	 * Build a JSON protocol response.
	 *
	 * @param array<string, mixed> $data Protocol response data.
	 * @param int $status HTTP status.
	 * @return ResponseInterface The JSON response.
	 */
	private function json(array $data, int $status = 200): ResponseInterface {
		return new Response($status, ['Content-Type' => 'application/json'], \json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}


	/**
	 * Add the browser and caching headers, then send the response and end the script.
	 *
	 * Behavior:
	 * - Adds Cache-Control no-store, Pragma no-cache, nosniff, Referrer-Policy same-origin,
	 *   X-Frame-Options DENY and a CSP that allows inline styles only and form actions to self
	 *   and the callback origins.
	 * - Sends no body for HEAD.
	 *
	 * @param ResponseInterface $response Response to send.
	 * @return never
	 */
	private function emit(ResponseInterface $response): never {
		$formActions = $this->oauthConfig?->formActionSources() ?? "'self'";
		$response = $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache')
			->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Referrer-Policy', 'same-origin')
			->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action {$formActions}; frame-ancestors 'none'; base-uri 'none'")
			->withHeader('X-Frame-Options', 'DENY');
		if ($this->app->request->method() === 'HEAD') {
			$response = $response->withBody((new Psr7Bridge($this->app))->factory()->createStream(''));
		}
		(new Psr7Bridge($this->app))->emit($response);
	}


}
