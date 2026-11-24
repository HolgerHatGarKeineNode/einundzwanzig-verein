<?php

use App\Models\ProjectProposal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('serves original media via signed route', function () {
    Storage::fake('private');

    $project = ProjectProposal::factory()->create();

    $project->addMedia(
        UploadedFile::fake()->image('test.jpg', 100, 100)
    )->toMediaCollection('main');

    $media = $project->getFirstMedia('main');

    $url = url()->temporarySignedRoute('media.signed', now()->addMinutes(60), ['media' => $media]);

    $this->get($url)->assertSuccessful();
});

it('serves conversion media via signed route when conversion parameter is provided', function () {
    Storage::fake('private');

    $project = ProjectProposal::factory()->create();

    $project->addMedia(
        UploadedFile::fake()->image('test.jpg', 500, 500)
    )->toMediaCollection('main');

    $media = $project->getFirstMedia('main');

    $url = url()->temporarySignedRoute('media.signed', now()->addMinutes(60), [
        'media' => $media,
        'conversion' => 'preview',
    ]);

    $this->get($url)->assertSuccessful();
});

it('falls back to original when conversion does not exist', function () {
    Storage::fake('private');

    $project = ProjectProposal::factory()->create();

    $project->addMedia(
        UploadedFile::fake()->image('test.jpg', 100, 100)
    )->toMediaCollection('main');

    $media = $project->getFirstMedia('main');

    $url = url()->temporarySignedRoute('media.signed', now()->addMinutes(60), [
        'media' => $media,
        'conversion' => 'nonexistent',
    ]);

    $this->get($url)->assertSuccessful();
});

it('rejects unsigned media requests', function () {
    Storage::fake('private');

    $project = ProjectProposal::factory()->create();

    $project->addMedia(
        UploadedFile::fake()->image('test.jpg', 100, 100)
    )->toMediaCollection('main');

    $media = $project->getFirstMedia('main');

    $this->get("/media/{$media->id}")->assertForbidden();
});

it('generates signed url with conversion parameter', function () {
    Storage::fake('private');

    $project = ProjectProposal::factory()->create();

    $project->addMedia(
        UploadedFile::fake()->image('test.jpg', 500, 500)
    )->toMediaCollection('main');

    $urlWithoutConversion = $project->getSignedMediaUrl('main');
    $urlWithConversion = $project->getSignedMediaUrl('main', 60, 'preview');

    expect($urlWithoutConversion)->not->toContain('conversion=');
});

it('returns fallback url when no media exists', function () {
    $project = ProjectProposal::factory()->create();

    $url = $project->getSignedMediaUrl('main', 60, 'preview');

    expect($url)->toContain('einundzwanzig-alpha.jpg');
});

it('keeps the signed url the same within its window, so the browser can cache the image, and valid for at least the asked time', function () {
    Storage::fake('private');
    $project = ProjectProposal::factory()->create();
    $project->addMedia(UploadedFile::fake()->image('test.jpg', 500, 500))->toMediaCollection('main');
    $expiresOf = fn (string $url): int => (int) Str::of($url)->match('/[?&]expires=(\d+)/')->toString();

    $this->travelTo(Carbon::parse('2026-11-24 10:00:05'));
    $first = $project->getSignedMediaUrl('main', 60, 'preview');
    $this->travelTo(Carbon::parse('2026-11-24 10:59:59'));
    $later = $project->getSignedMediaUrl('main', 60, 'preview');
    $this->travelTo(Carbon::parse('2026-11-24 11:00:01'));
    $next = $project->getSignedMediaUrl('main', 60, 'preview');

    expect($later)->toBe($first)
        ->and($expiresOf($first))->toBe(Carbon::parse('2026-11-24 12:00:00')->getTimestamp())
        ->and($next)->not->toBe($first)
        ->and($expiresOf($next) - now()->getTimestamp())->toBeGreaterThanOrEqual(3600);

    $this->get($next)->assertOk();
});

it('downloads the original file under its name via the signed dl route', function () {
    Storage::fake('private');

    $project = ProjectProposal::factory()->create();

    $project->addMedia(
        UploadedFile::fake()->image('report.jpg', 100, 100)
    )->toMediaCollection('main');

    $media = $project->getFirstMedia('main');

    $this->get(url()->temporarySignedRoute('dl', now()->addMinutes(5), ['media' => $media]))
        ->assertSuccessful()
        ->assertDownload('report.jpg');

    $this->get(route('dl', ['media' => $media]))->assertForbidden();
});
