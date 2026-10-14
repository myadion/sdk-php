<?php

declare(strict_types=1);

namespace MyAdion\Service\Pay;

use MyAdion\Client;

/**
 * Adion Pay.
 *
 *     $client->pay->sessions->create([...]);
 *     $client->pay->payments->all(['status' => 'succeeded']);
 *     $client->pay->refunds->create('pay_pmt_…', ['amount_cents' => 1000]);
 *     $client->pay->webhookEndpoints->create('pay_acct_…', [...]);
 */
final class PayService
{
    public readonly SessionService $sessions;

    public readonly PaymentService $payments;

    public readonly RefundService $refunds;

    public readonly WebhookEndpointService $webhookEndpoints;

    public function __construct(Client $client)
    {
        $this->sessions = new SessionService($client);
        $this->payments = new PaymentService($client);
        $this->refunds = new RefundService($client);
        $this->webhookEndpoints = new WebhookEndpointService($client);
    }
}
