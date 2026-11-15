<?php

namespace App\Services\Election;

use App\Enums\ElectionBallotRefusal;
use App\Models\ElectionRound;
use App\Services\Nostr\EventVerification;
use Carbon\CarbonInterface;

/**
 * The Nostr event a member signs to vote. It is never sent to a relay.
 *
 * Kind 22121. Not taken by any NIP this application knows, and not 2121 or
 * 32122, the kinds of the public 2024 election (docs/vereinshistorie/wahl-2024.md).
 * It lies in the ephemeral range on purpose: a relay that receives it by
 * mistake passes it on and does not store it.
 *
 * Tags, in any order:
 *
 *   ["election", "<election id>"]                 exactly once
 *   ["round", "<round id>"]                       exactly once
 *   ["vote", "<candidate id>", "for|yes|no"]      once per chosen candidate; none for a blank vote
 *
 * The signature covers the tags, so the event names one round of one
 * election and one set of choices; replayed into another round it fails the
 * binding check. `content` is free text for the signer to show and is not
 * read. Other tags are ignored.
 */
final class VoteEvent
{
    public const int KIND = 22121;

    /** How far `created_at` may lie outside the open period: clocks of members' devices drift. */
    public const int CLOCK_TOLERANCE_SECONDS = 300;

    /**
     * The unsigned event for `$selection` (candidate id => choice value).
     *
     * @param  array<int, string>  $selection
     * @return array{kind: int, created_at: int, tags: list<list<string>>, content: string}
     */
    public static function template(ElectionRound $round, array $selection, int $createdAt): array
    {
        $tags = [
            ['election', (string) $round->election_event_id],
            ['round', (string) $round->getKey()],
        ];

        foreach ($selection as $candidateId => $choice) {
            $tags[] = ['vote', (string) $candidateId, $choice];
        }

        return ['kind' => self::KIND, 'created_at' => $createdAt, 'tags' => $tags, 'content' => ''];
    }

    /**
     * What the event's `vote` tags say, candidate id => choice value; empty
     * for a blank vote. Null when a `vote` tag is malformed or names a
     * candidate twice. Whether the choices fit the round decides
     * `ElectionCountingRule::ballotRows()`.
     *
     * @param  array<mixed>  $event
     * @return array<int, string>|null
     */
    public static function selection(array $event): ?array
    {
        $selection = [];

        foreach (is_array($event['tags'] ?? null) ? $event['tags'] : [] as $tag) {
            if (! is_array($tag) || ($tag[0] ?? null) !== 'vote') {
                continue;
            }

            if (count($tag) !== 3 || ! is_string($tag[1]) || ! is_string($tag[2]) || preg_match('/^[1-9][0-9]{0,17}$/D', $tag[1]) !== 1) {
                return null;
            }

            $candidateId = (int) $tag[1];

            if (array_key_exists($candidateId, $selection)) {
                return null;
            }

            $selection[$candidateId] = $tag[2];
        }

        return $selection;
    }

    /**
     * Every check `$event` fails as a vote by `$pubkey` in `$round`, in this
     * order: signature, signer, kind, binding to election and round, time.
     * Empty when it passes all of them.
     *
     * The time check accepts `created_at` from the round's beginning to its
     * end or `$periodEnd`, whichever is earlier — the moment of the cast, or
     * the close when a stored vote is verified — each widened by
     * `CLOCK_TOLERANCE_SECONDS`.
     *
     * @param  array<mixed>  $event
     * @return list<ElectionBallotRefusal>
     */
    public static function failures(array $event, ElectionRound $round, string $pubkey, CarbonInterface $periodEnd): array
    {
        $failures = [];

        if (! EventVerification::isAuthentic($event)) {
            $failures[] = ElectionBallotRefusal::InvalidSignature;
        }

        if (($event['pubkey'] ?? null) !== $pubkey) {
            $failures[] = ElectionBallotRefusal::ForeignSigner;
        }

        if (($event['kind'] ?? null) !== self::KIND) {
            $failures[] = ElectionBallotRefusal::WrongKind;
        }

        if (self::tagValues($event, 'election') !== [(string) $round->election_event_id]
            || self::tagValues($event, 'round') !== [(string) $round->getKey()]) {
            $failures[] = ElectionBallotRefusal::WrongRound;
        }

        $createdAt = $event['created_at'] ?? null;
        $end = $round->closes_at === null ? $periodEnd : $periodEnd->min($round->closes_at);

        if (! is_int($createdAt)
            || $round->opens_at === null
            || $createdAt < $round->opens_at->getTimestamp() - self::CLOCK_TOLERANCE_SECONDS
            || $createdAt > $end->getTimestamp() + self::CLOCK_TOLERANCE_SECONDS) {
            $failures[] = ElectionBallotRefusal::OutsideOpenPeriod;
        }

        return $failures;
    }

    /**
     * The values of every `$name` tag of `$event`.
     *
     * @param  array<mixed>  $event
     * @return list<mixed>
     */
    private static function tagValues(array $event, string $name): array
    {
        $values = [];

        foreach (is_array($event['tags'] ?? null) ? $event['tags'] : [] as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name) {
                $values[] = count($tag) === 2 ? $tag[1] : null;
            }
        }

        return $values;
    }
}
