<?php

namespace App\Policies;

use App\Auth\NostrUser;
use App\Models\Election;

class ElectionPolicy
{
    /**
     * Determine whether the user can view any elections.
     */
    public function viewAny(?NostrUser $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the election.
     */
    public function view(?NostrUser $user, Election $election): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create elections.
     * Only board members.
     */
    public function create(NostrUser $user): bool
    {
        return $user->isBoardMember();
    }

    /**
     * Determine whether the user can update the election (e.g. manage candidates).
     * Only board members.
     */
    public function update(NostrUser $user, Election $election): bool
    {
        return $user->isBoardMember();
    }

    /**
     * Determine whether the user can delete the election.
     * Only board members.
     */
    public function delete(NostrUser $user, Election $election): bool
    {
        return $user->isBoardMember();
    }

    /**
     * Determine whether the user can vote in the election.
     *
     * Requires an active or honorary pleb whose fee for the current year is
     * actually paid. The status alone is not enough: it is now a consequence
     * of a payment, so a status without a paid year would let someone vote on
     * a membership that has lapsed — or, before the mass-assignment fix, on
     * one they had assigned to themselves.
     */
    public function vote(NostrUser $user, Election $election): bool
    {
        $pleb = $user->getPleb();

        if (! $pleb) {
            return false;
        }

        return $pleb->association_status->value >= 3
            && $pleb->hasPaidMembership();
    }
}
