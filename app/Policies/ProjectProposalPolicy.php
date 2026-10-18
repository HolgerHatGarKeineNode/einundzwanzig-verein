<?php

namespace App\Policies;

use App\Auth\NostrUser;
use App\Enums\ProjectProposalStatus;
use App\Models\ProjectProposal;

class ProjectProposalPolicy
{
    /**
     * Determine whether the user can view any project proposals.
     */
    public function viewAny(?NostrUser $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the project proposal.
     */
    public function view(?NostrUser $user, ProjectProposal $projectProposal): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create project proposals.
     * Allowed for: board members (always) OR active members with paid membership for the current year.
     */
    public function create(NostrUser $user): bool
    {
        $pleb = $user->getPleb();

        if (! $pleb) {
            return false;
        }

        return $pleb->isBoardMember() || $pleb->hasPaidMembership();
    }

    /**
     * Determine whether the user can update the project proposal.
     * Allowed for: the creator OR board members.
     */
    public function update(NostrUser $user, ProjectProposal $projectProposal): bool
    {
        $pleb = $user->getPleb();

        if (! $pleb) {
            return false;
        }

        return $pleb->id === $projectProposal->einundzwanzig_pleb_id
            || $pleb->isBoardMember();
    }

    /**
     * Determine whether the user can delete the project proposal.
     * Allowed for: the creator OR board members.
     */
    public function delete(NostrUser $user, ProjectProposal $projectProposal): bool
    {
        $pleb = $user->getPleb();

        if (! $pleb) {
            return false;
        }

        return $pleb->id === $projectProposal->einundzwanzig_pleb_id
            || $pleb->isBoardMember();
    }

    /**
     * Determine whether the user can see the submitter's contact preference.
     * Contact details are personal data on an otherwise public page, so they are
     * limited to board members and the submitter.
     */
    public function viewContact(?NostrUser $user, ProjectProposal $projectProposal): bool
    {
        $pleb = $user?->getPleb();

        if (! $pleb) {
            return false;
        }

        return $pleb->id === $projectProposal->einundzwanzig_pleb_id
            || $pleb->isBoardMember();
    }

    /**
     * Determine whether the user can record a payout.
     *
     * Board membership alone is not enough: money may only follow a resolution.
     * The proposal must carry the board's absolute majority in favour and must
     * not already be paid out — otherwise a single board member could pay out a
     * proposal that is still being voted on, or one the board rejected.
     */
    public function payout(NostrUser $user, ProjectProposal $projectProposal): bool
    {
        $pleb = $user->getPleb();

        if (! $pleb || ! $pleb->isBoardMember()) {
            return false;
        }

        return $projectProposal->status() === ProjectProposalStatus::Accepted;
    }

    /**
     * Determine whether the user can create the private Nostr chat room.
     *
     * Only board members. The room is created by signing NIP-29 moderation
     * events in the browser, and the relay only accepts those from a pubkey
     * with relay-wide manage rights — which the board has. Offering the action
     * to anyone else would produce a "restricted" error from the relay rather
     * than a useful message, so the gate belongs here, not just in the view.
     *
     * Deliberately not tied to the proposal's status: a room is a means of
     * talking, and the board may need to talk about a proposal at any stage.
     */
    public function createChatRoom(NostrUser $user, ProjectProposal $projectProposal): bool
    {
        $pleb = $user->getPleb();

        if (! $pleb || ! $pleb->isBoardMember()) {
            return false;
        }

        return ! $projectProposal->hasNostrGroup();
    }

    /**
     * Determine whether the user can clear the stored chat room reference.
     *
     * Only board members. This is the repair for a room that was deleted on the
     * relay (kind 9008): the association keeps its own `nostr_group_h`, and a
     * dead reference otherwise locks the proposal for good — `createChatRoom`
     * refuses while a room is on file, so the board could never get a fresh,
     * correctly tagged room through the interface.
     *
     * A server-side reconciliation with the relay is not available: PHP cannot
     * authenticate via NIP-42 here, so the truth about the room's existence
     * only lives in the browser. Hence a deliberate board action instead of an
     * automatic cleanup.
     *
     * Requires a room to actually be on file — with none there is nothing to
     * reset, and offering the action would only invite a pointless click.
     * Nothing is published to the relay; the room ID is derived from the
     * proposal (see ProjectProposal::nostrGroupId()), so creating it again
     * yields the very same ID.
     */
    public function resetChatRoom(NostrUser $user, ProjectProposal $projectProposal): bool
    {
        $pleb = $user->getPleb();

        if (! $pleb || ! $pleb->isBoardMember()) {
            return false;
        }

        return $projectProposal->hasNostrGroup();
    }

    /**
     * Determine whether the user can see and use the chat room of a proposal.
     *
     * The same circle that may see the submitter's contact details: board and
     * submitter. The relay enforces this independently — a non-member cannot
     * read the room even with the link — but the UI must not offer what the
     * relay will refuse.
     */
    public function viewChatRoom(?NostrUser $user, ProjectProposal $projectProposal): bool
    {
        if (! $projectProposal->hasNostrGroup()) {
            return false;
        }

        return $this->viewContact($user, $projectProposal);
    }

    /**
     * Determine whether the user can take down an uploaded image of the description.
     *
     * Only board members. They can already edit any proposal (see update()); the
     * new power is deleting the file itself, which takes the image down on every
     * page that shows it — the board's duty for what the association hosts.
     */
    public function removeDescriptionImage(NostrUser $user, ProjectProposal $projectProposal): bool
    {
        $pleb = $user->getPleb();

        return $pleb !== null && $pleb->isBoardMember();
    }

    /**
     * Determine whether the user can accept/reject the project proposal.
     * Only board members can change the accepted flag and sats_paid.
     */
    public function accept(NostrUser $user, ProjectProposal $projectProposal): bool
    {
        $pleb = $user->getPleb();

        if (! $pleb) {
            return false;
        }

        return $pleb->isBoardMember();
    }
}
