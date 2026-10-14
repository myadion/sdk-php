<?php

declare(strict_types=1);

namespace MyAdion;

/**
 * Where the access token lives between two PHP requests.
 *
 * Without a store, each PHP process asks for a token on its first call. With
 * one (the Laravel bridge plugs the application cache in), every process
 * shares the same token until shortly before it expires.
 */
interface TokenStore
{
    /** @return array{access_token:string,expires_at:int}|null */
    public function get(string $key): ?array;

    /** @param array{access_token:string,expires_at:int} $token */
    public function put(string $key, array $token): void;

    public function forget(string $key): void;
}
