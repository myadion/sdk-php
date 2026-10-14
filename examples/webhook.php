<?php

/*
 * Receive Adion Pay webhooks with plain PHP.
 *
 *     MYADION_WEBHOOK_SECRET=whsec_… php -S 127.0.0.1:8000 examples/webhook.php
 */

declare(strict_types=1);
use MyAdion\Event;
use MyAdion\Exception\SignatureVerificationError;
use MyAdion\Webhook;

require __DIR__.'/../vendor/autoload.php';

$payload = (string) file_get_contents('php://input'); // the raw body, never re-encoded
$signature = $_SERVER['HTTP_ADION_SIGNATURE'] ?? '';

try {
    $event = Webhook::constructEvent($payload, $signature, (string) getenv('MYADION_WEBHOOK_SECRET'));
} catch (SignatureVerificationError|UnexpectedValueException $e) {
    http_response_code(400);
    exit;
}

// The same event may arrive twice: remember $event->id and skip what you already handled.
switch ($event->type) {
    case Event::PAYMENT_SUCCEEDED:
        $payment = $event->dataObject();
        // mark the order $payment->reference as paid
        break;
    case Event::REFUND_SUCCEEDED:
        // record the refund
        break;
}

http_response_code(204);
