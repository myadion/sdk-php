<?php

declare(strict_types=1);

namespace MyAdion;

use MyAdion\Exception\SignatureVerificationError;

/**
 * The `Adion-Signature` header: `t=<unix time>,v1=<hex HMAC-SHA256>`, the
 * HMAC being computed on `"<t>.<raw body>"` with the endpoint secret.
 */
final class WebhookSignature
{
    public const SCHEME = 'v1';

    /**
     * @param  int  $tolerance  maximum age of the signature, in seconds (0 to skip the check)
     *
     * @throws SignatureVerificationError
     */
    public static function verifyHeader(string $payload, string $header, string $secret, int $tolerance = Webhook::DEFAULT_TOLERANCE, ?int $now = null): void
    {
        if ($secret === '') {
            throw new SignatureVerificationError('No webhook secret given: pass the whsec_… secret of the endpoint.');
        }
        if (trim($header) === '') {
            throw new SignatureVerificationError('No Adion-Signature header.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === self::SCHEME && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null) {
            throw new SignatureVerificationError('The Adion-Signature header has no timestamp.');
        }
        if ($signatures === []) {
            throw new SignatureVerificationError('The Adion-Signature header has no v1 signature.');
        }

        $expected = self::computeSignature($timestamp, $payload, $secret);
        $match = false;
        foreach ($signatures as $signature) {
            // Every candidate is compared, in constant time.
            $match = hash_equals($expected, $signature) || $match;
        }
        if (! $match) {
            throw new SignatureVerificationError('The signature does not match the payload. Check the secret and pass the raw body.');
        }

        if ($tolerance > 0 && abs(($now ?? time()) - $timestamp) > $tolerance) {
            throw new SignatureVerificationError('The signature timestamp is outside the tolerance zone (possible replay).');
        }
    }

    public static function computeSignature(int $timestamp, string $payload, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * A valid header for a payload, to test your webhook handler.
     */
    public static function generateTestHeaderString(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.','.self::SCHEME.'='.self::computeSignature($timestamp, $payload, $secret);
    }
}
