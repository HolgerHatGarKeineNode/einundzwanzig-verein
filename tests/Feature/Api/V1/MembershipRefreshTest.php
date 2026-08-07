<?php

use App\Enums\AssociationStatus;
use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Settled', 'amount' => '21000', 'currency' => 'SATS'])]);

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
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Settled', 'amount' => '21000', 'currency' => 'SATS'])]);

    $subject = refSubject(['association_status' => AssociationStatus::ACTIVE]);

    refreshCall($subject['privkey'])['response']->assertOk();

    expect($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and((bool) $subject['payment']->fresh()->paid)->toBeTrue();
});

it('grants nothing without recorded consent to the statutes', function () {
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Settled', 'amount' => '21000', 'currency' => 'SATS'])]);

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
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Expired', 'amount' => '21000', 'currency' => 'SATS'])]);

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
     *
     * CHANGED IN P5, and the change is the point rather than a concession:
     * `paid` is now taken back and a Storno row records that it was — because
     * BTCPay disowning an invoice is a fact, and leaving `paid` standing would
     * make the books claim money the association no longer has. What the test
     * still guards is what it always guarded: NOTHING IS DESTROYED. The row
     * survives, the invoice reference survives, the amount and year survive in
     * the reversal, and the membership category is not touched.
     *
     * The reversal runs on the refresh path and not only in the webhook on
     * purpose. Both ask BTCPay the same question; if only one of them acted on
     * the answer, the same fact would leave the database in two different
     * states depending on who happened to ask — which is the second truth this
     * whole phase exists to prevent.
     */
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'Invalid', 'amount' => '21000', 'currency' => 'SATS'])]);

    $subject = refSubject(paid: true, attributes: ['association_status' => AssociationStatus::PASSIVE]);

    refreshCall($subject['privkey'])['response']->assertOk();

    $payment = PaymentEvent::query()->find($subject['payment']->id);

    expect($payment)->not->toBeNull()
        ->and($payment->btc_pay_invoice)->toBe('inv-open')
        ->and((bool) $payment->paid)->toBeFalse()
        // The original incoming payment is still on the record, in full.
        ->and($payment->reversals()->count())->toBe(1)
        ->and((int) $payment->reversals()->first()->amount)->toBe(21000)
        ->and((int) $payment->reversals()->first()->year)->toBe((int) now()->year)
        // A payment provider does not end memberships (Art. 4.2).
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);
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
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-open', 'status' => 'New', 'amount' => '21000', 'currency' => 'SATS'])]);

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

it('applies the amount check on the refresh path too, not only in the webhook', function () {
    /*
     * THE POINT OF PUTTING THE CHECKS AT THE BOTTLENECK. `refresh` was, in
     * P4, the only API route from money to membership, and it verified
     * nothing: BTCPay reporting `Settled` on an invoice worth one satoshi set
     * `paid` and promoted the caller. The rule now lives in
     * MembershipService::markPaid(), which both this endpoint and the webhook
     * go through — so this test and its twin in BtcPayWebhookHardeningTest
     * exercise the SAME guard from two different doors.
     */
    Http::fake(['pay.einundzwanzig.space/*' => Http::response([
        'id' => 'inv-open', 'status' => 'Settled', 'amount' => '1', 'currency' => 'SATS',
    ])]);

    $subject = refSubject(['association_status' => AssociationStatus::DEFAULT]);

    refreshCall($subject['privkey'])['response']->assertOk();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and(MembershipGrant::query()->count())->toBe(0);

    $review = DB::table('payment_reviews')
        ->where('payment_event_id', $subject['payment']->id)
        ->first();

    expect($review)->not->toBeNull()
        ->and($review->reason)->toBe('amount_mismatch')
        ->and((int) $review->expected_amount)->toBe(21000)
        ->and($review->observed_amount)->toBe('1')
        // Same rule, different door — and the row says which one it came in by.
        ->and($review->source)->toBe('refresh');
});

it('does not promote through refresh on a settled fee for a past year', function () {
    /*
     * The measured 1970 case, reached through the endpoint rather than the
     * service. `refresh` accepts any year on purpose — an unsettled invoice
     * from a previous year is still worth resolving — which is exactly why the
     * year limit cannot live in the invoice endpoint alone.
     */
    Http::fake(['pay.einundzwanzig.space/*' => Http::response([
        'id' => 'inv-1970', 'status' => 'Settled', 'amount' => '21000', 'currency' => 'SATS',
    ])]);

    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    $pleb = EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => Carbon::parse('2026-03-01 12:00:00'),
        'applied_at' => Carbon::parse('2026-03-01 12:00:00'),
    ]);

    $payment = PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => 1970,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => 'inv-1970',
    ]);

    refreshCall($privkey, 1970)['response']->assertOk();

    // The fee is booked — the money did arrive — but a 1970 fee buys no seat
    // in the association of today.
    expect((bool) $payment->fresh()->paid)->toBeTrue()
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and(MembershipGrant::query()->count())->toBe(0);
});

it('does not destroy the Storno when the same invalid invoice is refreshed twice', function () {
    /*
     * F1, measured by the security-auditor and reproduced here. No attacker
     * needed and no admin either — the member's own profile page polls this.
     *
     * The trap is that the Storno sets `paid = false`, and the branch that
     * frees an expired fee year keyed its refusal on exactly that flag. So the
     * second call saw an unpaid record with a dead invoice, deleted it, and
     * `payment_reversals` plus `membership_grants` went with it through their
     * cascades — leaving a PASSIVE member with no record of a payment, no
     * record of its reversal and no record of why they were promoted.
     *
     * The membership category survives, which is what makes it silent: nothing
     * about the row looks wrong afterwards.
     */
    Http::fake(['pay.einundzwanzig.space/*' => Http::response([
        'id' => 'inv-open', 'status' => 'Invalid', 'amount' => '21000', 'currency' => 'SATS',
    ])]);

    $subject = refSubject(paid: true, attributes: ['association_status' => AssociationStatus::PASSIVE]);

    MembershipGrant::create([
        'einundzwanzig_pleb_id' => $subject['pleb']->id,
        'payment_event_id' => $subject['payment']->id,
        'from_status' => AssociationStatus::DEFAULT,
        'to_status' => AssociationStatus::PASSIVE,
        'year' => (int) now()->year,
        'granted_at' => now(),
    ]);

    refreshCall($subject['privkey'])['response']->assertOk();

    $afterFirst = [
        'payments' => PaymentEvent::query()->count(),
        'reversals' => DB::table('payment_reversals')->count(),
        'grants' => MembershipGrant::query()->count(),
    ];

    expect($afterFirst)->toBe(['payments' => 1, 'reversals' => 1, 'grants' => 1]);

    // The second call. Same endpoint, same upstream answer, nothing new to do.
    refreshCall($subject['privkey'])['response']->assertOk();

    expect([
        'payments' => PaymentEvent::query()->count(),
        'reversals' => DB::table('payment_reversals')->count(),
        'grants' => MembershipGrant::query()->count(),
    ])->toBe($afterFirst)
        ->and(PaymentEvent::query()->find($subject['payment']->id))->not->toBeNull()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);

    // And a third, because "survives once" is not the claim being made.
    refreshCall($subject['privkey'])['response']->assertOk();

    expect(PaymentEvent::query()->find($subject['payment']->id))->not->toBeNull()
        ->and(DB::table('payment_reversals')->count())->toBe(1);
});

it('reports on a past year without rewriting its fee', function () {
    /*
     * R1. The year limit was written into the reconciliation command — one of
     * three callers of `releaseExpiredInvoice()`. This endpoint was another,
     * and it accepts any four-digit year by design ("an unsettled invoice from
     * a previous year is still worth resolving"). A member could therefore
     * refresh their own 2023 fee, BTCPay would answer `Expired`, and the row
     * was deleted and re-created at TODAY's amount — destroying the exact
     * reference `verifyPayment()` measures incoming money against.
     *
     * The endpoint must still ANSWER. It only reads; what it may no longer do
     * is destroy.
     */
    Http::fake(['pay.einundzwanzig.space/*' => Http::response([
        'id' => 'inv-old', 'status' => 'Expired', 'amount' => '15000', 'currency' => 'SATS',
    ])]);

    Queue::fake();

    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);
    $pastYear = (int) now()->year - 3;

    $pleb = EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
        'statutes_accepted_at' => Carbon::parse('2026-03-01 12:00:00'),
    ]);

    $payment = PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => $pastYear,
        'amount' => 15000,
        'paid' => false,
        'event_id' => 'nostr-abc',
        'btc_pay_invoice' => 'inv-old',
    ]);

    $before = $payment->only(['id', 'amount', 'event_id', 'btc_pay_invoice', 'created_at']);

    refreshCall($privkey, $pastYear)['response']->assertOk();

    $after = PaymentEvent::query()->find($payment->id);

    expect($after)->not->toBeNull()
        ->and($after->only(['id', 'amount', 'event_id', 'btc_pay_invoice', 'created_at']))->toEqual($before)
        ->and(PaymentEvent::query()->where('year', $pastYear)->count())->toBe(1);

    // A re-created row would have queued a fresh kind-32121 publication for a
    // years-old fee.
    Queue::assertNothingPushed();
});

it('still frees the CURRENT fee year when its invoice expires', function () {
    /*
     * The discriminating counterpart to the test above: a guard that simply
     * stopped releasing anything would pass that one and quietly break the
     * ordinary "my checkout expired, let me start a new one" path.
     */
    Http::fake(['pay.einundzwanzig.space/*' => Http::response([
        'id' => 'inv-open', 'status' => 'Expired', 'amount' => '21000', 'currency' => 'SATS',
    ])]);

    $subject = refSubject();

    refreshCall($subject['privkey'])['response']->assertOk();

    expect($subject['pleb']->fresh()->paymentEvents()->where('year', (int) now()->year)->value('btc_pay_invoice'))
        ->toBeNull();
});
