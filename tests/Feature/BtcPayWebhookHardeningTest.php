<?php

use App\Enums\AssociationStatus;
use App\Enums\PaymentReviewReason;
use App\Models\BtcPayWebhookDelivery;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use App\Models\PaymentReversal;
use App\Models\PaymentReview;
use App\Services\MembershipService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
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

/**
 * The same subject, but with a fee row from the era before invoices carried
 * their own currency.
 *
 * `created_at` cannot travel through the factory: it is not in PaymentEvent's
 * `$fillable`, so `create()` drops it and Eloquent then stamps the row with the
 * current time — silently, and the row lands on the wrong side of the cut-off
 * while the test reads as though it were on the other. Forcing it afterwards is
 * the only way to place a row in the past.
 *
 * @return array{pleb: EinundzwanzigPleb, payment: PaymentEvent}
 */
function hardLegacySubject(): array
{
    $subject = hardSubject();

    /*
     * setTimezone() before subtracting, and a whole day rather than a second.
     * The column stores a naive datetime in the application timezone, so a
     * Carbon carrying a +02:00 offset is written with its wall-clock digits and
     * its offset dropped — a value one second before the cut-off then lands two
     * hours AFTER it, and the test measures the opposite of what it says. The
     * day of margin means no future change of either timezone can put the row
     * back on the wrong side by accident.
     */
    $subject['payment']->forceFill([
        'created_at' => hardCutoff()->setTimezone(config('app.timezone'))->subDay(),
    ])->saveQuietly();

    return $subject;
}

function hardCutoff(): Carbon
{
    return Carbon::parse(config('einundzwanzig.config.explicit_currency_since'));
}

/**
 * The timestamp BTCPay reports for an invoice created before, or after, the
 * cut-off.
 *
 * The waiver needs BOTH ages, and this is the one that carries it: the fee row
 * is created when a member opens their profile page, the invoice when they
 * start a checkout, and those can lie on opposite sides of the border.
 */
function hardInvoiceCreatedTime(bool $legacy = true): int
{
    return $legacy
        ? hardCutoff()->subDay()->timestamp
        : hardCutoff()->addDay()->timestamp;
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

/*
 * The one exception to the rule above, decided on 2026-08-07.
 *
 * Invoices created before P2 went to BTCPay without a `currency` and wear the
 * store's default instead — a setting outside this repository that nobody read.
 * Refusing them afterwards would punish a member for a configuration they had
 * no hand in: they paid exactly the invoice the association issued them.
 *
 * The exception is bounded three ways, and each bound has its own test below:
 * it applies only to rows older than the cut-off, it does not extend to the
 * amount, and it leaves a log entry instead of passing silently.
 */
it('accepts a legacy invoice in the store currency and grants the membership', function () {
    hardFakeInvoice(['currency' => 'BTC', 'createdTime' => hardInvoiceCreatedTime()]);

    $subject = hardLegacySubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeTrue()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and(MembershipGrant::query()->count())->toBe(1)
        ->and(DB::table('payment_reviews')->count())->toBe(0);
});

it('still refuses a legacy invoice for the wrong amount', function () {
    // The waiver covers what the money was called, never how much of it came.
    hardFakeInvoice(['currency' => 'BTC', 'amount' => '1', 'createdTime' => hardInvoiceCreatedTime()]);

    $subject = hardLegacySubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT);

    $review = DB::table('payment_reviews')
        ->where('payment_event_id', $subject['payment']->id)
        ->where('reason', PaymentReviewReason::AmountMismatch->value)
        ->first();

    expect($review)->not->toBeNull();
});

it('does not extend the currency waiver to a fee row created after the cut-off', function () {
    hardFakeInvoice(['currency' => 'BTC', 'createdTime' => hardInvoiceCreatedTime()]);

    // Plain hardSubject(): a row created now is by definition after the cut-off.
    $subject = hardSubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and(DB::table('payment_reviews')
            ->where('payment_event_id', $subject['payment']->id)
            ->where('reason', PaymentReviewReason::CurrencyMismatch->value)
            ->exists())->toBeTrue();
});

it('refuses the waiver when the invoice itself is younger than the cut-off', function () {
    /*
     * THE CASE THE ROW AGE ALONE GETS WRONG. `resolvePaymentEvent()` creates
     * the fee row when a member opens their profile page — not when a checkout
     * starts. A member who looked at their profile in July and pays in
     * September has an old row and a brand-new invoice, and that invoice sent
     * its currency explicitly. It has no claim to an exception for invoices
     * that could not.
     */
    hardFakeInvoice(['currency' => 'BTC', 'createdTime' => hardInvoiceCreatedTime(legacy: false)]);

    $subject = hardLegacySubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and(DB::table('payment_reviews')
            ->where('payment_event_id', $subject['payment']->id)
            ->where('reason', PaymentReviewReason::CurrencyMismatch->value)
            ->exists())->toBeTrue();
});

it('refuses the waiver when BTCPay reports no creation time at all', function () {
    // Absent evidence is not evidence of age.
    hardFakeInvoice(['currency' => 'BTC']);

    $subject = hardLegacySubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and(DB::table('payment_reviews')
            ->where('reason', PaymentReviewReason::CurrencyMismatch->value)
            ->exists())->toBeTrue();
});

it('refuses the waiver when the reported creation time is numeric but no moment', function (string $createdTime) {
    /*
     * `is_numeric()` accepts values that are not points in time. `9e99` and a
     * twenty-digit integer both pass it and both make
     * `Carbon::createFromTimestamp()` throw — and the throw would leave the
     * service for a 500, whereupon BTCPay retries eight times and then leaves
     * the payment unbooked forever. The waiver is refused instead: no proof the
     * invoice is old, so no exception, and the fee lands in `payment_reviews`
     * where a human can see it.
     */
    hardFakeInvoice(['currency' => 'BTC', 'createdTime' => $createdTime]);

    $subject = hardLegacySubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and(DB::table('payment_reviews')
            ->where('reason', PaymentReviewReason::CurrencyMismatch->value)
            ->exists())->toBeTrue();
})->with([
    'scientific notation' => '9e99',
    'beyond the representable range' => '1e18',
    'twenty digits' => '99999999999999999999',
    'negative and enormous' => '-9e99',
]);

it('refuses the waiver for a fee row with no created_at', function () {
    /*
     * Nothing in this application produces such a row — `created_at` is not
     * fillable on PaymentEvent — so it would come from a hand-written backfill.
     * The prices are not symmetric: the strict answer costs one line in
     * `payment_reviews` that a human clears in a minute, the lenient one
     * disables a check on the money path for that row forever.
     */
    hardFakeInvoice(['currency' => 'BTC', 'createdTime' => hardInvoiceCreatedTime()]);

    $subject = hardSubject();
    $subject['payment']->forceFill(['created_at' => null])->saveQuietly();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and(DB::table('payment_reviews')
            ->where('reason', PaymentReviewReason::CurrencyMismatch->value)
            ->exists())->toBeTrue();
});

it('closes the waiver rather than opening it when the cut-off is unusable', function (mixed $configured) {
    /*
     * THE MOST NATURAL WRONG MOVE MUST NOT OPEN THE GATE. An operator who wants
     * the waiver gone will empty the environment variable — and
     * `Carbon::parse('')` returns NOW rather than throwing, which would make
     * every existing row older than the cut-off and lift the currency check for
     * every payment the association ever receives. Measured before this guard
     * existed: an emptied value turned a `BTC` invoice on a row created today
     * into a full membership.
     */
    // Subject and fake are built FIRST: both read the cut-off to place
    // themselves in the legacy era, and an unusable value would blow up the
    // arrangement instead of the thing under test.
    hardFakeInvoice(['currency' => 'BTC', 'createdTime' => hardInvoiceCreatedTime()]);

    $subject = hardLegacySubject();

    config()->set('einundzwanzig.config.explicit_currency_since', $configured);

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeFalse()
        ->and(DB::table('payment_reviews')
            ->where('reason', PaymentReviewReason::CurrencyMismatch->value)
            ->exists())->toBeTrue();
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'unparsable' => 'not-a-date',
]);

it('does not claim an acceptance for a payment it goes on to refuse', function () {
    /*
     * The entry answers one question — is this exception still needed? — and it
     * can only answer it if `…_accepted` means accepted. Logged above the
     * amount comparison it also fired for payments that were about to be filed
     * for review, which is the opposite of what the word says.
     */
    Log::spy();

    hardFakeInvoice(['currency' => 'BTC', 'amount' => '1', 'createdTime' => hardInvoiceCreatedTime()]);

    hardLegacySubject();

    hardPost()->assertNoContent();

    /*
     * BOTH arguments have to be named. `warning()` is called with a message and
     * a context array; an expectation listing only the message matches no call
     * at all and passes no matter what the code does — checked by removing the
     * guard under test and watching this assertion stay green.
     */
    Log::shouldNotHaveReceived('warning', ['membership.legacy_currency_accepted', Mockery::any()]);
});

it('does not repeat the log entry on every status poll of a booked fee', function () {
    /*
     * `markPaid()` verifies unconditionally, also for a fee that is already
     * settled. The profile page polls every 20 seconds and
     * `POST /payments/{year}/refresh` allows 30 calls a minute, so without the
     * `paid` guard one waived fee produces tens of thousands of identical
     * warnings a day — enough to bury every other warning.
     */
    hardFakeInvoice(['currency' => 'BTC', 'createdTime' => hardInvoiceCreatedTime()]);

    $subject = hardLegacySubject();

    hardPost()->assertNoContent();

    expect((bool) $subject['payment']->fresh()->paid)->toBeTrue();

    Log::spy();

    app(MembershipService::class)->refreshPaymentStatus($subject['payment']->fresh());
    app(MembershipService::class)->refreshPaymentStatus($subject['payment']->fresh());

    /*
     * BOTH arguments have to be named. `warning()` is called with a message and
     * a context array; an expectation listing only the message matches no call
     * at all and passes no matter what the code does — checked by removing the
     * guard under test and watching this assertion stay green.
     */
    Log::shouldNotHaveReceived('warning', ['membership.legacy_currency_accepted', Mockery::any()]);
});

it('records an accepted legacy currency in the log', function () {
    /*
     * The waiver is the only place this service lets a discrepancy through. If
     * it ever fires without leaving a trace, nobody can tell whether it is
     * still needed — or whether it is quietly covering something new.
     */
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'membership.legacy_currency_accepted'
            && $context['observed_currency'] === 'BTC'
            && $context['expected_currency'] === 'SATS');

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    hardFakeInvoice(['currency' => 'BTC', 'createdTime' => hardInvoiceCreatedTime()]);

    hardLegacySubject();

    hardPost()->assertNoContent();
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
