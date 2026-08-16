<?php

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
#[Title('Projektförderung bearbeiten')]
class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ProjectProposal $project;

    public array $form = [
        'name' => '',
        'description' => '',
        'support_in_sats' => '',
        'website' => '',
        'contact_via_nostr_dm' => true,
        'contact_alternative' => '',
    ];

    public $file = null;

    #[Locked]
    public bool $isAllowed = false;

    public function mount(ProjectProposal $projectProposal): void
    {
        $this->project = $projectProposal;

        $nostrUser = NostrAuth::user();

        if ($nostrUser && Gate::forUser($nostrUser)->allows('update', $this->project)) {
            $this->isAllowed = true;
            $this->form = [
                'name' => $this->project->name,
                'description' => $this->project->description,
                'support_in_sats' => (string) $this->project->support_in_sats,
                'website' => $this->project->website ?? '',
                'contact_via_nostr_dm' => (bool) $this->project->contact_via_nostr_dm,
                'contact_alternative' => $this->project->contact_alternative ?? '',
            ];
        }
    }

    public function deleteMainImage(): void
    {
        Gate::forUser(NostrAuth::user())->authorize('update', $this->project);

        if ($this->project->getFirstMedia('main')) {
            $this->project->getFirstMedia('main')->delete();
        }
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
        return (new MarkdownRenderer)->toSafeHtml($this->form['description'] ?? null);
    }

    public function update(): void
    {
        Gate::forUser(NostrAuth::user())->authorize('update', $this->project);

        $executed = RateLimiter::attempt(
            'project-proposal-update:'.request()->ip(),
            5,
            function () {},
        );

        if (! $executed) {
            abort(429, 'Too many requests.');
        }

        $this->validate([
            'form.name' => 'required|string|max:255',
            'form.description' => 'required|string',
            'form.support_in_sats' => 'required|integer|min:0',
            'form.website' => 'required|url|max:255',
            'form.contact_via_nostr_dm' => 'boolean',
            'form.contact_alternative' => 'nullable|string|max:255',
            'file' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp|mimetypes:image/jpeg,image/png,image/gif,image/webp|max:10240',
        ]);

        $this->project->update([
            'name' => $this->form['name'],
            'description' => $this->form['description'],
            'support_in_sats' => (int) $this->form['support_in_sats'],
            'website' => $this->form['website'],
            'contact_via_nostr_dm' => (bool) ($this->form['contact_via_nostr_dm'] ?? true),
            'contact_alternative' => filled($this->form['contact_alternative'] ?? null)
                ? trim($this->form['contact_alternative'])
                : null,
        ]);

        if ($this->file) {
            $this->project->addMedia($this->file)->toMediaCollection('main');
        }

        $this->redirectRoute('association.projectSupport.item', $this->project);
    }
};
?>

<div>
    @if($isAllowed)
        <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
            <div
                class="flex flex-col md:flex-row items-center mb-6 space-y-4 md:space-y-0 md:space-x-4">
                <div class="flex items-center justify-between w-full">
                    <h1 class="text-2xl md:text-3xl text-zinc-800 dark:text-zinc-100 font-bold">
                        Projektförderungs-Antrag bearbeiten
                    </h1>
                    <flux:button :href="route('association.projectSupport')" variant="ghost" icon="arrow-left">
                        Zurück
                    </flux:button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left column - Form -->
                <div class="lg:col-span-2">
                    <flux:card>
                        <flux:fieldset>
                            <flux:legend>Formular</flux:legend>
                            <div class="space-y-4">
                                <div>
                                    <flux:field>
                                        <flux:label>Name</flux:label>
                                        <flux:input wire:model="form.name" placeholder="Projektname" />
                                        <flux:error name="form.name" />
                                    </flux:field>
                                </div>
                                <div>
                                    <flux:field>
                                        <flux:label>Website</flux:label>
                                        <flux:input wire:model="form.website" type="url" placeholder="https://example.com" />
                                        <flux:error name="form.website" />
                                    </flux:field>
                                </div>
                                <div>
                                    <flux:field>
                                        <flux:label>Unterstützung (Sats)</flux:label>
                                        <flux:input wire:model="form.support_in_sats" type="number" placeholder="100000" />
                                        <flux:error name="form.support_in_sats" />
                                    </flux:field>
                                </div>
                                <div>
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

                                    @if($project->getFirstMedia('main'))
                                        <div class="mt-4">
                                            <flux:file-item
                                                :heading="$project->getFirstMedia('main')->file_name"
                                                :image="$project->getSignedMediaUrl('main')"
                                                :size="$project->getFirstMedia('main')->size"
                                            >
                                                <x-slot name="actions">
                                                    <flux:file-item.remove wire:click="deleteMainImage" />
                                                </x-slot>
                                            </flux:file-item>
                                        </div>
                                    @endif
                                </div>
                                <div>
                                    <x-markdown-field
                                        model="form.description"
                                        label="Beschreibung"
                                        description="Projektbeschreibung — Markdown ist erlaubt."
                                        :preview="$this->descriptionPreview"
                                    />
                                </div>

                                <flux:separator />
                                <x-project-contact-fields :form="$form" />

                                <flux:button wire:click="update" wire:loading.attr="disabled" variant="primary" class="w-full mt-4">
                                    Speichern
                                </flux:button>
                            </div>
                        </flux:fieldset>
                    </flux:card>
                </div>

                <!-- Right column - Information -->
                <div>
                    <flux:card>
                        <flux:heading level="2">Information</flux:heading>
                        <p class="text-sm text-zinc-800 dark:text-zinc-100 mt-4">
                            Bearbeite die Projektförderung und speichere deine Änderungen.
                        </p>
                    </flux:card>
                </div>
            </div>
        </div>

    @else
        <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:heading>Projektförderung kann nicht bearbeitet werden</flux:heading>
                <p>Um diese Projektförderung zu bearbeiten, musst du entweder:</p>
                <ul class="list-disc ml-5 mt-2 space-y-1">
                    <li>Der Ersteller dieser Projektförderung sein</li>
                    <li>Ein Mitglied des aktuellen Vorstands sein</li>
                </ul>
                <p class="mt-3">
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
