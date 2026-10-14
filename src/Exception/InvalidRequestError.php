<?php

declare(strict_types=1);

namespace MyAdion\Exception;

/**
 * The request was refused as written: invalid parameters (400, 422), unknown
 * resource (404), conflict (409, an idempotency key reused with another body
 * for instance), insufficient credit (402)…
 *
 * For a 422, `getErrors()` gives the messages per field:
 *
 *     ['success_url' => ['The success url field must be an https address.']]
 */
class InvalidRequestError extends MyAdionException
{
    /** @return array<string,list<string>> */
    public function getErrors(): array
    {
        $errors = [];
        foreach ($this->getDetails() as $field => $messages) {
            if (is_array($messages)) {
                $errors[(string) $field] = array_values(array_map(
                    static fn ($m): string => is_scalar($m) ? (string) $m : '',
                    $messages,
                ));
            } elseif (is_string($messages)) {
                $errors[(string) $field] = [$messages];
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public function getFieldErrors(string $field): array
    {
        return $this->getErrors()[$field] ?? [];
    }
}
