<?php

use App\Livewire\Traits\WithViewer;
use App\Models\MemberProposal;
use App\Support\MarkdownRenderer;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use WithViewer;


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
            $this->isAllowed = $this->viewerCan('create', MemberProposal::class);

            return;
        }

        // Somebody who may not even read the proposal learns nothing about
        // it here — not that it exists and not that it is somebody else's.
        if (! $this->viewerCan('viewAny', MemberProposal::class)) {
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
            ? $this->authorizeViewer('create', MemberProposal::class)
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

<div class="mx-auto w-full max-w-3xl pb-32">
    @if (! $isAllowed)
        <x-proposal-members-only
            :heading="$proposalId === null ? __('Vorschlag kann nicht eingereicht werden') : __('Dieser Vorschlag kann nicht mehr geändert werden')"
        />
        @if ($proposalId !== null)
            <p class="mx-auto mt-4 w-full max-w-2xl text-base text-text-secondary">
                {{ __('Ändern kann nur, wer den Vorschlag eingereicht hat, und nur solange er offen ist.') }}
            </p>
        @endif
    @else
        <div>
            <flux:button
                :href="$proposalId === null ? route('association.proposals.index') : route('association.proposals.show', $proposalId)"
                variant="ghost" icon="arrow-left" inset="left" class="mb-4 min-h-11" wire:navigate>
                {{ $proposalId === null ? __('Vorschläge') : __('Zurück zum Vorschlag') }}
            </flux:button>

            <x-page-header>
                <x-slot:title>{{ $proposalId === null ? __('Vorschlag einreichen') : __('Vorschlag ändern') }}</x-slot:title>
                <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                    {{ __('Alle Mitglieder können ihn lesen, kommentieren und dafür oder dagegen stimmen. Der Vorstand entscheidet, ob er in eine Sitzung kommt.') }}
                </p>
            </x-page-header>

            <form wire:submit="save" class="space-y-6">
                <flux:field class="[&_input]:min-h-11">
                    <flux:label>{{ __('Titel') }}</flux:label>
                    <flux:description>{{ __('Ein Satz, der sagt, was der Verein tun soll.') }}</flux:description>
                    <flux:input wire:model="form.title" maxlength="255" />
                    <flux:error name="form.title" />
                </flux:field>

                {{-- Writing first, the rendered result right under it (reading width, P3/D6). --}}
                <div class="grid gap-6">
                    <flux:field>
                        <flux:label>{{ __('Beschreibung') }}</flux:label>
                        <flux:description>{{ __('Warum, was genau, und was es kostet, falls du das weißt. Markdown ist erlaubt. Dein Name steht am Vorschlag.') }}</flux:description>
                        <flux:textarea wire:model.live.debounce.500ms="form.description" rows="14" resize="vertical" class="text-base" />
                        <flux:error name="form.description" />
                    </flux:field>

                    <section aria-labelledby="preview-heading" aria-live="polite" class="min-w-0">
                        <h2 id="preview-heading" class="text-sm font-semibold text-text-primary">{{ __('Vorschau') }}</h2>
                        <p class="mt-1 text-sm text-text-secondary">{{ __('So erscheint die Beschreibung auf der Seite des Vorschlags.') }}</p>
                        <div class="mt-2 min-h-32 rounded-lg border border-dashed border-border-default p-4">
                            @if (filled($this->descriptionPreview))
                                <div class="prose max-w-none break-words [&_code]:break-all [&_a]:break-all">
                                    {!! $this->descriptionPreview !!}
                                </div>
                            @else
                                <p class="text-sm text-text-tertiary">{{ __('Noch leer. Sobald du schreibst, erscheint hier das Ergebnis.') }}</p>
                            @endif
                        </div>
                    </section>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-border-default pt-6 sm:flex-row sm:items-center sm:justify-end">
                    <flux:button
                        :href="$proposalId === null ? route('association.proposals.index') : route('association.proposals.show', $proposalId)"
                        variant="ghost" class="min-h-11" wire:navigate>
                        {{ __('Abbrechen') }}
                    </flux:button>
                    <flux:button type="submit" variant="primary" class="min-h-11" wire:loading.attr="disabled">
                        {{ $proposalId === null ? __('Vorschlag einreichen') : __('Änderungen speichern') }}
                    </flux:button>
                </div>
            </form>
        </div>
    @endif
</div>
