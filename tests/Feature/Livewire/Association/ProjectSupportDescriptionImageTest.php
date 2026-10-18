<?php

use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Support\NostrAuth;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
 * Board takedown of an uploaded description image on the proposal page.
 * The Livewire methods are public endpoints, so every refusal is tested at
 * the endpoint, not only at the hidden button.
 */
beforeEach(function () {
    Storage::fake('private');

    $this->board = EinundzwanzigPleb::factory()->boardMember()->create();
    $this->author = EinundzwanzigPleb::factory()->create();
    $this->image = storeDescriptionImage($this->author);
    $this->imagePath = $this->image->getPathRelativeToRoot();
    $this->markdown = '![Bild]('.route('description-image', $this->image->uuid).')';
    $this->proposal = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $this->author->id,
        'description' => "Intro mit **fett**.\n\n{$this->markdown}\n\nOutro\nletzte Zeile",
    ]);
});

function showPage(ProjectProposal $proposal)
{
    return Livewire::test('association.project-support.show', ['projectProposal' => $proposal]);
}

function imageIsIntact(Media $media, string $path): bool
{
    return Media::query()->whereKey($media->id)->exists() && Storage::disk('private')->exists($path);
}

it('lets a board member remove an image: row, file and uploader line gone, rest byte-identical', function () {
    Log::spy();
    NostrAuth::login($this->board->pubkey);

    showPage($this->proposal)
        ->assertSee('Bilder in der Beschreibung')
        ->assertSeeHtml("confirmRemoveDescriptionImage('{$this->image->uuid}')")
        ->call('confirmRemoveDescriptionImage', $this->image->uuid)
        ->assertSet('imageToRemove', $this->image->uuid)
        ->call('removeDescriptionImage')
        ->assertHasNoErrors()
        ->assertSet('imageToRemove', null)
        ->assertDontSee('Bilder in der Beschreibung')
        ->call('$refresh')
        ->assertOk();

    expect(Media::query()->whereKey($this->image->id)->exists())->toBeFalse()
        ->and($this->proposal->fresh()->description)->toBe("Intro mit **fett**.\n\nOutro\nletzte Zeile");
    Storage::disk('private')->assertMissing($this->imagePath);
    $this->get(route('description-image', $this->image->uuid))->assertNotFound();

    Log::shouldHaveReceived('info')->once()->with('Description image removed by board', [
        'board_pubkey' => $this->board->pubkey,
        'project_proposal_id' => $this->proposal->id,
        'media_uuid' => $this->image->uuid,
    ]);
});

it('accepts an uppercase reference to the image', function () {
    $this->proposal->update(['description' => 'A ![Bild](https://x.test/description-images/'.strtoupper($this->image->uuid).') B']);
    NostrAuth::login($this->board->pubkey);

    showPage($this->proposal)
        ->call('confirmRemoveDescriptionImage', strtoupper($this->image->uuid))
        ->call('removeDescriptionImage')
        ->assertHasNoErrors();

    expect(Media::query()->whereKey($this->image->id)->exists())->toBeFalse()
        ->and($this->proposal->fresh()->description)->toBe('A  B');
});

it('answers 404 and deletes nothing for an image this description does not refer to', function () {
    $foreign = storeDescriptionImage($this->author);
    $foreignPath = $foreign->getPathRelativeToRoot();
    ProjectProposal::factory()->create(['description' => '![Bild]('.route('description-image', $foreign->uuid).')']);
    NostrAuth::login($this->board->pubkey);

    showPage($this->proposal)
        ->call('confirmRemoveDescriptionImage', $foreign->uuid)
        ->assertNotFound();

    // The property is client writable: setting it directly must not get past the check.
    showPage($this->proposal)
        ->set('imageToRemove', $foreign->uuid)
        ->call('removeDescriptionImage')
        ->assertNotFound();

    expect(imageIsIntact($foreign, $foreignPath))->toBeTrue()
        ->and(imageIsIntact($this->image, $this->imagePath))->toBeTrue();
});

it('answers 404 and deletes nothing for a main image, even when the description names its uuid', function () {
    $main = $this->proposal->addMedia(UploadedFile::fake()->image('main.jpg'))->toMediaCollection('main');
    $mainPath = $main->getPathRelativeToRoot();
    $description = "Text\n\n![Bild](https://x.test/description-images/{$main->uuid})\n\n";
    $this->proposal->update(['description' => $description]);
    NostrAuth::login($this->board->pubkey);

    showPage($this->proposal)
        ->call('confirmRemoveDescriptionImage', $main->uuid)
        ->assertNotFound();

    showPage($this->proposal)
        ->set('imageToRemove', $main->uuid)
        ->call('removeDescriptionImage')
        ->assertNotFound();

    expect(imageIsIntact($main, $mainPath))->toBeTrue()
        ->and($this->proposal->fresh()->description)->toBe($description);
});

it('refuses author, member and guest at the endpoint and hides the button', function (string $who) {
    $pubkey = match ($who) {
        'author' => $this->author->pubkey,
        'member' => EinundzwanzigPleb::factory()->withPaidCurrentYear()->create()->pubkey,
        'guest' => null,
    };
    if ($pubkey !== null) {
        NostrAuth::login($pubkey);
    }

    showPage($this->proposal)
        ->assertDontSee('Bild entfernen')
        ->assertDontSee('Bilder in der Beschreibung')
        ->assertDontSeeHtml('confirmRemoveDescriptionImage');

    showPage($this->proposal)
        ->call('confirmRemoveDescriptionImage', $this->image->uuid)
        ->assertForbidden();

    showPage($this->proposal)
        ->set('imageToRemove', $this->image->uuid)
        ->call('removeDescriptionImage')
        ->assertForbidden();

    expect(imageIsIntact($this->image, $this->imagePath))->toBeTrue()
        ->and($this->proposal->fresh()->description)->toContain($this->markdown);
})->with(['author', 'member', 'guest']);

it('lists other proposals using the image, deletes the file anyway and leaves their text alone', function () {
    $other = ProjectProposal::factory()->create([
        'name' => 'Zweiter Antrag mit demselben Bild',
        'description' => "Kopie:\n\n{$this->markdown}\n\n",
    ]);
    NostrAuth::login($this->board->pubkey);

    showPage($this->proposal)
        ->call('confirmRemoveDescriptionImage', $this->image->uuid)
        ->assertSee('Zweiter Antrag mit demselben Bild')
        ->call('removeDescriptionImage')
        ->assertHasNoErrors();

    expect(Media::query()->whereKey($this->image->id)->exists())->toBeFalse()
        ->and($other->fresh()->description)->toBe("Kopie:\n\n{$this->markdown}\n\n");
});

it('tells the board to edit a reference in another form by hand', function () {
    $this->proposal->update(['description' => "{$this->markdown}\n\n[Link](".route('description-image', $this->image->uuid).')']);
    NostrAuth::login($this->board->pubkey);

    showPage($this->proposal)
        ->call('confirmRemoveDescriptionImage', $this->image->uuid)
        ->assertSee('Diese Stelle bitte danach von Hand bearbeiten.')
        ->call('removeDescriptionImage');

    expect($this->proposal->fresh()->description)->toBe('[Link]('.route('description-image', $this->image->uuid).')');
});

it('does not show the hand-edit notice when the uploader form is the only reference', function () {
    NostrAuth::login($this->board->pubkey);

    showPage($this->proposal)
        ->call('confirmRemoveDescriptionImage', $this->image->uuid)
        ->assertDontSee('Diese Stelle bitte danach von Hand bearbeiten.');
});
