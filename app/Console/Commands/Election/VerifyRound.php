<?php

namespace App\Console\Commands\Election;

use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionCandidateStatus;
use App\Exceptions\ElectionBallotRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Models\ElectionVote;
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
 * Three checks, each reported on its own:
 *
 *  1. Every stored vote again: signature, signer (the event's pubkey, the
 *     stored pubkey and the member's pubkey agree), kind, binding to election
 *     and round, `created_at` within the open period, the stored event id,
 *     choices valid for the round, stored choices equal to the signed ones.
 *  2. A recount from the signed events of the votes that pass (1), compared
 *     with the result in the stored manifest.
 *  3. The manifest rebuilt from the tables as they are now, compared with
 *     the stored text, and the stored text with its stored hash.
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
    private const RECOUNTED_FIELDS = ['counts', 'blank', 'outcome', 'elected', 'tied', 'tied_seats'];

    public function handle(RoundManifest $manifests, TallyRound $tally): int
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

        $storedIntact = hash_equals($manifest->hash, RoundManifest::hash($manifest->body));
        $tablesUnchanged = $recomputed !== null && hash_equals($manifest->hash, RoundManifest::hash($recomputed));

        if ($clean && $storedIntact && $tablesUnchanged) {
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

        foreach ($round->votes()->with('pleb')->orderBy('id')->get() as $vote) {
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

        if ($vote->pleb?->pubkey !== $vote->pubkey && ! in_array(ElectionBallotRefusal::ForeignSigner->value, $failed, true)) {
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
