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
 * The board is the Wahlleitung: every current board member
 * (`EinundzwanzigPleb::isBoardMember()`) may take every step of running an
 * election, also in a round they stand in as a candidate. Everybody else may
 * stand as a candidate and see the elections that are not tests, nothing
 * more.
 *
 * Every gap falls shut: no member record or an erased member answers "no".
 */
class ElectionPolicy
{
    /**
     * A test election is seen by the board only. Any other election by every
     * member record, erased ones excepted.
     */
    public function view(NostrUser $user, Election $election): bool
    {
        $pleb = $this->pleb($user);

        if ($pleb === null) {
            return false;
        }

        return ! $election->is_test || $pleb->isBoardMember();
    }

    public function announce(NostrUser $user, Election $election): bool
    {
        return $election->announced_at === null && $this->isBoard($user);
    }

    public function delete(NostrUser $user, Election $election): bool
    {
        return ! $this->hasOpenedRound($election) && $this->isBoard($user);
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
        return $this->decidable($candidate, [ElectionCandidateStatus::Pending]) && $this->isBoard($user);
    }

    public function rejectCandidacy(NostrUser $user, ElectionCandidate $candidate): bool
    {
        return $this->decidable($candidate, [ElectionCandidateStatus::Pending]) && $this->isBoard($user);
    }

    public function withdrawCandidacy(NostrUser $user, ElectionCandidate $candidate): bool
    {
        return $this->decidable($candidate, [ElectionCandidateStatus::Pending, ElectionCandidateStatus::Approved])
            && $this->isBoard($user);
    }

    public function open(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isBoard($user);
    }

    /**
     * Sealing a round whose window has run out. Ending one that still takes
     * ballots is `closeEarly`.
     */
    public function close(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isBoard($user);
    }

    public function closeEarly(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isBoard($user);
    }

    public function annul(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isBoard($user);
    }

    public function enrolByHand(NostrUser $user, ElectionRound $round): bool
    {
        return $this->isBoard($user);
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
