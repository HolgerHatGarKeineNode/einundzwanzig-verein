<?php

use App\Console\Commands\PruneDescriptionImages;
use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('private');
    $this->author = EinundzwanzigPleb::factory()->create();
});

function proposalReferencing(EinundzwanzigPleb $author, string $markdown): ProjectProposal
{
    return ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $author->id,
        'description' => $markdown,
    ]);
}

function markUnreferencedSince(Media $media, DateTimeInterface $since): void
{
    $media->setCustomProperty(PruneDescriptionImages::UNREFERENCED_SINCE, $since->format(DATE_ATOM))->save();
}

function imageExists(Media $media): bool
{
    return Media::query()->whereKey($media->id)->exists()
        && Storage::disk('private')->exists($media->getPathRelativeToRoot());
}

it('changes nothing in a dry run', function () {
    $fresh = storeDescriptionImage($this->author);
    $old = storeDescriptionImage($this->author);
    markUnreferencedSince($old, now()->subDays(30));
    $referenced = storeDescriptionImage($this->author);
    markUnreferencedSince($referenced, now()->subDays(30));
    proposalReferencing($this->author, '![Bild]('.route('description-image', $referenced->uuid).')');

    $before = Media::query()->orderBy('id')->toBase()->get()->map(fn (object $row) => (array) $row)->all();

    $this->artisan('description-images:prune', ['--dry-run' => true])
        ->expectsOutputToContain($old->uuid)
        ->expectsOutputToContain('Would delete 1 image(s)')
        ->assertSuccessful();

    expect(Media::query()->orderBy('id')->toBase()->get()->map(fn (object $row) => (array) $row)->all())->toBe($before)
        ->and(imageExists($fresh))->toBeTrue()
        ->and(imageExists($old))->toBeTrue()
        ->and(imageExists($referenced))->toBeTrue();
});

it('keeps a referenced image, also when the reference is uppercase', function (bool $uppercase) {
    $media = storeDescriptionImage($this->author);
    markUnreferencedSince($media, now()->subDays(30));
    $uuid = $uppercase ? strtoupper($media->uuid) : $media->uuid;
    proposalReferencing($this->author, "Text\n\n![Bild](https://verein.test/description-images/{$uuid})");

    $this->artisan('description-images:prune')->assertSuccessful();

    expect(imageExists($media))->toBeTrue();
})->with(['lowercase' => false, 'uppercase' => true]);

it('keeps an old image that lost its reference yesterday', function () {
    $media = storeDescriptionImage($this->author);
    $media->forceFill(['created_at' => now()->subYear()])->save();
    $proposal = proposalReferencing($this->author, '![Bild]('.route('description-image', $media->uuid).')');

    $this->artisan('description-images:prune')->assertSuccessful();

    $this->travel(-1)->days();
    $proposal->update(['description' => 'Bild entfernt.']);
    $this->artisan('description-images:prune')->assertSuccessful();
    $this->travelBack();

    $this->artisan('description-images:prune')->assertSuccessful();

    expect(imageExists($media))->toBeTrue()
        ->and(Carbon\Carbon::parse($media->fresh()->getCustomProperty(PruneDescriptionImages::UNREFERENCED_SINCE))->isYesterday())->toBeTrue();
});

it('starts the clock on the first unreferenced run instead of deleting', function () {
    $media = storeDescriptionImage($this->author);
    $media->forceFill(['created_at' => now()->subYear()])->save();

    $this->artisan('description-images:prune')->assertSuccessful();

    expect(imageExists($media))->toBeTrue()
        ->and($media->fresh()->hasCustomProperty(PruneDescriptionImages::UNREFERENCED_SINCE))->toBeTrue();
});

it('deletes an image unreferenced for more than seven days, file included', function () {
    $media = storeDescriptionImage($this->author);
    $path = $media->getPathRelativeToRoot();
    markUnreferencedSince($media, now()->subDays(8));
    Storage::disk('private')->assertExists($path);

    $this->artisan('description-images:prune')->assertSuccessful();

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();
    Storage::disk('private')->assertMissing($path);
});

it('keeps an image unreferenced for less than seven days', function () {
    $media = storeDescriptionImage($this->author);
    markUnreferencedSince($media, now()->subDays(6));

    $this->artisan('description-images:prune')->assertSuccessful();

    expect(imageExists($media))->toBeTrue();
});

it('clears the clock when an image is referenced again', function () {
    $media = storeDescriptionImage($this->author);
    markUnreferencedSince($media, now()->subDays(3));
    proposalReferencing($this->author, '![Bild]('.route('description-image', $media->uuid).')');

    $this->artisan('description-images:prune')->assertSuccessful();

    expect($media->fresh()->hasCustomProperty(PruneDescriptionImages::UNREFERENCED_SINCE))->toBeFalse();
});

it('restarts an unreadable clock instead of deleting', function () {
    $media = storeDescriptionImage($this->author);
    $media->setCustomProperty(PruneDescriptionImages::UNREFERENCED_SINCE, 'not a date')->save();

    $this->artisan('description-images:prune')->assertSuccessful();

    expect(imageExists($media))->toBeTrue()
        ->and(Carbon\Carbon::parse($media->fresh()->getCustomProperty(PruneDescriptionImages::UNREFERENCED_SINCE))->isToday())->toBeTrue();
});

it('leaves media of other collections untouched', function () {
    $proposal = proposalReferencing($this->author, 'Kein Bild.');
    $main = $proposal->addMedia(UploadedFile::fake()->image('main.jpg'))->toMediaCollection('main');
    markUnreferencedSince($main, now()->subDays(30));
    $before = $main->fresh()->toArray();

    $this->artisan('description-images:prune')->assertSuccessful();

    expect(imageExists($main))->toBeTrue()
        ->and($main->fresh()->toArray())->toBe($before);
});

it('is scheduled', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('description-images:prune');
});
