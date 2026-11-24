<?php

namespace App\Services\Nostr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The association's NIP-05 directory, einundzwanzig.space/.well-known/nostr.json.
 *
 * Read with a 3 s budget and cached for five minutes: the profile and the
 * benefits page ask it on every mount, and the request it replaces ran with
 * the HTTP client's defaults (10 s connect, 30 s total). Only a readable answer is
 * cached. A failed read counts as an empty directory (nothing verified) for
 * that one request and is tried again on the next. Only a connection
 * failure is caught: in tests a request nobody faked stays an error instead
 * of passing as an empty directory.
 *
 * Right after a member saved a handle the page asks with `$fresh`, so the
 * answer is not up to five minutes old.
 */
final class Nip05Directory
{
    public const string URL = 'https://einundzwanzig.space/.well-known/nostr.json';

    public const string CACHE_KEY = 'nip05-directory:names';

    public const int CACHE_SECONDS = 300;

    public const int TIMEOUT_SECONDS = 3;

    public function statusFor(string $pubkey, ?string $storedHandle, bool $fresh = false): Nip05Status
    {
        if (blank($storedHandle)) {
            return Nip05Status::none();
        }

        return Nip05Status::of($storedHandle, $this->handlesFor($pubkey, $fresh));
    }

    /**
     * @return list<string>
     */
    public function handlesFor(string $pubkey, bool $fresh = false): array
    {
        $handles = [];

        foreach ($this->names($fresh) as $handle => $listedPubkey) {
            if ($listedPubkey === $pubkey) {
                $handles[] = (string) $handle;
            }
        }

        return $handles;
    }

    /**
     * @return array<string, string>
     */
    private function names(bool $fresh): array
    {
        if (! $fresh && is_array($cached = Cache::get(self::CACHE_KEY))) {
            return $cached;
        }

        $names = $this->read();

        if ($names !== null) {
            Cache::put(self::CACHE_KEY, $names, self::CACHE_SECONDS);
        }

        return $names ?? [];
    }

    /**
     * The `names` map, or null when the directory could not be read.
     *
     * @return array<string, string>|null
     */
    private function read(): ?array
    {
        try {
            $response = Http::connectTimeout(self::TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS)->get(self::URL);
        } catch (ConnectionException $exception) {
            Log::warning('NIP-05 directory unreadable', ['error' => $exception->getMessage()]);

            return null;
        }

        $names = $response->successful() ? $response->json('names') : null;

        if (! is_array($names)) {
            Log::warning('NIP-05 directory without a names map', ['status' => $response->status()]);

            return null;
        }

        return array_filter($names, is_string(...));
    }
}
