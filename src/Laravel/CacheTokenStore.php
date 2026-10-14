<?php

declare(strict_types=1);

namespace MyAdion\Laravel;

use Illuminate\Contracts\Cache\Repository;
use MyAdion\TokenStore;

/** The access token in the Laravel cache, until shortly before it expires. */
final class CacheTokenStore implements TokenStore
{
    public function __construct(private readonly Repository $cache) {}

    public function get(string $key): ?array
    {
        $value = $this->cache->get($key);
        if (! is_array($value) || ! is_string($value['access_token'] ?? null) || ! is_int($value['expires_at'] ?? null)) {
            return null;
        }

        return ['access_token' => $value['access_token'], 'expires_at' => $value['expires_at']];
    }

    public function put(string $key, array $token): void
    {
        $this->cache->put($key, $token, max(1, $token['expires_at'] - time()));
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }
}
