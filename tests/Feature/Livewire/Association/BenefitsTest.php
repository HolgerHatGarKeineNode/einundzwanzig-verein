<?php

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Livewire\Livewire;

it('shows the locked state with all services for guests', function () {
    Livewire::test('association.benefits')
        ->assertSet('currentYearIsPaid', false)
        ->assertSee('Dienste gesperrt')
        ->assertSee('Blossom-Medienserver')
        ->assertSee('5 GB Speicher')
        ->assertSee('max. 1 GB pro Datei')
        ->assertSee('Buzz Relay')
        ->assertDontSee('https://blossom.einundzwanzig.space')
        ->assertDontSee('wss://buzz.einundzwanzig.space');
});

it('unlocks the blossom server for active paid members', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->assertSet('currentYearIsPaid', true)
        ->assertSee('Mitgliedschaft aktiv')
        ->assertSee('Blossom Medienserver')
        ->assertSee('https://blossom.einundzwanzig.space');
});

it('copies the blossom url for active members', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->call('copyBlossomUrl')
        ->assertHasNoErrors();
});

it('unlocks the nostr community group for active paid members', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->assertSee('Nostr Community-Gruppe')
        ->assertSee('https://group.einundzwanzig.space')
        ->assertSee('wss://group.einundzwanzig.space');
});

it('copies the community relay url for active members', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->call('copyGroupRelayUrl')
        ->assertHasNoErrors();
});

it('unlocks the buzz relay for active paid members', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->assertSee('Buzz Relay')
        ->assertSee('wss://buzz.einundzwanzig.space')
        ->assertSee('https://github.com/block/buzz/releases/latest');
});

it('flags the buzz relay as experimental', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->assertSee('Experimentell')
        ->assertSee('Testbetrieb.')
        ->assertSee('nicht als einzigen Ort für wichtige Daten', escape: false);
});

it('points buzz users to the group web app and the automatic nightly enrolment', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->assertSee('group.einundzwanzig.space')
        ->assertSee('in der Nacht darauf automatisch als Member des Buzz-Relays');
});

it('copies the buzz relay url for active members', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->call('copyBuzzRelayUrl')
        ->assertHasNoErrors();
});

it('refuses the reserved NIP-05 name on the benefits screen', function () {
    /*
     * The third write path for a handle, and it has to refuse exactly what the
     * other two refuse — `_@einundzwanzig.space` renders as the bare domain,
     * i.e. whoever holds it appears as the association. The rule comes from
     * `EinundzwanzigPleb::NIP05_HANDLE_RULES` here as well.
     */
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->set('nip05Handle', '_')
        ->call('saveNip05Handle')
        ->assertHasErrors(['nip05Handle']);

    expect($pleb->fresh()->nip05_handle)->toBeNull();
});

it('saves a well-formed handle on the benefits screen', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->set('nip05Handle', 'alice_bob')
        ->call('saveNip05Handle')
        ->assertHasNoErrors();

    expect($pleb->fresh()->nip05_handle)->toBe('alice_bob');
});
