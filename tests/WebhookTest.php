<?php

declare(strict_types=1);

namespace MyAdion\Tests;

use MyAdion\Event;
use MyAdion\Exception\SignatureVerificationError;
use MyAdion\Webhook;
use MyAdion\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret_for_the_suite';

    private const PAYLOAD = '{"id":"pay_evt_AbCdEf123456","object":"event","type":"payment.succeeded","api_version":"2026-10-14","created":"2026-10-14T10:03:12+00:00","livemode":false,"pay_account_id":"pay_acct_AbCdEf123456","data":{"object":{"id":"pay_pmt_AbCdEf123456","object":"pay.payment","status":"succeeded","amount_cents":4990,"reference":"CMD-4521","metadata":{"order_id":"4521"}}}}';

    public function test_a_valid_signature_gives_the_event(): void
    {
        $header = WebhookSignature::generateTestHeaderString(self::PAYLOAD, self::SECRET);
        $event = Webhook::constructEvent(self::PAYLOAD, $header, self::SECRET);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame(Event::PAYMENT_SUCCEEDED, $event->type);
        $this->assertSame('pay_pmt_AbCdEf123456', $event->data?->object->id);
        $this->assertSame('4521', $event->dataObject()?->metadata->order_id);
        $this->assertNotInstanceOf(Event::class, $event->dataObject());
    }

    public function test_it_matches_the_server_algorithm(): void
    {
        // HMAC-SHA256 of "t.body", in hex, exactly as Adion computes it.
        $expected = hash_hmac('sha256', '1760436000.'.self::PAYLOAD, self::SECRET);
        $this->assertSame($expected, WebhookSignature::computeSignature(1760436000, self::PAYLOAD, self::SECRET));
        $this->assertSame('t=1760436000,v1='.$expected, WebhookSignature::generateTestHeaderString(self::PAYLOAD, self::SECRET, 1760436000));
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $header = WebhookSignature::generateTestHeaderString(self::PAYLOAD, 'whsec_other');
        $this->expectException(SignatureVerificationError::class);
        Webhook::constructEvent(self::PAYLOAD, $header, self::SECRET);
    }

    public function test_a_modified_body_is_refused(): void
    {
        $header = WebhookSignature::generateTestHeaderString(self::PAYLOAD, self::SECRET);
        $this->expectException(SignatureVerificationError::class);
        Webhook::constructEvent(str_replace('4990', '1', self::PAYLOAD), $header, self::SECRET);
    }

    public function test_an_old_or_future_timestamp_is_refused(): void
    {
        foreach ([time() - 301, time() + 301] as $timestamp) {
            $header = WebhookSignature::generateTestHeaderString(self::PAYLOAD, self::SECRET, $timestamp);
            try {
                Webhook::constructEvent(self::PAYLOAD, $header, self::SECRET);
                $this->fail('No exception for '.$timestamp);
            } catch (SignatureVerificationError $e) {
                $this->assertStringContainsString('tolerance', $e->getMessage());
            }
        }

        $old = WebhookSignature::generateTestHeaderString(self::PAYLOAD, self::SECRET, time() - 3600);
        $this->assertSame('payment.succeeded', Webhook::constructEvent(self::PAYLOAD, $old, self::SECRET, 0)->type);
        $this->assertSame('payment.succeeded', Webhook::constructEvent(self::PAYLOAD, $old, self::SECRET, 7200)->type);
    }

    public function test_malformed_headers_are_refused(): void
    {
        $valid = WebhookSignature::generateTestHeaderString(self::PAYLOAD, self::SECRET);
        [$t, $v1] = explode(',', $valid);

        foreach (['', $t, $v1, 't=abc,'.$v1, 'garbage'] as $header) {
            try {
                Webhook::constructEvent(self::PAYLOAD, $header, self::SECRET);
                $this->fail('No exception for "'.$header.'"');
            } catch (SignatureVerificationError) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(SignatureVerificationError::class);
        Webhook::constructEvent(self::PAYLOAD, $valid, '');
    }

    public function test_any_of_several_signatures_may_match(): void
    {
        $valid = WebhookSignature::generateTestHeaderString(self::PAYLOAD, self::SECRET);
        [$t, $v1] = explode(',', $valid);
        $header = $t.',v1='.str_repeat('0', 64).','.$v1;

        $this->assertSame('payment.succeeded', Webhook::constructEvent(self::PAYLOAD, $header, self::SECRET)->type);
    }

    public function test_a_payload_that_is_not_json_is_refused(): void
    {
        $header = WebhookSignature::generateTestHeaderString('not json', self::SECRET);
        $this->expectException(\UnexpectedValueException::class);
        Webhook::constructEvent('not json', $header, self::SECRET);
    }
}
