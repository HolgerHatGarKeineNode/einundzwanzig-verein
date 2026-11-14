<?php

namespace App\Services\Nostr;

use App\Models\EinundzwanzigPleb;
use Carbon\CarbonImmutable;
use JsonException;
use swentel\nostr\Encryption\Nip44;
use Throwable;

/**
 * Turns a text for one member into a NIP-17 direct message: an unsigned
 * kind 14 rumor, sealed (kind 13, signed by the notification key) and
 * gift-wrapped (kind 1059, signed by a one-time key) for that member.
 *
 * One public method, and it takes a member record, never a pubkey: the caller
 * chooses who and what, never which kind, which tags or which key. Everything
 * else is a constant here.
 *
 * Built on the library's primitives (Nip44, Sign, Event, Key) instead of its
 * NIP-17 helper, which leaves the rumor id empty, stamps seal and wrap with
 * time() and fetches relay lists over the network on its own.
 */
final class DirectMessageSigner
{
    public const int KIND_CHAT_MESSAGE = 14;

    public const int KIND_SEAL = NotificationKey::KIND_SEAL;

    public const int KIND_GIFT_WRAP = 1059;

    /**
     * NIP-17: "randomize created_at in up to two days in the past in both the
     * seal and the gift wrap".
     */
    public const int MAX_BACKDATE_SECONDS = 2 * 24 * 60 * 60;

    /**
     * Keeps a wrap below 64 KiB, a common relay limit for one event; the text
     * grows by about 2.4x through two rounds of NIP-44 and base64. Measured on
     * 2026-11-14: 16384 bytes of text gave a wrap of 39,359 bytes as JSON.
     */
    public const int MAX_TEXT_BYTES = 16384;

    /**
     * The secp256k1 field prime. An x coordinate at or above it is not a key,
     * and the library reduces it silently instead of refusing it.
     */
    private const string FIELD_PRIME = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';

    public function __construct(private readonly NotificationKey $key) {}

    /**
     * @param  int|null  $rumorCreatedAt  the message's own time; pass the stored value to re-wrap the same rumor
     *
     * @throws DirectMessageNotBuildable for this recipient or this text
     * @throws NotificationKeyUnavailable when the installation has no usable key
     */
    public function wrapFor(EinundzwanzigPleb $recipient, string $plaintext, ?int $rumorCreatedAt = null): GiftWrappedMessage
    {
        $recipientPubkey = $this->recipientPubkey($recipient);
        $now = CarbonImmutable::now()->getTimestamp();
        $rumorCreatedAt ??= $now;

        if ($rumorCreatedAt <= 0 || $rumorCreatedAt > $now) {
            throw DirectMessageNotBuildable::invalidTimestamp();
        }

        $this->guardText($plaintext);

        $senderPubkey = $this->key->publicKey();

        try {
            $sealConversationKey = $this->key->conversationKeyWith($recipientPubkey);
        } catch (NotificationKeyUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            // An x coordinate without a point on the curve.
            throw DirectMessageNotBuildable::invalidRecipientKey();
        }

        $rumorTags = [['p', $recipientPubkey]];

        /*
         * An unsigned event with its id (NIP-59 "rumor"). The id is what a
         * client deduplicates on; without it every message from this key
         * would look like the same message.
         */
        $rumor = [
            'id' => EventHash::of($senderPubkey, $rumorCreatedAt, self::KIND_CHAT_MESSAGE, $rumorTags, $plaintext),
            'pubkey' => $senderPubkey,
            'created_at' => $rumorCreatedAt,
            'kind' => self::KIND_CHAT_MESSAGE,
            'tags' => $rumorTags,
            'content' => $plaintext,
        ];

        $sealCreatedAt = $now - random_int(1, self::MAX_BACKDATE_SECONDS);

        do {
            $wrapCreatedAt = $now - random_int(1, self::MAX_BACKDATE_SECONDS);
        } while ($wrapCreatedAt === $sealCreatedAt);

        $seal = $this->key->signSeal(
            Nip44::encrypt(EventHash::json($rumor), $sealConversationKey),
            $sealCreatedAt,
        );

        $oneTimeSecret = EventSigning::freshSecret();

        $wrap = EventSigning::sign(
            $oneTimeSecret,
            self::KIND_GIFT_WRAP,
            [['p', $recipientPubkey]],
            Nip44::encrypt(EventHash::json($seal), Nip44::getConversationKey($oneTimeSecret, $recipientPubkey)),
            $wrapCreatedAt,
        );

        return new GiftWrappedMessage($rumor['id'], $rumorCreatedAt, $recipientPubkey, $wrap);
    }

    private function recipientPubkey(EinundzwanzigPleb $recipient): string
    {
        if ($recipient->isErased()) {
            throw DirectMessageNotBuildable::erasedRecipient();
        }

        $pubkey = (string) $recipient->pubkey;

        if (preg_match('/^[0-9a-f]{64}$/', $pubkey) !== 1 || strcmp($pubkey, self::FIELD_PRIME) >= 0) {
            throw DirectMessageNotBuildable::invalidRecipientKey();
        }

        return $pubkey;
    }

    private function guardText(string $plaintext): void
    {
        if (trim($plaintext) === '') {
            throw DirectMessageNotBuildable::emptyText();
        }

        try {
            EventHash::json($plaintext);
        } catch (JsonException) {
            throw DirectMessageNotBuildable::invalidText();
        }

        if (strlen($plaintext) > self::MAX_TEXT_BYTES) {
            throw DirectMessageNotBuildable::textTooLong(strlen($plaintext), self::MAX_TEXT_BYTES);
        }
    }
}
