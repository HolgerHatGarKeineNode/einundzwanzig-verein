<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Services\Nostr\Nip05Directory;
use App\Services\Nostr\Nip05Status;
use App\Support\NostrAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

it('unlocks the nostr community group for active paid members', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->assertSee('Nostr Community-Gruppe')
        ->assertSee('https://group.einundzwanzig.space')
        ->assertSee('wss://group.einundzwanzig.space');
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

it('shows the current watchtower uri for active members', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->assertSee('02ce7b8a6bc3fb6245ecdcf67b25d80d03e1fea02459ae9af9e64ac1590139e467@62.171.139.240:9911')
        ->assertDontSee('03a09f56bba3d2c200cc55eda2f1f069564a97c1fb74345e1560e2868a8ab3d7d0');
});

it('offers every service address in a read-only copy field for active members', function (string $address) {
    /*
     * One copy field for all services (P3/D13): a read-only flux:input with
     * its copy button. The browser copies; nothing round-trips to the server.
     */
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    $html = Livewire::test('association.benefits')->html();

    expect($html)->toMatch('/<input[^>]*value="'.preg_quote($address, '/').'"[^>]*readonly/s')
        ->and($html)->toContain('fluxInputCopyable');
})->with([
    'relay' => 'wss://nostr.einundzwanzig.space',
    'watchtower' => '02ce7b8a6bc3fb6245ecdcf67b25d80d03e1fea02459ae9af9e64ac1590139e467@62.171.139.240:9911',
    'blossom' => 'https://blossom.einundzwanzig.space',
    'community relay' => 'wss://group.einundzwanzig.space',
    'buzz relay' => 'wss://buzz.einundzwanzig.space',
]);

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
    Http::fake(['einundzwanzig.space/.well-known/*' => Http::response(['names' => []])]);
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.benefits')
        ->set('nip05Handle', 'alice_bob')
        ->call('saveNip05Handle')
        ->assertHasNoErrors();

    expect($pleb->fresh()->nip05_handle)->toBe('alice_bob');
});

it('reads the directory past its cache right after a handle was saved', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create(['nip05_handle' => 'alice']);
    NostrAuth::login($pleb->pubkey);
    // Mount reads the published directory; after the save the rebuilt one lists the new handle too.
    Http::fakeSequence('einundzwanzig.space/.well-known/*')
        ->push(['names' => ['alice' => $pleb->pubkey]])
        ->push(['names' => ['alice' => $pleb->pubkey, 'alice_new' => $pleb->pubkey]]);

    $component = Livewire::test('association.benefits');

    expect($component->get('nip05')->handles)->toBe(['alice']);

    $component->set('nip05Handle', 'alice_new')->call('saveNip05Handle')->assertHasNoErrors();

    expect($component->get('nip05'))->toEqual(Nip05Status::of('alice_new', ['alice', 'alice_new']))
        ->and(Cache::get(Nip05Directory::CACHE_KEY))->toHaveKey('alice_new');
    $component->assertSee('Du hast 2 aktive Handles für deinen Pubkey.');
    Http::assertSentCount(2);
});

/*
 * R5 of the page optimisation plan: the benefits belong to every paying
 * member, whatever kind of membership the board entered, and "this year" is
 * the year of the application clock (`now()`), not of `date('Y')`.
 */
it('unlocks the benefits for every paying member', function (AssociationStatus $status, bool $unlocked) {
    $pleb = EinundzwanzigPleb::factory()->withPaidCurrentYear()->create(['association_status' => $status]);
    NostrAuth::login($pleb->pubkey);

    $page = Livewire::test('association.benefits')->assertSet('currentYearIsPaid', $unlocked);

    $unlocked ? $page->assertSee('Alle Dienste unten sind für dich freigeschaltet.') : $page->assertDontSee('Alle Dienste unten sind für dich freigeschaltet.');
})->with([
    'Passiv' => [AssociationStatus::PASSIVE, true],
    'Aktiv' => [AssociationStatus::ACTIVE, true],
    'Ehrenmitglied' => [AssociationStatus::HONORARY, true],
    'no member, fee paid' => [AssociationStatus::DEFAULT, false],
]);

it('says the benefits are for paying members', function () {
    Livewire::test('association.benefits')
        ->assertSee('exklusiv für zahlende Vereinsmitglieder')
        ->assertDontSee('exklusiv für aktive Vereinsmitglieder');
});

it('counts the new year from the first of January', function () {
    $this->travelTo(Carbon::create(2031, 1, 1, 0, 30, 0, config('app.timezone')));

    $paidLastYear = EinundzwanzigPleb::factory()->active()->withPaidYear(2030)->create();
    $paidThisYear = EinundzwanzigPleb::factory()->active()->withPaidYear(2031)->create();

    NostrAuth::login($paidLastYear->pubkey);
    Livewire::test('association.benefits')->assertSet('currentYearIsPaid', false);

    NostrAuth::login($paidThisYear->pubkey);
    Livewire::test('association.benefits')->assertSet('currentYearIsPaid', true);
});

it('rejects a malformed update to the locked nip05 status as locked, not with a 500', function (mixed $payload) {
    Livewire::test('association.benefits')->set('nip05', $payload);
})->with([
    'empty array' => [[]],
    'without handles' => [['mismatch' => false]],
    'handles not a list of strings' => [['handles' => [['x']], 'mismatch' => false]],
])->throws(CannotUpdateLockedPropertyException::class);

it('restores a well-formed nip05 status unchanged from the wire', function () {
    $status = Nip05Status::fromLivewire(['handles' => ['satoshi'], 'mismatch' => true]);

    expect($status->handles)->toBe(['satoshi'])
        ->and($status->mismatch)->toBeTrue()
        ->and($status->verifiedHandle)->toBe('satoshi');
});
