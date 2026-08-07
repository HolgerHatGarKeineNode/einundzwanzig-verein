<?php

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

const INV_CLIENT_KEY = 'inv111111111111111111111111111111111111111111111111111111inv11';

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => INV_CLIENT_KEY],
        'einundzwanzig.config.membership_fee' => 21000,
        'einundzwanzig.config.currency' => 'SATS',
        'services.btc_pay.base_url' => 'https://pay.einundzwanzig.space',
        'services.btc_pay.store_id' => 'test-store',
        'app.debug' => false,
    ]);

    invFakeBtcPay([
        'id' => 'inv-new',
        'checkoutLink' => 'https://pay.einundzwanzig.space/i/inv-new',
        'status' => 'New',
    ]);
});

/**
 * Fake BTCPay, replacing whatever was faked before.
 *
 * A FRESH FACTORY every time, and that is the point: `Http::fake()` APPENDS
 * its stubs and the FIRST matching one answers, so a second fake for the same
 * URL — in a test that needs a different upstream answer than the beforeEach
 * set up, or that needs two answers in a row — is silently ignored. Measured:
 * the 503 cases below returned 200 until this helper existed, i.e. they were
 * testing the happy path while claiming to test an outage.
 *
 * Swapping also resets the recorded requests, so `Http::assertSentCount()`
 * counts from this call onwards.
 *
 * @param  array<string, mixed>  $body
 */
function invFakeBtcPay(array $body, int $status = 200): void
{
    Http::swap(new HttpFactory);

    Http::fake(['pay.einundzwanzig.space/*' => Http::response($body, $status)]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function invMemberFor(string $privkey, array $attributes = []): EinundzwanzigPleb
{
    $pubkey = (new Key)->getPublicKey($privkey);

    return EinundzwanzigPleb::factory()->create($attributes + [
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
    ]);
}

/**
 * @param  array<string, mixed>|null  $body
 * @return array{response: TestResponse, pubkey: string, privkey: string}
 */
function invoiceCall(?string $privkey = null, ?int $year = null, ?array $body = null): array
{
    $year ??= (int) now()->year;

    return apiV1SignedRequest(
        'POST',
        '/api/v1/membership/payments/'.$year.'/invoice',
        INV_CLIENT_KEY,
        $privkey,
        $body,
    );
}

it('requires a NIP-98 signature', function () {
    $this->withHeaders([
        'X-Api-Key' => INV_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->post('/api/v1/membership/payments/'.now()->year.'/invoice')
        ->assertUnauthorized();

    Http::assertNothingSent();
});

it('lets a long-standing member without recorded consent create an invoice', function () {
    /*
     * NO GATE ON `statutes_accepted_at` (plan step 25). The statutes demand no
     * explicit consent and the membership begins with the payment; a gate here
     * would have locked out exactly those members whose membership already
     * exists and who have nothing left to consent to.
     */
    $privkey = (new Key)->generatePrivateKey();
    $pleb = invMemberFor($privkey, [
        'statutes_accepted_at' => null,
        'applied_at' => null,
    ]);

    $call = invoiceCall($privkey);

    $call['response']->assertOk()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.checkout_url', 'https://pay.einundzwanzig.space/i/inv-new')
        ->assertJsonPath('data.payment.year', (int) now()->year)
        ->assertJsonPath('data.payment.paid', false);

    expect($pleb->fresh()->statutes_accepted_at)->toBeNull()
        ->and($pleb->paymentEvents()->where('year', (int) now()->year)->value('btc_pay_invoice'))
        ->toBe('inv-new');
});

it('ignores amount, currency and year sent in the body', function () {
    $privkey = (new Key)->generatePrivateKey();
    invMemberFor($privkey);

    $call = invoiceCall($privkey, body: [
        'amount' => 1,
        'currency' => 'BTC',
        'year' => 1970,
    ]);

    $call['response']->assertOk()
        ->assertJsonPath('data.payment.year', (int) now()->year)
        ->assertJsonPath('data.payment.amount', 21000)
        ->assertJsonPath('data.payment.currency', 'SATS');

    // What actually left the building: the configured fee, in the configured
    // currency. The body reached the endpoint and changed none of it.
    Http::assertSent(function (ClientRequest $request): bool {
        return $request->url() === 'https://pay.einundzwanzig.space/api/v1/stores/test-store/invoices'
            && $request['amount'] === 21000
            && $request['currency'] === 'SATS';
    });

    $paymentEvents = PaymentEvent::query()->get();

    expect($paymentEvents)->toHaveCount(1)
        ->and((int) $paymentEvents->first()->year)->toBe((int) now()->year)
        ->and((int) $paymentEvents->first()->amount)->toBe(21000);
});

it('is idempotent per pubkey and year: one invoice, one BTCPay request', function () {
    /*
     * The limiter counts per DAY (three calls), so it does not carry this on
     * its own. Two calls in a row must hand back the same checkout instead of
     * leaving a second open invoice behind — an unaccounted one that, if paid,
     * produces money without a booking.
     */
    $privkey = (new Key)->generatePrivateKey();
    invMemberFor($privkey);

    $first = invoiceCall($privkey);
    $second = invoiceCall($privkey);

    $first['response']->assertOk()->assertJsonPath('data.created', true);
    $second['response']->assertOk()->assertJsonPath('data.created', false);

    expect($second['response']->json('data.checkout_url'))
        ->toBe($first['response']->json('data.checkout_url'));

    Http::assertSentCount(1);

    expect(PaymentEvent::query()->count())->toBe(1)
        ->and(PaymentEvent::query()->value('btc_pay_invoice'))->toBe('inv-new');
});

it('refuses a BTCPay 200 that carries no invoice id', function () {
    /*
     * A success's clothes over an upstream failure. Taken at face value it
     * stored `null`, answered `created: true` with a checkout URL pointing at
     * nothing, and — because nothing was stored — left the idempotency guard
     * with nothing to hold on to, so the client's retry ordered a SECOND
     * invoice. An unusable answer is an outage, not a success.
     */
    invFakeBtcPay(['status' => 'New']);

    $privkey = (new Key)->generatePrivateKey();
    invMemberFor($privkey);

    $call = invoiceCall($privkey);

    $call['response']->assertStatus(503);

    expect($call['response']->json('data.created'))->toBeNull()
        ->and(array_keys($call['response']->json()))->toBe(['message'])
        // The transaction rolled back: no phantom reference, nothing for a
        // later call to mistake for an existing checkout.
        ->and(PaymentEvent::query()->value('btc_pay_invoice'))->toBeNull();

    // One request, one POST — the failed call did not quietly try twice.
    Http::assertSentCount(1);
});

it('refuses an invoice id that is not one', function (string $invoiceId) {
    /*
     * The id is stored and then pasted into the checkout and receipt URLs the
     * payer is sent to. `{"id":"../../evil"}` produced
     * `https://pay.einundzwanzig.space/i/../../evil` and was handed out with a
     * 200. BTCPay is a trusted upstream and this is no attack path — but a
     * foreign value ending up unfiltered in a URL is a defect no matter who
     * supplied it, and "not empty" was never a check on what the value IS.
     */
    invFakeBtcPay(['id' => $invoiceId]);

    $privkey = (new Key)->generatePrivateKey();
    invMemberFor($privkey);

    $call = invoiceCall($privkey);

    $call['response']->assertStatus(503);

    expect(PaymentEvent::query()->value('btc_pay_invoice'))->toBeNull()
        ->and($call['response']->getContent())->not->toContain($invoiceId);
})->with([
    'path traversal' => '../../evil',
    'whitespace' => 'inv 1',
    'a full url' => 'https://evil.example/i/x',
    'a query string' => 'inv?redirect=evil',
]);

it('hands out one invoice once BTCPay answers properly again', function () {
    /*
     * The retry after the failure above. It DOES cost a second POST upstream,
     * because a call that stored nothing cannot be idempotent against one that
     * stored something — the only way to spend a single POST across both would
     * be to persist a reference we never received, which is the defect being
     * fixed. What must hold is that the association ends up with exactly ONE
     * invoice on record, and that every call after that spends nothing.
     *
     * The orphan this can leave at BTCPay (an invoice created upstream whose
     * id never reached us) is the reconciliation job's business in P5, and the
     * plan already names it.
     */
    $privkey = (new Key)->generatePrivateKey();
    invMemberFor($privkey);

    invFakeBtcPay(['status' => 'New']);
    invoiceCall($privkey)['response']->assertStatus(503);

    invFakeBtcPay([
        'id' => 'inv-second-attempt',
        'checkoutLink' => 'https://pay.einundzwanzig.space/i/inv-second-attempt',
    ]);

    $recovered = invoiceCall($privkey);
    $again = invoiceCall($privkey);

    $recovered['response']->assertOk()->assertJsonPath('data.created', true);
    $again['response']->assertOk()->assertJsonPath('data.created', false);

    expect(PaymentEvent::query()->count())->toBe(1)
        ->and(PaymentEvent::query()->value('btc_pay_invoice'))->toBe('inv-second-attempt');

    // Two calls after the recovery, one POST: the guard holds again as soon as
    // there is something to hold.
    Http::assertSentCount(1);
});

it('answers 503 when BTCPay refuses the invoice outright', function () {
    invFakeBtcPay(['error' => 'boom'], 500);

    $privkey = (new Key)->generatePrivateKey();
    invMemberFor($privkey);

    $call = invoiceCall($privkey);

    $call['response']->assertStatus(503);

    expect(array_keys($call['response']->json()))->toBe(['message'])
        ->and($call['response']->getContent())
        ->not->toContain('boom')
        ->not->toContain('test-store')
        ->and(PaymentEvent::query()->value('btc_pay_invoice'))->toBeNull();
});

it('refuses any year but the current fee year', function () {
    /*
     * The general assembly fixes the fee per year (Art. 4), so an invoice for
     * a past year would book today's amount against a year that owed a
     * different one. And since a settled fee constitutes a membership, an open
     * year would let somebody join by paying a fee for 1970.
     */
    $privkey = (new Key)->generatePrivateKey();
    invMemberFor($privkey);

    $past = invoiceCall($privkey, year: (int) now()->year - 1);
    $future = invoiceCall($privkey, year: (int) now()->year + 1);

    foreach ([$past, $future] as $call) {
        $call['response']->assertNotFound()
            ->assertJson(['message' => ApiV1Controller::NOT_FOUND_MESSAGE]);
    }

    Http::assertNothingSent();

    expect(PaymentEvent::query()->count())->toBe(0);
});

it('refuses a pubkey without a member record and spends nothing at BTCPay', function () {
    /*
     * The way in is POST /applications. Creating a record here would spend the
     * association's BTCPay key on a stranger who has agreed to nothing — and
     * the fee, once settled, constitutes a membership.
     */
    $call = invoiceCall();

    $call['response']->assertNotFound()
        ->assertJson(['message' => ApiV1Controller::NOT_FOUND_MESSAGE]);

    Http::assertNothingSent();

    expect(EinundzwanzigPleb::query()->where('pubkey', $call['pubkey'])->exists())->toBeFalse()
        ->and(PaymentEvent::query()->count())->toBe(0);
});

it('follows a travelled clock into the next fee year', function () {
    $privkey = (new Key)->generatePrivateKey();
    invMemberFor($privkey);

    $this->travelTo(Carbon::parse('2027-01-01 00:00:01'));

    $call = invoiceCall($privkey, year: 2027);

    $call['response']->assertOk()->assertJsonPath('data.payment.year', 2027);

    // And the year that has just ended is now refused, from the same record.
    invoiceCall($privkey, year: 2026)['response']->assertNotFound();
});

it('returns only the allowed fields and never personal data', function () {
    $privkey = (new Key)->generatePrivateKey();
    invMemberFor($privkey, [
        'email' => 'private@example.test',
        'application_text' => 'my private application prose',
        'archived_application_text' => 'my archived application prose',
    ]);

    $call = invoiceCall($privkey);

    $call['response']->assertOk();

    expect($call['response']->json('data'))
        ->toHaveKeys(['checkout_url', 'created', 'payment'])
        ->not->toHaveKeys([
            'id',
            'invoice',
            'email',
            'application_text',
            'archived_application_text',
        ]);

    expect($call['response']->json('data.payment'))
        ->toHaveKeys(['year', 'amount', 'currency', 'paid', 'receipt_url'])
        ->not->toHaveKeys(['id', 'einundzwanzig_pleb_id', 'btc_pay_invoice', 'event_id']);

    expect($call['response']->getContent())
        ->not->toContain('private@example.test')
        ->not->toContain('my private application prose')
        ->not->toContain('my archived application prose')
        // The raw BTCPay payload stays inside: store ids and the association's
        // own posData are nobody else's business.
        ->not->toContain('test-store');
});

it('refuses to create an invoice when the fee is not configured', function (int|string|null $fee) {
    /*
     * DoD 7. Measured before the guard existed: an empty `MEMBERSHIP_FEE`
     * casts to 0 and the payload that went out to BTCPay carried `amount: 0`.
     * BTCPay settles a zero-amount invoice on sight, and since P2 a settled
     * fee IS the membership — so a missing environment variable handed out
     * free memberships.
     *
     * Two assertions, and the second is the one that matters: the refusal
     * happens BEFORE the first request leaves the house. A 503 raised after
     * the call would still have created the invoice at BTCPay.
     */
    config(['einundzwanzig.config.membership_fee' => $fee]);

    invFakeBtcPay(['id' => 'inv-should-not-exist', 'status' => 'New']);

    $privkey = (new Key)->generatePrivateKey();
    $pleb = invMemberFor($privkey);

    invoiceCall($privkey)['response']->assertStatus(503);

    Http::assertNothingSent();

    // And no zero-amount fee year is left behind on this side either.
    expect($pleb->paymentEvents()->count())->toBe(0);
})->with([
    'empty string' => '',
    'zero' => 0,
    'null' => null,
    'negative' => -1,
]);

it('still refuses when a fee year already exists, so no zero payload goes out', function () {
    /*
     * The discriminating variant. A guard placed only where a payment event is
     * CREATED passes the test above and lets this one through: the record is
     * already there, resolvePaymentEvent() never reaches its guard, and
     * `invoicePayload()` happily asks BTCPay for `amount: 0`.
     */
    $privkey = (new Key)->generatePrivateKey();
    $pleb = invMemberFor($privkey);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => null,
    ]);

    config(['einundzwanzig.config.membership_fee' => 0]);

    invFakeBtcPay(['id' => 'inv-should-not-exist', 'status' => 'New']);

    invoiceCall($privkey)['response']->assertStatus(503);

    Http::assertNothingSent();

    expect($pleb->paymentEvents()->where('year', (int) now()->year)->value('btc_pay_invoice'))->toBeNull();
});
