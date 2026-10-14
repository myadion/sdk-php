<?php

declare(strict_types=1);

namespace MyAdion\Service\Pay;

use MyAdion\ApiObject;
use MyAdion\Client;
use MyAdion\Service\AbstractService;

/**
 * Refunds (scope `pay.refund.write`).
 *
 * @phpstan-import-type RequestOptions from Client
 */
final class RefundService extends AbstractService
{
    /**
     * Refund all or part of a payment. Without `amount_cents`, everything
     * still refundable is refunded.
     *
     *     $refund = $client->pay->refunds->create('pay_pmt_…', ['amount_cents' => 1000, 'reason' => 'Missing item']);
     *
     * A refund moves money: the library does not retry it after a server
     * error or a lost connection. Read the payment to know where it stands.
     *
     * @param  array{amount_cents?: int|null, reason?: string|null}|array<string,mixed>  $params
     * @param  RequestOptions  $options
     */
    public function create(string $paymentId, array $params = [], array $options = []): ApiObject
    {
        return $this->object('POST', self::path('/v1/pay/payments/%s/refunds', $paymentId), $params, $options);
    }
}
