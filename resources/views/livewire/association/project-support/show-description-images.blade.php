<?php

use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Support\DescriptionImageReferences;
use App\Support\NostrAuth;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Board takedown for the images uploaded into a proposal description: the
 * list of those images and the dialog that deletes one.
 *
 * A child of `association.project-support.show`, mounted for the board
 * only. After a removal it tells the page `project-proposal-changed`, so
 * the description loses the image line.
 *
 * Callable on its own like every Livewire component: every request asks the
 * `removeDescriptionImage` policy again.
 */
new class extends Component
{
    #[Locked]
    public ProjectProposal $projectProposal;

    /**
     * The uuid of the description image the board is about to remove. Client
     * writable like every public property, so removeDescriptionImage() checks
     * it again instead of trusting it.
     */
    public ?string $imageToRemove = null;

    public function mount(ProjectProposal $projectProposal): void
    {
        $this->projectProposal = $projectProposal;
        $this->authorizeBoard();
    }

    public function hydrate(): void
    {
        $this->authorizeBoard();
    }

    /**
     * The uploaded images this description refers to that still exist.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function descriptionImages(): Collection
    {
        $uuids = DescriptionImageReferences::extract($this->projectProposal->description);

        if ($uuids === []) {
            return new Collection;
        }

        return Media::query()
            ->where('collection_name', EinundzwanzigPleb::DESCRIPTION_IMAGES)
            ->whereIn('uuid', $uuids)
            ->orderBy('id')
            ->get();
    }

    /**
     * Other proposals whose description shows the image about to be removed.
     * The takedown deletes the file for all of them; the modal names them.
     *
     * @return \Illuminate\Support\Collection<int, ProjectProposal>
     */
    #[Computed]
    public function otherProposalsUsingImage(): \Illuminate\Support\Collection
    {
        if ($this->imageToRemove === null) {
            return collect();
        }

        return ProjectProposal::query()
            ->whereKeyNot($this->projectProposal->id)
            ->get(['id', 'name', 'slug', 'description'])
            ->filter(fn (ProjectProposal $proposal) => in_array(
                $this->imageToRemove,
                DescriptionImageReferences::extract($proposal->description),
                true,
            ))
            ->values()
            ->toBase();
    }

    /**
     * Whether the description still refers to the image after the uploader's
     * own line is gone — a form the rewrite does not touch.
     */
    #[Computed]
    public function imageHasOtherForms(): bool
    {
        if ($this->imageToRemove === null) {
            return false;
        }

        $rewritten = DescriptionImageReferences::removeUploaderForm($this->projectProposal->description ?? '', $this->imageToRemove);

        return in_array($this->imageToRemove, DescriptionImageReferences::extract($rewritten), true);
    }

    public function confirmRemoveDescriptionImage(string $uuid): void
    {
        Gate::forUser(NostrAuth::user())->authorize('removeDescriptionImage', $this->projectProposal);

        $this->projectProposal->refresh();
        $this->imageToRemove = $this->findRemovableImage($uuid)->uuid;
        unset($this->otherProposalsUsingImage, $this->imageHasOtherForms);

        Flux::modal('confirm-remove-description-image')->show();
    }

    /**
     * Takes an uploaded image down: deletes the media row and its file, so the
     * public route 404s on every page that used it, and drops the uploader's
     * line from THIS description. Other proposals that show the image are not
     * rewritten; they were listed in the modal.
     *
     * The description is re-read first: writing back the copy loaded at mount
     * would undo whatever the author saved in the meantime.
     */
    public function removeDescriptionImage(): void
    {
        Gate::forUser(NostrAuth::user())->authorize('removeDescriptionImage', $this->projectProposal);

        if ($this->imageToRemove === null) {
            abort(404);
        }

        $this->projectProposal->refresh();
        $media = $this->findRemovableImage($this->imageToRemove);

        $media->delete();

        $this->projectProposal->description = DescriptionImageReferences::removeUploaderForm(
            $this->projectProposal->description,
            $media->uuid,
        );
        $this->projectProposal->save();

        Log::info('Description image removed by board', [
            'board_pubkey' => NostrAuth::pubkey(),
            'project_proposal_id' => $this->projectProposal->id,
            'media_uuid' => $media->uuid,
        ]);

        $this->imageToRemove = null;
        unset($this->descriptionImages, $this->otherProposalsUsingImage, $this->imageHasOtherForms);
        $this->dispatch('project-proposal-changed');

        Flux::modals()->close();
        Flux::toast('Bild entfernt.');
    }

    /**
     * Only an image this description refers to, and only from the
     * description-images collection. Anything else — another proposal's
     * image, a `main` picture, a made-up uuid — is a 404 and nothing is
     * deleted: the uuid comes from the client.
     */
    private function findRemovableImage(string $uuid): Media
    {
        $uuid = strtolower($uuid);

        if (! in_array($uuid, DescriptionImageReferences::extract($this->projectProposal->description), true)) {
            abort(404);
        }

        return Media::query()
            ->where('collection_name', EinundzwanzigPleb::DESCRIPTION_IMAGES)
            ->where('uuid', $uuid)
            ->firstOr(fn () => abort(404));
    }

    private function authorizeBoard(): void
    {
        Gate::forUser(NostrAuth::user())->authorize('removeDescriptionImage', $this->projectProposal);
    }
};
?>

<div>
    {{-- Board takedown for uploaded images. The markup hangs on the
         server-side gate alone: non-board viewers never get it. --}}
    @if($this->descriptionImages->isNotEmpty())
        <section class="mt-8 max-w-prose rounded-lg border border-border-default p-4">
            <flux:heading size="sm">Bilder in der Beschreibung</flux:heading>
            <flux:text class="mt-1 text-sm">Nur für den Vorstand sichtbar.</flux:text>
            <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach($this->descriptionImages as $image)
                    <li wire:key="description-image-{{ $image->uuid }}" class="min-w-0">
                        <img src="{{ route('description-image', $image->uuid) }}"
                             alt="" loading="lazy" decoding="async"
                             class="aspect-square w-full rounded-md bg-bg-elevated object-cover">
                        <flux:button class="mt-2 min-h-11 w-full"
                                     size="sm"
                                     variant="subtle"
                                     icon="trash"
                                     wire:click="confirmRemoveDescriptionImage('{{ $image->uuid }}')">
                            Bild entfernen
                        </flux:button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

        <flux:modal name="confirm-remove-description-image" class="min-w-88">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Bild entfernen?</flux:heading>
                    <flux:text class="mt-2 space-y-2">
                        <p>
                            Die Bilddatei wird <strong>endgültig gelöscht</strong> und ist danach
                            auf keiner Seite mehr zu sehen. Die Bildzeile wird aus dieser
                            Beschreibung entfernt.
                        </p>
                        @if($this->imageHasOtherForms)
                            <p>
                                Die Beschreibung verweist noch in anderer Schreibweise auf das Bild.
                                Diese Stelle bitte danach von Hand bearbeiten.
                            </p>
                        @endif
                        @if($this->otherProposalsUsingImage->isNotEmpty())
                            <p>Das Bild wird auch hier verwendet und verschwindet dort ebenfalls:</p>
                            <ul class="list-disc pl-5">
                                @foreach($this->otherProposalsUsingImage as $other)
                                    <li wire:key="other-proposal-{{ $other->id }}">
                                        <a class="underline break-all" href="{{ route('association.projectSupport.item', $other) }}">{{ $other->name }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:spacer/>
                    <flux:modal.close>
                        <flux:button variant="ghost">Abbrechen</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="removeDescriptionImage" variant="danger" icon="trash">
                        Entfernen
                    </flux:button>
                </div>
            </div>
        </flux:modal>
</div>
