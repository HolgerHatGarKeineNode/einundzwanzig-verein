<?php

namespace App\Support;

use App\Models\Profile;

/**
 * Profile pictures for the name chips of the election and message pages,
 * looked up once per request. Registered as a scoped binding, so a list of
 * a hundred names asks the database once (preload) or once per name, never
 * once per render of the same name.
 */
final class NostrAvatars
{
    /** @var array<string, string|null> pubkey => picture URL */
    private array $pictures = [];

    /**
     * @param  iterable<int, string|null>  $pubkeys
     */
    public function preload(iterable $pubkeys): void
    {
        $missing = collect($pubkeys)
            ->filter(fn (?string $pubkey): bool => self::isPubkey($pubkey) && ! array_key_exists($pubkey, $this->pictures))
            ->unique()
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        $found = Profile::query()->whereIn('pubkey', $missing)->pluck('picture', 'pubkey');

        foreach ($missing as $pubkey) {
            $this->pictures[$pubkey] = self::safeUrl($found->get($pubkey));
        }
    }

    public function pictureOf(?string $pubkey): ?string
    {
        if (! self::isPubkey($pubkey)) {
            return null;
        }

        $this->preload([$pubkey]);

        return $this->pictures[$pubkey];
    }

    public static function isPubkey(?string $pubkey): bool
    {
        return is_string($pubkey) && preg_match('/^[0-9a-f]{64}$/', $pubkey) === 1;
    }

    /**
     * Only http(s) URLs of sane length reach an `src`.
     */
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048) {
            return null;
        }

        $url = trim($url);

        return preg_match('#^https?://[^\s"\'<>]+$#i', $url) === 1 ? $url : null;
    }
}
