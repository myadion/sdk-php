<?php

declare(strict_types=1);

namespace MyAdion\Tests;

use MyAdion\ApiObject;
use MyAdion\Exception\InvalidRequestError;

final class PayAndSmsTest extends TestCase
{
    public function test_create_and_retrieve_a_session(): void
    {
        $client = self::client();
        $session = $client->pay->sessions->create([
            'amount_cents' => 4990,
            'description' => 'Order 4521',
            'reference' => 'ORDER-4521',
            'metadata' => ['order_id' => '4521'],
            'success_url' => 'https://shop.example.com/orders/4521/thanks',
        ]);

        $this->assertSame(201, $client->getLastResponse()?->status);
        $this->assertSame('pay.session', $session->object);
        $this->assertStringStartsWith('pay_sess_', (string) $session->id);
        $this->assertSame('4521', $session->metadata->order_id);
        $this->assertFalse($session->livemode);

        $again = $client->pay->sessions->retrieve((string) $session->id);
        $this->assertSame($session->id, $again->id);
        $this->assertSame($session->toArray(), $again->toArray());
    }

    public function test_an_embedded_session_gives_a_client_secret_and_no_url(): void
    {
        $client = self::client();
        $session = $client->pay->sessions->create([
            'amount_cents' => 4990,
            'description' => 'Order 4521',
            'ui_mode' => 'embedded',
            'return_url' => 'https://shop.example.com/orders/4521/thanks',
        ]);

        $this->assertSame('embedded', $session->ui_mode);
        $this->assertNull($session->url);
        $this->assertSame('https://shop.example.com/orders/4521/thanks', $session->return_url);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{10}_secret_[A-Za-z0-9]{32}$/', (string) $session->client_secret);

        try {
            $client->pay->sessions->create(['amount_cents' => 4990, 'description' => 'Order 4521', 'ui_mode' => 'embedded', 'success_url' => 'https://shop.example.com/thanks']);
            $this->fail('success_url belongs to the hosted mode.');
        } catch (InvalidRequestError $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertNotSame([], $e->getFieldErrors('success_url'));
        }
    }

    public function test_an_explicit_idempotency_key_returns_the_same_session(): void
    {
        $client = self::client();
        $params = ['amount_cents' => 1500, 'description' => 'Order 7', 'success_url' => 'https://shop.example.com/thanks'];

        $first = $client->pay->sessions->create($params, ['idempotency_key' => 'order-7']);
        $second = $client->pay->sessions->create($params, ['idempotency_key' => 'order-7']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(200, $client->getLastResponse()?->status);

        $this->expectException(InvalidRequestError::class);
        $this->expectExceptionCode(409);
        $client->pay->sessions->create(['amount_cents' => 9900] + $params, ['idempotency_key' => 'order-7']);
    }

    public function test_payments_paginate_lazily(): void
    {
        $client = self::client();
        $page = $client->pay->payments->all(['per_page' => 2]);

        $this->assertCount(2, $page);
        $this->assertSame(1, $page->currentPage);
        $this->assertSame(3, $page->lastPage);
        $this->assertSame(5, $page->total);
        $this->assertTrue($page->hasMore());

        $ids = [];
        foreach ($page->autoPagingIterator() as $payment) {
            $this->assertInstanceOf(ApiObject::class, $payment);
            $ids[] = $payment->id;
        }
        $this->assertCount(5, array_unique($ids));

        $queries = array_column(array_values(array_filter(self::received(), fn ($r) => $r['path'] === '/api/v1/pay/payments')), 'query');
        $this->assertSame([['per_page' => '2'], ['page' => '2', 'per_page' => '2'], ['page' => '3', 'per_page' => '2']], $queries);
    }

    public function test_retrieve_and_refund_a_payment(): void
    {
        $client = self::client();
        $refund = $client->pay->refunds->create('pay_pmt_Test00000002', ['amount_cents' => 500, 'reason' => 'Missing item']);

        $this->assertSame('pay.refund', $refund->object);
        $this->assertSame(500, $refund->amount_cents);
        $this->assertSame('partially_refunded', $refund->payment->status);
        $this->assertArrayNotHasKey('idempotency-key', self::received()[1]['headers']);

        $payment = $client->pay->payments->retrieve('pay_pmt_Test00000002');
        $this->assertSame(500, $payment->refunded_cents);
        $this->assertCount(1, $payment->refunds);
        $this->assertSame('Missing item', $payment->refunds[0]->reason);
    }

    public function test_webhook_endpoints(): void
    {
        $client = self::client();
        $account = 'pay_acct_TestAccount01';

        $created = $client->pay->webhookEndpoints->create($account, [
            'url' => 'https://shop.example.com/webhooks/adion-pay',
            'events' => ['payment.succeeded', 'refund.succeeded'],
        ]);
        $this->assertStringStartsWith('whsec_', (string) $created->secret);

        $list = $client->pay->webhookEndpoints->all($account);
        $this->assertCount(1, $list);
        $this->assertFalse($list->hasMore());
        $this->assertSame('payment.succeeded', $list->raw()?->event_types[0]->code);

        $updated = $client->pay->webhookEndpoints->update($account, (string) $created->id, ['enabled' => false]);
        $this->assertFalse($updated->enabled);

        $rotated = $client->pay->webhookEndpoints->rotateSecret($account, (string) $created->id);
        $this->assertNotSame($created->secret, $rotated->secret);

        $this->assertTrue($client->pay->webhookEndpoints->delete($account, (string) $created->id)->deleted);
        $this->assertCount(0, $client->pay->webhookEndpoints->all($account));
    }

    public function test_ids_are_encoded_in_paths(): void
    {
        $this->expectException(InvalidRequestError::class);
        try {
            self::client()->pay->sessions->retrieve('../../auth/token');
        } finally {
            $this->assertSame('/api/v1/pay/sessions/..%2F..%2Fauth%2Ftoken', self::received()[1]['path'] ?? null);
        }
    }

    public function test_send_and_read_an_sms(): void
    {
        $client = self::client();
        $batch = $client->sms->send([
            'service_id' => 'service_AbCdEf123456',
            'to' => ['+33612345678', '+33698765432'],
            'message' => 'Your code: 482913',
            'type' => 'transactional',
        ]);

        $this->assertSame(202, $client->getLastResponse()?->status);
        $this->assertCount(2, $batch->messages);

        $message = $client->sms->retrieve((string) $batch->messages[0]->id);
        $this->assertSame('delivered', $message->status);
    }
}
