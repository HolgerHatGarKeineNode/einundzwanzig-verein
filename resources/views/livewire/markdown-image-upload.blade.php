<?php

use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Support\NostrAuth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

/*
 * The image button of the Markdown field.
 *
 * Uploads one image, stores it with its author, and hands the Markdown for it
 * back to the browser, which inserts it at the cursor. The description itself
 * stays plain Markdown with an ordinary https URL — nothing to rewrite on save.
 */
new class extends Component
{
    use WithFileUploads;

    /** The id of the textarea the Markdown is inserted into. */
    #[Locked]
    public string $target;

    /** Set when editing: the proposal whose author or board may upload. */
    #[Locked]
    public ?int $proposalId = null;

    public $image = null;

    /**
     * Decoding cost is pixels, not bytes: GD allocates about 2–4 bytes per
     * pixel outside PHP's memory_limit, and a 31 KB PNG can declare
     * 16000×16000 (measured: 555 MB RSS). The cap is read from the header
     * via getimagesize() before anything is decoded. 16 MP admits a phone
     * photo in its default 12 MP mode; at that cap GD peaks around 200 MB
     * for a progressive JPEG (25 MP measured 317 MB).
     */
    private const MAX_PIXELS = 16_000_000;

    /**
     * Images one author may keep. Unreferenced ones are deleted by
     * `description-images:prune` after its grace period; the cap bounds what
     * an author can pile up before that, since there is no gallery for them.
     */
    private const MAX_IMAGES_PER_AUTHOR = 200;

    /**
     * One re-encode at a time, site-wide. The hourly rate limit does not
     * bound concurrency: 30 parallel uploads at ~160 MB each would need
     * ~4.8 GB on a host with 3.8 GB RAM shared by five FPM pools (measured
     * on prod 2026-10-18: 211 MB peak RSS, 1.4 s for a 16 MP progressive
     * JPEG). A waiting upload gives up after this many seconds.
     */
    private const ENCODE_WAIT_SECONDS = 10;

    public function updatedImage(): void
    {
        $user = NostrAuth::user();

        if ($this->proposalId !== null) {
            Gate::forUser($user)->authorize('update', ProjectProposal::findOrFail($this->proposalId));
        } else {
            Gate::forUser($user)->authorize('create', ProjectProposal::class);
        }

        if (! RateLimiter::attempt('description-image:'.NostrAuth::pubkey(), 30, fn () => true, 3600)) {
            $this->reset('image');
            $this->addError('image', 'Zu viele Bilder in kurzer Zeit. Bitte später erneut versuchen.');

            return;
        }

        $this->validate([
            'image' => [
                'required', 'file', 'max:10240',
                'mimes:jpeg,png,jpg,gif,webp',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp',
                'dimensions:max_width=8000,max_height=8000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $size = @getimagesize($value->getRealPath());

                    if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
                        $fail('Das Bild ist zu groß (höchstens 16 Megapixel).');
                    }
                },
            ],
        ]);

        $author = EinundzwanzigPleb::query()->where('pubkey', NostrAuth::pubkey())->firstOrFail();

        if ($author->getMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES)->count() >= self::MAX_IMAGES_PER_AUTHOR) {
            $this->reset('image');
            $this->addError('image', 'Bildlimit erreicht.');

            return;
        }

        /*
         * Re-encode BEFORE storing: the original never lands in the media
         * library, so no EXIF/GPS block and no polyglot payload is kept
         * anywhere, and what is stored is exactly what is served.
         */
        $scratch = tempnam(sys_get_temp_dir(), 'descimg');
        $webp = $scratch.'.webp';

        try {
            $media = Cache::lock('description-image-encode', 60)->block(self::ENCODE_WAIT_SECONDS, function () use ($author, $webp) {
                Image::useImageDriver(config('media-library.image_driver'))
                    ->loadFile($this->image->getRealPath())
                    ->fit(Fit::Max, 1600, 1600)
                    ->format('webp')
                    ->save($webp);

                return $author
                    ->addMedia($webp)
                    ->usingFileName(Str::uuid().'.webp')
                    ->toMediaCollection(EinundzwanzigPleb::DESCRIPTION_IMAGES);
            });
        } catch (LockTimeoutException) {
            $media = null;
        } finally {
            @unlink($webp);
            @unlink($scratch);
            $this->image->delete();
        }

        $this->reset('image');

        if ($media === null) {
            $this->addError('image', 'Gerade werden andere Bilder verarbeitet. Bitte gleich erneut versuchen.');

            return;
        }

        $this->dispatch(
            'markdown-image-uploaded',
            target: $this->target,
            markdown: '![Bild]('.route('description-image', $media->uuid).')',
        );
    }
};
?>
<div class="contents">
    <flux:button
        as="label"
        size="sm"
        variant="ghost"
        icon="arrow-up-tray"
        title="Bild hochladen"
        aria-label="Bild hochladen"
        class="cursor-pointer"
        wire:loading.attr="data-loading"
        wire:target="image"
    >
        <input type="file" class="sr-only" accept="image/jpeg,image/png,image/gif,image/webp" wire:model="image">
    </flux:button>

    <span wire:loading wire:target="image" class="text-xs text-text-tertiary">Lädt hoch …</span>

    @error('image')
        <span class="text-xs text-danger">{{ $message }}</span>
    @enderror
</div>
