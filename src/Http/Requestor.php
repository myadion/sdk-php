<?php

declare(strict_types=1);

namespace MyAdion\Http;

use MyAdion\ApiObject;
use MyAdion\Exception\ApiConnectionError;
use MyAdion\Exception\ApiError;
use MyAdion\Exception\AuthenticationError;
use MyAdion\Exception\InvalidRequestError;
use MyAdion\Exception\MyAdionException;
use MyAdion\Exception\PermissionError;
use MyAdion\Exception\RateLimitError;
use MyAdion\TokenStore;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The HTTP engine behind the client: the token, the headers, the retries and
 * the translation of errors. Internal: use MyAdion\Client.
 *
 * @internal
 */
final class Requestor
{
    /** POST routes where the API honours `Idempotency-Key`: the library sets one and may retry them. */
    private const IDEMPOTENT_POSTS = [
        '#^/v1/pay/sessions$#',
    ];

    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    private const MAX_RETRY_DELAY = 8.0;

    private const MAX_RETRY_AFTER = 60.0;

    /** Seconds kept before the real expiry: a token is never sent in its last minute. */
    private const TOKEN_MARGIN = 60;

    /** @var array{access_token:string,expires_at:int}|null */
    private ?array $token = null;

    private ?Response $lastResponse = null;

    /** @var \Closure(float):void */
    private \Closure $sleep;

    /**
     * @param  list<string>  $scopes
     * @param  array<string,string>  $defaultHeaders
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUrl,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly ?string $staticToken,
        private readonly array $scopes,
        private readonly ?string $project,
        private readonly ?string $organization,
        private readonly int $maxRetries,
        private readonly float $retryBaseDelay,
        private readonly array $defaultHeaders,
        private readonly ?TokenStore $tokenStore = null,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    /**
     * Send a request and decode its JSON answer.
     *
     * @param  array<string,mixed>  $params  query string for GET and DELETE, JSON body otherwise
     * @param  array{project?:string|null,organization?:string|null,idempotency_key?:string|null,headers?:array<string,string>,max_retries?:int}  $options
     */
    public function request(string $method, string $path, array $params = [], array $options = []): mixed
    {
        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');

        $headers = $this->defaultHeaders;
        $project = array_key_exists('project', $options) ? $options['project'] : $this->project;
        if ($project !== null && $project !== '') {
            $headers['x-api-project'] = $project;
        }
        $organization = array_key_exists('organization', $options) ? $options['organization'] : $this->organization;
        if ($organization !== null && $organization !== '') {
            $headers['x-api-org'] = $organization;
        }

        $retryable = in_array($method, ['GET', 'HEAD', 'PUT', 'DELETE'], true);
        if ($method === 'POST') {
            $key = $options['idempotency_key'] ?? null;
            if ($this->honoursIdempotency($path)) {
                $key ??= 'myadion-php-'.bin2hex(random_bytes(16));
                $retryable = true;
            }
            if ($key !== null && $key !== '') {
                $headers['Idempotency-Key'] = $key;
            }
        }
        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[$name] = $value;
        }

        $url = $this->baseUrl.$path;
        $body = null;
        if (in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
            $query = self::encodeQuery($params);
            if ($query !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?').$query;
            }
        } else {
            $body = json_encode($params === [] ? new \stdClass : $params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $headers['Content-Type'] = 'application/json';
        }

        $maxRetries = $options['max_retries'] ?? $this->maxRetries;
        $response = $this->sendWithRetries($method, $url, $headers, $body, $retryable, $maxRetries, true);

        return self::decode($response);
    }

    /** A valid access token, fetched or renewed if needed. */
    public function accessToken(): string
    {
        if ($this->staticToken !== null) {
            return $this->staticToken;
        }
        if ($this->token === null && $this->tokenStore !== null) {
            $this->token = $this->tokenStore->get($this->tokenKey());
        }
        if ($this->token !== null && time() < $this->token['expires_at'] - self::TOKEN_MARGIN) {
            return $this->token['access_token'];
        }

        return $this->fetchToken();
    }

    public function forgetToken(): void
    {
        $this->token = null;
        $this->tokenStore?->forget($this->tokenKey());
    }

    private function fetchToken(): string
    {
        if ($this->clientId === null || $this->clientId === '' || $this->clientSecret === null || $this->clientSecret === '') {
            throw new AuthenticationError('No credentials: pass client_id and client_secret (or access_token) to MyAdion\Client.');
        }

        $form = ['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret];
        if ($this->scopes !== []) {
            $form['scope'] = implode(' ', $this->scopes);
        }
        $headers = $this->defaultHeaders;
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        $response = $this->sendWithRetries('POST', $this->baseUrl.'/auth/token', $headers, http_build_query($form, '', '&', PHP_QUERY_RFC3986), true, $this->maxRetries, false);
        $decoded = self::decode($response);
        $token = $decoded instanceof ApiObject ? $decoded['access_token'] : null;
        if (! is_string($token) || $token === '') {
            throw new ApiError('The token endpoint answered without access_token.', $response->status, null, [], $response->headers, $response->body);
        }
        $expiresIn = $decoded['expires_in'];
        $this->token = [
            'access_token' => $token,
            'expires_at' => time() + (is_numeric($expiresIn) ? (int) $expiresIn : 3600),
        ];
        $this->tokenStore?->put($this->tokenKey(), $this->token);

        return $token;
    }

    private function tokenKey(): string
    {
        return 'myadion_token_'.hash('sha256', $this->baseUrl.'|'.$this->clientId.'|'.implode(' ', $this->scopes));
    }

    private function honoursIdempotency(string $path): bool
    {
        $path = (string) strtok($path, '?');
        foreach (self::IDEMPOTENT_POSTS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,string>  $headers
     */
    private function sendWithRetries(string $method, string $url, array $headers, ?string $body, bool $retryable, int $maxRetries, bool $authenticated): Response
    {
        $attempt = 0;
        $renewedToken = false;
        while (true) {
            if ($authenticated) {
                $headers['Authorization'] = 'Bearer '.$this->accessToken();
            }
            $request = $this->requestFactory->createRequest($method, $url);
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            if ($body !== null) {
                $request = $request->withBody($this->streamFactory->createStream($body));
            }

            try {
                $psr = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                if ($retryable && $attempt < $maxRetries) {
                    ($this->sleep)($this->delay($attempt, null));
                    $attempt++;

                    continue;
                }
                throw new ApiConnectionError('Could not reach the Adion Cloud API: '.$e->getMessage(), null, null, [], [], null, $e);
            }

            $response = self::wrap($psr);
            $this->lastResponse = $response;

            // A managed token refused once (revoked, expired early): one new token, one new try.
            if ($response->status === 401 && $authenticated && $this->staticToken === null && ! $renewedToken) {
                $renewedToken = true;
                $this->forgetToken();

                continue;
            }

            if (in_array($response->status, self::RETRY_STATUSES, true) && $attempt < $maxRetries
                && ($retryable || $response->status === 429)) {
                ($this->sleep)($this->delay($attempt, $response->header('retry-after')));
                $attempt++;

                continue;
            }

            if ($response->status >= 400) {
                throw self::error($response);
            }

            return $response;
        }
    }

    private function delay(int $attempt, ?string $retryAfter): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return min((float) $retryAfter, self::MAX_RETRY_AFTER);
        }
        $delay = min($this->retryBaseDelay * (2 ** $attempt), self::MAX_RETRY_DELAY);

        // Jitter: between half and all of the delay, so that clients do not retry in step.
        return $delay * (0.5 + mt_rand() / mt_getrandmax() / 2);
    }

    private static function wrap(ResponseInterface $psr): Response
    {
        $headers = [];
        foreach ($psr->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = array_values(array_map('strval', $values));
        }

        return new Response($psr->getStatusCode(), $headers, (string) $psr->getBody());
    }

    private static function decode(Response $response): mixed
    {
        $body = trim($response->body);
        if ($body === '') {
            return null;
        }
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ApiError('The API answered something that is not JSON.', $response->status, null, [], $response->headers, $response->body, $e);
        }

        return ApiObject::convert($decoded);
    }

    private static function error(Response $response): MyAdionException
    {
        $message = null;
        $code = null;
        $details = [];
        $decoded = json_decode($response->body, true);
        if (is_array($decoded)) {
            $message = is_string($decoded['message'] ?? null) ? $decoded['message']
                : (is_string($decoded['error_description'] ?? null) ? $decoded['error_description'] : null);
            $code = is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
            $details = is_array($decoded['details'] ?? null) ? $decoded['details'] : [];
        }
        $message ??= 'The API answered HTTP '.$response->status.'.';
        /** @var array<string,mixed> $details */
        $args = [$message, $response->status, $code, $details, $response->headers, $response->body];

        return match (true) {
            $response->status === 401 => new AuthenticationError(...$args),
            $response->status === 403 => new PermissionError(...$args),
            $response->status === 429 => new RateLimitError(...$args),
            $response->status >= 500 => new ApiError(...$args),
            default => new InvalidRequestError(...$args),
        };
    }

    /**
     * `page=2&status=succeeded`, with booleans as 1/0 and lists as `key[]=`.
     *
     * @param  array<string,mixed>  $params
     */
    public static function encodeQuery(array $params): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $clean[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }
}
