<?php

namespace App\Livewire\Traits;

use App\Enums\MemberProposalState;
use App\Models\MemberProposal;
use App\Models\MemberProposalComment;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Gate;

/**
 * The board's actions on member proposals — every public method here is one.
 *
 * Kept in one trait so that "all board actions" is a set a test can
 * enumerate by reflection rather than a list somebody has to keep up to date.
 * Livewire exposes every public method as a directly callable endpoint, so
 * each one authorises itself, on every call: the board list is read from the
 * configuration at that moment, not remembered from when the page loaded.
 */
trait ModeratesMemberProposals
{
    /**
     * Notes being typed, keyed by proposal id. Client writable like every
     * public property; it only ever reaches the database through
     * saveBoardNote() and setProposalState(), which authorise first.
     *
     * @var array<int|string, string|null>
     */
    public array $boardNotes = [];

    /**
     * Set the state. A note typed alongside is saved with it; an empty field
     * leaves the stored note alone, so a state change from a page that was
     * loaded a while ago cannot wipe a note a colleague wrote since.
     */
    public function setProposalState(int $proposalId, string $state): void
    {
        $this->authorizeModeration();

        $newState = MemberProposalState::tryFrom($state) ?? abort(422);
        $proposal = $this->moderatedProposal($proposalId);
        $note = $this->validatedBoardNote($proposalId);

        if ($note !== null) {
            $proposal->board_note = $note;
        }

        if ($proposal->state !== $newState) {
            $proposal->state = $newState;
            $proposal->state_changed_at = now();
            $proposal->last_activity_at = now();
        }

        $proposal->save();

        unset($this->boardNotes[$proposalId]);
        $this->memberProposalsChanged();
    }

    /**
     * Save the note as typed. An empty field removes it — here that is the
     * explicit request, unlike in setProposalState().
     */
    public function saveBoardNote(int $proposalId): void
    {
        $this->authorizeModeration();

        $proposal = $this->moderatedProposal($proposalId);
        $proposal->board_note = $this->validatedBoardNote($proposalId);
        $proposal->save();

        unset($this->boardNotes[$proposalId]);
        $this->memberProposalsChanged();
    }

    public function hideProposal(int $proposalId): void
    {
        $this->setProposalHidden($proposalId, true);
    }

    public function unhideProposal(int $proposalId): void
    {
        $this->setProposalHidden($proposalId, false);
    }

    public function hideComment(int $commentId): void
    {
        $this->setCommentHidden($commentId, true);
    }

    public function unhideComment(int $commentId): void
    {
        $this->setCommentHidden($commentId, false);
    }

    /**
     * Hook for the using component: drop whatever it has computed.
     */
    protected function memberProposalsChanged(): void {}

    private function authorizeModeration(): void
    {
        Gate::forUser(NostrAuth::user())->authorize('moderate', MemberProposal::class);
    }

    private function moderatedProposal(int $proposalId): MemberProposal
    {
        return MemberProposal::query()->notWithdrawn()->findOrFail($proposalId);
    }

    private function validatedBoardNote(int $proposalId): ?string
    {
        $this->validate(
            ['boardNotes.'.$proposalId => 'nullable|string|max:1000'],
            attributes: ['boardNotes.'.$proposalId => __('Notiz')],
        );

        $note = trim((string) ($this->boardNotes[$proposalId] ?? ''));

        return $note === '' ? null : $note;
    }

    private function setProposalHidden(int $proposalId, bool $hidden): void
    {
        $this->authorizeModeration();

        $proposal = $this->moderatedProposal($proposalId);
        $proposal->is_hidden = $hidden;
        $proposal->save();

        $this->memberProposalsChanged();
    }

    private function setCommentHidden(int $commentId, bool $hidden): void
    {
        $this->authorizeModeration();

        $comment = MemberProposalComment::query()->findOrFail($commentId);
        $comment->is_hidden = $hidden;
        $comment->save();

        $this->memberProposalsChanged();
    }
}
