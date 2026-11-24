<?php

use App\Livewire\Forms\ProjectProposalForm;
use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Support\NostrAuth;
use App\Support\MarkdownRenderer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('layouts.app')]
#[Title('Projektförderung anlegen')]
class extends Component
{
    use WithFileUploads;

    public ProjectProposalForm $form;

    public $file = null;

    #[Locked]
    public bool $isAllowed = false;

    public function mount(): void
    {
        $nostrUser = NostrAuth::user();

        if ($nostrUser && Gate::forUser($nostrUser)->allows('create', ProjectProposal::class)) {
            $this->isAllowed = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [...$this->form->rulesFor(), 'file' => ProjectProposalForm::IMAGE_RULES];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return $this->form->validationMessages();
    }

    public function removeFile(): void
    {
        if ($this->file) {
            $this->file->delete();
            $this->file = null;
        }
    }

    /**
     * Die Vorschau unter dem Eingabefeld.
     *
     * SERVERSEITIG UND NICHT IM BROWSER, und darin liegt der eigentliche Wert:
     * Sie durchläuft denselben Renderer und denselben Sanitizer wie die
     * Detailseite, zeigt also nicht eine Annäherung an das Ergebnis, sondern
     * das Ergebnis. Ein Editor mit eigener JS-Vorschau (EasyMDE via marked,
     * Toast UI via eigenem Parser) hätte hier einen zweiten, ungehärteten
     * Renderpfad aufgemacht und dem Autor etwas anderes gezeigt, als die
     * Seite später ausgibt — auf genau dem Feld, dessen XSS gerade erst
     * geschlossen wurde.
     */
    #[Computed]
    public function descriptionPreview(): string
    {
        return (new MarkdownRenderer)->toSafeHtml($this->form->description);
    }

    public function save(): void
    {
        Gate::forUser(NostrAuth::user())->authorize('create', ProjectProposal::class);

        $executed = RateLimiter::attempt(
            'project-proposal-create:'.request()->ip(),
            5,
            function () {},
        );

        if (! $executed) {
            abort(429, 'Too many requests.');
        }

        $this->validate();

        $projectProposal = new ProjectProposal;
        $projectProposal->forceFill($this->form->toAttributes());
        // Ein neuer Antrag ist nie vorab angenommen oder ausgezahlt. Beides wird
        // ausschliesslich im Vorstands-Panel der Detailseite gesetzt.
        $projectProposal->accepted = false;
        $projectProposal->sats_paid = 0;
        $projectProposal->einundzwanzig_pleb_id = EinundzwanzigPleb::query()->where('pubkey', NostrAuth::pubkey())->first()->id;
        $projectProposal->save();

        if ($this->file) {
            $projectProposal->addMedia($this->file)->toMediaCollection('main');
        }

        $this->redirectRoute('association.projectSupport');
    }
};
?>
<div class="mx-auto w-full max-w-3xl pb-32">
        @if($isAllowed)
            <div>
                <x-page-header title="Projektförderungs-Antrag anlegen" lead="Fülle das Formular aus, um eine neue Projektförderung anzulegen.">
                    <x-slot:actions>
                        <flux:button :href="route('association.projectSupport')" variant="ghost" icon="arrow-left" class="min-h-11">
                            Zurück
                        </flux:button>
                    </x-slot:actions>
                </x-page-header>

                <div>
                        <section class="rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
                            <flux:fieldset>
                                <flux:legend class="sr-only">Projektdaten</flux:legend>
                                <div class="space-y-4">
                                    <flux:field>
                                        <flux:label>Name</flux:label>
                                        <flux:input wire:model="form.name" placeholder="Projektname" />
                                        <flux:error name="form.name" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Website</flux:label>
                                        <flux:input wire:model="form.website" type="url" placeholder="https://example.com" />
                                        <flux:error name="form.website" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Unterstützung (Sats)</flux:label>
                                        <flux:input wire:model="form.support_in_sats" type="number" placeholder="100000" />
                                        <flux:error name="form.support_in_sats" />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Bild</flux:label>
                                        <flux:file-upload wire:model="file" label="Bild hochladen">
                                            <flux:file-upload.dropzone
                                                heading="Bild hier ablegen oder zum Durchsuchen klicken"
                                                text="JPG, PNG, GIF, WebP bis zu 10MB"
                                            />
                                        </flux:file-upload>
                                        <flux:error name="file" />

                                        @if($file)
                                            <div class="mt-4 flex flex-col gap-2">
                                                <flux:file-item
                                                    :heading="$file->getClientOriginalName()"
                                                    :image="$file->temporaryUrl()"
                                                    :size="$file->getSize()"
                                                >
                                                    <x-slot name="actions">
                                                        <flux:file-item.remove wire:click="removeFile" />
                                                    </x-slot>
                                                </flux:file-item>
                                            </div>
                                        @endif
                                    </flux:field>
                                    <x-markdown-field
                                        model="form.description"
                                        label="Beschreibung"
                                        description="Projektbeschreibung — Markdown ist erlaubt."
                                        :preview="$this->descriptionPreview"
                                    />

                                    <flux:separator />
                                    <x-project-contact-fields :form="$form->all()" />

                                    <flux:button wire:click="save" wire:loading.attr="disabled" variant="primary" class="mt-4 min-h-11 w-full">
                                        Speichern
                                    </flux:button>
                                </div>
                            </flux:fieldset>
                        </section>
                </div>
            </div>

            @else
            <div>
                <flux:callout variant="warning" icon="exclamation-circle">
                    <flux:heading>Projektförderung kann nicht angelegt werden</flux:heading>
                    <p>Um eine Projektförderung anzulegen, benötigst du:</p>
                    <ul class="mt-2 ml-4 list-disc space-y-1">
                        <li>Einen Vereinsstatus von mindestens 2 (Aktives Mitglied)</li>
                        <li>Eine bezahlte Mitgliedschaft für das aktuelle Jahr ({{ now()->year }})</li>
                    </ul>
                    <p class="mt-4">
                        @if(!NostrAuth::check())
                            Bitte melde dich zunächst mit Nostr an.
                        @else
                            Bitte kontaktiere den Vorstand, wenn du denkst, dass du berechtigt sein solltest.
                        @endif
                    </p>
                </flux:callout>
            </div>
        @endif
    </div>
