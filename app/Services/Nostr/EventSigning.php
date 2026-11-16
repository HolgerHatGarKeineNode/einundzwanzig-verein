<?php

namespace App\Services\Nostr;

use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;

/**
 * Schnorr-signs one event on the library's primitives, with the id from
 * EventHash.
 *
 * The id is set BEFORE Sign::signEvent() runs: the library computes an id only
 * when none is present, and its own computation is the one that gets U+2028
 * wrong (see EventHash). With the id preset it signs ours.
 *
 * Internal to the DM core. The notification key reaches it only through
 * NotificationKey, which decides the kinds it may sign.
 */
final class EventSigning
{
    /**
     * A secp256k1 secret in its 64-character lowercase hex form is valid when
     * it lies in [1, n-1]. Fixed-width lowercase hex compares like the number.
     */
    private const string CURVE_ORDER = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    /**
     * @param  array<int, array<int, string>>  $tags
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: array<int, array<int, string>>, content: string, sig: string}
     */
    public static function sign(
        #[\SensitiveParameter] string $secretHex,
        int $kind,
        array $tags,
        string $content,
        int $createdAt,
    ): array {
        $pubkey = (new Key)->getPublicKey($secretHex);

        $event = (new Event)
            ->setKind($kind)
            ->setTags($tags)
            ->setContent($content)
            ->setCreatedAt($createdAt)
            ->setPublicKey($pubkey);

        $event->setId(EventHash::of($pubkey, $createdAt, $kind, $tags, $content));

        (new Sign)->signEvent($event, $secretHex);

        return [
            'id' => $event->getId(),
            'pubkey' => $event->getPublicKey(),
            'created_at' => $event->getCreatedAt(),
            'kind' => $event->getKind(),
            'tags' => $event->getTags(),
            'content' => $event->getContent(),
            'sig' => $event->getSignature(),
        ];
    }

    public static function isValidSecret(#[\SensitiveParameter] string $secretHex): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $secretHex) === 1
            && $secretHex !== str_repeat('0', 64)
            && strcmp($secretHex, self::CURVE_ORDER) < 0;
    }

    /**
     * A one-time secret from the OS CSPRNG, for the gift wrap (NIP-59). Drawn
     * again in the astronomically unlikely case it falls outside [1, n-1].
     */
    public static function freshSecret(): string
    {
        do {
            $secret = bin2hex(random_bytes(32));
        } while (! self::isValidSecret($secret));

        return $secret;
    }
}
