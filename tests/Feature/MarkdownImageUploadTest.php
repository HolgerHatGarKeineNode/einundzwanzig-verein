<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Services\MembershipService;
use App\Support\NostrAuth;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('private');

    $this->member = EinundzwanzigPleb::query()->create([
        'pubkey' => 'test_pubkey_'.Str::random(20),
        'npub' => 'test_npub_'.Str::random(20),
    ]);
    $this->member->association_status = AssociationStatus::ACTIVE;
    $this->member->save();
    $this->member->paymentEvents()->create([
        'year' => date('Y'),
        'amount' => 21000,
        'paid' => true,
        'event_id' => 'test_event_'.Str::random(40),
    ]);

    // No paid membership: may neither create nor upload for a new proposal.
    $this->outsider = EinundzwanzigPleb::query()->create([
        'pubkey' => 'outsider_pubkey_'.Str::random(20),
        'npub' => 'outsider_npub_'.Str::random(20),
    ]);
});

function uploadDescriptionImage(array $params, UploadedFile $file)
{
    return Livewire::test('markdown-image-upload', ['target' => 'md-field'] + $params)
        ->set('image', $file);
}

it('stores an uploaded image and hands back its markdown', function () {
    NostrAuth::login($this->member->pubkey);

    $component = uploadDescriptionImage([], UploadedFile::fake()->image('photo.jpg', 2400, 1200));

    $media = $this->member->fresh()->getFirstMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES);

    expect($media)->not->toBeNull()
        ->and($media->mime_type)->toBe('image/webp');

    $component->assertHasNoErrors()
        ->assertDispatched('markdown-image-uploaded',
            target: 'md-field',
            markdown: '![Bild]('.route('description-image', $media->uuid).')',
        );
});

it('renders the uploaded image through the description sanitizer', function () {
    NostrAuth::login($this->member->pubkey);
    uploadDescriptionImage([], UploadedFile::fake()->image('photo.png'));

    $url = route('description-image', $this->member->fresh()->getFirstMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES)->uuid);
    $proposal = new ProjectProposal(['description' => "![Bild]({$url})"]);

    expect($proposal->safeDescription())->toContain('<img src="'.$url.'"');
});

it('refuses uploads from someone who may not create a proposal', function () {
    NostrAuth::login($this->outsider->pubkey);

    uploadDescriptionImage([], UploadedFile::fake()->image('photo.jpg'))->assertForbidden();

    expect($this->outsider->fresh()->getMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES))->toHaveCount(0);
});

it('refuses uploads from guests', function () {
    uploadDescriptionImage([], UploadedFile::fake()->image('photo.jpg'))->assertForbidden();
});

it('lets the author upload while editing even without a paid membership', function () {
    $proposal = ProjectProposal::query()->forceCreate([
        'einundzwanzig_pleb_id' => $this->outsider->id,
        'name' => 'Own Proposal',
        'description' => 'x',
        'support_in_sats' => 21000,
        'website' => 'https://example.com',
        'accepted' => false,
        'sats_paid' => 0,
    ]);

    NostrAuth::login($this->outsider->pubkey);
    uploadDescriptionImage(['proposalId' => $proposal->id], UploadedFile::fake()->image('photo.jpg'))
        ->assertHasNoErrors();

    NostrAuth::login($this->member->pubkey);
    uploadDescriptionImage(['proposalId' => $proposal->id], UploadedFile::fake()->image('photo.jpg'))
        ->assertForbidden();
});

it('rejects files that are not raster images', function (UploadedFile $file) {
    NostrAuth::login($this->member->pubkey);

    uploadDescriptionImage([], $file)->assertHasErrors('image');

    expect($this->member->fresh()->getMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES))->toHaveCount(0);
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
    'html' => fn () => UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
    'too large' => fn () => UploadedFile::fake()->image('huge.jpg')->size(10241),
]);

it('refuses a pixel bomb before decoding it', function () {
    NostrAuth::login($this->member->pubkey);

    // A 1x1 PNG whose header claims 16000×16000: a few bytes to send, ~555 MB for GD to decode.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $ihdr = 'IHDR'.pack('NN', 16000, 16000).substr($png, 24, 5);
    $png = substr($png, 0, 12).$ihdr.pack('N', crc32($ihdr)).substr($png, 33);
    $bomb = UploadedFile::fake()->createWithContent('bomb.png', $png);
    expect(getimagesize($bomb->getRealPath()))->toMatchArray([0 => 16000, 1 => 16000]);

    uploadDescriptionImage([], $bomb)->assertHasErrors('image');

    expect($this->member->fresh()->getMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES))->toHaveCount(0);
});

it('rejects more than 16 megapixels even within the side limits', function () {
    NostrAuth::login($this->member->pubkey);

    uploadDescriptionImage([], UploadedFile::fake()->image('wide.png', 7000, 4000))->assertHasErrors('image');
});

it('stores no metadata from the upload', function () {
    NostrAuth::login($this->member->pubkey);

    // A JPEG carrying an EXIF APP1 segment with a camera make.
    $jpeg = UploadedFile::fake()->image('gps.jpg', 400, 300);
    $bytes = file_get_contents($jpeg->getRealPath());
    $exif = "Exif\0\0MM\0\x2A\0\0\0\x08\0\x01\x01\x0F\0\x02\0\0\0\x0ATestPhone\0\0\0\0\0";
    file_put_contents($jpeg->getRealPath(), substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2));
    expect(file_get_contents($jpeg->getRealPath()))->toContain('TestPhone');

    uploadDescriptionImage([], $jpeg)->assertHasNoErrors();

    $media = $this->member->fresh()->getFirstMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES);
    $stored = Storage::disk('private')->get($media->getPathRelativeToRoot());

    expect($this->member->fresh()->getMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES))->toHaveCount(1)
        ->and($stored)->not->toContain('TestPhone')
        ->and($stored)->not->toContain('Exif');
});

it('removes the images when personal data is erased', function () {
    NostrAuth::login($this->member->pubkey);
    uploadDescriptionImage([], UploadedFile::fake()->image('photo.jpg'));
    $uuid = $this->member->fresh()->getFirstMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES)->uuid;

    app(MembershipService::class)->erasePersonalData($this->member->fresh());

    $this->get(route('description-image', $uuid))->assertNotFound();
});

it('serves only the re-encoded webp, publicly cacheable', function () {
    NostrAuth::login($this->member->pubkey);
    uploadDescriptionImage([], UploadedFile::fake()->image('photo.jpg', 2400, 1200));
    $media = $this->member->fresh()->getFirstMedia(EinundzwanzigPleb::DESCRIPTION_IMAGES);

    $response = $this->get(route('description-image', $media->uuid));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('public')
        ->and(getimagesizefromstring($response->streamedContent()))->toMatchArray([0 => 1600, 1 => 800]);
});

it('does not serve media from other collections through the public route', function () {
    $proposal = ProjectProposal::query()->forceCreate([
        'einundzwanzig_pleb_id' => $this->member->id,
        'name' => 'Private Main Image',
        'description' => 'x',
        'support_in_sats' => 21000,
        'website' => 'https://example.com',
        'accepted' => false,
        'sats_paid' => 0,
    ]);
    $main = $proposal->addMedia(UploadedFile::fake()->image('main.jpg'))->toMediaCollection('main');

    $this->get(route('description-image', $main->uuid))->assertNotFound();
});
