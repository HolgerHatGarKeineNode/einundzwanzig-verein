<?php

declare(strict_types=1);

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Livewire\Livewire;

/*
 * The payment constitutes the membership — an application only records data
 * and the consent to the statutes. Livewire exposes every public method as a
 * directly callable endpoint, so `->call('save', <status>)` is exactly the
 * request a client can craft. None of them may move `association_status`.
 */

it('ignores a client supplied status when saving an application', function (int $status) {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('form.check', true)
        ->call('save', $status)
        ->assertHasNoErrors();

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::DEFAULT);
})->with([
    'passive' => AssociationStatus::PASSIVE->value,
    'active' => AssociationStatus::ACTIVE->value,
    'honorary' => AssociationStatus::HONORARY->value,
]);

it('records applied_at and statutes_accepted_at and leaves the status at DEFAULT', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
    ]);

    expect($pleb->applied_at)->toBeNull()
        ->and($pleb->statutes_accepted_at)->toBeNull();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('form.check', true)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $pleb->fresh();

    expect($fresh->applied_at)->not->toBeNull()
        ->and($fresh->statutes_accepted_at)->not->toBeNull()
        ->and($fresh->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and($fresh->association_status->value)->toBe(1);
});

it('refuses the application without consent to the statutes', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('form.check', false)
        ->call('save')
        ->assertHasErrors(['form.check' => 'accepted']);

    $fresh = $pleb->fresh();

    expect($fresh->applied_at)->toBeNull()
        ->and($fresh->statutes_accepted_at)->toBeNull()
        ->and($fresh->association_status)->toBe(AssociationStatus::DEFAULT);
});

/*
 * End-to-end for a brand new pubkey: nothing in the database, log in the way a
 * browser does (signed kind-22242 event), then apply. Before the member record
 * moved to the authenticated login path this deadlocked — `currentPleb` stayed
 * null, mount()/handleNostrLoggedIn() returned early and the application form
 * was unreachable, with the whole suite still green.
 */
it('lets a brand new pubkey log in and apply in one go', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $signedEvent, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge);

    expect(EinundzwanzigPleb::query()->where('pubkey', $pubkey)->exists())->toBeFalse();

    Livewire::test('association.profile')
        ->call('handleNostrLoggedIn', $signedEvent)
        ->assertSet('currentPubkey', $pubkey)
        ->assertHasNoErrors()
        ->set('form.check', true)
        ->call('save')
        ->assertHasNoErrors();

    $pleb = EinundzwanzigPleb::query()->where('pubkey', $pubkey)->firstOrFail();

    expect($pleb->applied_at)->not->toBeNull()
        ->and($pleb->statutes_accepted_at)->not->toBeNull()
        ->and($pleb->association_status)->toBe(AssociationStatus::DEFAULT);
});

it('opens the payment section to an applicant who is not a member yet', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertDontSee('Mitgliedsbeitrag')
        ->set('form.check', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Mitgliedsbeitrag');

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::DEFAULT);
});

it('keeps the original consent timestamp when applying a second time', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('form.check', true)
        ->call('save');

    $firstConsent = $pleb->fresh()->statutes_accepted_at;

    $this->travel(1)->hours();

    Livewire::test('association.profile')
        ->set('form.check', true)
        ->call('save');

    $fresh = $pleb->fresh();

    expect($fresh->statutes_accepted_at->timestamp)->toBe($firstConsent->timestamp)
        ->and($fresh->applied_at->timestamp)->toBeGreaterThan($firstConsent->timestamp);
});
