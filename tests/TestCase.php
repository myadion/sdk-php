<?php

declare(strict_types=1);

namespace MyAdion\Tests;

use MyAdion\Client;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::fake('POST', '/__reset');
    }

    /**
     * @param  array<string,mixed>  $options
     */
    protected static function client(array $options = []): Client
    {
        return new Client($options + [
            'client_id' => 'test_client',
            'client_secret' => 'test_secret',
            'base_url' => MYADION_FAKE_URL.'/api',
            'retry_base_delay' => 0.0,
            'timeout' => 5,
        ]);
    }

    /**
     * Inject failures: each entry answers `status` to `times` matching calls.
     *
     * @param  list<array<string,mixed>>  $failures
     */
    protected static function failNext(array $failures): void
    {
        self::fake('POST', '/__scenario', ['fail' => $failures]);
    }

    /** @param array<string,mixed> $values */
    protected static function scenario(array $values): void
    {
        self::fake('POST', '/__scenario', $values);
    }

    /**
     * The requests the fake API received, test hooks excluded.
     *
     * @return list<array{method:string,path:string,query:array<string,mixed>,headers:array<string,string>,body:string}>
     */
    protected static function received(): array
    {
        /** @var list<array{method:string,path:string,query:array<string,mixed>,headers:array<string,string>,body:string}> */
        return self::fake('GET', '/__log');
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private static function fake(string $method, string $path, array $body = []): mixed
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'content' => $body === [] ? '' : json_encode($body),
            'ignore_errors' => true,
        ]]);
        $raw = file_get_contents(MYADION_FAKE_URL.$path, false, $context);

        return json_decode((string) $raw, true);
    }
}
