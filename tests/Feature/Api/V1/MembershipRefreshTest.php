<?php

use App\Enums\AssociationStatus;
use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

const REF_CLIENT_KEY = 'ref111111111111111111111111111111111111111111111111111111ref11';

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => REF_CLIENT_KEY],
        'einundzwanzig.config.membership_fee' => 21000,
        'services.btc_pay.base_url' => 'https://pay.einundzwanzig.space',
        'services.btc_pay.store_id' => 'test-store',
        'app.debug' => false,
    ]);
});

/**
 * A member with an open invoice for the current fee year — the state a refresh
 * exists for.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{pleb: EinundzwanzigPleb, payment: PaymentEvent, privkey: string}
 */
function refSubject(array $attributes = [], bool $paid = false): array
{
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    $pleb = EinundzwanzigPleb::factory()->create($attributes + [
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
        'statutes_accepted_at' => Carbon::parse('2026-03-01 12:00:00'),
        'applied_at' => Carbon::parse('2026-03-01 12:00:00'),
    ]);

    $payment = PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => $paid,
        'btc_pay_invoice' => 'inv-open',
    ]);

    return ['pleb' => $pleb, 'payment' => $payment, 'privkey' => $privkey];
}

/**
 * @return array{response: TestResponse, pubkey: string, privkey: string}
 */
function refreshCall(?string $privkey = null, ?int $year = null): array
{
    $year ??= (int) now()->year;

    return apiV1SignedRequest(
        'POST',
        '/api/v1/membership/payments/'.$year.'/refresh',
        REF_CLIENT_KEY,
        $privkey,
    );
}

it('requires a NIP-98 signature', function () {
    Http::fake();

    $this->withHeaders([
        'X-Api-Key' => REF_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->post('/api/v1/membership/payments/'.now()->year.'/refresh')
        ->assertUnauthorized();

    Http::assertNothingSent();
});

it('books a settled payment and lets it constitute the membership', function () {
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Settled'])]);

    $subject = refSubject(['association_status' => AssociationStatus::DEFAULT]);

    $call = refreshCall($subject['privkey']);

    $call['response']->assertOk()
        ->assertJsonPath('data.payment.paid', true)
        ->assertJsonPath('data.created', false);

    $pleb = $subject['pleb']->fresh();

    expect((bool) $subject['payment']->fresh()->paid)->toBeTrue()
        // The promotion is the effect of the payment, run through
        // MembershipService — not a second rule restated in the controller.
        ->and($pleb->association_status)->toBe(AssociationStatus::PASSIVE)
        // And it left the audit row that names the payment behind.
        ->and(MembershipGrant::query()->where('payment_event_id', $subject['payment']->id)->exists())
        ->toBeTrue();
});

it('never lowers an active member to passive when their fee settles', function () {
    /*
     * The discriminating counterpart: a rule of the shape "paid → PASSIVE"
     * would pass the test above and demote every active member who pays.
     */
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Settled'])]);

    $subject = refSubject(['association_status' => AssociationStatus::ACTIVE]);

    refreshCall($subject['privkey'])['response']->assertOk();

    expect($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and((bool) $subject['payment']->fresh()->paid)->toBeTrue();
});

it('grants nothing without recorded consent to the statutes', function () {
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Settled'])]);

    $subject = refSubject([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => null,
    ]);

    refreshCall($subject['privkey'])['response']->assertOk();

    // The fee is booked — the money arrived and the books must say so — but no
    // membership follows without the document that carries it.
    expect((bool) $subject['payment']->fresh()->paid)->toBeTrue()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and(MembershipGrant::query()->count())->toBe(0);
});

it('answers 503 when BTCPay returns an error and leaves paid untouched', function () {
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['error' => 'boom'], 500)]);

    $subject = refSubject(['association_status' => AssociationStatus::DEFAULT]);

    $call = refreshCall($subject['privkey']);

    /*
     * Fail closed. An unreachable payment provider means the status is
     * UNKNOWN, and the only safe reading of an unknown payment is the one
     * already on record — not "settled", and not a 500 that would let a
     * client's retry loop treat the endpoint as broken beyond repair.
     */
    $call['response']->assertStatus(503);

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and(MembershipGrant::query()->count())->toBe(0);
});

it('answers 503 when BTCPay does not answer at all', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out after 10 seconds'));

    $subject = refSubject(['association_status' => AssociationStatus::DEFAULT]);

    $call = refreshCall($subject['privkey']);

    $call['response']->assertStatus(503);

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT);
});

it('frees the fee year when BTCPay reports the invoice as expired', function () {
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Expired'])]);

    $subject = refSubject();

    $call = refreshCall($subject['privkey']);

    $call['response']->assertOk()
        ->assertJsonPath('data.payment.paid', false)
        // No checkout page is left to send a payer to; the client's next step
        // is POST /invoice, which starts a fresh one.
        ->assertJsonPath('data.checkout_url', null);

    expect($subject['pleb']->fresh()->paymentEvents()->where('year', (int) now()->year)->value('btc_pay_invoice'))
        ->toBeNull();
});

it('never drops a settled fee because BTCPay calls the invoice invalid', function () {
    /*
     * No attacker needed: a manual "mark invalid" in the BTCPay backend after
     * a refund used to be enough to destroy the only proof that the fee had
     * ever been paid.
     */
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Invalid'])]);

    $subject = refSubject(paid: true);

    refreshCall($subject['privkey'])['response']->assertOk();

    $payment = PaymentEvent::query()->find($subject['payment']->id);

    expect($payment)->not->toBeNull()
        ->and((bool) $payment->paid)->toBeTrue()
        ->and($payment->btc_pay_invoice)->toBe('inv-open');
});

it('refuses a year that has no invoice to ask about', function () {
    Http::fake();

    $subject = refSubject();

    // A year with no payment event at all …
    refreshCall($subject['privkey'], year: (int) now()->year - 1)['response']
        ->assertNotFound()
        ->assertJson(['message' => ApiV1Controller::NOT_FOUND_MESSAGE]);

    // … and a year whose payment event never got an invoice.
    $subject['payment']->update(['btc_pay_invoice' => null]);

    refreshCall($subject['privkey'])['response']
        ->assertNotFound()
        ->assertJson(['message' => ApiV1Controller::NOT_FOUND_MESSAGE]);

    Http::assertNothingSent();
});

it('refuses a pubkey without a member record', function () {
    Http::fake();

    refreshCall()['response']
        ->assertNotFound()
        ->assertJson(['message' => ApiV1Controller::NOT_FOUND_MESSAGE]);

    Http::assertNothingSent();
});

it('returns only the allowed fields and never personal data', function () {
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'New'])]);

    $subject = refSubject([
        'email' => 'private@example.test',
        'application_text' => 'my private application prose',
        'archived_application_text' => 'my archived application prose',
    ]);

    $call = refreshCall($subject['privkey']);

    $call['response']->assertOk();

    expect($call['response']->json('data'))
        ->toHaveKeys(['checkout_url', 'created', 'payment'])
        ->not->toHaveKeys(['id', 'invoice', 'email', 'application_text', 'archived_application_text']);

    expect($call['response']->json('data.payment'))
        ->toHaveKeys(['year', 'amount', 'currency', 'paid', 'receipt_url'])
        ->not->toHaveKeys(['id', 'einundzwanzig_pleb_id', 'btc_pay_invoice', 'event_id']);

    expect($call['response']->getContent())
        ->not->toContain('private@example.test')
        ->not->toContain('my private application prose')
        ->not->toContain('my archived application prose')
        ->not->toContain('test-store');
});

it('keeps error responses down to a message', function () {
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['error' => 'boom'], 500)]);

    $subject = refSubject(['email' => 'private@example.test']);

    $call = refreshCall($subject['privkey']);

    $call['response']->assertStatus(503);

    expect(array_keys($call['response']->json()))->toBe(['message'])
        // Not one byte about the upstream failure: not the store id, not the
        // BTCPay body, not the invoice id.
        ->and($call['response']->getContent())
        ->not->toContain('boom')
        ->not->toContain('test-store')
        ->not->toContain('inv-open')
        ->not->toContain('private@example.test');
});
