<?php

namespace App\Enums;

/**
 * Where one recipient of one run stands.
 *
 * Only `pending` and the failures (isFailure()) lead back into work: they
 * can be claimed by SendDirectMessage. `sent` and `no_inbox` are final;
 * nothing moves a delivery from there back to `pending`.
 *
 * Three failures, so the board's label says what actually happened (jury
 * run 1, L1 and board F9): `failed` means relays were dialled and none took
 * the wrap; `no_sender_key` and `not_sent` mean no relay was tried at all.
 * Read as "Kein Relay hat angenommen", a missing key looked like a relay
 * problem that a re-send might fix.
 */
enum DmDeliveryStatus: string
{
    /** Created by the fan-out, not yet picked up. */
    case Pending = 'pending';

    /** A worker holds it right now (see SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS). */
    case Claimed = 'claimed';

    /** At least one of the member's inbox relays answered `OK true`. */
    case Sent = 'sent';

    /** Inbox relays were dialled and none took the wrap. */
    case Failed = 'failed';

    /** The notification key is missing or unreadable: nothing was signed, no relay was tried. */
    case NoSenderKey = 'no_sender_key';

    /** No relay was tried: the recipient erased their data, or the message could not be built. */
    case NotSent = 'not_sent';

    /** The member has no kind 10050 list: NIP-17 forbids sending, nothing was attempted. */
    case NoInbox = 'no_inbox';

    /**
     * What the board reads. A server knows what relays answered, not what a
     * person saw, so no label claims more than that.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Wartet auf Versand'),
            self::Claimed => __('Wird gerade gesendet'),
            self::Sent => __('An Relays übergeben'),
            self::Failed => __('Kein Relay hat angenommen'),
            self::NoSenderKey => __('Nicht gesendet: Absenderschlüssel fehlt oder ist ungültig'),
            self::NotSent => __('Nicht gesendet, kein Relay versucht'),
            self::NoInbox => __('Kein Posteingang, nur im Portal'),
        };
    }

    /**
     * May SendDirectMessage claim a delivery in this state?
     */
    public function isClaimable(): bool
    {
        return $this === self::Pending || $this->isFailure();
    }

    /**
     * Did the delivery end without reaching any relay? What the board counts
     * as failed and what a re-send picks up.
     */
    public function isFailure(): bool
    {
        return in_array($this, self::failures(), true);
    }

    /**
     * @return list<self>
     */
    public static function failures(): array
    {
        return [self::Failed, self::NoSenderKey, self::NotSent];
    }

    /**
     * The stored values of `pending` and every failure: the states a worker
     * may claim.
     *
     * @return list<string>
     */
    public static function claimableValues(): array
    {
        return [self::Pending->value, ...array_map(fn (self $status): string => $status->value, self::failures())];
    }
}
