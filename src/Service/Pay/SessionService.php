<?php

declare(strict_types=1);

namespace MyAdion\Service\Pay;

use MyAdion\ApiObject;
use MyAdion\Client;
use MyAdion\Service\AbstractService;

/**
 * Payment sessions: the buyer pays on Adion's payment page, then comes back
 * to `success_url?session_id=pay_sess_…`.
 *
 * Embedded form (`ui_mode` = `embedded`): no redirect. The session has no
 * `url` but a `client_secret` of its own, which your page hands to
 * https://pay.adioncloud.com/v1/adion-pay.js to show the payment form inside
 * your page. `return_url` replaces `success_url` and `cancel_url`. The form
 * only shows on the origins declared on the Pay account.
 *
 * @phpstan-import-type RequestOptions from Client
 */
final class SessionService extends AbstractService
{
    /**
     * Create a session (scope `pay.session.write`).
     *
     *     $session = $client->pay->sessions->create([
     *         'amount_cents' => 4990,
     *         'description' => 'Order 4521',
     *         'reference' => 'ORDER-4521',
     *         'success_url' => 'https://shop.example.com/orders/4521/thanks',
     *     ], ['idempotency_key' => 'order-4521']);
     *     header('Location: '.$session->url);
     *
     *     // Embedded form: hand the client_secret to your page.
     *     $session = $client->pay->sessions->create([
     *         'amount_cents' => 4990,
     *         'description' => 'Order 4521',
     *         'ui_mode' => 'embedded',
     *         'return_url' => 'https://shop.example.com/orders/4521/thanks',
     *     ]);
     *     echo json_encode(['clientSecret' => $session->client_secret]);
     *
     * Without `idempotency_key`, the library makes one up, so its own retries
     * never create a second session. Give your own (one per order) to make
     * your retries safe too.
     *
     * @param array{
     *     amount_cents: int,
     *     description: string,
     *     ui_mode?: 'hosted'|'embedded'|null,
     *     success_url?: string|null,
     *     cancel_url?: string|null,
     *     return_url?: string|null,
     *     currency?: string|null,
     *     reference?: string|null,
     *     metadata?: array<string,string>|null,
     *     customer_email?: string|null,
     *     expires_at?: string|null,
     *     pay_account_id?: string|null,
     * }|array<string,mixed> $params
     * @param  RequestOptions  $options
     */
    public function create(array $params, array $options = []): ApiObject
    {
        return $this->object('POST', '/v1/pay/sessions', $params, $options);
    }

    /**
     * Read a session (scope `pay.payment.read`). `status` is `open`,
     * `complete` (then `payment_id` and `payment_status`) or `expired`.
     *
     * @param  RequestOptions  $options
     */
    public function retrieve(string $id, array $options = []): ApiObject
    {
        return $this->object('GET', self::path('/v1/pay/sessions/%s', $id), [], $options);
    }
}
