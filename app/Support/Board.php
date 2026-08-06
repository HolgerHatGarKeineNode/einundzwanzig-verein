<?php

namespace App\Support;

use swentel\nostr\Key\Key;
use Throwable;

/**
 * The single source of truth for "who is on the board".
 *
 * The list lives in `config/einundzwanzig.config.current_board` as npubs.
 * Everything that needs an answer — the proposal policies via
 * `EinundzwanzigPleb::isBoardMember()`, and the member admin screen, which
 * only ever sees the hex pubkey of the Nostr session — asks this class, so
 * npub and hex encodings can never disagree.
 *
 * Fail-closed by design: an npub that cannot be decoded is dropped instead
 * of being passed through, so a malformed config entry denies access rather
 * than matching something unintended.
 */
class Board
{
    /**
     * Decoded hex pubkeys, keyed by the npub list they were derived from.
     * Keying by the source rather than memoizing a single value keeps the
     * cache honest when the configuration changes inside one process.
     *
     * @var array<string, array<int, string>>
     */
    private static array $pubkeys = [];

    /**
     * The configured board npubs.
     *
     * @return array<int, string>
     */
    public static function npubs(): array
    {
        return array_values(array_filter(
            (array) config('einundzwanzig.config.current_board', []),
            static fn ($npub): bool => is_string($npub) && $npub !== ''
        ));
    }

    /**
     * The configured board members as 64-character hex pubkeys.
     *
     * @return array<int, string>
     */
    public static function pubkeys(): array
    {
        $npubs = self::npubs();
        $cacheKey = implode(',', $npubs);

        if (isset(self::$pubkeys[$cacheKey])) {
            return self::$pubkeys[$cacheKey];
        }

        $key = new Key;
        $pubkeys = [];

        foreach ($npubs as $npub) {
            try {
                $pubkeys[] = $key->convertToHex($npub);
            } catch (Throwable) {
                continue;
            }
        }

        return self::$pubkeys[$cacheKey] = $pubkeys;
    }

    public static function containsNpub(?string $npub): bool
    {
        return $npub !== null && in_array($npub, self::npubs(), true);
    }

    public static function containsPubkey(?string $pubkey): bool
    {
        return $pubkey !== null && in_array($pubkey, self::pubkeys(), true);
    }

    /**
     * Drop the memoized hex lists.
     */
    public static function flush(): void
    {
        self::$pubkeys = [];
    }
}
