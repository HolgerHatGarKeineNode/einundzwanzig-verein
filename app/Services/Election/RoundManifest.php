<?php

namespace App\Services\Election;

use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;

/**
 * The manifest of a sealed round: one canonical JSON text and its SHA-256.
 *
 * What goes in: the rule, the numbers per option, who is elected, how many
 * were on the roll, how many votes there are, and a hash over the sorted
 * event ids of those votes. Neither members nor choices of a single vote.
 *
 * The same text is built twice: by `CloseRound` when the round is sealed,
 * and by `election:verify` from the tables as they are now. A vote or roll
 * entry changed after the close makes the two differ. Changes before the
 * close are not caught here; `election:verify` checks every vote against its
 * signed event for those.
 */
class RoundManifest
{
    /**
     * Raise whenever the content of `body()` changes, so an old manifest is
     * never compared with a text built by different rules.
     */
    public const VERSION = 2;

    public function __construct(protected TallyRound $tally) {}

    /**
     * Build the manifest of a sealed round and store it.
     *
     * `$auditHeadHash` is the head of the election's audit log at the close
     * (`ElectionAuditLog::headHash()`, `GENESIS` for an election without a
     * line), passed in by `CloseRound`. Manifests written before it was
     * wired in carry null.
     *
     * @throws ElectionRoundStateException when the round is not sealed
     */
    public function record(ElectionRound $round, ?string $auditHeadHash = null): ElectionManifest
    {
        $body = $this->body($round, $auditHeadHash);

        return ElectionManifest::query()->create([
            'election_round_id' => $round->getKey(),
            'hash' => self::hash($body),
            'body' => $body,
        ]);
    }

    /**
     * The canonical text. Same tables, same text, byte for byte.
     *
     * @throws ElectionRoundStateException when the round is not sealed
     */
    public function body(ElectionRound $round, ?string $auditHeadHash = null): string
    {
        $round = ElectionRound::query()->findOrFail($round->getKey());
        $result = $this->tally->handle($round);

        $voteEventIds = $round->votes()
            ->toBase()
            ->pluck('event_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->sort(SORT_STRING)
            ->values()
            ->all();

        return json_encode([
            'version' => self::VERSION,
            'election' => (int) $round->election_event_id,
            'round' => (int) $round->getKey(),
            'counting_rule' => $result->rule->value,
            'seats' => $result->seats,
            'candidates' => array_keys($result->counts),
            'counts' => (object) $result->counts,
            'blank' => $result->blank,
            'outcome' => $result->outcome->value,
            'elected' => $result->elected,
            'tied' => $result->tied,
            'tied_seats' => $result->tiedSeats,
            'roll_size' => $round->rollEntries()->count(),
            'votes_cast' => count($voteEventIds),
            'vote_event_ids_hash' => self::voteEventIdsHash($voteEventIds),
            'closed_at' => $round->closed_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'audit_head_hash' => $auditHeadHash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function hash(string $body): string
    {
        return hash('sha256', $body);
    }

    /**
     * SHA-256 over the event ids, sorted, one per line.
     *
     * @param  list<string>  $eventIds  sorted
     */
    public static function voteEventIdsHash(array $eventIds): string
    {
        return hash('sha256', implode("\n", $eventIds));
    }
}
