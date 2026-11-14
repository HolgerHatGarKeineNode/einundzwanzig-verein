<?php

namespace App\Services\Nostr;

use JsonException;

/**
 * The NIP-01 event id: sha256 over `[0, pubkey, created_at, kind, tags, content]`.
 *
 * Exists because `swentel\nostr\Sign\Sign::serializeEvent()` hashes without
 * JSON_UNESCAPED_LINE_TERMINATORS. PHP then writes U+2028 and U+2029 as the
 * six-character escapes (backslash, u, 2028), while JSON.stringify
 * (nostr-tools, and every relay that re-hashes) writes the characters
 * themselves. Text containing either
 * character got an id nobody else could reproduce — measured on 2026-11-14
 * against nostr-tools 2.25.2 `getEventHash`.
 *
 * The flags below produce exactly what JSON.stringify produces for these
 * values: `"`, `\` and the control characters escaped, `/` and every other
 * character written verbatim.
 */
final class EventHash
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_THROW_ON_ERROR;

    /**
     * @param  array<int, array<int, string>>  $tags
     *
     * @throws JsonException for content or tags that are not valid UTF-8
     */
    public static function of(string $pubkey, int $createdAt, int $kind, array $tags, string $content): string
    {
        return hash('sha256', self::serialize($pubkey, $createdAt, $kind, $tags, $content));
    }

    /**
     * @param  array<int, array<int, string>>  $tags
     *
     * @throws JsonException for content or tags that are not valid UTF-8
     */
    public static function serialize(string $pubkey, int $createdAt, int $kind, array $tags, string $content): string
    {
        return json_encode([0, $pubkey, $createdAt, $kind, array_values($tags), $content], self::FLAGS);
    }

    /**
     * JSON for anything that travels inside an event or over the wire, encoded
     * the same way the id is computed.
     *
     * @throws JsonException
     */
    public static function json(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }
}
