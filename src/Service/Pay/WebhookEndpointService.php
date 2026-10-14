<?php

declare(strict_types=1);

namespace MyAdion\Service\Pay;

use MyAdion\ApiObject;
use MyAdion\Client;
use MyAdion\Collection;
use MyAdion\Service\AbstractService;

/**
 * The webhook endpoints of a Pay account (scope `pay.webhook.manage`).
 *
 * The signing secret (`whsec_…`) comes back once, in the answer of
 * `create()` and `rotateSecret()`. Store it right away.
 *
 * @phpstan-import-type RequestOptions from Client
 */
final class WebhookEndpointService extends AbstractService
{
    /**
     * The endpoints of the account. `->raw()->event_types` lists the events
     * that can be subscribed to.
     *
     * @param  RequestOptions  $options
     */
    public function all(string $payAccountId, array $options = []): Collection
    {
        return $this->collection(self::path('/account/pay/%s/webhook', $payAccountId), [], $options);
    }

    /**
     *     $endpoint = $client->pay->webhookEndpoints->create('pay_acct_…', [
     *         'url' => 'https://shop.example.com/webhooks/adion-pay',
     *         'events' => ['payment.succeeded', 'refund.succeeded'],
     *     ]);
     *     $endpoint->secret; // whsec_…, shown once
     *
     * @param  array{url: string, events: list<string>, description?: string|null, enabled?: bool|null}|array<string,mixed>  $params
     * @param  RequestOptions  $options
     */
    public function create(string $payAccountId, array $params, array $options = []): ApiObject
    {
        return $this->object('POST', self::path('/account/pay/%s/webhook', $payAccountId), $params, $options);
    }

    /**
     * An endpoint, with its latest deliveries.
     *
     * @param  RequestOptions  $options
     */
    public function retrieve(string $payAccountId, string $id, array $options = []): ApiObject
    {
        return $this->object('GET', self::path('/account/pay/%s/webhook/%s', $payAccountId, $id), [], $options);
    }

    /**
     * @param  array{url?: string, events?: list<string>, description?: string|null, enabled?: bool}|array<string,mixed>  $params
     * @param  RequestOptions  $options
     */
    public function update(string $payAccountId, string $id, array $params, array $options = []): ApiObject
    {
        return $this->object('PUT', self::path('/account/pay/%s/webhook/%s', $payAccountId, $id), $params, $options);
    }

    /**
     * @param  RequestOptions  $options
     */
    public function delete(string $payAccountId, string $id, array $options = []): ApiObject
    {
        return $this->object('DELETE', self::path('/account/pay/%s/webhook/%s', $payAccountId, $id), [], $options);
    }

    /**
     * A new signing secret, effective at once (the old one stops working).
     *
     * @param  RequestOptions  $options
     */
    public function rotateSecret(string $payAccountId, string $id, array $options = []): ApiObject
    {
        return $this->object('POST', self::path('/account/pay/%s/webhook/%s/secret', $payAccountId, $id), [], $options);
    }

    /**
     * The delivery log. `detail => true` adds the attempts and the payload.
     *
     * @param  array{status?: 'pending'|'succeeded'|'failed'|null, detail?: bool|null, page?: int|null, per_page?: int|null}|array<string,mixed>  $params
     * @param  RequestOptions  $options
     */
    public function deliveries(string $payAccountId, string $id, array $params = [], array $options = []): Collection
    {
        return $this->collection(self::path('/account/pay/%s/webhook/%s/delivery', $payAccountId, $id), $params, $options);
    }

    /**
     * Send a delivery again, now.
     *
     * @param  RequestOptions  $options
     */
    public function resendDelivery(string $payAccountId, string $id, string $deliveryId, array $options = []): ApiObject
    {
        return $this->object('POST', self::path('/account/pay/%s/webhook/%s/delivery/%s/resend', $payAccountId, $id, $deliveryId), [], $options);
    }
}
