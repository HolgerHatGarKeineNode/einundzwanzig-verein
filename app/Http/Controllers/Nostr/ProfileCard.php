<?php

namespace App\Http\Controllers\Nostr;

use App\Http\Controllers\Controller;
use App\Models\EinundzwanzigPleb;
use App\Models\PlebDmRelay;
use App\Models\Profile;
use App\Support\NostrAuth;
use App\Support\NostrAvatars;
use Einundzwanzig\Group\Nostr\ProfileCache;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use swentel\nostr\Key\Key;
use Throwable;

/**
 * The card behind a Nostr name chip: links to Nostr clients and checks of
 * the profile at a glance. For signed-in users only; the membership rows
 * (status, fee, DM inbox) only for the board.
 *
 * The kind 0 comes from the shared ProfileCache (fixed indexer relays, no
 * caller-chosen URL), with the portal's own profile row as fallback. NIP-05
 * is checked in the browser, so the server never fetches a domain a profile
 * names.
 */
class ProfileCard extends Controller
{
    public function __invoke(string $pubkey, ProfileCache $profiles): View
    {
        abort_unless(NostrAuth::check(), 403);

        $viewer = NostrAuth::user()?->getPleb();
        $isBoard = $viewer instanceof EinundzwanzigPleb && ! $viewer->isErased() && $viewer->isBoardMember();

        [$metadata, $updatedAt, $source] = $this->metadataOf($pubkey, $profiles);
        $npub = (new Key)->convertPublicKeyToBech32($pubkey);
        $member = EinundzwanzigPleb::query()->notErased()->where('pubkey', $pubkey)->first();

        return view('components.nostr-profile-card', [
            'pubkey' => $pubkey,
            'npub' => $npub,
            'name' => self::text($metadata['name'] ?? null) ?? self::text($metadata['display_name'] ?? null),
            'displayName' => self::text($metadata['display_name'] ?? null),
            'picture' => NostrAvatars::safeUrl($metadata['picture'] ?? null),
            'about' => self::text($metadata['about'] ?? null, 280),
            'website' => NostrAvatars::safeUrl($metadata['website'] ?? null),
            'nip05' => self::nip05($metadata['nip05'] ?? null),
            'lightning' => self::text($metadata['lud16'] ?? null) ?? (self::text($metadata['lud06'] ?? null) !== null ? 'LNURL' : null),
            'hasMetadata' => $metadata !== [],
            'updatedAt' => $updatedAt,
            'source' => $source,
            'isBoard' => $isBoard,
            'member' => $member,
            'memberPaid' => $isBoard && $member?->hasPaidMembership(),
            'memberInbox' => $isBoard && $member !== null ? PlebDmRelay::query()->where('einundzwanzig_pleb_id', $member->id)->value('relays') : null,
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?Carbon, 2: string}
     */
    private function metadataOf(string $pubkey, ProfileCache $profiles): array
    {
        try {
            $event = collect($profiles->get([$pubkey]))->first(fn ($event): bool => is_object($event) && ($event->pubkey ?? null) === $pubkey);
            $content = $event !== null ? json_decode((string) ($event->content ?? ''), true) : null;

            if (is_array($content)) {
                return [$content, isset($event->created_at) ? now()->setTimestamp((int) $event->created_at) : null, 'relays'];
            }
        } catch (Throwable) {
            // Indexer down: fall back to the portal's copy.
        }

        $profile = Profile::query()->where('pubkey', $pubkey)->first();

        if ($profile === null || $profile->deleted) {
            return [[], null, 'none'];
        }

        return [array_filter($profile->only(['name', 'display_name', 'picture', 'about', 'website', 'nip05', 'lud16', 'lud06']), fn ($value): bool => filled($value)), $profile->updated_at, 'portal'];
    }

    private static function text(mixed $value, int $limit = 120): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return str(trim($value))->squish()->limit($limit)->toString();
    }

    /**
     * A NIP-05 handle as [local part, domain], or null when it is not one.
     *
     * @return array{name: string, domain: string, handle: string}|null
     */
    private static function nip05(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $handle = strtolower(trim($value));
        $handle = str_contains($handle, '@') ? $handle : "_@{$handle}";

        if (preg_match('/^([a-z0-9._-]{1,64})@((?:[a-z0-9-]{1,63}\.)+[a-z]{2,63})$/', $handle, $match) !== 1) {
            return null;
        }

        return ['name' => $match[1], 'domain' => $match[2], 'handle' => $match[1] === '_' ? $match[2] : $handle];
    }
}
