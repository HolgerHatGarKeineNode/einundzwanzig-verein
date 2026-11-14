<?php

namespace App\Policies;

use App\Auth\NostrUser;
use App\Enums\AssociationStatus;
use App\Enums\MemberProposalState;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Models\MemberProposalComment;

/**
 * Who may do what with the members' proposals.
 *
 * Two circles and nothing in between. Taking part — reading, submitting,
 * commenting, voting — is for every member (see isParticipant()); passive
 * members are included on purpose, because this is an opinion poll and not a
 * resolution under the statutes. Deciding — state, note, hiding — is for the
 * board alone.
 *
 * Every method that takes a user is typed non-nullable unless a guest must
 * get an answer other than "no": the gate denies a missing user by itself.
 */
class MemberProposalPolicy
{
    /**
     * The proposals name their authors, so they are not a public page.
     */
    public function viewAny(NostrUser $user): bool
    {
        return $this->isParticipant($user) || $this->isBoard($user);
    }

    /**
     * A withdrawn proposal is gone for everybody. A hidden one stays visible
     * to the board, who must be able to unhide it, and to its author, who
     * should learn that it was hidden rather than find it missing.
     */
    public function view(NostrUser $user, MemberProposal $proposal): bool
    {
        if (! $this->viewAny($user) || $proposal->isWithdrawn()) {
            return false;
        }

        return ! $proposal->is_hidden
            || $this->isBoard($user)
            || $proposal->isAuthoredBy($user->getPleb());
    }

    public function create(NostrUser $user): bool
    {
        return $this->isParticipant($user);
    }

    /**
     * The author, while the proposal is open. Not once it is scheduled: what
     * the board has put on the agenda must not change under its hands.
     */
    public function update(NostrUser $user, MemberProposal $proposal): bool
    {
        return $this->isParticipant($user)
            && $proposal->isAuthoredBy($user->getPleb())
            && $proposal->state === MemberProposalState::Open
            && ! $proposal->isWithdrawn();
    }

    /**
     * The author, until the proposal has reached the archive.
     */
    public function withdraw(NostrUser $user, MemberProposal $proposal): bool
    {
        return $this->view($user, $proposal)
            && $proposal->isAuthoredBy($user->getPleb())
            && ! $proposal->isArchived();
    }

    public function vote(NostrUser $user, MemberProposal $proposal): bool
    {
        return $this->isParticipant($user) && $proposal->acceptsParticipation();
    }

    public function comment(NostrUser $user, MemberProposal $proposal): bool
    {
        return $this->isParticipant($user) && $proposal->acceptsParticipation();
    }

    /**
     * Own comments only, and not in the archive — there the discussion is
     * part of the record.
     */
    public function deleteComment(NostrUser $user, MemberProposal $proposal, MemberProposalComment $comment): bool
    {
        return $this->view($user, $proposal)
            && $comment->member_proposal_id === $proposal->id
            && $comment->isAuthoredBy($user->getPleb())
            && ! $proposal->isArchived();
    }

    /**
     * Setting the state, writing the note, hiding and unhiding proposals and
     * comments: the board, and only the board.
     */
    public function moderate(NostrUser $user): bool
    {
        return $this->isBoard($user);
    }

    /**
     * A member of the association: honorary members without a fee, passive
     * and active members with the fee paid for this year or the last one. The
     * previous year counts so that nobody is shut out from 1 January until
     * the new fee is settled.
     */
    private function isParticipant(NostrUser $user): bool
    {
        $pleb = $user->getPleb();

        if (! $pleb instanceof EinundzwanzigPleb || $pleb->isErased()) {
            return false;
        }

        if ($pleb->association_status === AssociationStatus::HONORARY) {
            return true;
        }

        if (! in_array($pleb->association_status, [AssociationStatus::PASSIVE, AssociationStatus::ACTIVE], true)) {
            return false;
        }

        $currentYear = (int) now()->year;

        return $pleb->hasPaidMembership($currentYear)
            || $pleb->hasPaidMembership($currentYear - 1);
    }

    private function isBoard(NostrUser $user): bool
    {
        $pleb = $user->getPleb();

        return $pleb instanceof EinundzwanzigPleb && $pleb->isBoardMember();
    }
}
