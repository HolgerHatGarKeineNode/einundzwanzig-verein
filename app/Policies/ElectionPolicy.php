<?php

namespace App\Policies;

use App\Auth\NostrUser;
use App\Enums\AssociationStatus;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionRoundState;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;

/**
 * Who may do what in an election. Registered for `Election`,
 * `ElectionRound` and `ElectionCandidate`; each ability names the model it
 * is asked about.
 *
 * Two roles, decided per election:
 *
 *  - the board (`EinundzwanzigPleb::isBoardMember()`), and
 *  - the Wahlleitung (`Election::isLedBy()`), named per election by board
 *    resolution and possibly outside the board.
 *
 * Nobody acts in a round they are a candidate in — a candidacy in any state
 * counts, a rejected or withdrawn one included. The exceptions to the frozen
 * course of an election (reject, withdraw, close early, annul, enrol by hand,
 * paper vote) are the Wahlleitung's alone. Approving, opening and sealing a
 * round whose window has run out are open to the Wahlleitung and to every
 * board member who does not stand in that round, so an election in which the
 * whole board stands can still be run by a Wahlleitung from outside it.
 *
 * Every gap falls shut: no member record, an erased member, an election
 * without a Wahlleitung — each answers "no".
 */
class ElectionPolicy
{
    /**
     * A test election is seen by the board and its Wahlleitung only. Any
     * other election by every member record, erased ones excepted.
     */
    public function view(NostrUser $user, Election $election): bool
    {
        $pleb = $this->pleb($user);

        if ($pleb === null) {
            return false;
        }

        return ! $election->is_test || $pleb->isBoardMember() || $election->isLedBy($pleb);
    }

    /**
     * Naming the Wahlleitung is a board resolution, carried out by a board
     * member before anything is frozen.
     */
    public function appointLead(NostrUser $user, Election $election): bool
    {
        return $this->isBoard($user) && ! $this->hasOpenedRound($election);
    }

    /**
     * From the announcement on, membership categories change only through
     * the Wahlleitung — so an election without one cannot be announced.
     */
    public function announce(NostrUser $user, Election $election): bool
    {
        return $election->announced_at === null
            && $election->election_lead_pleb_id !== null
            && ($this->isBoard($user) || $election->isLedBy($this->pleb($user)));
    }

    public function delete(NostrUser $user, Election $election): bool
    {
        return ! $this->hasOpenedRound($election)
            && ($this->isBoard($user) || $election->isLedBy($this->pleb($user)));
    }

    /**
     * A member stands for themself only: active and honorary members, in a
     * draft round that is not a run-off, once.
     */
    public function submitCandidacy(NostrUser $user, ElectionRound $round): bool
    {
        $pleb = $this->pleb($user);

        return $pleb !== null
            && in_array($pleb->association_status, [AssociationStatus::ACTIVE, AssociationStatus::HONORARY], true)
            && $round->parent_round_id === null
            && $round->stateAt(now()) === ElectionRoundState::Draft
            && ! $this->standsIn($pleb, $round);
    }

    public function approveCandidacy(NostrUser $user, ElectionCandidate $candidate): bool
    {
        return $this->decidable($candidate, [ElectionCandidateStatus::Pending])
            && $this->isUninvolvedSteward($user, $candidate->round);
    }

    public function rejectCandidacy(NostrUser $user, ElectionCandidate $candidate): bool
    {
        return $this->decidable($candidate, [ElectionCandidateStatus::Pending])
            && $this->isUninvolvedLead($user, $candidate->round);
    }

    public function withdrawCandidacy(NostrUser $user, ElectionCandidate $candidate): bool
    {
        return $this->decidable($candidate, [ElectionCandidateStatus::Pending, ElectionCandidateStatus::Approved])
            && $this->isUninvolvedLead($user, $candidate->round);
    }

    public function open(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isUninvolvedSteward($user, $round);
    }

    /**
     * Sealing a round whose window has run out. Ending one that still takes
     * ballots is `closeEarly`.
     */
    public function close(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isUninvolvedSteward($user, $round);
    }

    public function closeEarly(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isUninvolvedLead($user, $round);
    }

    public function annul(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isUninvolvedLead($user, $round);
    }

    public function enrolByHand(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isUninvolvedLead($user, $round);
    }

    public function recordPaperVote(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isUninvolvedLead($user, $round);
    }

    /**
     * The second person of a paper vote: a board member or the Wahlleitung
     * who does not stand in the round. That it is not the same person as the
     * first is checked where both are known, in `RecordPaperVote`.
     */
    public function confirmPaperVote(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isUninvolvedSteward($user, $round);
    }

    /**
     * The Wahlleitung of the round's election, not standing in the round.
     *
     * Mutation that must turn `ElectionPolicyTest` red: drop the
     * `standsIn()` check here — a Wahlleitung who stands in the round can
     * then close it early.
     */
    protected function isUninvolvedLead(NostrUser $user, ?ElectionRound $round): bool
    {
        $pleb = $this->pleb($user);

        return $pleb !== null
            && $round !== null
            && $round->election?->isLedBy($pleb) === true
            && ! $this->standsIn($pleb, $round);
    }

    /**
     * The Wahlleitung or a board member, not standing in the round.
     */
    protected function isUninvolvedSteward(NostrUser $user, ?ElectionRound $round): bool
    {
        $pleb = $this->pleb($user);

        if ($pleb === null || $round === null || $round->election === null) {
            return false;
        }

        return ($pleb->isBoardMember() || $round->election->isLedBy($pleb))
            && ! $this->standsIn($pleb, $round);
    }

    /**
     * Any candidacy of `$pleb` in `$round`, whatever its state.
     */
    protected function standsIn(EinundzwanzigPleb $pleb, ElectionRound $round): bool
    {
        return ElectionCandidate::query()
            ->where('election_round_id', $round->getKey())
            ->where('einundzwanzig_pleb_id', $pleb->getKey())
            ->exists();
    }

    /**
     * @param  list<ElectionCandidateStatus>  $from
     */
    protected function decidable(ElectionCandidate $candidate, array $from): bool
    {
        return in_array($candidate->status, $from, true)
            && $candidate->round?->stateAt(now()) === ElectionRoundState::Draft;
    }

    protected function hasOpenedRound(Election $election): bool
    {
        return $election->rounds()->whereNotNull('opened_at')->exists();
    }

    protected function isBoard(NostrUser $user): bool
    {
        return $this->pleb($user)?->isBoardMember() === true;
    }

    /**
     * The member behind the session, or null for a session without one or
     * an erased member.
     */
    protected function pleb(NostrUser $user): ?EinundzwanzigPleb
    {
        $pleb = $user->getPleb();

        return $pleb instanceof EinundzwanzigPleb && ! $pleb->isErased() ? $pleb : null;
    }
}
