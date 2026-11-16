<?php

namespace App\Console\Commands\Election;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionCandidateStatus;
use App\Exceptions\ElectionBallotRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCastingVote;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Models\ElectionVote;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\RoundManifest;
use App\Services\Election\TallyRound;
use App\Services\Election\VoteEvent;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use JsonException;
use UnexpectedValueException;

/**
 * Check a sealed round against the signed votes and the manifest written at
 * the close.
 *
 * Four checks, each reported on its own:
 *
 *  1. Every stored vote again: signature, signer (the event's pubkey, the
 *     stored pubkey and the member's pubkey agree), kind, binding to election
 *     and round, nonce, `created_at` within the open period, the stored event
 *     id, choices valid for the round, stored choices equal to the signed
 *     ones. A member who erased their data has no pubkey left to compare
 *     (`MembershipService::erasePersonalData()` tombstones it); their vote is
 *     checked against the key that signed it, see failedChecks().
 *  2. A recount from the signed events of the votes that pass (1), compared
 *     with the result in the stored manifest.
 *  3. The manifest rebuilt from the tables as they are now, compared with
 *     the stored text, and the stored text with its stored hash.
 *  4. The election's audit log: its hash chain holds, and the head the
 *     manifest anchored at the seal (`audit_head_hash`) is still a line of
 *     it. Without (4) a line written before the seal — say the reason for a
 *     voter enrolled by hand — could be edited, or deleted with the rest of
 *     the chain re-hashed, and this command still said OK (jury run 1, A).
 *  5. The chair's casting vote, if the round's tie has one: it fits the
 *     tie of the count and equals its audit line. The manifest holds the raw
 *     count from the seal; the effective result, with the casting vote, is
 *     printed on its own line.
 *
 * Exit 0: nothing differs. Exit 1: discrepancies — the output names vote ids
 * and check names or manifest fields, never a choice. Exit 2: there is
 * nothing to compare (unknown round, not sealed, no manifest, annulled).
 */
class VerifyRound extends Command
{
    protected $signature = 'election:verify {round : The id of the round}';

    protected $description = 'Re-verify the signed votes of a sealed election round, recount them and compare with the stored manifest';

    /** Manifest fields a recount from the signed events must reproduce. */
    private const RECOUNTED_FIELDS = ['counts', 'blank', 'outcome', 'elected', 'tied', 'tied_seats', 'tie_kind'];

    public function handle(RoundManifest $manifests, TallyRound $tally, ElectionAuditLog $audit): int
    {
        $round = ElectionRound::query()->find($this->argument('round'));

        if ($round === null) {
            $this->error('No such round.');

            return self::INVALID;
        }

        $manifest = ElectionManifest::query()->where('election_round_id', $round->getKey())->first();

        if ($manifest === null) {
            $this->error('This round has no manifest. Only a sealed round has one.');

            return self::INVALID;
        }

        try {
            $stored = json_decode($manifest->body, true, flags: JSON_THROW_ON_ERROR);
            $recomputed = $manifests->body($round, is_array($stored) ? ($stored['audit_head_hash'] ?? null) : null);
        } catch (ElectionRoundStateException) {
            $this->error('The votes of this round cannot be read: it is not sealed, or it has been annulled.');

            return self::INVALID;
        } catch (JsonException) {
            $this->error('MISMATCH: the stored manifest is not readable JSON.');

            return self::FAILURE;
        } catch (UnexpectedValueException) {
            $this->error('MISMATCH: a stored vote does not fit the rule or the candidates of the round.');
            $recomputed = null;
        }

        $clean = $this->verifyVotes($round, $tally, is_array($stored) ? $stored : []);
        $auditHolds = $this->verifyAuditLog($round, $audit, is_array($stored) ? ($stored['audit_head_hash'] ?? null) : null);
        $castingVoteHolds = $this->verifyCastingVote($round, $tally);

        $storedIntact = hash_equals($manifest->hash, RoundManifest::hash($manifest->body));
        $tablesUnchanged = $recomputed !== null && hash_equals($manifest->hash, RoundManifest::hash($recomputed));

        if ($clean && $auditHolds && $castingVoteHolds && $storedIntact && $tablesUnchanged) {
            $this->info('OK round '.$round->getKey().' manifest '.$manifest->hash);

            return self::SUCCESS;
        }

        if (! $storedIntact) {
            $this->error('MISMATCH: the stored manifest text no longer has the stored hash.');
        }

        if ($recomputed !== null && ! $tablesUnchanged) {
            $this->error('MISMATCH: the vote table no longer gives the manifest written at the close.');

            $fresh = json_decode($recomputed, true);
            $differing = is_array($stored)
                ? array_keys(array_filter($fresh, fn (mixed $value, string $key): bool => ($stored[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH))
                : [];

            if ($differing !== []) {
                $this->line('Differing fields: '.implode(', ', $differing));
            }
        }

        return self::FAILURE;
    }

    /**
     * Check (4). Prints one line per failure; true when there is none.
     *
     * The anchor is safe to compare by membership: it sits inside the
     * manifest text, whose hash check (3) covers, and every line's hash
     * covers the line before it. Editing a line before the anchor breaks the
     * chain; deleting one and re-hashing the rest changes every hash from
     * there on, the anchor included, so it is no longer found. `GENESIS` is
     * the anchor of a round sealed while the log was still empty — there was
     * nothing before the seal to protect.
     */
    private function verifyAuditLog(ElectionRound $round, ElectionAuditLog $audit, mixed $anchor): bool
    {
        $holds = true;

        if (! $audit->verify($round->election)) {
            $this->error('MISMATCH: the audit log of this election no longer holds its hash chain.');
            $holds = false;
        }

        $anchored = is_string($anchor) && (
            hash_equals(ElectionAuditLog::GENESIS, $anchor)
            || ElectionAuditEntry::query()
                ->where('election_event_id', $round->election_event_id)
                ->where('hash', $anchor)
                ->exists()
        );

        if (! $anchored) {
            $this->error('MISMATCH: the audit log head the manifest anchored at the seal is no longer a line of the audit log.');
            $holds = false;
        }

        return $holds;
    }

    /**
     * Check (5). Silent for a round without a casting vote; otherwise one
     * line with the effective result, or one per failure.
     */
    private function verifyCastingVote(ElectionRound $round, TallyRound $tally): bool
    {
        $castingVote = ElectionCastingVote::query()->where('election_round_id', $round->getKey())->first();

        if ($castingVote === null) {
            return true;
        }

        try {
            $result = $tally->handle($round);
        } catch (UnexpectedValueException) {
            return false;
        }

        $decision = $castingVote->decision($result->tied, $result->tiedSeats);
        $holds = true;

        if (! $decision->fits($result)) {
            $this->error('MISMATCH: the recorded casting vote does not fit the tie of the count.');
            $holds = false;
        }

        $lines = ElectionAuditEntry::query()
            ->where('election_round_id', $round->getKey())
            ->where('action', ElectionAuditAction::CastingVoteRecorded->value)
            ->get();
        $details = $lines->count() === 1 && is_array($lines->first()->details) ? $lines->first()->details : null;

        if ($details === null
            || ($details['chair'] ?? null) !== $decision->chair
            || ($details['decided_for'] ?? null) !== implode(',', $decision->candidates)
            || ($details['reason'] ?? null) !== $decision->note) {
            $this->error('MISMATCH: the recorded casting vote differs from its line in the audit log.');
            $holds = false;
        }

        if ($holds) {
            $this->line('Casting vote of the chair ('.$decision->chair.'): elected '.json_encode($result->decided($decision)->elected).' of tied '.json_encode($result->tied).'.');
        }

        return $holds;
    }

    /**
     * Checks (1) and (2). Prints one line per failing vote and one for a
     * recount that differs; true when there is nothing to print.
     *
     * @param  array<string, mixed>  $stored  the stored manifest
     */
    private function verifyVotes(ElectionRound $round, TallyRound $tally, array $stored): bool
    {
        $round = ElectionRound::query()->findOrFail($round->getKey());
        $periodEnd = $round->closed_at ?? $round->closes_at ?? now();
        $approved = $round->candidates()->where('status', ElectionCandidateStatus::Approved)->pluck('id')->all();
        $clean = true;
        $verified = [];
        $byErased = 0;

        foreach ($round->votes()->with('pleb')->orderBy('id')->get() as $vote) {
            $byErased += $vote->pleb?->isErased() === true ? 1 : 0;
            $event = is_array($vote->event) ? $vote->event : [];
            $selection = VoteEvent::selection($event);
            $failed = $this->failedChecks($vote, $event, $selection, $round, $periodEnd, $approved);

            if ($failed === []) {
                $verified[] = $selection;

                continue;
            }

            $clean = false;
            $this->error('FAIL vote '.$vote->getKey().' event '.$vote->event_id.': '.implode(', ', $failed));
        }

        if ($byErased > 0) {
            $this->line('Note: '.$byErased.' vote(s) by members who erased their data, checked against the key that signed them.');
        }

        try {
            $recount = $tally->count($round, $verified)->toArray();
        } catch (UnexpectedValueException) {
            $this->error('MISMATCH: the signed votes cannot be counted for this round.');

            return false;
        }

        $differing = array_values(array_filter(
            self::RECOUNTED_FIELDS,
            fn (string $field): bool => json_encode($stored[$field] ?? null) !== json_encode($field === 'counts' ? (object) $recount['counts'] : $recount[$field]),
        ));

        if (($stored['votes_cast'] ?? null) !== count($verified)) {
            $differing[] = 'votes_cast';
        }

        if ($differing !== []) {
            $this->error('MISMATCH: the recount from the signed events differs from the manifest in: '.implode(', ', $differing));

            return false;
        }

        return $clean;
    }

    /**
     * The names of the checks `$vote` fails.
     *
     * @param  array<mixed>  $event
     * @param  array<int, string>|null  $selection
     * @param  list<int>  $approved
     * @return list<string>
     */
    private function failedChecks(ElectionVote $vote, array $event, ?array $selection, ElectionRound $round, CarbonInterface $periodEnd, array $approved): array
    {
        $failed = array_map(
            fn (ElectionBallotRefusal $refusal): string => $refusal->value,
            VoteEvent::failures($event, $round, (string) $vote->pubkey, $periodEnd),
        );

        /*
         * The member record must still hold the key the vote was signed
         * with. Not for a member who erased their data: their pubkey is a
         * tombstone now, so the comparison would report every such vote as
         * foreign and the round as tampered with — a signal any voter could
         * trigger by exercising a legal right (jury run 1, C). Their vote
         * still has to pass every check above against the pubkey stored
         * with it and signed into the event. Skipping this one comparison
         * for an erased row gives a database writer nothing: whoever can
         * tombstone a member row can as well write the matching pubkey
         * into it, so the comparison never protected against that writer.
         */
        $erased = $vote->pleb?->isErased() === true;

        if (! $erased && $vote->pleb?->pubkey !== $vote->pubkey && ! in_array(ElectionBallotRefusal::ForeignSigner->value, $failed, true)) {
            $failed[] = ElectionBallotRefusal::ForeignSigner->value;
        }

        if (($event['id'] ?? null) !== $vote->event_id) {
            $failed[] = 'event_id';
        }

        try {
            if ($selection === null) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::InvalidChoices);
            }

            $round->counting_rule->ballotRows($selection, $approved, $round->seats);
        } catch (ElectionBallotRefusedException) {
            $failed[] = ElectionBallotRefusal::InvalidChoices->value;
        }

        $storedChoices = is_array($vote->choices) ? $vote->choices : null;

        if ($storedChoices === null || $selection === null || $this->sorted($storedChoices) !== $this->sorted($selection)) {
            $failed[] = 'choices_differ';
        }

        return $failed;
    }

    /**
     * @param  array<array-key, mixed>  $choices
     * @return array<array-key, mixed>
     */
    private function sorted(array $choices): array
    {
        ksort($choices);

        return $choices;
    }
}
