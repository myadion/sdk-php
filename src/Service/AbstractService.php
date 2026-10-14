<?php

declare(strict_types=1);

namespace MyAdion\Service;

use MyAdion\ApiObject;
use MyAdion\Client;
use MyAdion\Collection;
use MyAdion\Exception\ApiError;

/**
 * @phpstan-import-type RequestOptions from Client
 */
abstract class AbstractService
{
    public function __construct(protected readonly Client $client) {}

    /**
     * @param  array<string,mixed>  $params
     * @param  RequestOptions  $options
     */
    protected function object(string $method, string $path, array $params = [], array $options = []): ApiObject
    {
        $result = $this->client->request($method, $path, $params, $options);
        if (! $result instanceof ApiObject) {
            throw new ApiError('The API answered without an object for '.$method.' '.$path.'.');
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $params
     * @param  RequestOptions  $options
     */
    protected function collection(string $path, array $params = [], array $options = []): Collection
    {
        return $this->client->requestCollection('GET', $path, $params, $options);
    }

    /** `/v1/pay/sessions/%s` with each id encoded. */
    protected static function path(string $format, string ...$ids): string
    {
        foreach ($ids as $id) {
            if (trim($id) === '') {
                throw new \InvalidArgumentException('An identifier is empty.');
            }
        }

        return vsprintf($format, array_map('rawurlencode', $ids));
    }
}
