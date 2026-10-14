<?php

declare(strict_types=1);

namespace MyAdion\Tests;

use MyAdion\Exception\ApiConnectionError;
use MyAdion\Exception\ApiError;
use MyAdion\Exception\InvalidRequestError;
use MyAdion\Exception\PermissionError;
use MyAdion\Exception\RateLimitError;

final class RetryAndErrorTest extends TestCase
{
    public function test_a_get_is_retried_after_server_errors(): void
    {
        self::failNext([['method' => 'GET', 'path' => '/api/v1/pay/payments', 'status' => 503, 'times' => 2]]);

        $page = self::client()->pay->payments->all();

        $this->assertCount(5, $page);
        $this->assertCount(3, array_filter(self::received(), fn ($r) => $r['path'] === '/api/v1/pay/payments'));
    }

    public function test_retries_stop_at_max_retries(): void
    {
        self::failNext([['method' => 'GET', 'path' => '/api/v1/pay/payments', 'status' => 500, 'times' => 5]]);

        try {
            self::client(['max_retries' => 1])->pay->payments->all();
            $this->fail('No exception');
        } catch (ApiError $e) {
            $this->assertSame(500, $e->getHttpStatus());
            $this->assertSame('server_error', $e->getErrorCode());
        }
        $this->assertCount(2, array_filter(self::received(), fn ($r) => $r['path'] === '/api/v1/pay/payments'));
    }

    public function test_a_session_is_retried_with_the_same_idempotency_key(): void
    {
        self::failNext([['method' => 'POST', 'path' => '/api/v1/pay/sessions', 'status' => 502, 'times' => 1]]);

        $session = self::client()->pay->sessions->create(self::sessionParams());

        $this->assertSame('open', $session->status);
        $calls = array_values(array_filter(self::received(), fn ($r) => $r['path'] === '/api/v1/pay/sessions'));
        $this->assertCount(2, $calls);
        $this->assertStringStartsWith('myadion-php-', $calls[0]['headers']['idempotency-key']);
        $this->assertSame($calls[0]['headers']['idempotency-key'], $calls[1]['headers']['idempotency-key']);
    }

    public function test_a_refund_is_never_retried_after_a_server_error(): void
    {
        self::failNext([['method' => 'POST', 'path' => '/api/v1/pay/payments/pay_pmt_Test00000001/refunds', 'status' => 500, 'times' => 1]]);

        $this->expectException(ApiError::class);
        try {
            self::client()->pay->refunds->create('pay_pmt_Test00000001');
        } finally {
            $this->assertCount(1, array_filter(self::received(), fn ($r) => str_ends_with($r['path'], '/refunds')));
        }
    }

    public function test_a_429_is_retried_even_for_an_sms(): void
    {
        self::failNext([['method' => 'POST', 'path' => '/api/v1/sms', 'status' => 429, 'times' => 1, 'headers' => ['Retry-After' => '0']]]);

        $batch = self::client()->sms->send(['service_id' => 'service_AbCdEf123456', 'to' => '+33612345678', 'message' => 'Hi', 'type' => 'transactional']);

        $this->assertCount(1, $batch->messages);
        $this->assertCount(2, array_filter(self::received(), fn ($r) => $r['path'] === '/api/v1/sms'));
        $this->assertArrayNotHasKey('idempotency-key', self::received()[1]['headers']);
    }

    public function test_a_persistent_429_raises_a_rate_limit_error(): void
    {
        self::failNext([['method' => 'GET', 'path' => '/api/v1/pay/payments', 'status' => 429, 'times' => 9]]);

        $this->expectException(RateLimitError::class);
        self::client(['max_retries' => 2])->pay->payments->all();
    }

    public function test_validation_errors_come_per_field(): void
    {
        try {
            self::client()->pay->sessions->create(['amount_cents' => 50, 'description' => 'x', 'success_url' => 'http://insecure.example.com']);
            $this->fail('No exception');
        } catch (InvalidRequestError $e) {
            $this->assertSame(422, $e->getHttpStatus());
            $this->assertSame('validation_error', $e->getErrorCode());
            $this->assertSame(['amount_cents', 'success_url'], array_keys($e->getErrors()));
            $this->assertSame(['Le champ success url doit être une adresse https.'], $e->getFieldErrors('success_url'));
            $this->assertSame([], $e->getFieldErrors('description'));
        }
    }

    public function test_status_codes_map_to_typed_errors(): void
    {
        $client = self::client();

        try {
            $client->request('GET', '/forbidden');
            $this->fail('No exception');
        } catch (PermissionError $e) {
            $this->assertSame(403, $e->getHttpStatus());
        }

        try {
            $client->pay->sessions->retrieve('pay_sess_Unknown00000');
            $this->fail('No exception');
        } catch (InvalidRequestError $e) {
            $this->assertSame(404, $e->getHttpStatus());
            $this->assertSame('not_found', $e->getErrorCode());
        }
    }

    public function test_an_unreachable_api_raises_a_connection_error(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket); // nothing listens there any more

        $this->expectException(ApiConnectionError::class);
        self::client(['base_url' => 'http://'.$address.'/api', 'max_retries' => 1])->request('GET', '/echo');
    }

    /** @return array<string,mixed> */
    private static function sessionParams(): array
    {
        return [
            'amount_cents' => 4990,
            'description' => 'Order 4521',
            'reference' => 'ORDER-4521',
            'success_url' => 'https://shop.example.com/orders/4521/thanks',
        ];
    }
}
