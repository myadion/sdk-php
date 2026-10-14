<?php

declare(strict_types=1);

namespace MyAdion\Service;

use MyAdion\ApiObject;
use MyAdion\Client;

/**
 * Adion SMS.
 *
 * @phpstan-import-type RequestOptions from Client
 */
final class SmsService extends AbstractService
{
    /**
     * Send one text message, or the same one to up to 500 numbers (scope
     * `sms.message.send`). The answer (202) lists each message created and
     * the numbers rejected.
     *
     *     $batch = $client->sms->send([
     *         'service_id' => 'service_AbCdEf123456',
     *         'to' => '+33612345678',
     *         'message' => 'Your code: 482913',
     *         'type' => 'transactional',
     *     ]);
     *     $batch->messages[0]->id; // sms_msg_…
     *
     * Sending is not idempotent: the library does not retry it after a server
     * error or a lost connection, only after a 429 (refused before sending).
     *
     * @param array{
     *     service_id: string,
     *     to: string|list<string>,
     *     message: string,
     *     type: 'transactional'|'commercial',
     *     sender?: string|null,
     *     reference?: string|null,
     * }|array<string,mixed> $params
     * @param  RequestOptions  $options
     */
    public function send(array $params, array $options = []): ApiObject
    {
        return $this->object('POST', '/v1/sms', $params, $options);
    }

    /**
     * One message and its status: `queued`, `sent`, `delivered` or `failed`.
     *
     * @param  RequestOptions  $options
     */
    public function retrieve(string $id, array $options = []): ApiObject
    {
        return $this->object('GET', self::path('/v1/sms/%s', $id), [], $options);
    }
}
