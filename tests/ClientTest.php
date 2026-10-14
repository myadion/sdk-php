<?php

declare(strict_types=1);

namespace MyAdion\Tests;

use GuzzleHttp\Client as GuzzleClient;
use MyAdion\ApiObject;
use MyAdion\Client;
use MyAdion\Exception\AuthenticationError;
use MyAdion\Http\CurlClient;
use MyAdion\TokenStore;
use Nyholm\Psr7\Factory\Psr17Factory;

final class ClientTest extends TestCase
{
    public function test_it_needs_credentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(['client_id' => 'only_an_id']);
    }

    public function test_it_refuses_unknown_options(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api_key');
        new Client(['client_id' => 'a', 'client_secret' => 'b', 'api_key' => 'c']);
    }

    public function test_it_fetches_one_token_and_reuses_it(): void
    {
        $client = self::client(['scopes' => ['pay.session.write', 'pay.payment.read']]);
        $client->request('GET', '/echo');
        $client->request('GET', '/echo');

        $log = self::received();
        $this->assertSame(['/api/auth/token', '/api/echo', '/api/echo'], array_column($log, 'path'));
        parse_str($log[0]['body'], $form);
        $this->assertSame('client_credentials', $form['grant_type']);
        $this->assertSame('pay.session.write pay.payment.read', $form['scope']);
        $this->assertStringStartsWith('Bearer tok_1_', $log[1]['headers']['authorization']);
        $this->assertSame($log[1]['headers']['authorization'], $log[2]['headers']['authorization']);
        $this->assertStringStartsWith('myadion-php/'.Client::VERSION, $log[1]['headers']['user-agent']);
    }

    public function test_it_renews_a_token_close_to_expiry(): void
    {
        self::scenario(['token_expires_in' => 30]);
        $client = self::client();
        $client->request('GET', '/echo');
        $client->request('GET', '/echo');

        $this->assertSame(2, count(array_filter(self::received(), fn ($r) => $r['path'] === '/api/auth/token')));
    }

    public function test_it_renews_a_revoked_token_once(): void
    {
        $client = self::client();
        $client->request('GET', '/echo');
        self::scenario(['tokens' => new \stdClass]); // every token revoked

        $result = $client->request('GET', '/echo');

        $this->assertInstanceOf(ApiObject::class, $result);
        $this->assertSame(
            ['/api/auth/token', '/api/echo', '/api/echo', '/api/auth/token', '/api/echo'],
            array_column(self::received(), 'path'),
        );
    }

    public function test_wrong_credentials_raise_an_authentication_error(): void
    {
        $client = self::client(['client_secret' => 'wrong']);

        try {
            $client->request('GET', '/echo');
            $this->fail('No exception');
        } catch (AuthenticationError $e) {
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertSame('unauthorized', $e->getErrorCode());
            $this->assertSame('Le secret client est invalide.', $e->getMessage());
        }
    }

    public function test_a_static_access_token_is_sent_as_is(): void
    {
        $client = new Client(['access_token' => 'tok_static', 'base_url' => MYADION_FAKE_URL.'/api', 'retry_base_delay' => 0]);

        $this->expectException(AuthenticationError::class);
        try {
            $client->request('GET', '/echo');
        } finally {
            $log = self::received();
            $this->assertCount(1, $log, 'a static token is never renewed');
            $this->assertSame('Bearer tok_static', $log[0]['headers']['authorization']);
        }
    }

    public function test_project_and_organization_headers(): void
    {
        $client = self::client(['project' => 'project_AbCdEf123456', 'organization' => 'org_AbCdEf123456']);
        $result = $client->request('GET', '/echo');
        $this->assertInstanceOf(ApiObject::class, $result);
        $this->assertSame('project_AbCdEf123456', $result->project);

        $client->request('GET', '/echo', [], ['project' => 'project_ZyXwVu987654']);
        $client->request('GET', '/echo', [], ['project' => null]);

        $log = self::received();
        $this->assertSame('org_AbCdEf123456', $log[1]['headers']['x-api-org']);
        $this->assertSame('project_ZyXwVu987654', $log[2]['headers']['x-api-project']);
        $this->assertArrayNotHasKey('x-api-project', $log[3]['headers']);
    }

    public function test_query_and_json_body_encoding(): void
    {
        $client = self::client();
        $get = $client->request('GET', 'echo', ['detail' => true, 'page' => 2, 'skip' => null]);
        $post = $client->request('POST', '/echo', ['amount_cents' => 4990, 'metadata' => ['order_id' => '4521'], 'description' => 'Café']);

        $this->assertInstanceOf(ApiObject::class, $get);
        $this->assertSame(['detail' => '1', 'page' => '2'], $get->query->toArray());
        $this->assertInstanceOf(ApiObject::class, $post);
        $this->assertSame(4990, $post->body->amount_cents);
        $this->assertSame('4521', $post->body->metadata->order_id);
        $this->assertSame('Café', $post['body']['description']);
        $this->assertSame('application/json', self::received()[2]['headers']['content-type']);
    }

    public function test_a_token_store_is_shared_between_clients(): void
    {
        $store = new class implements TokenStore
        {
            /** @var array<string,array{access_token:string,expires_at:int}> */
            public array $tokens = [];

            public function get(string $key): ?array
            {
                return $this->tokens[$key] ?? null;
            }

            public function put(string $key, array $token): void
            {
                $this->tokens[$key] = $token;
            }

            public function forget(string $key): void
            {
                unset($this->tokens[$key]);
            }
        };

        self::client(['token_store' => $store])->request('GET', '/echo');
        self::client(['token_store' => $store])->request('GET', '/echo');

        $this->assertSame(1, count(array_filter(self::received(), fn ($r) => $r['path'] === '/api/auth/token')));
        $this->assertCount(1, $store->tokens);
    }

    public function test_both_transports_work(): void
    {
        $factory = new Psr17Factory;
        $curl = self::client(['http_client' => new CurlClient($factory, $factory)]);
        $guzzle = self::client(['http_client' => new GuzzleClient(['http_errors' => false])]);

        foreach ([$curl, $guzzle] as $client) {
            $result = $client->request('POST', '/echo', ['a' => 1]);
            $this->assertInstanceOf(ApiObject::class, $result);
            $this->assertSame(1, $result->body->a);
            $this->assertSame(200, $client->getLastResponse()?->status);
        }
    }
}
