<?php

declare(strict_types=1);

namespace MyAdion\Http;

/**
 * The raw HTTP answer behind the last call: its status (201 for a new session,
 * 200 for the same one returned again under an idempotency key), its headers
 * and its body.
 */
final class Response
{
    /**
     * @param  array<string,list<string>>  $headers  lower-cased header names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
