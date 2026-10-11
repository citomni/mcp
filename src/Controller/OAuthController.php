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
use CitOmni\Mcp\Exception\OAuthCapacityException;
use CitOmni\Mcp\Operation\OAuth\BeginAuthorization;
use CitOmni\Mcp\Operation\OAuth\ApproveAuthorization;
use CitOmni\Mcp\Operation\OAuth\IssueTokens;
use CitOmni\Mcp\Support\OAuth\ConsentPage;
use CitOmni\Mcp\Support\OAuth\Audit;
use CitOmni\Mcp\Repository\OAuthRepository;
use CitOmni\Mcp\Support\OAuth\Config;
use CitOmni\Mcp\Support\OAuth\ServerFactory;
use CitOmni\Mcp\Support\OAuth\User;
use CitOmni\Mcp\Support\Psr7Bridge;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP adapters for the optional single-owner OAuth authorization server.
 *
 * Uses League for authorization codes, PKCE, encrypted refresh tokens and
 * protocol errors. Consent is explicit, password-protected, CSRF-protected,
 * browser-bound and short-lived; it never starts the host's PHP session.
 */
final class OAuthController extends BaseController {
	private const string COOKIE = '__Host-citomni_mcp_oauth';
	private const int MAX_BODY = 16384;
	private ?Config $oauthConfig = null;

	/**
	 * Emit the authorization server metadata for the configured issuer.
	 *
	 * @return never
	 */
	public function metadata(): never {
		$config = $this->guard();
		$this->emit($this->json($config->metadata()));
	}

	/**
	 * Describe the protected MCP resource and its authorization server.
	 *
	 * @return never
	 */
	public function resourceMetadata(): never {
		$config = $this->guard();
		$this->emit($this->json($config->resourceMetadata()));
	}

	/** Validate the protocol request before showing a password or redirecting. */
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
				$this->emit(ConsentPage::response($config, $pending['id'], $pending['csrf'])->withHeader('Set-Cookie', self::COOKIE . '=' . $pending['browser']
					. '; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=' . $config->values['approval_ttl']));
			}

			$this->checkBrowserOrigin($config);
			$form = $this->formParameters();
			$cookie = $this->app->request->cookie(self::COOKIE);
			$result = (new ApproveAuthorization($this->app))->execute($repository, $form, \is_string($cookie) ? $cookie : null, $this->app->request->ip(),
				function (array $params, bool $approved) use ($server, $config): ResponseInterface {
					$this->validateAuthorizationParameters($params, $config);
					$auth = $server->validateAuthorizationRequest((new ServerRequest('GET', $config->issuer . '/mcp/oauth/authorize'))->withQueryParams($params));
					$auth->setUser(new User($config->values['owner_id']));
					$auth->setAuthorizationApproved($approved);
					return $server->completeAuthorizationRequest($auth, new Response());
				});
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

	/** Redeem codes and rotate refresh tokens atomically across PHP workers. */
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
			if (\is_string($header) && $header !== '' && isset($params['client_secret'])) {
				throw OAuthServerException::invalidRequest('client_secret', 'Use one client authentication method.');
			}
			$request = (new ServerRequest('POST', $config->issuer . '/mcp/oauth/token',
				\is_string($header) && $header !== '' ? ['Authorization' => $header] : []))->withParsedBody($params);
			$repository = new OAuthRepository($this->app);
			$server = ServerFactory::create($repository);
			$result = (new IssueTokens($this->app))->execute($repository,
				static fn(): ResponseInterface => $server->respondToAccessTokenRequest($request, new Response()),
				$params['grant_type'] ?? '', $this->app->request->ip());
			$response = $result instanceof OAuthServerException ? $result->generateHttpResponse(new Response()) : $result;
			$this->emit($response);
		} catch (OAuthCapacityException) {
			Audit::record($this->app, 'token.request', 'capacity', $this->app->request->ip());
			$this->unavailable('capacity', 60);
		} catch (OAuthServerException $e) {
			$this->emit($e->generateHttpResponse(new Response()));
		}
	}

	/**
	 * Require enabled OAuth, HTTPS and the configured canonical host and port.
	 *
	 * @return Config Validated result.
	 */
	private function guard(): Config {
		if (!(bool)($this->app->cfg->mcp->oauth->enabled ?? false)) {
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
	 * @param array $params Decoded scalar protocol parameters.
	 * @param Config $config Validated OAuth configuration.
	 * @return void
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
	 * Reject explicit foreign or opaque origins before processing consent.
	 *
	 * @param Config $config Validated OAuth configuration.
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
	 * Read a bounded URL-encoded request body without PHP key coercion.
	 *
	 * @return array Validated result.
	 */
	private function formParameters(): array {
		$type = \strtolower(\trim(\explode(';', $this->app->request->header('Content-Type') ?? '', 2)[0]));
		if ($type !== 'application/x-www-form-urlencoded') {
			$this->emit($this->json(['error' => 'invalid_request'], 415));
		}

		return $this->parameters((new Psr7Bridge($this->app))->readBody(self::MAX_BODY));
	}

	/** Decode scalar form/query parameters without PHP's duplicate-key coercion. */
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

	/** Add issuer identification to validated success and error callbacks. */
	private function withIssuer(ResponseInterface $response, Config $config): ResponseInterface {
		if (!$response->hasHeader('Location')) {
			return $response;
		}

		$uri = $response->getHeaderLine('Location');
		return $response->withHeader('Location', $uri . (\str_contains($uri, '?') ? '&' : '?')
			. 'iss=' . \rawurlencode($config->issuer));
	}


	/** Convert bounded admission failure into a retryable transport response. */
	private function unavailable(string $outcome, int $retryAfter): never {
		$this->emit($this->json(['error' => 'temporarily_unavailable'], $outcome === 'rate_limited' ? 429 : 503)
			->withHeader('Retry-After', (string)$retryAfter));
	}

	/**
	 * Construct a JSON protocol response without transport side effects.
	 *
	 * @param array $data Protocol response data.
	 * @param int $status HTTP response status.
	 * @return ResponseInterface Validated result.
	 */
	private function json(array $data, int $status = 200): ResponseInterface {
		return new Response($status, ['Content-Type' => 'application/json'], \json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	/**
	 * Apply browser and caching policy, then emit the response.
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
