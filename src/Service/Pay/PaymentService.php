<?php

declare(strict_types=1);

namespace MyAdion\Service\Pay;

use MyAdion\ApiObject;
use MyAdion\Client;
use MyAdion\Collection;
use MyAdion\Service\AbstractService;

/**
 * Payments, most recent first (scope `pay.payment.read`).
 *
 * @phpstan-import-type RequestOptions from Client
 */
final class PaymentService extends AbstractService
{
    /**
     * One page of payments. Walk them all with `->autoPagingIterator()`.
     *
     *     foreach ($client->pay->payments->all(['status' => 'succeeded'])->autoPagingIterator() as $payment) { … }
     *
     * @param array{
     *     status?: 'pending'|'succeeded'|'failed'|'canceled'|'refunded'|'partially_refunded'|null,
     *     reference?: string|null,
     *     session_id?: string|null,
     *     from?: string|null,
     *     to?: string|null,
     *     pay_account_id?: string|null,
     *     page?: int|null,
     *     per_page?: int|null,
     * }|array<string,mixed> $params
     * @param  RequestOptions  $options
     */
    public function all(array $params = [], array $options = []): Collection
    {
        return $this->collection('/v1/pay/payments', $params, $options);
    }

    /**
     * One payment, with its `refunds`.
     *
     * @param  RequestOptions  $options
     */
    public function retrieve(string $id, array $options = []): ApiObject
    {
        return $this->object('GET', self::path('/v1/pay/payments/%s', $id), [], $options);
    }
}
