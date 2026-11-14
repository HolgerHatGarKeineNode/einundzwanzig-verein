<?php

use App\Enums\MemberProposalState;
use App\Livewire\Traits\ModeratesMemberProposals;
use App\Livewire\Traits\WithMemberProposalAccess;
use App\Models\MemberProposal;
use App\Models\MemberProposalComment;
use App\Models\MemberProposalVote;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use ModeratesMemberProposals;
    use WithMemberProposalAccess;

    /**
     * Only the id is kept on the component. The proposal, the two vote
     * counts and the viewer's own vote are read per request — no vote row
     * and no voter ever becomes component state, so none can reach the
     * Livewire snapshot.
     */
    #[Locked]
    public int $proposalId;

    public string $commentBody = '';

    public function mount(MemberProposal $memberProposal): void
    {
        $this->proposalId = $memberProposal->id;

        // A viewer outside the membership gets the "members only" notice for
        // every id alike; whether this one exists is not theirs to learn.
        if (! $this->viewerCan('viewAny')) {
            return;
        }

        abort_unless($this->viewerCan('view', $memberProposal), 404);
    }

    #[Computed]
    public function canView(): bool
    {
        return $this->viewerCan('viewAny');
    }

    #[Computed]
    public function proposal(): MemberProposal
    {
        $proposal = MemberProposal::query()
            ->withVoteCounts()
            ->with('einundzwanzigPleb.profile')
            ->findOrFail($this->proposalId);

        $this->authorizeViewer('view', $proposal);

        return $proposal;
    }

    /**
     * @return Collection<int, MemberProposalComment>
     */
    #[Computed]
    public function comments(): Collection
    {
        return $this->proposal->comments()
            ->with('einundzwanzigPleb.profile')
            ->oldest()
            ->orderBy('id')
            ->get();
    }

    /**
     * The viewer's own vote — the only vote this page ever attributes.
     */
    #[Computed]
    public function ownVote(): ?string
    {
        return $this->proposal->voteOf($this->viewer());
    }

    #[Computed]
    public function canVote(): bool
    {
        return $this->viewerCan('vote', $this->proposal);
    }

    #[Computed]
    public function canComment(): bool
    {
        return $this->viewerCan('comment', $this->proposal);
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return $this->viewerCan('update', $this->proposal);
    }

    #[Computed]
    public function canWithdraw(): bool
    {
        return $this->viewerCan('withdraw', $this->proposal);
    }

    #[Computed]
    public function canModerate(): bool
    {
        return $this->viewerCan('moderate');
    }

    public function vote(string $value): void
    {
        $proposal = $this->freshProposal();

        $this->authorizeViewer('vote', $proposal);

        abort_unless(in_array($value, MemberProposalVote::VALUES, true), 422);

        if (! RateLimiter::attempt('member-proposal-vote:'.$this->viewer()->id, 30, function () {})) {
            abort(429, 'Too many voting attempts.');
        }

        $proposal->castVote($this->viewer(), $value);

        $this->memberProposalsChanged();
    }

    public function addComment(): void
    {
        $proposal = $this->freshProposal();

        $this->authorizeViewer('comment', $proposal);

        if (! RateLimiter::attempt('member-proposal-comment:'.$this->viewer()->id, 10, function () {})) {
            abort(429, 'Too many requests.');
        }

        $this->validate(
            ['commentBody' => 'required|string|max:5000'],
            attributes: ['commentBody' => __('Kommentar')],
        );

        $comment = new MemberProposalComment;
        $comment->member_proposal_id = $proposal->id;
        $comment->einundzwanzig_pleb_id = $this->viewer()->id;
        $comment->body = trim($this->commentBody);
        $comment->save();

        $proposal->last_activity_at = now();
        $proposal->save();

        $this->commentBody = '';
        $this->memberProposalsChanged();
    }

    public function deleteComment(int $commentId): void
    {
        $proposal = $this->freshProposal();

        $this->authorizeViewer('view', $proposal);

        $comment = $proposal->comments()->findOrFail($commentId);

        $this->authorizeViewer('deleteComment', [$proposal, $comment]);

        $comment->delete();

        $this->memberProposalsChanged();
    }

    public function withdraw(): void
    {
        $proposal = $this->freshProposal();

        $this->authorizeViewer('withdraw', $proposal);

        $proposal->withdrawn_at = now();
        $proposal->save();

        $this->redirectRoute('association.proposals.index');
    }

    /**
     * The proposal as it is now — every action decides on the current row,
     * not on what the page showed when it was loaded.
     */
    private function freshProposal(): MemberProposal
    {
        return MemberProposal::query()->findOrFail($this->proposalId);
    }

    protected function memberProposalsChanged(): void
    {
        unset(
            $this->proposal,
            $this->comments,
            $this->ownVote,
            $this->canVote,
            $this->canComment,
            $this->canUpdate,
            $this->canWithdraw,
        );
    }
};
?>

<div>
    @if (! $this->canView)
        <x-proposal-members-only />
    @else
        @php($proposal = $this->proposal)
        <div class="w-full max-w-3xl mx-auto space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:button
                    :href="$proposal->isArchived() ? route('association.proposals.archive') : route('association.proposals.index')"
                    variant="ghost" icon="arrow-left" wire:navigate>
                    {{ $proposal->isArchived() ? __('Archiv') : __('Vorschläge') }}
                </flux:button>
                <div class="flex flex-wrap gap-2">
                    @if ($this->canUpdate)
                        <flux:button :href="route('association.proposals.edit', $proposal)" icon="pencil" wire:navigate>
                            {{ __('Ändern') }}
                        </flux:button>
                    @endif
                    @if ($this->canWithdraw)
                        <flux:button wire:click="withdraw" icon="arrow-uturn-left" variant="danger"
                                     wire:confirm="{{ __('Vorschlag wirklich zurückziehen? Er ist danach für niemanden mehr sichtbar.') }}">
                            {{ __('Zurückziehen') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            <flux:card class="space-y-4">
                <div>
                    <h1 class="text-2xl font-bold text-text-primary break-words">{{ $proposal->title }}</h1>
                    <p class="mt-1 text-sm text-text-secondary break-words">
                        {{ __('von :name', ['name' => $proposal->authorName()]) }}
                        · {{ $proposal->created_at->format('d.m.Y') }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="$proposal->state->color()" :icon="$proposal->state->icon()">
                            {{ __($proposal->state->label()) }}
                        </flux:badge>
                        @if ($proposal->state_changed_at)
                            <span class="text-xs text-text-secondary">
                                {{ __('seit :date', ['date' => $proposal->state_changed_at->format('d.m.Y')]) }}
                            </span>
                        @endif
                        @if ($proposal->is_hidden)
                            <flux:badge size="sm" color="red" icon="eye-slash">{{ __('Ausgeblendet') }}</flux:badge>
                        @endif
                    </div>
                </div>

                @if ($proposal->is_hidden)
                    <flux:callout variant="warning" icon="eye-slash">
                        <flux:callout.text>
                            {{ __('Der Vorstand hat diesen Vorschlag ausgeblendet. Andere Mitglieder sehen ihn nicht.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif

                @if (filled($proposal->board_note))
                    <flux:callout icon="chat-bubble-bottom-center-text">
                        <flux:callout.heading>{{ __('Notiz des Vorstands') }}</flux:callout.heading>
                        <flux:callout.text><span class="break-words whitespace-pre-line">{{ $proposal->board_note }}</span></flux:callout.text>
                    </flux:callout>
                @endif

                <div class="prose max-w-none break-words [&_code]:break-all [&_a]:break-all" data-test="description">
                    {!! $proposal->safeDescription() !!}
                </div>
            </flux:card>

            <flux:card class="space-y-3">
                <flux:heading level="2">{{ __('Stimmungsbild') }}</flux:heading>
                <div class="flex flex-wrap items-center gap-3">
                    @foreach ([
                        ['up', __('Dafür'), 'hand-thumb-up', $proposal->up_votes_count],
                        ['down', __('Dagegen'), 'hand-thumb-down', $proposal->down_votes_count],
                    ] as [$value, $label, $icon, $count])
                        @if ($this->canVote)
                            <flux:button wire:click="vote('{{ $value }}')" wire:key="vote-{{ $value }}"
                                         :icon="$icon" :variant="$this->ownVote === $value ? 'primary' : 'outline'"
                                         aria-pressed="{{ $this->ownVote === $value ? 'true' : 'false' }}">
                                {{ $label }} <span data-test="{{ $value }}-count">{{ $count }}</span>
                            </flux:button>
                        @else
                            <span class="inline-flex items-center gap-2 text-sm text-text-secondary">
                                <flux:icon :name="$icon" variant="micro" aria-hidden="true" />
                                {{ $label }} <span data-test="{{ $value }}-count">{{ $count }}</span>
                            </span>
                        @endif
                    @endforeach
                </div>
                @if ($this->ownVote !== null)
                    <p class="text-sm text-text-secondary">
                        {{ $this->ownVote === 'up' ? __('Du hast dafür gestimmt.') : __('Du hast dagegen gestimmt.') }}
                        @if ($this->canVote)
                            {{ __('Ein zweiter Klick auf dieselbe Schaltfläche nimmt deine Stimme zurück.') }}
                        @endif
                    </p>
                @endif
                <p class="text-sm text-text-secondary">
                    @if ($proposal->isArchived())
                        {{ __('Dieser Vorschlag liegt im Archiv. Stimmen und Kommentare sind geschlossen.') }}
                    @elseif (! $this->canVote)
                        {{ __('Abstimmen und kommentieren können Ehrenmitglieder sowie Mitglieder, die den Beitrag für dieses oder das vergangene Jahr bezahlt haben.') }}
                    @endif
                    {{ __('Angezeigt werden nur die beiden Zahlen, nie Namen. Wer wie gestimmt hat, ist in der Datenbank des Vereins gespeichert, weil du deine Stimme sonst nicht ändern oder zurücknehmen könntest. Das ist anders als bei einer Wahl.') }}
                </p>
            </flux:card>

            @if ($this->canModerate)
                <flux:card class="space-y-3" data-test="board-panel">
                    <flux:heading level="2">{{ __('Vorstand') }}</flux:heading>
                    <flux:field>
                        <flux:label>{{ __('Notiz') }}</flux:label>
                        <flux:description>{{ __('Zum Beispiel das Datum der Sitzung. Leer speichern löscht die Notiz.') }}</flux:description>
                        <flux:textarea wire:model="boardNotes.{{ $proposal->id }}" rows="2" maxlength="1000"
                                       placeholder="{{ $proposal->board_note }}" />
                        <flux:error name="boardNotes.{{ $proposal->id }}" />
                    </flux:field>
                    <div class="flex flex-wrap gap-2">
                        <flux:button wire:click="saveBoardNote({{ $proposal->id }})" size="sm">{{ __('Notiz speichern') }}</flux:button>
                        @foreach (MemberProposalState::cases() as $state)
                            @if ($state !== $proposal->state)
                                <flux:button wire:click="setProposalState({{ $proposal->id }}, '{{ $state->value }}')"
                                             wire:key="state-{{ $state->value }}" size="sm" :icon="$state->icon()">
                                    {{ __($state->label()) }}
                                </flux:button>
                            @endif
                        @endforeach
                        @if ($proposal->is_hidden)
                            <flux:button wire:click="unhideProposal({{ $proposal->id }})" wire:key="unhide-proposal" size="sm" icon="eye">{{ __('Einblenden') }}</flux:button>
                        @else
                            <flux:button wire:click="hideProposal({{ $proposal->id }})" wire:key="hide-proposal" size="sm" icon="eye-slash">{{ __('Ausblenden') }}</flux:button>
                        @endif
                    </div>
                </flux:card>
            @endif

            <flux:card class="space-y-4">
                <flux:heading level="2">{{ __('Kommentare') }}</flux:heading>

                @forelse ($this->comments as $comment)
                    <article wire:key="comment-{{ $comment->id }}" class="border-t border-border-subtle pt-4 first:border-t-0 first:pt-0">
                        <p class="text-sm text-text-secondary break-words">
                            {{ $comment->authorName() }} · {{ $comment->created_at->format('d.m.Y H:i') }}
                            @if ($comment->is_hidden)
                                <flux:badge size="sm" color="red" icon="eye-slash">{{ __('Ausgeblendet') }}</flux:badge>
                            @endif
                        </p>

                        {{-- A hidden comment keeps its place in the thread, but
                             its text only reaches the board's browser. --}}
                        @if ($comment->is_hidden && ! $this->canModerate)
                            <p class="mt-1 text-sm italic text-text-secondary">{{ __('Dieser Kommentar wurde vom Vorstand ausgeblendet.') }}</p>
                        @else
                            <div class="prose prose-sm mt-1 max-w-none break-words [&_code]:break-all [&_a]:break-all">
                                {!! $comment->safeBody() !!}
                            </div>
                        @endif

                        <div class="mt-2 flex flex-wrap gap-2">
                            @if (Gate::forUser(\App\Support\NostrAuth::user())->allows('deleteComment', [$proposal, $comment]))
                                <flux:button wire:click="deleteComment({{ $comment->id }})" wire:key="delete-comment-{{ $comment->id }}"
                                             size="xs" variant="ghost" icon="trash"
                                             wire:confirm="{{ __('Kommentar wirklich löschen?') }}">
                                    {{ __('Löschen') }}
                                </flux:button>
                            @endif
                            @if ($this->canModerate)
                                @if ($comment->is_hidden)
                                    <flux:button wire:click="unhideComment({{ $comment->id }})" wire:key="unhide-comment-{{ $comment->id }}" size="xs" variant="ghost" icon="eye">{{ __('Einblenden') }}</flux:button>
                                @else
                                    <flux:button wire:click="hideComment({{ $comment->id }})" wire:key="hide-comment-{{ $comment->id }}" size="xs" variant="ghost" icon="eye-slash">{{ __('Ausblenden') }}</flux:button>
                                @endif
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-text-secondary">{{ __('Noch keine Kommentare.') }}</p>
                @endforelse

                @if ($this->canComment)
                    <form wire:submit="addComment" class="space-y-3 border-t border-border-subtle pt-4">
                        <flux:field>
                            <flux:label>{{ __('Kommentar') }}</flux:label>
                            <flux:description>{{ __('Markdown ist erlaubt. Dein Name steht am Kommentar.') }}</flux:description>
                            <flux:textarea wire:model="commentBody" rows="4" maxlength="5000" />
                            <flux:error name="commentBody" />
                        </flux:field>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Kommentieren') }}</flux:button>
                    </form>
                @endif
            </flux:card>
        </div>
    @endif
</div>
