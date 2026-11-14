<?php

namespace App\Services\Election;

use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionManifest;
use App\Models\ElectionMark;
use App\Models\ElectionReceipt;
use App\Models\ElectionRound;

/**
 * The manifest of a sealed round: one canonical JSON text and its SHA-256.
 *
 * What goes in is what anybody may know once the round is sealed — the rule,
 * the numbers per option, who is elected, how many were on the roll, how
 * many marks and ballots there are, and the sorted list of receipt hashes
 * (E4: a voter finds their receipt in that list in the browser). Nothing in
 * it is tied to a single member or a single ballot.
 *
 * The same text is built twice: by `CloseRound` when the round is sealed,
 * and by `election:verify` from the tables as they are now. A ballot row,
 * receipt or roll entry changed after the close makes the two differ.
 * Changes before the close are not caught — there is nothing to compare
 * them with.
 *
 * `ballots_cast` is the number of receipts: every ballot taken in online has
 * exactly one. `marks` is counted on its own, so a mark without a ballot (a
 * vote cast on paper, once that exists) shows as a difference instead of
 * being hidden in one number.
 */
class RoundManifest
{
    /**
     * Raise whenever the content of `body()` changes, so an old manifest is
     * never compared with a text built by different rules.
     */
    public const VERSION = 1;

    public function __construct(protected TallyRound $tally) {}

    /**
     * Build the manifest of a sealed round and store it.
     *
     * `$auditHeadHash` is the head of the election's audit log at the close.
     * Null until the audit log of package P2-B2 is merged and wired in here
     * by `CloseRound`.
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

        $receiptHashes = ElectionReceipt::query()
            ->where('election_round_id', $round->getKey())
            ->toBase()
            ->pluck('hash')
            ->map(fn (mixed $hash): string => (string) $hash)
            ->sort()
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
            'marks' => ElectionMark::query()->where('election_round_id', $round->getKey())->count(),
            'ballots_cast' => count($receiptHashes),
            'receipt_list_hash' => hash('sha256', implode("\n", $receiptHashes)),
            'receipt_hashes' => $receiptHashes,
            'closed_at' => $round->closed_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'audit_head_hash' => $auditHeadHash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function hash(string $body): string
    {
        return hash('sha256', $body);
    }
}
