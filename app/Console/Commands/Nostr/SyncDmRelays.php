<?php

namespace App\Console\Commands\Nostr;

use App\Models\EinundzwanzigPleb;
use App\Models\PlebDmRelay;
use App\Services\Nostr\EventVerification;
use App\Services\Nostr\Relay\RelayAddressGuard;
use App\Services\Nostr\RelayTransport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reads every member's kind 10050 list — their DM inbox relays (NIP-17) —
 * through RelayTransport and stores the relays in `pleb_dm_relays`.
 *
 * Reads from the relays in `einundzwanzig.config.profile_relays`, the same
 * set the profile lookup uses (purplepag.es is an indexer for exactly these
 * replaceable lists). A member's NIP-65 outbox relays are not consulted: a
 * list published only there is missed, and that member counts as
 * without_inbox until it reaches one of these relays.
 *
 * Every event is checked before it counts: kind, author among the members,
 * id and signature, and a `created_at` no later than a few minutes from now —
 * a list dated into the future would otherwise win against every later,
 * honest one. Among the valid lists of a member the newest wins (NIP-01
 * replaceable events; equal timestamps go to the lowest id).
 *
 * The stored state only ever moves forward. A row is replaced by a newer list
 * and never by an older one; a member whose newest list has no usable relay
 * loses the row; a member with no list at all loses it only when every read
 * was complete — one unreachable relay must not unsubscribe anybody.
 */
class SyncDmRelays extends Command
{
    protected $signature = 'nostr:sync-dm-relays
        {--dry-run : Read and count only; store nothing}';

    protected $description = 'Read the members\' DM inbox relays (kind 10050, NIP-17) and store them';

    public const int KIND_DM_RELAYS = 10050;

    public const int AUTHORS_PER_REQUEST = 100;

    /** NIP-17: "keep kind:10050 lists small (1-3 relays)". */
    public const int MAX_RELAYS_PER_MEMBER = 3;

    public const int MAX_FUTURE_SECONDS = 900;

    public function handle(RelayTransport $transport): int
    {
        /** @var array<int, string> $sources */
        $sources = config('einundzwanzig.config.profile_relays', []);

        if ($sources === []) {
            $this->error('No relays to read from: einundzwanzig.config.profile_relays (NOSTR_PROFILE_RELAYS) is empty.');

            return self::FAILURE;
        }

        $members = EinundzwanzigPleb::query()->notErased()->pluck('pubkey', 'id');

        /** @var array<string, int> $memberIdByPubkey */
        $memberIdByPubkey = [];

        foreach ($members as $id => $pubkey) {
            if (is_string($pubkey) && preg_match('/^[0-9a-f]{64}$/', $pubkey) === 1) {
                $memberIdByPubkey[$pubkey] = (int) $id;
            }
        }

        [$newest, $complete] = $this->readNewestLists($transport, $sources, $memberIdByPubkey);

        /** @var array<int, array{relays: array<int, string>, event: array<string, mixed>}> $inboxes */
        $inboxes = [];

        foreach ($newest as $pubkey => $event) {
            $relays = self::inboxRelays($event['tags']);

            if ($relays !== []) {
                $inboxes[$memberIdByPubkey[$pubkey]] = ['relays' => $relays, 'event' => $event];
            }
        }

        $total = $members->count();
        $withInbox = count($inboxes);

        $this->line("members={$total} with_inbox={$withInbox} without_inbox=".($total - $withInbox));

        if (! $complete) {
            $this->warn('Some reads were incomplete; rows of members without a list were kept.');
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $this->store($newest, $inboxes, $memberIdByPubkey, $complete);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $sources
     * @param  array<string, int>  $memberIdByPubkey
     * @return array{0: array<string, array<string, mixed>>, 1: bool}
     */
    private function readNewestLists(RelayTransport $transport, array $sources, array $memberIdByPubkey): array
    {
        $complete = true;
        $latestAcceptable = CarbonImmutable::now()->getTimestamp() + self::MAX_FUTURE_SECONDS;

        /** @var array<string, array<string, mixed>> $candidates */
        $candidates = [];

        foreach (array_chunk(array_keys($memberIdByPubkey), self::AUTHORS_PER_REQUEST) as $authors) {
            foreach ($sources as $url) {
                $result = $transport->fetch($url, ['kinds' => [self::KIND_DM_RELAYS], 'authors' => $authors]);

                if (! $result->complete) {
                    $complete = false;
                    $this->warn("{$url}: {$result->message}");
                }

                foreach ($result->events as $event) {
                    // Types first: a relay's JSON can put a list where a string belongs,
                    // and a list used as an array key throws instead of failing the check.
                    if (! is_array($event)
                        || ! is_string($event['pubkey'] ?? null)
                        || ! is_string($event['id'] ?? null)
                        || ! is_int($event['created_at'] ?? null)
                        || ($event['kind'] ?? null) !== self::KIND_DM_RELAYS
                        || ! isset($memberIdByPubkey[$event['pubkey']])
                        || $event['created_at'] > $latestAcceptable) {
                        continue;
                    }

                    $candidates[self::fingerprint($event)] = $event;
                }
            }
        }

        return [self::newestAuthenticPerAuthor($candidates), $complete];
    }

    /**
     * The newest authentic list of each author (NIP-01: the higher created_at
     * wins, an equal one goes to the lowest id). Candidates are checked
     * newest-first and each author's search stops at the first event whose id
     * and signature hold, so older lists cost no signature check.
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
     * Identifies an event by everything it is signed over, plus the signature.
     *
     * Not by its id alone: an id is only a claim until verified. A copy that
     * carries a list's real id with other tags would, deduplicated by id,
     * displace the real list read from another relay, and the member's
     * newest list would silently fall back to an older one.
     *
     * @param  array<string, mixed>  $event
     */
    private static function fingerprint(array $event): string
    {
        return hash('sha256', serialize([
            $event['id'],
            $event['pubkey'],
            $event['created_at'],
            $event['kind'],
            $event['tags'] ?? null,
            $event['content'] ?? null,
            $event['sig'] ?? null,
        ]));
    }

    /**
     * @param  array<string, array<string, mixed>>  $newest
     * @param  array<int, array{relays: array<int, string>, event: array<string, mixed>}>  $inboxes
     * @param  array<string, int>  $memberIdByPubkey
     */
    private function store(array $newest, array $inboxes, array $memberIdByPubkey, bool $complete): void
    {
        DB::transaction(function () use ($newest, $inboxes, $memberIdByPubkey, $complete): void {
            $stored = PlebDmRelay::query()->get()->keyBy('einundzwanzig_pleb_id');

            foreach ($newest as $pubkey => $event) {
                $memberId = $memberIdByPubkey[$pubkey];
                $row = $stored->get($memberId);

                if ($row !== null && ! self::isNewer($event['created_at'], $event['id'], $row->event_created_at, $row->event_id)) {
                    continue;
                }

                if (! isset($inboxes[$memberId])) {
                    $row?->delete();

                    continue;
                }

                PlebDmRelay::query()->updateOrCreate(
                    ['einundzwanzig_pleb_id' => $memberId],
                    [
                        'relays' => $inboxes[$memberId]['relays'],
                        'event_id' => $event['id'],
                        'event_created_at' => $event['created_at'],
                    ],
                );
            }

            if ($complete) {
                $listed = array_map(fn (string $pubkey): int => $memberIdByPubkey[$pubkey], array_keys($newest));

                PlebDmRelay::query()->whereNotIn('einundzwanzig_pleb_id', $listed)->delete();
            }
        });
    }

    /**
     * The usable `relay` tags of a kind 10050 list: wss:// only, normalised,
     * de-duplicated, at most MAX_RELAYS_PER_MEMBER. Whether an address may
     * actually be dialled is decided at send time by RelayAddressGuard.
     *
     * @param  array<int, array<int, string>>  $tags
     * @return array<int, string>
     */
    public static function inboxRelays(array $tags): array
    {
        $relays = [];

        foreach ($tags as $tag) {
            if (($tag[0] ?? null) !== 'relay' || ! is_string($tag[1] ?? null)) {
                continue;
            }

            $url = trim($tag[1]);
            $parts = parse_url($url);

            if (strlen($url) > RelayAddressGuard::MAX_URL_LENGTH
                || ! is_array($parts)
                || strtolower($parts['scheme'] ?? '') !== 'wss'
                || ($parts['host'] ?? '') === '') {
                continue;
            }

            $normalised = 'wss://'.strtolower($parts['host'])
                .(isset($parts['port']) ? ':'.$parts['port'] : '')
                .($parts['path'] ?? '/')
                .(isset($parts['query']) ? '?'.$parts['query'] : '');

            $relays[$normalised] = true;
        }

        return array_slice(array_keys($relays), 0, self::MAX_RELAYS_PER_MEMBER);
    }

    private static function isNewer(int $createdAt, string $id, int $thanCreatedAt, string $thanId): bool
    {
        return $createdAt > $thanCreatedAt || ($createdAt === $thanCreatedAt && strcmp($id, $thanId) < 0);
    }
}
