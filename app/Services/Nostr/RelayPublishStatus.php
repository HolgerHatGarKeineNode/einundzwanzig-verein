<?php

namespace App\Services\Nostr;

/**
 * How one publish to one relay ended.
 *
 * The four cases need different follow-ups, which is why they are not folded
 * into a boolean: a retry can help after Failed, rarely after Rejected, and
 * never after Refused.
 */
enum RelayPublishStatus: string
{
    /** The relay answered `OK true` (or `duplicate:`): it has the event. */
    case Accepted = 'accepted';

    /** The relay answered `OK false` with a reason. */
    case Rejected = 'rejected';

    /** No usable answer: unreachable, timed out, broken connection, oversized frame. */
    case Failed = 'failed';

    /** Not attempted: the relay address or the event did not pass our own checks. */
    case Refused = 'refused';
}
