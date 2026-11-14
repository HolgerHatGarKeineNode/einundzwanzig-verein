<?php

use App\Livewire\Traits\WithMemberProposalAccess;
use App\Models\MemberProposal;
use App\Support\MarkdownRenderer;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use WithMemberProposalAccess;

    /**
     * The proposal being edited, or null while submitting a new one. Locked:
     * which row save() writes to is not the client's to choose.
     */
    #[Locked]
    public ?int $proposalId = null;

    #[Locked]
    public bool $isAllowed = false;

    /** @var array{title: string, description: string} */
    public array $form = [
        'title' => '',
        'description' => '',
    ];

    public function mount(?MemberProposal $memberProposal = null): void
    {
        if ($memberProposal === null || ! $memberProposal->exists) {
            $this->isAllowed = $this->viewerCan('create');

            return;
        }

        // Somebody who may not even read the proposal learns nothing about
        // it here — not that it exists and not that it is somebody else's.
        if (! $this->viewerCan('viewAny')) {
            return;
        }

        abort_unless($this->viewerCan('view', $memberProposal), 404);

        $this->proposalId = $memberProposal->id;
        $this->isAllowed = $this->viewerCan('update', $memberProposal);

        if ($this->isAllowed) {
            $this->form = [
                'title' => $memberProposal->title,
                'description' => $memberProposal->description,
            ];
        }
    }

    /**
     * The preview under the field — the same renderer and the same sanitizer
     * as the detail page, so it is the result and not an approximation.
     */
    #[Computed]
    public function descriptionPreview(): string
    {
        return (new MarkdownRenderer)->toSafeHtml($this->form['description'] ?? null);
    }

    public function save(): void
    {
        /*
         * Read again and authorised again, on every call: the page may have
         * been open while the board scheduled or archived the proposal, and
         * the rule "editable while open" is about now, not about page load.
         */
        $proposal = $this->proposalId === null
            ? null
            : MemberProposal::query()->findOrFail($this->proposalId);

        $proposal === null
            ? $this->authorizeViewer('create')
            : $this->authorizeViewer('update', $proposal);

        if (! RateLimiter::attempt('member-proposal-save:'.$this->viewer()->id, 10, function () {})) {
            abort(429, 'Too many requests.');
        }

        $this->validate([
            'form.title' => 'required|string|max:255',
            'form.description' => 'required|string|max:20000',
        ], attributes: [
            'form.title' => __('Titel'),
            'form.description' => __('Beschreibung'),
        ]);

        if ($proposal === null) {
            $proposal = new MemberProposal;
            $proposal->einundzwanzig_pleb_id = $this->viewer()->id;
        }

        $proposal->title = trim($this->form['title']);
        $proposal->description = $this->form['description'];
        $proposal->last_activity_at = now();
        $proposal->save();

        $this->redirectRoute('association.proposals.show', $proposal);
    }
};
?>

<div>
    @if (! $isAllowed)
        <x-proposal-members-only
            :heading="$proposalId === null ? __('Vorschlag kann nicht eingereicht werden') : __('Dieser Vorschlag kann nicht mehr geändert werden')"
        />
        @if ($proposalId !== null)
            <p class="mt-4 text-sm text-text-secondary">
                {{ __('Ändern kann nur, wer den Vorschlag eingereicht hat, und nur solange er offen ist.') }}
            </p>
        @endif
    @else
        <div class="w-full max-w-3xl mx-auto">
            <div class="mb-5 flex items-center justify-between gap-4">
                <h1 class="min-w-0 break-words text-2xl font-bold text-text-primary">
                    {{ $proposalId === null ? __('Vorschlag einreichen') : __('Vorschlag ändern') }}
                </h1>
                <flux:button
                    :href="$proposalId === null ? route('association.proposals.index') : route('association.proposals.show', $proposalId)"
                    variant="ghost" icon="arrow-left" class="shrink-0" wire:navigate>
                    {{ __('Zurück') }}
                </flux:button>
            </div>

            <flux:card>
                <form wire:submit="save" class="space-y-4">
                    <flux:field>
                        <flux:label>{{ __('Titel') }}</flux:label>
                        <flux:input wire:model="form.title" maxlength="255" />
                        <flux:error name="form.title" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Beschreibung') }}</flux:label>
                        <flux:description>{{ __('Markdown ist erlaubt. Dein Name steht am Vorschlag.') }}</flux:description>
                        <flux:textarea wire:model.live.debounce.500ms="form.description" rows="12" resize="vertical" class="font-mono text-sm" />
                        <flux:error name="form.description" />
                    </flux:field>

                    @if (filled($this->descriptionPreview))
                        <flux:callout>
                            <flux:callout.heading>{{ __('Vorschau') }}</flux:callout.heading>
                            <flux:callout.text>
                                <div class="prose max-w-none break-words [&_code]:break-all [&_a]:break-all">
                                    {!! $this->descriptionPreview !!}
                                </div>
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                        {{ __('Speichern') }}
                    </flux:button>
                </form>
            </flux:card>
        </div>
    @endif
</div>
