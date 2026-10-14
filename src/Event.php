<?php

declare(strict_types=1);

namespace MyAdion;

/**
 * A webhook event, as returned by Webhook::constructEvent().
 *
 *     $event->id;              // pay_evt_…
 *     $event->type;            // payment.succeeded
 *     $event->livemode;        // false for a test organization
 *     $event->data->object;    // the payment, session or refund
 *
 * @property-read string|null $id
 * @property-read string|null $type
 * @property-read string|null $api_version
 * @property-read bool|null $livemode
 * @property-read string|null $pay_account_id
 * @property-read int|string|null $created
 * @property-read ApiObject|null $data
 */
class Event extends ApiObject
{
    public const PAYMENT_SUCCEEDED = 'payment.succeeded';

    public const PAYMENT_FAILED = 'payment.failed';

    public const REFUND_SUCCEEDED = 'refund.succeeded';

    public const DISPUTE_OPENED = 'dispute.opened';

    public const DISPUTE_CLOSED = 'dispute.closed';

    public const SESSION_EXPIRED = 'session.expired';

    /** The object the event is about (`data.object`). */
    public function dataObject(): ?ApiObject
    {
        $data = $this->values['data'] ?? null;
        if (! $data instanceof ApiObject) {
            return null;
        }
        $object = $data['object'];

        return $object instanceof ApiObject ? $object : null;
    }
}
