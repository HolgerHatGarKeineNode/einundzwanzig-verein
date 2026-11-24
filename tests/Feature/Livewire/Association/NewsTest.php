<?php

use App\Enums\AssociationStatus;
use App\Enums\NewsCategory;
use App\Models\EinundzwanzigPleb;
use App\Models\Notification;
use App\Support\NostrAuth;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
});

it('denies access when pleb has insufficient association status', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::PASSIVE,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSet('isAllowed', false);
});

it('denies access when pleb has not paid for current year', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::ACTIVE,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSet('isAllowed', false);
});

it('grants access when pleb is active and has paid', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSet('isAllowed', true);
});

it('allows board member to edit news', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSet('canEdit', true);
});

it('grants board member access even without active membership or paid year', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->create([
        'association_status' => AssociationStatus::DEFAULT,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSet('isAllowed', true)
        ->assertSet('canEdit', true);
});

it('can create news entry with pdf', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    // Write PDF magic bytes to the temp file so Spatie media library detects correct MIME
    file_put_contents($file->getPathname(), '%PDF-1.4 fake pdf content for testing');

    Livewire::test('association.news')
        ->set('file', $file)
        ->set('form.category', (string) NewsCategory::Organisation->value)
        ->set('form.name', 'Test News')
        ->set('form.description', 'Test Description')
        ->call('save')
        ->assertHasNoErrors();

    expect(Notification::where('name', 'Test News')->exists())->toBeTrue();
});

it('validates news entry creation', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->call('save')
        ->assertHasErrors(['file', 'form.category', 'form.name']);
});

it('can delete news entry', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->withPaidCurrentYear()->create();
    $news = Notification::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->call('confirmDelete', $news->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Notification::find($news->id))->toBeNull();
});

it('forbids guests from deleting news', function () {
    $author = EinundzwanzigPleb::factory()->create();
    $news = Notification::factory()->create([
        'einundzwanzig_pleb_id' => $author->id,
    ]);

    Livewire::test('association.news')
        ->call('delete')
        ->assertForbidden();

    expect(Notification::find($news->id))->not->toBeNull();
});

it('forbids non-board members from deleting news', function () {
    $author = EinundzwanzigPleb::factory()->create();
    $news = Notification::factory()->create([
        'einundzwanzig_pleb_id' => $author->id,
    ]);
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->call('delete')
        ->assertForbidden();

    expect(Notification::find($news->id))->not->toBeNull();
});

it('forbids non-board members from creating news', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->call('save')
        ->assertForbidden();

    expect(Notification::count())->toBe(0);
});

it('does not load news for unauthorized visitors', function () {
    $author = EinundzwanzigPleb::factory()->create();
    Notification::factory()->count(2)->create([
        'einundzwanzig_pleb_id' => $author->id,
    ]);

    Livewire::test('association.news')
        ->assertSet('isAllowed', false)
        ->assertSet('news', fn (Collection $news): bool => $news->isEmpty());
});

it('displays news list', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $news1 = Notification::factory()->create();
    $news2 = Notification::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSet('isAllowed', true)
        ->assertSee($news1->name)
        ->assertSee($news2->name);
});

it('shows warning callout when access is denied', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::PASSIVE,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSet('isAllowed', false)
        ->assertSee('Zugriff auf News nicht möglich')
        ->assertSee('Aktives Mitglied');
});

it('shows nostr login hint when not authenticated', function () {
    Livewire::test('association.news')
        ->assertSet('isAllowed', false)
        ->assertSee('Bitte melde dich zunächst mit Nostr an');
});

it('displays category badges as filters', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSee('Alle')
        ->assertSee('Einundzwanzig')
        ->assertSee('Allgemeines')
        ->assertSee('Organisation');
});

it('filters news by category', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $newsOrg = Notification::factory()->create(['category' => NewsCategory::Organisation]);
    $newsBtc = Notification::factory()->create(['category' => NewsCategory::Bitcoin]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSee($newsOrg->name)
        ->assertSee($newsBtc->name)
        ->call('filterByCategory', NewsCategory::Organisation->value)
        ->assertSee($newsOrg->name)
        ->assertDontSee($newsBtc->name);
});

it('shows empty state when no news exist', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSee('Noch keine News vorhanden');
});

it('shows filtered empty state with clear button', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->call('filterByCategory', NewsCategory::Bildung->value)
        ->assertSee('Keine News in dieser Kategorie')
        ->assertSee('Alle anzeigen');
});

it('displays news card with author name and date', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $news = Notification::factory()->create([
        'name' => 'Wichtige Neuigkeiten',
        'description' => 'Hier steht die Beschreibung',
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSee('Wichtige Neuigkeiten')
        ->assertSee('Hier steht die Beschreibung')
        ->assertSee($news->created_at->format('d.m.Y'));
});

it('shows create form only for board members', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertDontSee('News anlegen');
});

it('displays create form for board members', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.news')
        ->assertSee('News anlegen')
        ->assertSee('Hinzufügen');
});

it('renders the news list with its PDFs in a fixed number of queries, however many posts there are', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);
    $addPosts = function (int $count): void {
        Notification::factory()->count($count)->create()->each(function (Notification $post): void {
            $file = UploadedFile::fake()->create('rundbrief.pdf', 10, 'application/pdf');
            file_put_contents($file->getPathname(), '%PDF-1.4 fake pdf content for testing');
            $post->addMedia($file)->toMediaCollection('pdf');
        });
    };
    $queriesToRender = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('association.news')->assertSee('PDF öffnen');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $addPosts(2);
    $fewer = $queriesToRender();
    $addPosts(5);

    expect($queriesToRender())->toBe($fewer);
});
