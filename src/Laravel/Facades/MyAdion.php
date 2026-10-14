<?php

declare(strict_types=1);

namespace MyAdion\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use MyAdion\Client;

/**
 *     MyAdion::request('GET', '/account/pay');
 *     MyAdion::getFacadeRoot()->pay->sessions->create([...]);
 *     app(\MyAdion\Client::class)->pay->sessions->create([...]);
 *
 * @method static \MyAdion\ApiObject|list<mixed>|null request(string $method, string $path, array<string,mixed> $params = [], array<string,mixed> $options = [])
 * @method static \MyAdion\Collection requestCollection(string $method, string $path, array<string,mixed> $params = [], array<string,mixed> $options = [])
 * @method static \MyAdion\Http\Response|null getLastResponse()
 * @method static string getAccessToken()
 *
 * @see Client
 */
final class MyAdion extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
