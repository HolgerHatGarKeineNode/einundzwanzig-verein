<?php

use App\Auth\NostrUser;
use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Support\Board;
use App\Support\NostrAuth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Livewire;

/**
 * Derived from the one board source instead of being spelled out again —
 * a second literal list in the tests would reintroduce exactly the drift
 * the production code just got rid of.
 */
const ALLOWED_ADMIN_PUBKEY = '0adf67475ccc5ca456fd3022e46f5d526eb0af6284bf85494c0dd7847f3e5033';

it('denies access to unauthorized users', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.members.admin')
        ->assertSet('isAllowed', false)
        ->assertSee('Mitglieder können nicht bearbeitet werden');
});

it('grants access to every configured board pubkey', function () {
    $pubkeys = Board::pubkeys();

    expect($pubkeys)->not->toBeEmpty()
        ->and($pubkeys)->toHaveCount(count(Board::npubs()));

    foreach ($pubkeys as $pubkey) {
        $pleb = EinundzwanzigPleb::factory()->create([
            'pubkey' => $pubkey,
        ]);

        NostrAuth::login($pleb->pubkey);

        Livewire::test('association.members.admin')
            ->assertSet('isAllowed', true);
    }
});

it('answers board membership identically for the model, the policy user and the admin screen', function () {
    foreach (Board::npubs() as $index => $npub) {
        $pubkey = Board::pubkeys()[$index];

        $pleb = EinundzwanzigPleb::factory()->create([
            'npub' => $npub,
            'pubkey' => $pubkey,
        ]);

        NostrAuth::login($pubkey);

        expect($pleb->isBoardMember())->toBeTrue()
            ->and((new NostrUser($pubkey))->isBoardMember())->toBeTrue();

        Livewire::test('association.members.admin')
            ->assertSet('isAllowed', true);
    }
});

it('denies the member admin screen to a pubkey that is not on the board', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.members.admin')
        ->assertSet('isAllowed', false)
        ->call('exportCsv')
        ->assertForbidden();
});

it('reflects an authorized nostr session on mount', function () {
    $allowedPubkey = '0adf67475ccc5ca456fd3022e46f5d526eb0af6284bf85494c0dd7847f3e5033';
    EinundzwanzigPleb::factory()->create([
        'pubkey' => $allowedPubkey,
    ]);

    NostrAuth::login($allowedPubkey);

    Livewire::test('association.members.admin')
        ->assertSet('isAllowed', true)
        ->assertSet('currentPubkey', $allowedPubkey);
});

it('clears state on nostr logout', function () {
    $allowedPubkey = '0adf67475ccc5ca456fd3022e46f5d526eb0af6284bf85494c0dd7847f3e5033';
    EinundzwanzigPleb::factory()->create([
        'pubkey' => $allowedPubkey,
    ]);

    NostrAuth::login($allowedPubkey);

    Livewire::test('association.members.admin')
        ->call('handleNostrLoggedOut')
        ->assertSet('isAllowed', false)
        ->assertSet('currentPubkey', null);
});

it('displays einundzwanzig pleb table when authorized', function () {
    $allowedPubkey = '0adf67475ccc5ca456fd3022e46f5d526eb0af6284bf85494c0dd7847f3e5033';
    $pleb = EinundzwanzigPleb::factory()->create([
        'pubkey' => $allowedPubkey,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.members.admin')
        ->assertSet('isAllowed', true)
        ->assertSee('einundzwanzig-pleb-table');
});

it('does not load the member list for unauthorized visitors', function () {
    EinundzwanzigPleb::factory()->count(3)->create();

    Livewire::test('association.members.admin')
        ->assertSet('isAllowed', false)
        ->assertDontSee('einundzwanzig-pleb-table');
});

it('forbids guests from exporting the member CSV', function () {
    Livewire::test('association.members.admin')
        ->call('exportCsv')
        ->assertForbidden();
});

it('forbids unauthorized members from exporting the member CSV', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.members.admin')
        ->call('exportCsv')
        ->assertForbidden();
});

it('forbids unauthorized members from accepting an application', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.members.admin')
        ->call('acceptPleb')
        ->assertForbidden();
});

it('forbids unauthorized members from rejecting an application', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.members.admin')
        ->call('deletePleb')
        ->assertForbidden();
});

it('lets an authorized member pass the authorization guard', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'pubkey' => ALLOWED_ADMIN_PUBKEY,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.members.admin')
        ->call('acceptPleb')
        ->assertStatus(200)
        ->assertHasNoErrors();
});

it('does nothing when accepting a pleb without a pending application', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => ALLOWED_ADMIN_PUBKEY]);

    $applicant = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::PASSIVE,
    ]);

    expect($applicant->application_for)->toBeNull();

    NostrAuth::login(ALLOWED_ADMIN_PUBKEY);

    Livewire::test('association.members.admin')
        ->call('accept', $applicant->id)
        ->call('acceptPleb')
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->assertSet('confirmAcceptId', null);

    $fresh = $applicant->fresh();

    expect($fresh->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and($fresh->application_for)->toBeNull()
        ->and($fresh->archived_application_text)->toBeNull();
});

it('raises the status of a pleb with a pending application', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => ALLOWED_ADMIN_PUBKEY]);

    $applicant = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::PASSIVE,
        'application_for' => AssociationStatus::ACTIVE->value,
        'application_text' => 'Ich moechte aktiv mitarbeiten.',
    ]);

    NostrAuth::login(ALLOWED_ADMIN_PUBKEY);

    Livewire::test('association.members.admin')
        ->call('accept', $applicant->id)
        ->call('acceptPleb')
        ->assertHasNoErrors();

    $fresh = $applicant->fresh();

    expect($fresh->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and($fresh->application_for)->toBeNull()
        ->and($fresh->application_text)->toBeNull()
        ->and($fresh->archived_application_text)->toBe('Ich moechte aktiv mitarbeiten.');
});

it('never demotes a pleb below the status they already hold', function (int $applicationFor) {
    EinundzwanzigPleb::factory()->create(['pubkey' => ALLOWED_ADMIN_PUBKEY]);

    $honorary = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::HONORARY,
        'application_for' => $applicationFor,
    ]);

    NostrAuth::login(ALLOWED_ADMIN_PUBKEY);

    Livewire::test('association.members.admin')
        ->call('accept', $honorary->id)
        ->call('acceptPleb')
        ->assertHasNoErrors();

    expect($honorary->fresh()->association_status)->toBe(AssociationStatus::HONORARY);
})->with([
    'default' => AssociationStatus::DEFAULT->value,
    'passive' => AssociationStatus::PASSIVE->value,
    'active' => AssociationStatus::ACTIVE->value,
    'honorary' => AssociationStatus::HONORARY->value,
]);

it('ignores an application for a status value that does not exist', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => ALLOWED_ADMIN_PUBKEY]);

    $applicant = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::PASSIVE,
        'application_for' => 99,
    ]);

    NostrAuth::login(ALLOWED_ADMIN_PUBKEY);

    Livewire::test('association.members.admin')
        ->call('accept', $applicant->id)
        ->call('acceptPleb')
        ->assertStatus(200)
        ->assertHasNoErrors();

    $fresh = $applicant->fresh();

    expect($fresh->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and($fresh->application_for)->toBe(99);
});

it('paginates the member list instead of loading everything', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => ALLOWED_ADMIN_PUBKEY]);
    EinundzwanzigPleb::factory()->count(30)->create();

    NostrAuth::login(ALLOWED_ADMIN_PUBKEY);

    $component = Livewire::test('association.members.admin')
        ->assertSet('isAllowed', true);

    expect($component->instance()->plebs)
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($component->instance()->plebs->perPage())->toBe(25)
        ->and($component->instance()->plebs->total())->toBe(31)
        ->and($component->instance()->plebs->count())->toBe(25);
});

it('resets to the first page when searching', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => ALLOWED_ADMIN_PUBKEY]);
    EinundzwanzigPleb::factory()->count(30)->create();

    NostrAuth::login(ALLOWED_ADMIN_PUBKEY);

    $component = Livewire::test('association.members.admin')
        ->call('gotoPage', 2);

    expect($component->instance()->plebs->currentPage())->toBe(2);

    $component->set('search', 'anything');

    expect($component->instance()->plebs->currentPage())->toBe(1);
});

it('filters the member list by npub via search', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => ALLOWED_ADMIN_PUBKEY]);
    $needle = EinundzwanzigPleb::factory()->create(['npub' => 'npubneedle123']);
    EinundzwanzigPleb::factory()->count(3)->create();

    NostrAuth::login(ALLOWED_ADMIN_PUBKEY);

    $component = Livewire::test('association.members.admin')
        ->set('search', 'npubneedle');

    expect($component->instance()->plebs->total())->toBe(1)
        ->and($component->instance()->plebs->first()->id)->toBe($needle->id);
});

it('sorts by the profile name relation without breaking', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => ALLOWED_ADMIN_PUBKEY]);

    $plebA = EinundzwanzigPleb::factory()->create();
    Profile::factory()->create(['pubkey' => $plebA->pubkey, 'name' => 'Alice']);

    $plebZ = EinundzwanzigPleb::factory()->create();
    Profile::factory()->create(['pubkey' => $plebZ->pubkey, 'name' => 'Zoe']);

    NostrAuth::login(ALLOWED_ADMIN_PUBKEY);

    $component = Livewire::test('association.members.admin')
        ->call('sort', 'name')
        ->assertSet('sortBy', 'name')
        ->assertSet('sortDirection', 'asc')
        ->assertHasNoErrors();

    $names = $component->instance()->plebs->pluck('profile.name')->filter()->values()->all();

    expect($names)->toBe(['Alice', 'Zoe']);
});

it('ignores sort requests for non-whitelisted columns', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => ALLOWED_ADMIN_PUBKEY]);

    NostrAuth::login(ALLOWED_ADMIN_PUBKEY);

    Livewire::test('association.members.admin')
        ->call('sort', 'email')
        ->assertSet('sortBy', 'association_status')
        ->assertHasNoErrors();
});

it('forbids unauthorized members from reading the paginated list', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.members.admin')
        ->call('sort', 'name')
        ->assertForbidden();
});
