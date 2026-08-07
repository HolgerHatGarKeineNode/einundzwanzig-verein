<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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
        'paid' => true,
    ]);

    Http::fake([
        'https://pay.einundzwanzig.space/*' => Http::response([], 500),
    ]);

    NostrAuth::login($pleb->pubkey);

    // With API failure, the component should show error status regardless of previous state
    // Locked properties prevent client-side tampering, so we verify the API failure handling directly
    Livewire::test('association.profile')
        ->call('listenForPayment')
        ->assertSet('invoiceStatus', null)
        ->assertSet('invoiceStatusLabel', 'Status unbekannt')
        ->assertSet('invoiceStatusVariant', 'danger')
        ->assertSet('invoiceStatusMessage', 'Die Rechnung konnte nicht überprüft werden. Bitte versuche es später erneut.')
        ->assertSet('currentYearIsPaid', true);
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
