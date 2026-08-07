<?php

use App\Enums\AssociationStatus;
use App\Enums\PaymentReviewReason;
use App\Models\BtcPayWebhookDelivery;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use App\Models\PaymentReversal;
use App\Models\PaymentReview;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/*
 * P5: the webhook stops being a bookkeeping endpoint and becomes the joining
 * path. Everything here is about what that costs — the amount has to be
 * checked, the year has to be checked, the delivery has to be de-duplicated
 * for real, and both writes have to land together or not at all.
 *
 * The six pre-existing cases live in BtcPayWebhookTest.php and are untouched;
 * this file only adds.
 */

const HARD_INVOICE = 'inv-hard';

beforeEach(function () {
    config()->set('services.btc_pay.webhook_secret', 'test-secret');
    config()->set('services.btc_pay.store_id', 'store-123');
    config()->set('services.btc_pay.base_url', 'https://pay.einundzwanzig.space');
    config()->set('einundzwanzig.config.membership_fee', 21000);
    config()->set('einundzwanzig.config.currency', 'SATS');
});

/**
 * What BTCPay answers when asked about the invoice.
 *
 * `Http::swap()` and not another `Http::fake()`: fakes are APPENDED and the
 * FIRST match answers, so a second fake registered in a test would lose to one
 * set up earlier and the test would silently measure the earlier case. That
 * exact failure has been made in this project before — see
 * `invFakeBtcPay()` in tests/Feature/Api/V1/MembershipInvoiceTest.php.
 */
function hardFakeInvoice(array $overrides = []): void
{
    Http::swap(new Factory);

    Http::fake([
        'pay.einundzwanzig.space/*' => Http::response($overrides + [
            'id' => HARD_INVOICE,
            'status' => 'Settled',
            'amount' => '21000',
            'currency' => 'SATS',
        ]),
    ]);
}

/**
 * A signed delivery, shaped the way BTCPay really shapes one.
 *
 * `originalDeliveryId` is present from the FIRST delivery and equal to
 * `deliveryId` (WebhookProviderHostedService.cs:48-52); a redelivery keeps it
 * and mints a new `deliveryId` (WebhookSender.cs:96-100). Both facts are what
 * the idempotency test below exercises.
 */
function hardPost(array $payload = [], ?string $secret = 'test-secret'): TestResponse
{
    $delivery = $payload['deliveryId'] ?? 'del-1';

    $body = json_encode($payload + [
        'deliveryId' => $delivery,
        'webhookId' => 'wh-1',
        'originalDeliveryId' => $delivery,
        'isRedelivery' => false,
        // Real InvoiceSettled deliveries always carry both — see
        // swagger.template.webhooks.json. Leaving them out of the default
        // payload would make every test here measure the "store said nothing"
        // path rather than the ordinary one.
        'manuallyMarked' => false,
        'overPaid' => false,
        'type' => 'InvoiceSettled',
        'timestamp' => 1786000000,
        'storeId' => 'store-123',
        'invoiceId' => HARD_INVOICE,
    ], JSON_THROW_ON_ERROR);

    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_BTCPAY_SIG'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', '/webhooks/btcpay', [], [], [], $server, $body);
}

/**
 * An applicant with an open invoice for the current fee year.
 *
 * @return array{pleb: EinundzwanzigPleb, payment: PaymentEvent}
 */
function hardSubject(array $plebAttributes = [], array $paymentAttributes = []): array
{
    $pleb = EinundzwanzigPleb::factory()->create($plebAttributes + [
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
        'applied_at' => now(),
    ]);

    $payment = PaymentEvent::factory()->create($paymentAttributes + [
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => HARD_INVOICE,
    ]);

    return ['pleb' => $pleb, 'payment' => $payment];
}

/* ---------------------------------------------------------------- DoD 1 --- */

it('raises a DEFAULT applicant to PASSIVE and records the grant on a settled delivery', function () {
    hardFakeInvoice();

    $subject = hardSubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeTrue()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::PASSIVE)
        // Through markPaid(), not through logic of the controller's own: the
        // grant row is written by grantMembershipOnPayment() and nowhere else.
        ->and(MembershipGrant::query()->where('payment_event_id', $subject['payment']->id)->count())->toBe(1);
});

it('never lowers an active member who pays their fee', function () {
    /*
     * The discriminating counterpart to the test above. A rule shaped
     * "settled → PASSIVE" passes that one and demotes every active member.
     */
    hardFakeInvoice();

    $subject = hardSubject(['association_status' => AssociationStatus::ACTIVE]);

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeTrue()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and(MembershipGrant::query()->count())->toBe(0);
});

/* ---------------------------------------------------------------- DoD 2 --- */

it('does nothing a second time when the same delivery arrives twice', function () {
    hardFakeInvoice();

    $subject = hardSubject();

    hardPost()->assertNoContent();
    hardPost()->assertNoContent();

    expect(MembershipGrant::query()->count())->toBe(1)
        ->and(BtcPayWebhookDelivery::query()->count())->toBe(1)
        // One booking, one verification round trip at BTCPay. A second GET
        // would mean the second delivery ran the whole path again and merely
        // happened to land on the same result.
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);

    Http::assertSentCount(1);
});

it('treats a REDELIVERY as the same event, even though its deliveryId is new', function () {
    /*
     * THE CASE THAT MAKES THE KEY CHOICE VISIBLE. BTCPay retries on 5xx, 429,
     * 408 and connection errors, and every attempt carries a FRESH
     * `deliveryId` while `originalDeliveryId` stays put
     * (WebhookSender.cs:90-100, master, fetched 2026-08-07). Keyed on
     * `deliveryId` this would be a second, fully effective delivery — and the
     * naive choice looks correct until exactly this payload arrives.
     */
    hardFakeInvoice();

    $subject = hardSubject();

    hardPost(['deliveryId' => 'del-first', 'originalDeliveryId' => 'del-first'])->assertNoContent();

    hardPost([
        'deliveryId' => 'del-second-attempt',
        'originalDeliveryId' => 'del-first',
        'isRedelivery' => true,
    ])->assertNoContent();

    expect(MembershipGrant::query()->count())->toBe(1)
        ->and(BtcPayWebhookDelivery::query()->count())->toBe(1)
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);

    Http::assertSentCount(1);
});

/* ---------------------------------------------------------------- DoD 3 --- */

it('refuses a settled invoice for the wrong amount and files it for review', function () {
    hardFakeInvoice(['amount' => '1']);

    $subject = hardSubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and(MembershipGrant::query()->count())->toBe(0);

    // Findable from the database, not only from a log file.
    $review = DB::table('payment_reviews')
        ->where('payment_event_id', $subject['payment']->id)
        ->where('reason', PaymentReviewReason::AmountMismatch->value)
        ->first();

    expect($review)->not->toBeNull()
        ->and((int) $review->expected_amount)->toBe(21000)
        ->and($review->observed_amount)->toBe('1')
        ->and($review->source)->toBe('webhook');
});

it('refuses a settled invoice in the wrong currency and files it for review', function () {
    /*
     * "1" against "1" matches on the number and is a catastrophe in fact when
     * one of them is euros. Reported as a currency mismatch rather than an
     * amount one so whoever reads the row looks in the right place.
     */
    hardFakeInvoice(['currency' => 'EUR']);

    $subject = hardSubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT);

    $review = DB::table('payment_reviews')
        ->where('payment_event_id', $subject['payment']->id)
        ->where('reason', PaymentReviewReason::CurrencyMismatch->value)
        ->first();

    expect($review)->not->toBeNull()
        ->and($review->observed_currency)->toBe('EUR')
        ->and($review->expected_currency)->toBe('SATS');
});

/* ---------------------------------------------------------------- DoD 4 --- */

it('rejects an invoice id that no payment event claims', function () {
    hardFakeInvoice();

    // No subject at all: BTCPay knows this invoice, this database does not.
    hardPost()->assertNotFound();

    expect(PaymentReview::query()
        ->where('btc_pay_invoice', HARD_INVOICE)
        ->where('reason', PaymentReviewReason::UnknownInvoice->value)
        ->count())->toBe(1);

    // Not verified against BTCPay either — there is nothing to compare with.
    Http::assertNothingSent();
});

/* ---------------------------------------------------------------- DoD 5 --- */

it('does not promote on a settled fee for a past year', function () {
    /*
     * The measured case, verbatim: a settled payment event for 1970 raised a
     * record to PASSIVE and wrote a grant dated 1970. The year limit used to
     * live in the invoice endpoint alone, where the webhook and the refresh
     * endpoint both walked straight past it.
     */
    hardFakeInvoice();

    $subject = hardSubject(paymentAttributes: ['year' => 1970]);

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeTrue()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and(MembershipGrant::query()->count())->toBe(0);
});

/* ---------------------------------------------------------------- DoD 8 --- */

it('reverses a booked fee on InvoiceInvalid without lowering the category', function () {
    hardFakeInvoice();

    $subject = hardSubject(
        ['association_status' => AssociationStatus::PASSIVE],
        ['paid' => true],
    );

    hardPost(['type' => 'InvoiceInvalid', 'deliveryId' => 'del-invalid'])->assertNoContent();

    $payment = $subject['payment']->fresh();

    expect((bool) $payment->paid)->toBeFalse()
        // A payment provider does not end memberships; Art. 4.2 rules out any
        // claim to a refund in the first place.
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);

    $reversal = PaymentReversal::query()->where('payment_event_id', $payment->id)->first();

    // The history still shows what came in: year, amount and currency of the
    // original entry, kept in the reversal rather than joined off a row that
    // may be invoiced again.
    expect($reversal)->not->toBeNull()
        ->and($reversal->year)->toBe((int) now()->year)
        ->and($reversal->amount)->toBe(21000)
        ->and($reversal->currency)->toBe('SATS')
        ->and($reversal->reason)->toBe('InvoiceInvalid')
        ->and($payment->btc_pay_invoice)->toBe(HARD_INVOICE);
});

it('writes no second Storno when InvoiceInvalid is redelivered', function () {
    hardFakeInvoice();

    $subject = hardSubject(
        ['association_status' => AssociationStatus::PASSIVE],
        ['paid' => true],
    );

    hardPost(['type' => 'InvoiceInvalid', 'deliveryId' => 'del-x'])->assertNoContent();
    hardPost(['type' => 'InvoiceInvalid', 'deliveryId' => 'del-y', 'originalDeliveryId' => 'del-x', 'isRedelivery' => true])->assertNoContent();

    expect(PaymentReversal::query()->count())->toBe(1);
});

/* ---------------------------------------------------------------- DoD 9 --- */

it('rolls both writes back when the process dies between them', function () {
    /*
     * The failure this transaction exists for: `paid` set, membership not
     * granted, and nothing anywhere that would ever notice or repair it. The
     * abort is injected at the grant — the second write — so the first one is
     * already on the connection when it fires.
     */
    hardFakeInvoice();

    $subject = hardSubject();

    MembershipGrant::creating(function (): void {
        throw new RuntimeException('process died between the two writes');
    });

    hardPost()->assertServerError();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and(MembershipGrant::query()->count())->toBe(0)
        // And the delivery is NOT marked done, so BTCPay's retry does the work
        // instead of finding a "processed" marker over a job that never ran.
        ->and(BtcPayWebhookDelivery::query()->whereNotNull('processed_at')->count())->toBe(0);
});

/* -------------------------------------------------- fail-closed defaults --- */

it('books nothing when BTCPay cannot be asked about the amount', function () {
    /*
     * Fail closed, and specifically with a 5xx: an unreachable provider means
     * the amount is UNKNOWN, and BTCPay retries on 5xx — so the delivery is
     * recovered rather than lost.
     */
    Http::swap(new Factory);
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $subject = hardSubject();

    hardPost()->assertServerError();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and(BtcPayWebhookDelivery::query()->whereNotNull('processed_at')->count())->toBe(0);
});

it('refuses a settled invoice whose amount BTCPay does not report', function () {
    /*
     * Transient, so it asks for a retry rather than acknowledging: a store
     * that answered without an amount may well answer with one next time, and
     * a 204 here would end the delivery for good.
     */
    hardFakeInvoice(['amount' => null, 'currency' => null]);

    $subject = hardSubject();

    hardPost()->assertServerError();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and(BtcPayWebhookDelivery::query()->whereNotNull('processed_at')->count())->toBe(0)
        ->and(PaymentReview::query()
            ->where('reason', PaymentReviewReason::UnverifiableAmount->value)
            ->count())->toBe(1);
});

/* --------------------------------------------- F4: transient vs. final --- */

it('answers 5xx and stays unprocessed when BTCPay returns an unusable body', function () {
    /*
     * F4. A 200 with an empty body is a BROKEN answer, not a verdict — and
     * BTCPay does not retry a 204. Acknowledging it turned a transient upstream
     * hiccup into a permanent one: the real redelivery, which would have
     * carried the correct amount, then found a "processed" marker and did
     * nothing.
     */
    Http::swap(new Factory);
    Http::fake(['pay.einundzwanzig.space/*' => Http::response('', 200)]);

    $subject = hardSubject();

    hardPost()->assertServerError();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and(BtcPayWebhookDelivery::query()->whereNotNull('processed_at')->count())->toBe(0);

    // The redelivery — new deliveryId, same event — now finds a healthy store
    // and does the work that was never done.
    hardFakeInvoice();

    hardPost(['deliveryId' => 'del-retry', 'originalDeliveryId' => 'del-1', 'isRedelivery' => true])
        ->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeTrue()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);
});

it('acknowledges a wrong amount instead of asking BTCPay to retry it', function () {
    /*
     * The other half of F4, and the reason it is not simply "5xx on any
     * refusal": a mismatched amount is a FINAL answer. Retrying it eight times
     * over an hour would change nothing and would bury the delivery log.
     */
    hardFakeInvoice(['amount' => '1']);

    hardSubject();

    hardPost()->assertNoContent();

    expect(BtcPayWebhookDelivery::query()->whereNotNull('processed_at')->count())->toBe(1);
});

/* ------------------------------------- manual settlement is on the record --- */

it('records that a settlement was marked by hand', function () {
    /*
     * A "mark settled" click in the BTCPay backend raises a membership, and
     * the amount check cannot narrow that down at all — the store reports the
     * invoiced amount by construction, so the comparison always succeeds. If
     * it is not written down at the moment it happens, nobody can ever
     * establish afterwards which memberships were created by mouse click.
     */
    hardFakeInvoice();

    $subject = hardSubject();

    hardPost(['manuallyMarked' => true, 'overPaid' => true])->assertNoContent();

    $delivery = BtcPayWebhookDelivery::query()->first();
    $grant = MembershipGrant::query()->where('payment_event_id', $subject['payment']->id)->first();

    expect($delivery->manually_marked)->toBeTrue()
        ->and($delivery->over_paid)->toBeTrue()
        ->and($grant)->not->toBeNull()
        ->and($grant->manually_marked)->toBeTrue();
});

it('records an ordinary settlement as not marked by hand', function () {
    hardFakeInvoice();

    $subject = hardSubject();

    hardPost()->assertNoContent();

    expect(BtcPayWebhookDelivery::query()->first()->manually_marked)->toBeFalse()
        ->and(MembershipGrant::query()->where('payment_event_id', $subject['payment']->id)->first()->manually_marked)
        ->toBeFalse();
});
