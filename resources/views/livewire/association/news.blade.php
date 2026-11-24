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
    public function filteredNews(): Collection|array
    {
        if ($this->selectedCategory === null) {
            return $this->news;
        }

        return collect($this->news)->filter(
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

<div>
    @if($isAllowed)
        <div class="flex flex-col gap-6 lg:flex-row lg:gap-8">

            <!-- Main content -->
            <div class="flex-1 min-w-0 flex flex-col gap-6">
                <!-- Page title -->
                <h1 class="text-[28px] font-semibold text-text-primary">News</h1>

                <!-- Category filter pills -->
                <div class="flex flex-nowrap gap-2 overflow-x-auto no-scrollbar pb-1">
                    <button
                        wire:click="clearFilter"
                        class="shrink-0 rounded-full px-4 py-1.5 text-[13px] font-semibold transition-colors cursor-pointer {{ $selectedCategory === null ? 'bg-orange-primary text-white' : 'border border-border-default text-text-secondary hover:text-text-primary' }}"
                    >
                        Alle
                    </button>
                    @foreach(\App\Enums\NewsCategory::selectOptions() as $category)
                        <button
                            wire:key="cat_{{ $category['value'] }}"
                            wire:click="filterByCategory({{ $category['value'] }})"
                            class="shrink-0 rounded-full px-4 py-1.5 text-[13px] transition-colors cursor-pointer {{ $selectedCategory === $category['value'] ? 'bg-orange-primary text-white font-semibold' : 'border border-border-default text-text-secondary hover:text-text-primary font-normal' }}"
                        >
                            {{ $category['emoji'] }} {{ $category['label'] }}
                        </button>
                    @endforeach
                </div>

                <!-- News list -->
                <div class="flex flex-col gap-4">
                    @forelse($this->filteredNews as $post)
                        <div wire:key="post_{{ $post->id }}" class="news-card bg-bg-surface rounded-xl p-5 border border-border-subtle flex flex-col gap-4">
                            <!-- Card header: avatar + meta -->
                            <div class="flex items-center gap-3">
                                <img
                                    src="{{ $post->einundzwanzigPleb->profile?->picture ?? asset('einundzwanzig-alpha.jpg') }}"
                                    alt="{{ $post->einundzwanzigPleb->profile?->name ?? 'Anonym' }}"
                                    class="w-10 h-10 rounded-full bg-bg-elevated object-cover shrink-0"
                                />
                                <div class="flex-1 min-w-0 flex flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-text-primary">{{ $post->einundzwanzigPleb?->profile?->name ?? str($post->einundzwanzigPleb?->npub)->limit(32) }}</span>
                                        <span class="text-xs text-text-tertiary">{{ $post->created_at->format('d.m.Y') }}</span>
                                    </div>
                                    <div>
                                        <button
                                            wire:click="filterByCategory({{ $post->category->value }})"
                                            class="news-category-badge news-category-badge--{{ $post->category->color() }} inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] cursor-pointer"
                                        >
                                            {{ $post->category->emoji() }} {{ $post->category->label() }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Card body -->
                            <div class="flex flex-col gap-2">
                                <h3 class="text-base font-semibold text-text-primary">{{ $post->name }}</h3>
                                @if($post->description)
                                    <p class="text-[13px] leading-relaxed text-text-secondary">{{ $post->description }}</p>
                                @endif
                            </div>

                            <!-- Card footer -->
                            <div class="flex items-center">
                                @if($post->getFirstMedia('pdf'))
                                    <a
                                        href="{{ url()->temporarySignedRoute('media.signed', now()->addMinutes(30), ['media' => $post->getFirstMedia('pdf')]) }}"
                                        target="_blank"
                                        class="inline-flex items-center gap-2 rounded-lg border border-border-default px-4 py-2 text-[13px] font-medium text-text-secondary hover:text-text-primary transition-colors"
                                    >
                                        <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 13H6"/><path d="M14 13h-1"/></svg>
                                        PDF öffnen
                                    </a>
                                @endif
                                @if($canEdit)
                                    <div class="ml-auto">
                                    <flux:modal.trigger name="delete-news-{{ $post->id }}">
                                        <button
                                            wire:click="confirmDelete({{ $post->id }})"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-red-500/20 px-4 py-2 text-[13px] font-medium text-red-500 hover:bg-red-500/30 transition-colors cursor-pointer"
                                        >
                                            <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                                            Löschen
                                        </button>
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
                                                    <flux:button variant="ghost">Abbrechen</flux:button>
                                                </flux:modal.close>
                                                <flux:button wire:click="delete" variant="danger">Löschen</flux:button>
                                            </div>
                                        </div>
                                    </flux:modal>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="bg-bg-surface rounded-xl p-5 border border-border-subtle">
                            <div class="py-6 text-center">
                                @if($selectedCategory !== null)
                                    <flux:icon name="funnel" class="mx-auto mb-3 text-text-disabled" />
                                    <h3 class="text-base font-semibold text-text-primary">Keine News in dieser Kategorie</h3>
                                    <p class="mt-1 text-sm text-text-secondary">Versuche eine andere Kategorie oder zeige alle an.</p>
                                    <button wire:click="clearFilter" class="mt-4 rounded-lg border border-border-default px-4 py-2 text-sm text-text-secondary hover:text-text-primary transition-colors cursor-pointer">
                                        Alle anzeigen
                                    </button>
                                @else
                                    <flux:icon name="newspaper" class="mx-auto mb-3 text-text-disabled" />
                                    <h3 class="text-base font-semibold text-text-primary">Noch keine News vorhanden</h3>
                                    <p class="mt-1 text-sm text-text-secondary">Hier werden zukünftige Neuigkeiten angezeigt.</p>
                                @endif
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Sidebar: create form (board members only) -->
            @if($canEdit)
                <div class="w-full lg:w-[360px] shrink-0">
                    <div class="lg:sticky lg:top-16 flex flex-col gap-6">
                        <div class="flex flex-col gap-6">
                            <h2 class="text-lg font-semibold text-text-primary">News anlegen</h2>

                            <!-- Upload section -->
                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-medium text-text-primary">PDF hochladen</label>
                                <flux:file-upload wire:model="file">
                                    <flux:file-upload.dropzone heading="Datei hier ablegen oder klicken" text="PDF bis 10MB" class="!border-orange-primary !border-2 !bg-orange-primary/10 !rounded-xl" />
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
                            </div>

                            <!-- Kategorie -->
                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-medium text-text-primary">Kategorie</label>
                                <flux:select
                                    wire:model="form.category"
                                    placeholder="Wähle Kategorie"
                                >
                                    @foreach(\App\Enums\NewsCategory::selectOptions() as $category)
                                        <flux:select.option
                                            :label="$category['label']"
                                            :value="$category['value']"
                                        />
                                    @endforeach
                                </flux:select>
                                <flux:error name="form.category" />
                            </div>

                            <!-- Titel -->
                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-medium text-text-primary">Titel</label>
                                <flux:input wire:model="form.name" placeholder="News-Titel" />
                                <flux:error name="form.name" />
                            </div>

                            <!-- Beschreibung -->
                            <div class="flex flex-col gap-2">
                                <div class="flex items-center gap-2">
                                    <label class="text-sm font-medium text-text-primary">Beschreibung</label>
                                    <span class="text-xs text-text-tertiary">optional</span>
                                </div>
                                <flux:textarea wire:model="form.description" rows="4" placeholder="Beschreibung..." />
                                <flux:error name="form.description" />
                            </div>

                            <!-- Submit -->
                            <button
                                wire:click="save"
                                class="w-full rounded-lg bg-orange-primary py-3 px-6 text-sm font-semibold text-white hover:bg-orange-light transition-colors cursor-pointer"
                            >
                                Hinzufügen
                            </button>

                            <!-- User badge -->
                            @if($currentPleb)
                                <div class="flex items-center gap-2.5 rounded-xl bg-bg-surface border border-border-subtle px-4 py-2.5">
                                    <img
                                        src="{{ $currentPleb->profile?->picture ?? asset('einundzwanzig-alpha.jpg') }}"
                                        alt="{{ $currentPleb->profile?->name ?? 'Anonym' }}"
                                        class="w-8 h-8 rounded-full bg-bg-elevated object-cover shrink-0"
                                    />
                                    <span class="text-[13px] font-medium text-text-primary">{{ $currentPleb->profile?->name ?? str($currentPleb->npub)->limit(32) }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

        </div>
    @else
        <div class="max-w-2xl mx-auto">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:callout.heading>Zugriff auf News nicht möglich</flux:callout.heading>
                <flux:callout.text>
                    <p>Um die News einzusehen, benötigst du:</p>
                    <ul class="list-disc ml-5 mt-2 space-y-1">
                        <li>Einen Vereinsstatus von "Aktives Mitglied"</li>
                        <li>Eine bezahlte Mitgliedschaft für das aktuelle Jahr ({{ date('Y') }})</li>
                    </ul>
                    <p class="mt-3">
                        @if(!NostrAuth::check())
                            Bitte melde dich zunächst mit Nostr an.
                        @else
                            Bitte kontaktiere den Vorstand, wenn du denkst, dass du berechtigt sein solltest.
                        @endif
                    </p>
                </flux:callout.text>
            </flux:callout>
        </div>
    @endif
</div>
