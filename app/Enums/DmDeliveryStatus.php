<?php

namespace App\Enums;

/**
 * Where one recipient of one run stands.
 *
 * Only two transitions lead back into work: `pending` and `failed` can be
 * claimed by SendDirectMessage. `sent` and `no_inbox` are final; nothing
 * moves a delivery from there back to `pending`.
 */
enum DmDeliveryStatus: string
{
    /** Created by the fan-out, not yet picked up. */
    case Pending = 'pending';

    /** A worker holds it right now (see SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS). */
    case Claimed = 'claimed';

    /** At least one of the member's inbox relays answered `OK true`. */
    case Sent = 'sent';

    /** No inbox relay took the wrap, or the wrap could not be built. */
    case Failed = 'failed';

    /** The member has no kind 10050 list: NIP-17 forbids sending, nothing was attempted. */
    case NoInbox = 'no_inbox';

    /**
     * May SendDirectMessage claim a delivery in this state?
     */
    public function isClaimable(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }
}
