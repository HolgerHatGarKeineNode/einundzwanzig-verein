<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Services\Nostr\EventSigning;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

/*
 * The association's own nostr.json, which this screen fetches whenever a save
 * leaves the member with a NIP-05 handle set
 * (`NostrFetcherTrait::getNip05HandlesForPubkey()`, called from three places in
 * profile.blade.php).
 *
 * It is faked here rather than in the three tests that trigger it, because
 * nothing about those tests announces the request: it is fired from the
 * component after `saveNip05Handle()` succeeds, not from anything the test
 * writes. Any future test that saves a handle would reopen the same hole.
 *
 * Until P7 armed `Http::preventStrayRequests()` this call really did leave the
 * machine on every run of `it can save nip05 handle`, `it can save null nip05
 * handle` and `it still allows an underscore inside a handle on the profile
 * screen` (measured across the full suite via the HTTP client's
 * RequestSending/ResponseReceived events). It stayed invisible because the
 * fetcher wraps the call in `catch (\Exception) { return []; }` — the same
 * catch that would swallow the StrayRequestException, so the guard alone would
 * never have made these tests red either.
 *
 * An empty `names` map is the truthful answer, not a convenient one: the
 * members in these tests are factory rows that exist for milliseconds, so the
 * published nostr.json cannot list them. The screen's verification therefore
 * comes out unverified — exactly the outcome the tests already assert against,
 * now for a stated reason instead of by accident.
 *
 * The pattern is the same form the BTCPay fakes in this file use, and it does
 * not collide with them: `pay.einundzwanzig.space/*` never matches a
 * `/.well-known/` path, and this one never matches a store endpoint.
 */
beforeEach(function () {
    Http::fake(['einundzwanzig.space/.well-known/*' => Http::response(['names' => []], 200)]);
});

it('rejects non-string values for the fax field', function () {
    Livewire::test('association.profile')
        ->set('fax', [])
        ->assertStatus(422);
});

it('lists all member benefits for guests with a link to the benefits page', function () {
    Livewire::test('association.profile')
        ->assertSee('Nostr Relay')
        ->assertSee('NIP-05 Verifizierung')
        ->assertSee('Lightning Watchtower')
        ->assertSee('Blossom Medienserver')
        ->assertSee('Nostr Community-Gruppe')
        ->assertSee('Buzz Relay')
        ->assertSeeHtml(route('association.benefits'));
});

it('flags the buzz relay as experimental in the profile teaser', function () {
    Livewire::test('association.profile')
        ->assertSee('Experimentell')
        ->assertSee('Testbetrieb · Desktop App nötig', escape: false);
});

it('rejects non-string values for the email field', function () {
    Livewire::test('association.profile')
        ->set('profileForm.email', [])
        ->assertStatus(422);
});

it('rejects non-string values for the nip05Handle field', function () {
    Livewire::test('association.profile')
        ->set('profileForm.nip05Handle', [])
        ->assertStatus(422);
});

it('reflects an authenticated nostr session on mount', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertSet('currentPubkey', $pleb->pubkey)
        ->assertSet('currentPleb.pubkey', $pleb->pubkey);
});

it('initializes payment state for an active member on mount', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    expect($pleb->paymentEvents()->count())->toBe(0);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertSet('currentPubkey', $pleb->pubkey)
        ->assertSet('currentPleb.pubkey', $pleb->pubkey)
        ->assertSet('amountToPay', config('app.env') === 'production' ? 21000 : 1);

    expect($pleb->paymentEvents()->count())->toBeGreaterThan(0);
});

it('clears state on nostr logout', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->call('handleNostrLoggedOut')
        ->assertSet('currentPubkey', null)
        ->assertSet('currentPleb', null);
});

it('renders without error for a logged-in pubkey that has no pleb record', function () {
    NostrAuth::login(str_repeat('a', 64));

    Livewire::test('association.profile')
        ->assertSet('currentPubkey', str_repeat('a', 64))
        ->assertSet('currentPleb', null)
        ->assertStatus(200);
});

it('can save email address', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('profileForm.email', 'test@example.com')
        ->call('saveEmail')
        ->assertHasNoErrors();

    expect($pleb->fresh()->email)->toBe('test@example.com');
});

it('validates email format', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('profileForm.email', 'invalid-email')
        ->call('saveEmail')
        ->assertHasErrors(['profileForm.email']);
});

it('can save nip05 handle', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('profileForm.nip05Handle', 'test-handle')
        ->call('saveNip05Handle')
        ->assertHasNoErrors();

    expect($pleb->fresh()->nip05_handle)->toBe('test-handle');
});

it('validates nip05 handle format', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('profileForm.nip05Handle', 'invalid handle with spaces')
        ->call('saveNip05Handle')
        ->assertHasErrors(['profileForm.nip05Handle']);
});

it('validates nip05 handle uniqueness', function () {
    $pleb1 = EinundzwanzigPleb::factory()->active()->create([
        'nip05_handle' => 'taken-handle',
    ]);

    $pleb2 = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb2->pubkey);

    Livewire::test('association.profile')
        ->set('profileForm.nip05Handle', 'taken-handle')
        ->call('saveNip05Handle')
        ->assertHasErrors(['profileForm.nip05Handle']);
});

it('can save null nip05 handle', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create([
        'nip05_handle' => 'old-handle',
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('profileForm.nip05Handle', null)
        ->call('saveNip05Handle')
        ->assertHasNoErrors();

    expect($pleb->fresh()->nip05_handle)->toBeNull();
});

it('can update no email preference', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('no', true)
        ->assertSet('showEmail', false);

    expect((bool) $pleb->fresh()->no_email)->toBeTrue();
});

it('can save membership application', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('form.check', true)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $pleb->fresh();

    expect($fresh->applied_at)->not->toBeNull()
        ->and($fresh->statutes_accepted_at)->not->toBeNull()
        ->and($fresh->association_status)->toBe(AssociationStatus::DEFAULT);
});

it('creates payment event when pleb becomes active', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertSet('amountToPay', config('app.env') === 'production' ? 21000 : 1);

    expect($pleb->paymentEvents()->count())->toBeGreaterThan(0);
});

it('displays paid status for current year', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->call('listenForPayment')
        ->assertSet('currentYearIsPaid', true);
});

it('can initiate payment', function () {
    Http::fake([
        'pay.einundzwanzig.space/*' => Http::response([
            'id' => 'invoice123',
            'checkoutLink' => 'https://pay.einundzwanzig.space/checkout/invoice123',
        ], 200),
    ]);

    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    $response = Livewire::test('association.profile')
        ->call('pay', 'test-comment');

    $response->assertRedirect();
});

it('removes expired invoices so a fresh payment event is available', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $pleb->paymentEvents()->create([
        'year' => date('Y'),
        'amount' => 21000,
        'event_id' => 'event-old',
        'btc_pay_invoice' => 'invoice-old',
    ]);

    Http::fake([
        'https://pay.einundzwanzig.space/*' => Http::response([
            'id' => 'invoice-old',
            'status' => 'Expired',
            'expirationTime' => now()->subMinutes(5)->toIso8601String(),
            'monitoringExpiration' => now()->toIso8601String(),
        ], 200),
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertSet('invoiceStatus', 'Expired')
        ->assertSet('invoiceStatusVariant', 'warning');

    $pleb->refresh();

    expect($pleb->paymentEvents()->count())->toBe(1)
        ->and($pleb->paymentEvents()->first()->btc_pay_invoice)->toBeNull();
});

it('shows invoice status details including remaining validity', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $pleb->paymentEvents()->create([
        'year' => date('Y'),
        'amount' => 21000,
        'event_id' => 'event-status',
        'btc_pay_invoice' => 'invoice-new',
    ]);

    Http::fake([
        'https://pay.einundzwanzig.space/*' => Http::response([
            'id' => 'invoice-new',
            'status' => 'New',
            'expirationTime' => now()->addMinutes(30)->toIso8601String(),
            'monitoringExpiration' => now()->addHours(2)->toIso8601String(),
        ], 200),
    ]);

    NostrAuth::login($pleb->pubkey);

    $component = Livewire::test('association.profile')
        ->call('listenForPayment')
        ->assertSet('invoiceStatus', 'New')
        ->assertSet('invoiceStatusVariant', 'info');

    expect($component->get('invoiceExpiresAt'))->not->toBeNull()
        ->and($component->get('invoiceExpiresIn'))->not->toBeNull();
});

it('handles settled invoice with numeric expiration timestamps', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $pleb->paymentEvents()->create([
        'year' => date('Y'),
        'amount' => 21000,
        'event_id' => 'event-real',
        'btc_pay_invoice' => 'invoice-real',
    ]);

    Http::fake([
        'https://pay.einundzwanzig.space/*' => Http::response([
            'id' => 'invoice-real',
            'status' => 'Settled',
            'additionalStatus' => 'None',
            'monitoringExpiration' => now()->addDay()->timestamp,
            'expirationTime' => now()->addHour()->timestamp,
            'createdTime' => now()->subDay()->timestamp,
            'amount' => '21000',
            'currency' => 'SATS',
            'paidAmount' => '21000',
        ], 200),
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->call('listenForPayment')
        ->assertSet('invoiceStatus', 'Settled')
        ->assertSet('invoiceStatusVariant', 'success')
        ->assertSet('currentYearIsPaid', true)
        ->assertSet('invoiceStatusMessage', 'Zahlung bestätigt. Danke!');
});

it('does not show stale settled status when invoice check fails', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $pleb->paymentEvents()->create([
        'year' => date('Y'),
        'amount' => 21000,
        'event_id' => 'event-fail',
        'btc_pay_invoice' => 'invoice-fail',
        'paid' => false,
    ]);

    Http::fake([
        'https://pay.einundzwanzig.space/*' => Http::response([], 500),
    ]);

    NostrAuth::login($pleb->pubkey);

    // An open invoice is read from BTCPay on page load; when that fails, the
    // page says so instead of guessing. The poll reads the database only and
    // leaves that message standing.
    Livewire::test('association.profile')
        ->call('listenForPayment')
        ->assertSet('invoiceStatus', null)
        ->assertSet('invoiceStatusLabel', 'Status unbekannt')
        ->assertSet('invoiceStatusVariant', 'danger')
        ->assertSet('invoiceStatusMessage', 'Die Rechnung konnte nicht überprüft werden. Bitte versuche es später erneut.')
        ->assertSet('currentYearIsPaid', false);
});

it('never asks btcpay about a fee our database has as paid', function () {
    /*
     * Owner decision of 2026-11-24, verbatim: "Wenn Webhook meldet, dass
     * bezahlt, dann ist bezahlt! Lightning Invoices sind nicht rückholbar!
     * Heißt eine weitere Abfrage auf BTCPay ist nicht mehr notwendig." Until
     * then a failing BTCPay turned a paid fee into "Status unbekannt".
     */
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $pleb->paymentEvents()->create([
        'year' => date('Y'),
        'amount' => 21000,
        'event_id' => 'event-paid',
        'btc_pay_invoice' => 'invoice-paid',
        'paid' => true,
    ]);

    Http::fake([
        'https://pay.einundzwanzig.space/*' => Http::response([], 500),
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->call('listenForPayment')
        ->assertSet('invoiceStatus', 'Settled')
        ->assertSet('invoiceStatusVariant', 'success')
        ->assertSet('invoiceStatusMessage', 'Zahlung bestätigt. Danke!')
        ->assertSet('currentYearIsPaid', true);

    Http::assertNothingSent();
});

it('picks up a fee the webhook booked while the page was open, from the database only', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'applied_at' => now(),
    ]);

    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => date('Y'),
        'amount' => 21000,
        'event_id' => 'event-open',
        'btc_pay_invoice' => 'invoice-open',
        'paid' => false,
    ]);

    Http::fake([
        'https://pay.einundzwanzig.space/*' => Http::response([
            'id' => 'invoice-open',
            'status' => 'New',
            'expirationTime' => now()->addMinutes(30)->toIso8601String(),
        ], 200),
    ]);

    NostrAuth::login($pleb->pubkey);

    $component = Livewire::test('association.profile')
        ->assertSet('invoiceStatus', 'New')
        ->assertSet('currentYearIsPaid', false);

    // Page load read the open invoice once; the poll must not ask again.
    Http::assertSentCount(1);

    $component->call('listenForPayment')->assertSet('currentYearIsPaid', false);

    // The webhook books the fee and makes the applicant a member.
    $paymentEvent->forceFill(['paid' => true, 'paid_at' => now()])->save();
    $pleb->forceFill(['association_status' => AssociationStatus::PASSIVE])->save();

    $component->call('listenForPayment')
        ->assertSet('currentYearIsPaid', true)
        ->assertSet('invoiceStatus', 'Settled')
        ->assertSet('invoiceStatusVariant', 'success');

    expect($component->get('currentPleb')->association_status)->toBe(AssociationStatus::PASSIVE);

    Http::assertSentCount(1);
});

it('renders the payment poll only while the current year is unpaid and an invoice is open', function () {
    $poll = 'wire:poll.20s="listenForPayment"';

    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => date('Y'),
        'amount' => 21000,
        'event_id' => 'event-poll',
        'btc_pay_invoice' => 'invoice-poll',
        'paid' => false,
    ]);

    Http::fake([
        'https://pay.einundzwanzig.space/*' => Http::response([
            'id' => 'invoice-poll',
            'status' => 'New',
            'expirationTime' => now()->addMinutes(30)->toIso8601String(),
        ], 200),
    ]);

    NostrAuth::login($pleb->pubkey);

    $component = Livewire::test('association.profile')->assertSeeHtml($poll);

    // The webhook books the fee: the next poll sees it and the poll goes away.
    $paymentEvent->forceFill(['paid' => true, 'paid_at' => now()])->save();

    $component->call('listenForPayment')->assertDontSeeHtml($poll);

    // Paid on page load: nothing to wait for from the start.
    Livewire::test('association.profile')->assertDontSeeHtml($poll);
});

it('renders no payment poll without an open invoice', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    // No invoice started yet.
    Livewire::test('association.profile')->assertDontSeeHtml('wire:poll.20s="listenForPayment"');

    $pleb->paymentEvents()->where('year', date('Y'))->update(['btc_pay_invoice' => 'invoice-dead']);

    Http::fake([
        'https://pay.einundzwanzig.space/*' => Http::response([
            'id' => 'invoice-dead',
            'status' => 'Expired',
            'expirationTime' => now()->subMinutes(5)->toIso8601String(),
        ], 200),
    ]);

    // A dead invoice is freed on page load; nothing is open to wait for.
    Livewire::test('association.profile')->assertDontSeeHtml('wire:poll.20s="listenForPayment"');
});

it('shows the pay button when the relay does not answer, without the fee event text', function () {
    config(['services.relay' => 'wss://relay.example/']);
    $relay = FakeRelayTransport::bind()->withUnansweringRelay('wss://relay.example/');

    $pleb = EinundzwanzigPleb::factory()->active()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertSet('feeEventContent', null)
        ->assertSee(__(':amount Sats bezahlen', ['amount' => config('einundzwanzig.config.membership_fee')]))
        ->assertDontSee('Unser Nostr-Relay konnte derzeit nicht erreicht werden');

    // The miss is cached too: the next page load does not wait on the relay again.
    Livewire::test('association.profile');

    expect($relay->fetched())->toHaveCount(1);
});

it('reads the fee event text once and serves it from the cache', function () {
    config(['services.relay' => 'wss://relay.example/']);
    $relay = FakeRelayTransport::bind()->withStoredEvents('wss://relay.example/', [
        EventSigning::sign(testPrivateKey(), 32121, [], 'Dieses Event dient der Zahlung des Mitgliedsbeitrags.', time()),
    ]);

    $pleb = EinundzwanzigPleb::factory()->active()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertSet('feeEventContent', 'Dieses Event dient der Zahlung des Mitgliedsbeitrags.')
        ->assertSee('Dieses Event dient der Zahlung des Mitgliedsbeitrags.');

    Livewire::test('association.profile')
        ->assertSet('feeEventContent', 'Dieses Event dient der Zahlung des Mitgliedsbeitrags.');

    expect($relay->fetched())->toHaveCount(1)
        ->and($relay->fetched()[0]['filter'])->toMatchArray(['kinds' => [32121], 'limit' => 1]);
});

it('refuses the reserved NIP-05 name on the profile screen', function () {
    /*
     * NIP-05 gives the name `_` a special meaning: clients render
     * `_@einundzwanzig.space` as the bare domain `einundzwanzig.space`, so
     * whoever holds that handle appears AS THE ASSOCIATION ITSELF. All three
     * write paths read the same rule from
     * `EinundzwanzigPleb::NIP05_HANDLE_RULES`; this is that rule arriving here.
     */
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('profileForm.nip05Handle', '_')
        ->call('saveNip05Handle')
        ->assertHasErrors(['profileForm.nip05Handle']);

    expect($pleb->fresh()->nip05_handle)->toBeNull();
});

it('still allows an underscore inside a handle on the profile screen', function () {
    // Only the name consisting of nothing but `_` is reserved — refusing the
    // character outright would lock existing handles out of every future save.
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->set('profileForm.nip05Handle', 'alice_bob')
        ->call('saveNip05Handle')
        ->assertHasNoErrors();

    expect($pleb->fresh()->nip05_handle)->toBe('alice_bob');
});

it('shows a member whether their Nostr DM inbox is set up and re-checks it on request, at most three times a minute', function () {
    config(['einundzwanzig.config.profile_relays' => ['wss://profiles.example/']]);
    $secret = testPrivateKey();
    $pleb = EinundzwanzigPleb::factory()->create(['pubkey' => (new Key)->getPublicKey($secret)]);
    $relay = FakeRelayTransport::bind()->withStoredEvents('wss://profiles.example/', [
        EventSigning::sign($secret, 10050, [['relay', 'wss://inbox.member.example/']], '', time()),
    ]);
    NostrAuth::login($pleb->pubkey);

    $profile = Livewire::test('association.profile')
        ->assertSeeHtml('data-test="dm-inbox-check"')
        ->assertSee('Kein Nostr-Postfach gefunden')
        ->assertDontSeeHtml('$dmInboxRelays')
        ->call('recheckDmInbox')
        ->assertSeeHtml('data-test="dm-inbox-relays"')
        ->assertSee('Dein Nostr-Postfach ist eingerichtet')
        ->assertSee('wss://inbox.member.example/');

    $profile->call('recheckDmInbox')->call('recheckDmInbox')->call('recheckDmInbox');

    expect($relay->fetched())->toHaveCount(3);
});

it('lets no guest trigger the DM inbox re-check', function () {
    $relay = FakeRelayTransport::bind();

    Livewire::test('association.profile')->call('recheckDmInbox')->assertForbidden();

    expect($relay->fetched())->toBe([]);
});
