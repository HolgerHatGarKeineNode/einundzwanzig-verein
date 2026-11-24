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

<div class="mx-auto w-full max-w-5xl pb-32">
    @if (! $this->canView)
        <x-proposal-members-only />
    @else
        @php
            $proposal = $this->proposal;
        @endphp
        <div>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <flux:button
                    :href="$proposal->isArchived() ? route('association.proposals.archive') : route('association.proposals.index')"
                    variant="ghost" icon="arrow-left" inset="left" class="min-h-11" wire:navigate>
                    {{ $proposal->isArchived() ? __('Archiv') : __('Vorschläge') }}
                </flux:button>
                <div class="flex flex-wrap gap-2">
                    @if ($this->canUpdate)
                        <flux:button :href="route('association.proposals.edit', $proposal)" icon="pencil" class="min-h-11" wire:navigate>
                            {{ __('Ändern') }}
                        </flux:button>
                    @endif
                    @if ($this->canWithdraw)
                        <flux:button wire:click="withdraw" icon="arrow-uturn-left" variant="subtle" class="min-h-11"
                                     wire:confirm="{{ __('Vorschlag wirklich zurückziehen? Er ist danach für niemanden mehr sichtbar.') }}">
                            {{ __('Zurückziehen') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            {{-- Reading column on the left; the mood and the board's tools beside it.
                 Narrow screens read top to bottom: proposal, mood, comments. --}}
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-8">
                <article class="min-w-0 space-y-6 lg:col-start-1 lg:row-start-1">
                    <header>
                        <div class="mb-3 flex flex-wrap items-center gap-2 text-sm text-text-secondary">
                            <flux:badge size="sm" :color="$proposal->state->color()" :icon="$proposal->state->icon()">
                                {{ __($proposal->state->label()) }}
                            </flux:badge>
                            @if ($proposal->state_changed_at)
                                <span>{{ __('seit :date', ['date' => $proposal->state_changed_at->format('d.m.Y')]) }}</span>
                            @endif
                            @if ($proposal->is_hidden)
                                <flux:badge size="sm" color="red" icon="eye-slash">{{ __('Ausgeblendet') }}</flux:badge>
                            @endif
                        </div>
                        <h1 class="hyphens-auto break-words text-3xl font-bold leading-tight text-text-primary sm:text-4xl">{{ $proposal->title }}</h1>
                        <p class="mt-2 text-base text-text-secondary break-words">
                            {{ __('Eingereicht von :name am :date', ['name' => $proposal->authorName(), 'date' => $proposal->created_at->format('d.m.Y')]) }}
                        </p>
                    </header>

                    @if ($proposal->is_hidden)
                        <flux:callout variant="warning" icon="eye-slash">
                            <flux:callout.text>
                                {{ __('Der Vorstand hat diesen Vorschlag ausgeblendet. Andere Mitglieder sehen ihn nicht.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    @if (filled($proposal->board_note))
                        <aside class="border-l-4 border-orange-500 bg-bg-surface py-3 pl-4 pr-4" aria-label="{{ __('Notiz des Vorstands') }}">
                            <p class="text-sm font-semibold text-orange-500">{{ __('Notiz des Vorstands') }}</p>
                            <p class="mt-1 text-base text-text-primary break-words whitespace-pre-line">{{ $proposal->board_note }}</p>
                        </aside>
                    @endif

                    <div class="prose max-w-prose break-words [&_code]:break-all [&_a]:break-all" data-test="description">
                        {!! $proposal->safeDescription() !!}
                    </div>
                </article>

                <div class="min-w-0 space-y-6 lg:col-start-2 lg:row-span-2 lg:row-start-1">
                    <section aria-labelledby="mood-heading" class="rounded-xl border border-border-default bg-bg-surface p-5 lg:sticky lg:top-6">
                        <h2 id="mood-heading" class="text-lg font-semibold text-text-primary">{{ __('Stimmungsbild') }}</h2>

                        <x-proposal-mood :up="$proposal->up_votes_count" :down="$proposal->down_votes_count" size="lg" class="mt-4" />

                        @if ($this->canVote)
                            <div class="mt-5 grid grid-cols-2 gap-2">
                                @foreach ([
                                    ['up', __('Dafür'), 'hand-thumb-up'],
                                    ['down', __('Dagegen'), 'hand-thumb-down'],
                                ] as [$value, $label, $icon])
                                    <flux:button wire:click="vote('{{ $value }}')" wire:key="vote-{{ $value }}"
                                                 :icon="$icon" :variant="$this->ownVote === $value ? 'primary' : 'outline'"
                                                 class="min-h-11 w-full"
                                                 aria-pressed="{{ $this->ownVote === $value ? 'true' : 'false' }}">
                                        {{ $label }}
                                    </flux:button>
                                @endforeach
                            </div>
                        @endif

                        @if ($this->ownVote !== null)
                            <p class="mt-3 text-sm text-text-primary">
                                {{ $this->ownVote === 'up' ? __('Du hast dafür gestimmt.') : __('Du hast dagegen gestimmt.') }}
                                @if ($this->canVote)
                                    <span class="text-text-secondary">{{ __('Ein zweiter Klick auf dieselbe Schaltfläche nimmt deine Stimme zurück.') }}</span>
                                @endif
                            </p>
                        @endif

                        @if ($proposal->isArchived())
                            <p class="mt-3 text-sm text-text-primary">{{ __('Dieser Vorschlag liegt im Archiv. Stimmen und Kommentare sind geschlossen.') }}</p>
                        @elseif ($proposal->isWithdrawn())
                            <p class="mt-3 text-sm text-text-primary">{{ __('Dieser Vorschlag wurde zurückgezogen. Stimmen und Kommentare sind geschlossen.') }}</p>
                        @elseif ($proposal->is_hidden)
                            <p class="mt-3 text-sm text-text-primary">{{ __('Dieser Vorschlag ist ausgeblendet. Stimmen und Kommentare sind geschlossen.') }}</p>
                        @elseif (! $this->canVote)
                            <p class="mt-3 text-sm text-text-primary">{{ __('Abstimmen und kommentieren können Ehrenmitglieder sowie Mitglieder, die den Beitrag für dieses oder das vergangene Jahr bezahlt haben.') }}</p>
                        @endif

                        <p class="mt-4 border-t border-border-default pt-4 text-sm text-text-tertiary">
                            {{ __('Angezeigt werden nur die beiden Zahlen, nie Namen. Wer wie gestimmt hat, ist in der Datenbank des Vereins gespeichert, weil du deine Stimme sonst nicht ändern oder zurücknehmen könntest. Das ist anders als bei einer Wahl.') }}
                        </p>
                    </section>

                    @if ($this->canModerate)
                        <section aria-labelledby="board-heading" data-test="board-panel" class="rounded-xl border border-border-default bg-bg-elevated p-5 space-y-4">
                            <div>
                                <h2 id="board-heading" class="text-lg font-semibold text-text-primary">{{ __('Vorstand') }}</h2>
                                <p class="mt-1 text-sm text-text-secondary">{{ __('Nur für den Vorstand sichtbar.') }}</p>
                            </div>
                            <flux:field>
                                <flux:label>{{ __('Notiz') }}</flux:label>
                                <flux:description>{{ __('Zum Beispiel das Datum der Sitzung. Leer speichern löscht die Notiz.') }}</flux:description>
                                <flux:textarea wire:model="boardNotes.{{ $proposal->id }}" rows="2" maxlength="1000"
                                               placeholder="{{ $proposal->board_note }}" />
                                <flux:error name="boardNotes.{{ $proposal->id }}" />
                            </flux:field>
                            <div class="grid grid-cols-2 gap-2">
                                <flux:button wire:click="saveBoardNote({{ $proposal->id }})" class="min-h-11 w-full">{{ __('Notiz speichern') }}</flux:button>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button icon:trailing="chevron-down" class="min-h-11 w-full">{{ __('Zustand ändern') }}</flux:button>
                                    <flux:menu>
                                        @foreach (MemberProposalState::cases() as $state)
                                            @if ($state !== $proposal->state)
                                                <flux:menu.item class="min-h-11" wire:click="setProposalState({{ $proposal->id }}, '{{ $state->value }}')"
                                                                wire:key="state-{{ $state->value }}" :icon="$state->icon()">
                                                    {{ __($state->label()) }}
                                                </flux:menu.item>
                                            @endif
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                            @if ($proposal->is_hidden)
                                <flux:button wire:click="unhideProposal({{ $proposal->id }})" wire:key="unhide-proposal" variant="ghost" icon="eye" class="min-h-11 w-full">{{ __('Für Mitglieder einblenden') }}</flux:button>
                            @else
                                <flux:button wire:click="hideProposal({{ $proposal->id }})" wire:key="hide-proposal" variant="ghost" icon="eye-slash" class="min-h-11 w-full">{{ __('Für Mitglieder ausblenden') }}</flux:button>
                            @endif
                        </section>
                    @endif
                </div>

                <section aria-labelledby="comments-heading" class="min-w-0 lg:col-start-1 lg:row-start-2">
                    <h2 id="comments-heading" class="text-lg font-semibold text-text-primary">
                        {{ __('Kommentare') }}
                        <span class="font-normal text-text-tertiary">{{ $this->comments->count() }}</span>
                    </h2>

                    <div class="mt-4 divide-y divide-border-default border-y border-border-default">
                        @forelse ($this->comments as $comment)
                            <article wire:key="comment-{{ $comment->id }}" class="py-4">
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <span class="min-w-0 break-words font-semibold text-text-primary">{{ $comment->authorName() }}</span>
                                    <time class="text-sm text-text-tertiary" datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->format('d.m.Y H:i') }}</time>
                                    @if ($comment->is_hidden)
                                        <flux:badge size="sm" color="red" icon="eye-slash">{{ __('Ausgeblendet') }}</flux:badge>
                                    @endif
                                </div>

                                {{-- A hidden comment keeps its place in the thread, but
                                     its text only reaches the board's browser. --}}
                                @if ($comment->is_hidden && ! $this->canModerate)
                                    <p class="mt-2 text-sm italic text-text-secondary">{{ __('Dieser Kommentar wurde vom Vorstand ausgeblendet.') }}</p>
                                @else
                                    <div @class([
                                        'prose mt-2 max-w-prose break-words [&_code]:break-all [&_a]:break-all',
                                        'opacity-60' => $comment->is_hidden,
                                    ])>
                                        {!! $comment->safeBody() !!}
                                    </div>
                                @endif

                                @php
                                    $canDeleteComment = Gate::forUser(\App\Support\NostrAuth::user())->allows('deleteComment', [$proposal, $comment]);
                                @endphp
                                @if ($canDeleteComment || $this->canModerate)
                                    <div class="mt-2 -ml-3 flex flex-wrap gap-1">
                                        @if ($canDeleteComment)
                                            <flux:button wire:click="deleteComment({{ $comment->id }})" wire:key="delete-comment-{{ $comment->id }}"
                                                         size="sm" variant="ghost" icon="trash" class="min-h-11"
                                                         wire:confirm="{{ __('Kommentar wirklich löschen?') }}">
                                                {{ __('Löschen') }}
                                            </flux:button>
                                        @endif
                                        @if ($this->canModerate)
                                            @if ($comment->is_hidden)
                                                <flux:button wire:click="unhideComment({{ $comment->id }})" wire:key="unhide-comment-{{ $comment->id }}" size="sm" variant="ghost" icon="eye" class="min-h-11">{{ __('Einblenden') }}</flux:button>
                                            @else
                                                <flux:button wire:click="hideComment({{ $comment->id }})" wire:key="hide-comment-{{ $comment->id }}" size="sm" variant="ghost" icon="eye-slash" class="min-h-11">{{ __('Ausblenden') }}</flux:button>
                                            @endif
                                        @endif
                                    </div>
                                @endif
                            </article>
                        @empty
                            <p class="py-4 text-base text-text-secondary">
                                {{ $this->canComment ? __('Noch keine Kommentare. Schreib den ersten.') : __('Noch keine Kommentare.') }}
                            </p>
                        @endforelse
                    </div>

                    @if ($this->canComment)
                        <form wire:submit="addComment" class="mt-6 space-y-3">
                            <flux:field>
                                <flux:label>{{ __('Kommentar') }}</flux:label>
                                <flux:description>{{ __('Markdown ist erlaubt. Dein Name steht am Kommentar.') }}</flux:description>
                                <flux:textarea wire:model="commentBody" rows="4" maxlength="5000" />
                                <flux:error name="commentBody" />
                            </flux:field>
                            <flux:button type="submit" variant="primary" class="min-h-11" wire:loading.attr="disabled">{{ __('Kommentieren') }}</flux:button>
                        </form>
                    @endif
                </section>
            </div>
        </div>
    @endif
</div>
