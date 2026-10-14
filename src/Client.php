<?php

declare(strict_types=1);

namespace MyAdion;

use GuzzleHttp\Client as GuzzleClient;
use MyAdion\Http\CurlClient;
use MyAdion\Http\Requestor;
use MyAdion\Http\Response;
use MyAdion\Service\Pay\PayService;
use MyAdion\Service\SmsService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The entry point of the library.
 *
 *     $client = new \MyAdion\Client([
 *         'client_id' => getenv('MYADION_CLIENT_ID'),
 *         'client_secret' => getenv('MYADION_CLIENT_SECRET'),
 *         'project' => 'project_AbCdEf123456', // optional
 *     ]);
 *
 *     $session = $client->pay->sessions->create([...]);
 *     $result = $client->request('GET', '/account/pay');
 *
 * The client exchanges its credentials for an access token on the first call
 * (`POST /auth/token`, client_credentials) and renews it before it expires.
 *
 * @phpstan-type ClientConfig array{
 *     client_id?: string|null,
 *     client_secret?: string|null,
 *     access_token?: string|null,
 *     scopes?: list<string>|string|null,
 *     project?: string|null,
 *     organization?: string|null,
 *     base_url?: string|null,
 *     timeout?: float|int,
 *     connect_timeout?: float|int,
 *     max_retries?: int,
 *     retry_base_delay?: float|int,
 *     http_client?: ClientInterface|null,
 *     request_factory?: RequestFactoryInterface|null,
 *     stream_factory?: StreamFactoryInterface|null,
 *     token_store?: TokenStore|null,
 *     app_info?: array{name: string, version?: string, url?: string}|null,
 * }
 * @phpstan-type RequestOptions array{
 *     project?: string|null,
 *     organization?: string|null,
 *     idempotency_key?: string|null,
 *     headers?: array<string,string>,
 *     max_retries?: int,
 * }
 */
final class Client
{
    public const VERSION = '0.2.0';

    public const DEFAULT_BASE_URL = 'https://adion-api.eu/api';

    /** Adion Pay: sessions, payments, refunds, webhook endpoints. */
    public readonly PayService $pay;

    /** Adion SMS: send and read text messages. */
    public readonly SmsService $sms;

    private readonly Requestor $requestor;

    /**
     * @param  ClientConfig  $config
     */
    public function __construct(array $config = [])
    {
        $known = ['client_id', 'client_secret', 'access_token', 'scopes', 'project', 'organization', 'base_url', 'timeout',
            'connect_timeout', 'max_retries', 'retry_base_delay', 'http_client', 'request_factory', 'stream_factory', 'token_store', 'app_info'];
        $unknown = array_diff(array_keys($config), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown option(s) for MyAdion\Client: '.implode(', ', $unknown).'.');
        }

        $clientId = self::nonEmpty($config['client_id'] ?? null);
        $clientSecret = self::nonEmpty($config['client_secret'] ?? null);
        $accessToken = self::nonEmpty($config['access_token'] ?? null);
        if ($accessToken === null && ($clientId === null || $clientSecret === null)) {
            throw new \InvalidArgumentException('MyAdion\Client needs client_id and client_secret (or an access_token).');
        }

        $scopes = $config['scopes'] ?? [];
        if (is_string($scopes)) {
            $scopes = preg_split('/[\s,]+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $baseUrl = rtrim(self::nonEmpty($config['base_url'] ?? null) ?? self::DEFAULT_BASE_URL, '/');
        $timeout = (float) ($config['timeout'] ?? 30.0);
        $connectTimeout = (float) ($config['connect_timeout'] ?? 10.0);

        $factory = new Psr17Factory;
        $requestFactory = $config['request_factory'] ?? $factory;
        $streamFactory = $config['stream_factory'] ?? $factory;
        $http = $config['http_client'] ?? self::defaultHttpClient($factory, $streamFactory, $timeout, $connectTimeout);

        $userAgent = 'myadion-php/'.self::VERSION.' PHP/'.PHP_VERSION;
        $appInfo = $config['app_info'] ?? null;
        if ($appInfo !== null) {
            $userAgent .= ' '.$appInfo['name'].(isset($appInfo['version']) ? '/'.$appInfo['version'] : '')
                .(isset($appInfo['url']) ? ' ('.$appInfo['url'].')' : '');
        }

        $this->requestor = new Requestor(
            $http,
            $requestFactory,
            $streamFactory,
            $baseUrl,
            $clientId,
            $clientSecret,
            $accessToken,
            $scopes,
            self::nonEmpty($config['project'] ?? null),
            self::nonEmpty($config['organization'] ?? null),
            max(0, (int) ($config['max_retries'] ?? 2)),
            max(0.0, (float) ($config['retry_base_delay'] ?? 0.5)),
            ['Accept' => 'application/json', 'User-Agent' => $userAgent],
            $config['token_store'] ?? null,
        );

        $this->pay = new PayService($this);
        $this->sms = new SmsService($this);
    }

    /**
     * Call any route of the API. `$path` is relative to the base URL
     * (`/account/pay`, `/v1/sms/sms_msg_…`).
     *
     *     $client->request('GET', '/account/pay');
     *     $client->request('POST', '/account/pay/pay_acct_…/webhook', ['url' => …], ['project' => 'project_…']);
     *
     * @param  array<string,mixed>  $params  query string for GET and DELETE, JSON body otherwise
     * @param  RequestOptions  $options
     * @return ApiObject|list<mixed>|null the decoded answer
     */
    public function request(string $method, string $path, array $params = [], array $options = []): ApiObject|array|null
    {
        $decoded = $this->requestor->request($method, $path, $params, $options);
        if ($decoded === null || $decoded instanceof ApiObject || is_array($decoded)) {
            /** @var ApiObject|list<mixed>|null $decoded */
            return $decoded;
        }

        return ApiObject::constructFrom(['value' => $decoded]);
    }

    /**
     * Call a list route and get a Collection that knows how to fetch the
     * following pages.
     *
     *     foreach ($client->requestCollection('GET', '/account/pay/pay_acct_…/payment')->autoPagingIterator() as $p) { … }
     *
     * @param  array<string,mixed>  $params
     * @param  RequestOptions  $options
     */
    public function requestCollection(string $method, string $path, array $params = [], array $options = []): Collection
    {
        $fetch = function (int $page) use ($method, $path, $params, $options): Collection {
            return $this->requestCollection($method, $path, ['page' => $page] + $params, $options);
        };

        return Collection::fromResponse($this->requestor->request($method, $path, $params, $options), $params, $fetch);
    }

    /** The raw HTTP answer of the last call (status, headers, body). */
    public function getLastResponse(): ?Response
    {
        return $this->requestor->lastResponse();
    }

    /** The current access token, fetched if needed. */
    public function getAccessToken(): string
    {
        return $this->requestor->accessToken();
    }

    private static function defaultHttpClient(ResponseFactoryInterface $responses, StreamFactoryInterface $streams, float $timeout, float $connectTimeout): ClientInterface
    {
        if (class_exists(GuzzleClient::class)) {
            return new GuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        }

        return new CurlClient($responses, $streams, $timeout, $connectTimeout);
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
