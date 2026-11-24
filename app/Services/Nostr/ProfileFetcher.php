<?php

namespace App\Services\Nostr;

use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use JsonException;
use swentel\nostr\Key\Key;
use Throwable;

/**
 * Reads kind-0 profiles (NIP-01 metadata) from the profile relays and mirrors
 * them into `profiles`.
 *
 * Per author the newest event whose id and signature hold wins (NIP-01: the
 * higher created_at, an equal one goes to the lowest id); its content replaces
 * the stored profile as a whole. Before, the events of all relays were merged
 * field by field in arrival order, unverified, so any relay could put a name
 * on any pubkey and an old event could overwrite a newer one.
 *
 * Every relay goes through RelayTransport, whose deadline also covers the DNS
 * lookup and the connect (swentel's client waited up to 60 s for the connect).
 * No relays configured means no read at all — the normal case in tests.
 */
final class ProfileFetcher
{
    public const int KIND_METADATA = 0;

    public const int AUTHORS_PER_REQUEST = 100;

    public const int MAX_FUTURE_SECONDS = 900;

    private const array FIELDS = ['name', 'display_name', 'picture', 'banner', 'website', 'about', 'nip05', 'lud16', 'lud06'];

    public function __construct(private readonly RelayTransport $transport) {}

    /**
     * @param  iterable<string>  $keys  hex pubkeys or npubs; anything else is skipped
     * @return int how many profiles were stored
     */
    public function fetch(iterable $keys): int
    {
        /** @var array<int, string> $relays */
        $relays = config('einundzwanzig.config.profile_relays', []);
        $pubkeys = self::pubkeysOf($keys);

        if ($relays === [] || $pubkeys === []) {
            return 0;
        }

        $stored = 0;

        foreach (self::newestAuthenticPerAuthor($this->candidates($relays, $pubkeys)) as $pubkey => $event) {
            $attributes = self::attributesOf($event);

            if ($attributes === null) {
                continue;
            }

            Profile::query()->updateOrCreate(['pubkey' => $pubkey], $attributes);
            $stored++;
        }

        return $stored;
    }

    /**
     * @param  array<int, string>  $relays
     * @param  array<int, string>  $pubkeys
     * @return array<string, array<string, mixed>>
     */
    private function candidates(array $relays, array $pubkeys): array
    {
        $wanted = array_flip($pubkeys);
        $latestAcceptable = CarbonImmutable::now()->getTimestamp() + self::MAX_FUTURE_SECONDS;
        $candidates = [];

        foreach (array_chunk($pubkeys, self::AUTHORS_PER_REQUEST) as $authors) {
            foreach ($relays as $url) {
                $result = $this->transport->fetch($url, ['kinds' => [self::KIND_METADATA], 'authors' => $authors]);

                if (! $result->complete) {
                    Log::warning('Profile relay answered incompletely', ['relay' => $url, 'message' => $result->message]);
                }

                foreach ($result->events as $event) {
                    // Types first: a list where a string belongs would throw as an array key.
                    if (! is_array($event)
                        || ! is_string($event['pubkey'] ?? null)
                        || ! is_string($event['id'] ?? null)
                        || ! is_int($event['created_at'] ?? null)
                        || ($event['kind'] ?? null) !== self::KIND_METADATA
                        || ! isset($wanted[$event['pubkey']])
                        || $event['created_at'] > $latestAcceptable) {
                        continue;
                    }

                    // Keyed by everything signed plus the signature, not by the
                    // claimed id: a forged copy must not displace the real event.
                    $candidates[hash('sha256', serialize($event))] = $event;
                }
            }
        }

        return $candidates;
    }

    /**
     * Candidates are checked newest-first; each author's search stops at the
     * first authentic event, so older ones cost no signature check.
     *
     * @param  array<string, array<string, mixed>>  $candidates
     * @return array<string, array<string, mixed>>
     */
    private static function newestAuthenticPerAuthor(array $candidates): array
    {
        usort($candidates, fn (array $a, array $b): int => ($b['created_at'] <=> $a['created_at']) ?: strcmp($a['id'], $b['id']));

        $newest = [];

        foreach ($candidates as $event) {
            if (! isset($newest[$event['pubkey']]) && EventVerification::isAuthentic($event)) {
                $newest[$event['pubkey']] = $event;
            }
        }

        return $newest;
    }

    /**
     * The profile columns from an event's content, or null when the content
     * is no JSON object. A field that is not a string is stored as null.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, string|bool|null>|null
     */
    private static function attributesOf(array $event): ?array
    {
        try {
            $content = json_decode((string) $event['content'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($content) || ($content !== [] && array_is_list($content))) {
            return null;
        }

        $attributes = [];

        foreach (self::FIELDS as $field) {
            $attributes[$field] = is_string($content[$field] ?? null) ? $content[$field] : null;
        }

        $attributes['deleted'] = ($content['deleted'] ?? false) === true;

        return $attributes;
    }

    /**
     * @param  iterable<string>  $keys
     * @return array<int, string>
     */
    private static function pubkeysOf(iterable $keys): array
    {
        $pubkeys = [];

        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            if (preg_match('/^[0-9a-fA-F]{64}$/', $key) === 1) {
                $pubkeys[] = strtolower($key);

                continue;
            }

            try {
                $hex = (new Key)->convertToHex($key);
            } catch (Throwable) {
                continue;
            }

            if (is_string($hex) && preg_match('/^[0-9a-f]{64}$/', $hex) === 1) {
                $pubkeys[] = $hex;
            }
        }

        return array_values(array_unique($pubkeys));
    }
}
