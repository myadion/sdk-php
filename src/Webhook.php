<?php

declare(strict_types=1);

namespace MyAdion;

use MyAdion\Exception\SignatureVerificationError;

/**
 * Webhook events sent by Adion, signed in the `Adion-Signature` header.
 *
 *     $event = \MyAdion\Webhook::constructEvent(
 *         file_get_contents('php://input'),
 *         $_SERVER['HTTP_ADION_SIGNATURE'] ?? '',
 *         getenv('MYADION_WEBHOOK_SECRET'),
 *     );
 *
 * Pass the raw body, before any JSON decoding: one byte changed and the
 * signature no longer matches.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'Adion-Signature';

    public const DEFAULT_TOLERANCE = 300;

    /**
     * Check the signature, then decode the event.
     *
     * @param  int  $tolerance  maximum age of the signature, in seconds (0 to skip the check: never in production)
     *
     * @throws SignatureVerificationError when the signature is missing, wrong or too old
     * @throws \UnexpectedValueException when the payload is not a JSON event
     */
    public static function constructEvent(string $payload, string $sigHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): Event
    {
        WebhookSignature::verifyHeader($payload, $sigHeader, $secret, $tolerance);

        try {
            $decoded = json_decode($payload, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('The webhook payload is not valid JSON.', 0, $e);
        }
        if (! $decoded instanceof \stdClass) {
            throw new \UnexpectedValueException('The webhook payload is not a JSON object.');
        }

        return Event::constructFrom($decoded);
    }
}
