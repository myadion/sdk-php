<?php

declare(strict_types=1);

namespace MyAdion\Exception;

/**
 * Base class of every error thrown by the library.
 *
 * API errors carry the HTTP status, the machine-readable `error` code returned
 * by the API (`validation_error`, `forbidden`…), the per-field `details`, the
 * response headers and the raw body.
 */
class MyAdionException extends \RuntimeException
{
    /**
     * @param  array<string,mixed>  $details
     * @param  array<string,list<string>>  $httpHeaders  lower-cased header names
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $errorCode = null,
        private readonly array $details = [],
        private readonly array $httpHeaders = [],
        private readonly ?string $httpBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** The `error` field of the API response, e.g. `validation_error`. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** @return array<string,list<string>> */
    public function getHttpHeaders(): array
    {
        return $this->httpHeaders;
    }

    public function getHttpBody(): ?string
    {
        return $this->httpBody;
    }

    /** The `x-request-id` response header when the API sends one. */
    public function getRequestId(): ?string
    {
        return $this->httpHeaders['x-request-id'][0] ?? null;
    }
}
