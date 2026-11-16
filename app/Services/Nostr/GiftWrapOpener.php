<?php

namespace App\Services\Nostr;

use swentel\nostr\Encryption\Nip44;
use Throwable;

/**
 * Opens a gift wrap addressed to the notification key (NIP-59 / NIP-17) and
 * returns the chat message inside, or null for anything that is not one.
 *
 * Every layer is checked, because the inbox is open to anyone:
 * - the wrap is kind 1059, tagged for this key and correctly signed;
 * - the seal inside is kind 13 and correctly signed;
 * - the rumor inside is kind 14, its id is the hash of its fields, and its
 *   pubkey is the seal's signer. That last check is what stops someone from
 *   sealing a rumor that claims to come from a member.
 */
final class GiftWrapOpener
{
    public function __construct(private readonly NotificationKey $key) {}

    /**
     * @param  array<string, mixed>  $wrap
     * @return array{rumor_id: string, sender: string, body: string, created_at: int}|null
     *
     * @throws NotificationKeyUnavailable
     */
    public function open(array $wrap): ?array
    {
        $own = $this->key->publicKey();

        if (($wrap['kind'] ?? null) !== DirectMessageSigner::KIND_GIFT_WRAP
            || ! is_array($wrap['tags'] ?? null)
            || ! in_array(['p', $own], array_map(fn (mixed $tag): mixed => is_array($tag) ? array_slice($tag, 0, 2) : null, $wrap['tags']), true)
            || ! is_string($wrap['pubkey'] ?? null)
            || ! is_string($wrap['content'] ?? null)
            || ! EventVerification::isAuthentic($wrap)) {
            return null;
        }

        $seal = $this->decryptJson($wrap['content'], $wrap['pubkey']);

        if ($seal === null
            || ($seal['kind'] ?? null) !== DirectMessageSigner::KIND_SEAL
            || ! is_string($seal['pubkey'] ?? null)
            || ! is_string($seal['content'] ?? null)
            || ! EventVerification::isAuthentic($seal)) {
            return null;
        }

        $rumor = $this->decryptJson($seal['content'], $seal['pubkey']);

        if ($rumor === null
            || ($rumor['kind'] ?? null) !== DirectMessageSigner::KIND_CHAT_MESSAGE
            || ($rumor['pubkey'] ?? null) !== $seal['pubkey']
            || ! is_int($rumor['created_at'] ?? null)
            || ! is_array($rumor['tags'] ?? null)
            || ! is_string($rumor['content'] ?? null)
            || ! is_string($rumor['id'] ?? null)
            || trim($rumor['content']) === ''
            || strlen($rumor['content']) > DirectMessageSigner::MAX_TEXT_BYTES) {
            return null;
        }

        try {
            $id = EventHash::of($rumor['pubkey'], $rumor['created_at'], $rumor['kind'], $rumor['tags'], $rumor['content']);
        } catch (Throwable) {
            return null;
        }

        if (! hash_equals($id, $rumor['id'])) {
            return null;
        }

        return ['rumor_id' => $id, 'sender' => $rumor['pubkey'], 'body' => $rumor['content'], 'created_at' => $rumor['created_at']];
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws NotificationKeyUnavailable
     */
    private function decryptJson(string $payload, string $counterpartPubkey): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $counterpartPubkey) !== 1) {
            return null;
        }

        try {
            $conversationKey = $this->key->conversationKeyWith($counterpartPubkey);
        } catch (NotificationKeyUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }

        try {
            $decoded = json_decode(Nip44::decrypt($payload, $conversationKey), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
