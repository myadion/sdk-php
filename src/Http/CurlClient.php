<?php

declare(strict_types=1);

namespace MyAdion\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A minimal PSR-18 client on ext-curl, used when Guzzle is not installed.
 * It follows no redirect and never throws on an HTTP status.
 */
final class CurlClient implements ClientInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
    ) {
        if (! function_exists('curl_init')) {
            throw new \LogicException('ext-curl is required when no PSR-18 client is given. Install guzzlehttp/guzzle or enable ext-curl.');
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new NetworkException('Unable to initialise cURL.', $request);
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name.': '.$value;
            }
        }
        // cURL adds "Expect: 100-continue" to large bodies: one round trip lost for nothing.
        $headers[] = 'Expect:';

        $body = (string) $request->getBody();
        $method = strtoupper($request->getMethod());
        $url = (string) $request->getUri();
        if ($url === '' || $method === '') {
            throw new NetworkException('A request needs a method and a URL.', $request);
        }

        /** @var list<array{0:string,1:string}> $responseHeaders */
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) round($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeout * 1000),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = []; // a new response (after 100 Continue)
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[] = [trim($name), trim($value)];
                }

                return strlen($line);
            },
        ]);
        if ($method === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        } elseif ($body !== '' || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if (! is_string($raw)) {
            $message = curl_error($handle) ?: 'Unknown cURL error';
            $errno = curl_errno($handle);
            throw new NetworkException(sprintf('cURL error %d: %s', $errno, $message), $request, $errno);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        $response = $this->responseFactory->createResponse($status);
        foreach ($responseHeaders as [$name, $value]) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response->withBody($this->streamFactory->createStream($raw));
    }
}
