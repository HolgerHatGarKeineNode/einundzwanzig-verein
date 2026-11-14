<?php

namespace App\Services\Nostr;

use Mdanter\Ecc\Crypto\Signature\SchnorrSignature;
use Throwable;

/**
 * Checks an event received from a relay: shape (NIP-01), id and signature.
 *
 * Not Event::verify() from the library: its id computation lacks the
 * line-terminator flag (see EventHash), so a correctly signed event with a
 * U+2028 in its content or tags would be called forged there.
 */
final class EventVerification
{
    /**
     * @param  array<mixed>  $event
     */
    public static function isAuthentic(array $event): bool
    {
        foreach (['id', 'pubkey', 'sig', 'content'] as $field) {
            if (! is_string($event[$field] ?? null)) {
                return false;
            }
        }

        if (! is_int($event['created_at'] ?? null) || ! is_int($event['kind'] ?? null) || ! is_array($event['tags'] ?? null)) {
            return false;
        }

        if (preg_match('/^[0-9a-f]{64}$/', $event['id']) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $event['pubkey']) !== 1
            || preg_match('/^[0-9a-f]{128}$/', $event['sig']) !== 1) {
            return false;
        }

        if (! array_is_list($event['tags'])) {
            return false;
        }

        foreach ($event['tags'] as $tag) {
            if (! is_array($tag) || ! array_is_list($tag)) {
                return false;
            }

            foreach ($tag as $value) {
                if (! is_string($value)) {
                    return false;
                }
            }
        }

        try {
            $id = EventHash::of($event['pubkey'], $event['created_at'], $event['kind'], $event['tags'], $event['content']);

            return hash_equals($id, $event['id'])
                && (new SchnorrSignature)->verify($event['pubkey'], $event['sig'], $event['id']);
        } catch (Throwable) {
            return false;
        }
    }
}
