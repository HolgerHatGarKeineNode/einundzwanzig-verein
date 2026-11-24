<?php

use App\Enums\NewsCategory;
use App\Livewire\Traits\GuardsActingPleb;
use App\Models\Notification;
use App\Support\NostrAuth;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('layouts.app')]
#[Title('News')]
class extends Component
{
    use GuardsActingPleb;
    use WithFileUploads;

    #[Url(as: 'kategorie')]
    public ?int $selectedCategory = null;

    public array $form = [
        'category' => '',
        'name' => '',
        'description' => '',
    ];

    public $file;

    #[Locked]
    public bool $isAllowed = false;

    #[Locked]
    public bool $canEdit = false;

    #[Locked]
    public ?\App\Models\EinundzwanzigPleb $currentPleb = null;

    #[Locked]
    public ?int $confirmDeleteId = null;

    public function mount(): void
    {
        $this->currentPleb = NostrAuth::user()?->getPleb();

        if (! $this->currentPleb) {
            return;
        }

        if ($this->currentPleb->isBoardMember()) {
            $this->isAllowed = true;
            $this->canEdit = true;
        } elseif ($this->currentPleb->hasPaidMembership()) {
            $this->isAllowed = true;
        }
    }

    /**
     * Every post, newest first, with its author and its PDF loaded up front:
     * computed, so the posts stay out of the snapshot, and no card fetches
     * its media on its own.
     *
     * @return Collection<int, Notification>
     */
    #[Computed]
    public function news(): Collection
    {
        if (! $this->isAllowed) {
            return collect();
        }

        return Notification::query()
            ->with(['einundzwanzigPleb.profile', 'media'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function filteredNews(): Collection
    {
        if ($this->selectedCategory === null) {
            return $this->news;
        }

        return $this->news->filter(
            fn ($item) => $item->category->value === $this->selectedCategory
        );
    }

    public function filterByCategory(?int $category): void
    {
        $this->selectedCategory = $this->selectedCategory === $category ? null : $category;
    }

    public function clearFilter(): void
    {
        $this->selectedCategory = null;
    }

    public function save(): void
    {
        $this->ensureCanEdit();
        $author = $this->actingPleb();

        $this->validate([
            'file' => 'required|file|mimes:pdf',
            'form.category' => 'required|string|in:'.implode(',', NewsCategory::values()),
            'form.name' => 'required|string|max:255',
            'form.description' => 'nullable|string',
        ]);

        $news = new Notification;
        $news->name = $this->form['name'];
        $news->description = $this->form['description'] ?? null;
        $news->category = $this->form['category'];
        $news->einundzwanzig_pleb_id = $author->id;
        $news->save();

        if ($this->file) {
            $news
                ->addMedia($this->file)
                ->toMediaCollection('pdf');
        }

        $this->reset(['form', 'file']);
        unset($this->news);
    }

    public function confirmDelete(int $id): void
    {
        $this->ensureCanEdit();

        $this->confirmDeleteId = $id;
    }

    public function delete(): void
    {
        $this->ensureCanEdit();

        $news = Notification::query()->findOrFail($this->confirmDeleteId);
        $news->delete();
        unset($this->news);
    }

    public function removeFile(): void
    {
        $this->ensureCanEdit();

        $this->file->delete();
        $this->file = null;
    }

    private function canEditNews(): bool
    {
        return NostrAuth::user()?->isBoardMember() ?? false;
    }

    private function ensureCanEdit(): void
    {
        abort_unless($this->canEditNews(), 403);
    }
};
?>

<div @class(['mx-auto w-full pb-32', 'max-w-5xl' => $isAllowed && $canEdit, 'max-w-3xl' => ! ($isAllowed && $canEdit)])>
    @if($isAllowed)
        <x-page-header title="News" />

        <div @class(['grid gap-8', 'lg:grid-cols-[minmax(0,1fr)_20rem]' => $canEdit])>
            <div class="flex min-w-0 flex-col gap-6">
                {{-- Category filter: one pill per category, pressed state in aria-pressed. --}}
                <div class="flex flex-wrap gap-2" role="group" aria-label="Nach Kategorie filtern">
                    <x-filter-pill :active="$selectedCategory === null" wire:click="clearFilter">Alle</x-filter-pill>
                    @foreach(\App\Enums\NewsCategory::selectOptions() as $category)
                        <x-filter-pill wire:key="cat_{{ $category['value'] }}"
                                       :active="$selectedCategory === $category['value']"
                                       wire:click="filterByCategory({{ $category['value'] }})">
                            {{ $category['label'] }}
                        </x-filter-pill>
                    @endforeach
                </div>

                @if($this->filteredNews->isEmpty())
                    <section class="rounded-lg border border-dashed border-border-default px-6 py-10" aria-labelledby="no-news">
                        @if($selectedCategory !== null)
                            <h2 id="no-news" class="text-xl font-bold text-text-primary">Keine News in dieser Kategorie</h2>
                            <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">Versuche eine andere Kategorie oder zeige alle an.</p>
                            <flux:button wire:click="clearFilter" class="mt-4 min-h-11">Alle anzeigen</flux:button>
                        @else
                            <h2 id="no-news" class="text-xl font-bold text-text-primary">Noch keine News vorhanden</h2>
                            <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">Hier werden zukünftige Neuigkeiten angezeigt.</p>
                        @endif
                    </section>
                @else
                    <ul class="divide-y divide-border-default overflow-hidden rounded-lg border border-border-default bg-bg-surface" aria-label="News, neueste zuerst">
                        @foreach($this->filteredNews as $post)
                            <li wire:key="post_{{ $post->id }}" class="flex flex-col gap-3 px-4 py-6 sm:px-6">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-text-tertiary">
                                    <img
                                        src="{{ $post->einundzwanzigPleb->profile?->picture ?? asset('einundzwanzig-alpha.jpg') }}"
                                        alt=""
                                        class="size-8 shrink-0 rounded-full bg-bg-elevated object-cover"
                                    />
                                    <span class="font-medium text-text-secondary">{{ $post->einundzwanzigPleb?->profile?->name ?? str($post->einundzwanzigPleb?->npub)->limit(32) }}</span>
                                    <time datetime="{{ $post->created_at->toDateString() }}">{{ $post->created_at->format('d.m.Y') }}</time>
                                    <flux:badge size="sm">{{ $post->category->label() }}</flux:badge>
                                </div>

                                <h2 class="text-lg font-bold leading-snug text-text-primary">{{ $post->name }}</h2>
                                @if($post->description)
                                    <p class="max-w-prose text-base leading-normal text-text-secondary">{{ $post->description }}</p>
                                @endif

                                @if($post->getFirstMedia('pdf') || $canEdit)
                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                        @if($post->getFirstMedia('pdf'))
                                            <flux:button
                                                href="{{ url()->temporarySignedRoute('media.signed', now()->addMinutes(30), ['media' => $post->getFirstMedia('pdf')]) }}"
                                                target="_blank"
                                                icon="document-text"
                                                class="min-h-11">
                                                PDF öffnen
                                            </flux:button>
                                        @endif
                                        @if($canEdit)
                                            <flux:modal.trigger name="delete-news-{{ $post->id }}">
                                                <flux:button wire:click="confirmDelete({{ $post->id }})" variant="ghost" icon="trash" class="ms-auto min-h-11">
                                                    Löschen
                                                </flux:button>
                                            </flux:modal.trigger>

                                            <flux:modal name="delete-news-{{ $post->id }}" class="min-w-88">
                                                <div class="space-y-6">
                                                    <div>
                                                        <flux:heading size="lg">News löschen?</flux:heading>
                                                        <flux:text class="mt-2">
                                                            Du bist dabei, diese News zu löschen.<br>
                                                            Diese Aktion kann nicht rückgängig gemacht werden.
                                                        </flux:text>
                                                    </div>
                                                    <div class="flex gap-2">
                                                        <flux:spacer />
                                                        <flux:modal.close>
                                                            <flux:button variant="ghost" class="min-h-11">Abbrechen</flux:button>
                                                        </flux:modal.close>
                                                        <flux:button wire:click="delete" variant="danger" class="min-h-11">Löschen</flux:button>
                                                    </div>
                                                </div>
                                            </flux:modal>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Create form, board only. --}}
            @if($canEdit)
                <section aria-labelledby="create-news" class="h-fit rounded-lg border border-border-default bg-bg-surface p-6 lg:sticky lg:top-8">
                    <h2 id="create-news" class="text-xl font-bold text-text-primary">News anlegen</h2>

                    <div class="mt-6 flex flex-col gap-6">
                        <flux:field>
                            <flux:label>PDF hochladen</flux:label>
                            <flux:file-upload wire:model="file">
                                <flux:file-upload.dropzone heading="Datei hier ablegen oder klicken" text="PDF bis 10MB" />
                            </flux:file-upload>
                            <flux:error name="file" />

                            @if ($file)
                                <flux:file-item
                                    :heading="$file->getClientOriginalName()"
                                    :size="$file->getSize()"
                                >
                                    <x-slot name="actions">
                                        <flux:file-item.remove wire:click="removeFile" aria-label="{{ 'Remove file: ' . $file->getClientOriginalName() }}" />
                                    </x-slot>
                                </flux:file-item>
                            @endif
                        </flux:field>

                        <flux:field>
                            <flux:label>Kategorie</flux:label>
                            <flux:select wire:model="form.category" placeholder="Wähle Kategorie">
                                @foreach(\App\Enums\NewsCategory::selectOptions() as $category)
                                    <flux:select.option :label="$category['label']" :value="$category['value']" />
                                @endforeach
                            </flux:select>
                            <flux:error name="form.category" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Titel</flux:label>
                            <flux:input wire:model="form.name" placeholder="News-Titel" />
                            <flux:error name="form.name" />
                        </flux:field>

                        <flux:field>
                            <flux:label badge="optional">Beschreibung</flux:label>
                            <flux:textarea wire:model="form.description" rows="4" placeholder="Beschreibung..." />
                            <flux:error name="form.description" />
                        </flux:field>

                        <flux:button wire:click="save" variant="primary" class="min-h-11 w-full">Hinzufügen</flux:button>

                        @if($currentPleb)
                            <p class="flex items-center gap-2 text-sm text-text-tertiary">
                                <img
                                    src="{{ $currentPleb->profile?->picture ?? asset('einundzwanzig-alpha.jpg') }}"
                                    alt=""
                                    class="size-8 shrink-0 rounded-full bg-bg-elevated object-cover"
                                />
                                <span class="min-w-0 break-words font-medium text-text-secondary">{{ $currentPleb->profile?->name ?? str($currentPleb->npub)->limit(32) }}</span>
                            </p>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    @else
        <flux:callout variant="warning" icon="exclamation-circle">
            <flux:callout.heading>Zugriff auf News nicht möglich</flux:callout.heading>
            <flux:callout.text>
                <p>Um die News einzusehen, benötigst du:</p>
                <ul class="mt-2 ml-5 list-disc space-y-1">
                    <li>Einen Vereinsstatus von "Aktives Mitglied"</li>
                    <li>Eine bezahlte Mitgliedschaft für das aktuelle Jahr ({{ date('Y') }})</li>
                </ul>
                <p class="mt-4">
                    @if(!NostrAuth::check())
                        Bitte melde dich zunächst mit Nostr an.
                    @else
                        Bitte kontaktiere den Vorstand, wenn du denkst, dass du berechtigt sein solltest.
                    @endif
                </p>
            </flux:callout.text>
        </flux:callout>
    @endif
</div>
