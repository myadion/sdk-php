<?php

/*
 * Create an Adion Pay session and send the buyer to the payment page.
 *
 *     MYADION_CLIENT_ID=… MYADION_CLIENT_SECRET=… php examples/checkout.php
 */

declare(strict_types=1);
use MyAdion\Client;
use MyAdion\Exception\InvalidRequestError;
use MyAdion\Exception\MyAdionException;

require __DIR__.'/../vendor/autoload.php';

$client = new Client([
    'client_id' => getenv('MYADION_CLIENT_ID') ?: null,
    'client_secret' => getenv('MYADION_CLIENT_SECRET') ?: null,
]);

try {
    $session = $client->pay->sessions->create([
        'amount_cents' => 4990,
        'description' => 'Order 4521',
        'reference' => 'ORDER-4521',
        'metadata' => ['order_id' => '4521'],
        'success_url' => 'https://shop.example.com/orders/4521/thanks?session_id={SESSION_ID}',
        'cancel_url' => 'https://shop.example.com/cart',
    ], ['idempotency_key' => 'order-4521']);
} catch (InvalidRequestError $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL.print_r($e->getErrors(), true));
    exit(1);
} catch (MyAdionException $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
}

echo 'Send the buyer to: '.$session->url.PHP_EOL;
echo 'Test mode: '.($session->livemode ? 'no' : 'yes').PHP_EOL;
