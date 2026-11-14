<?php

namespace App\Services\Nostr;

/**
 * One direct message, ready to hand to the recipient's inbox relays.
 *
 * `rumorId` and `rumorCreatedAt` identify the message itself; store them
 * before publishing. Building again with the same recipient, text and
 * `rumorCreatedAt` yields the same rumor id inside a fresh wrap — that is how
 * a retry stays one message for clients that key by rumor id.
 *
 * There is one wrap, addressed to the recipient. No copy is wrapped for the
 * association's own key: NIP-17 lets a sender omit it ("by not generating a
 * gift wrap to the sender's public key"), the key has no kind 10050 a copy
 * could be published to, and the database is the record of what was sent.
 */
final readonly class GiftWrappedMessage
{
    /**
     * @param  array{id: string, pubkey: string, created_at: int, kind: int, tags: array<int, array<int, string>>, content: string, sig: string}  $wrap
     */
    public function __construct(
        public string $rumorId,
        public int $rumorCreatedAt,
        public string $recipientPubkey,
        public array $wrap,
    ) {}
}
