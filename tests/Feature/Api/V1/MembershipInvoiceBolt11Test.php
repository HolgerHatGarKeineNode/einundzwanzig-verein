<?php

use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

/*
 * `bolt11` on POST /payments/{year}/invoice — the Lightning payment request
 * that lets a client with a wallet pay without opening the checkout page.
 *
 * Everything asserted here about BTCPay's shape was measured against the live
 * store first (P2, `p2-machbarkeit.md` section (a)) rather than guessed, and
 * the two findings that shaped the code are the two that are pinned hardest:
 *
 *  1. `BTC-LNURL` is present on nearly every invoice and carries `destination`
 *     as the EMPTY STRING. "First method with a destination" therefore returns
 *     an empty string that looks like a payment request. Selection is on
 *     `paymentMethodId === 'BTC-LN'` and the test below is built so that a
 *     regression to the loose rule fails it.
 *  2. The Lightning method can be missing outright — 4 of 239 invoices in the
 *     store's history, one of them a real membership fee invoice of this
 *     application. Null is a case that happens, not a case that is feared.
 *
 * And the field is ADDITIVE: a BTCPay that will not answer the extra read
 * costs `bolt11` and nothing else. The checkout URL still goes out, because
 * the payer can still pay.
 */

const BOLT11_CLIENT_KEY = 'bolt1111111111111111111111111111111111111111111111111111bolt1';

const BOLT11_PAYMENT_REQUEST = 'lnbc210u1p48naztpp5tl2nrhjn8skyy3jv0mlhfaugppmu6dvexamplenotreal';

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => BOLT11_CLIENT_KEY],
        'einundzwanzig.config.membership_fee' => 21000,
        'einundzwanzig.config.currency' => 'SATS',
        'services.btc_pay.base_url' => 'https://pay.einundzwanzig.space',
        'services.btc_pay.store_id' => 'test-store',
        'app.debug' => false,
    ]);
});

/**
 * Fake BTCPay with a separate answer per endpoint.
 *
 * A FRESH FACTORY every time, for the reason spelled out in
 * `MembershipInvoiceTest::invFakeBtcPay()`: `Http::fake()` appends its stubs
 * and the first matching one answers, so a second fake for the same URL is
 * silently ignored.
 *
 * The payment-methods stub is deliberately keyed to the invoice id `inv-new`
 * and not to a wildcard. That is what makes the idempotency test below say
 * something: a repeat call has no BTCPay payload to read from and must reload
 * through the invoice id it STORED. Reload it through anything else and this
 * fake does not answer.
 *
 * @param  mixed  $paymentMethods  the payment-methods response, or a closure to throw from
 */
function bolt11FakeBtcPay(mixed $paymentMethods, string $invoiceId = 'inv-new'): void
{
    Http::swap(new HttpFactory);

    Http::fake([
        'pay.einundzwanzig.space/api/v1/stores/test-store/invoices/'.$invoiceId.'/payment-methods' => $paymentMethods,
        'pay.einundzwanzig.space/api/v1/stores/test-store/invoices' => Http::response([
            'id' => $invoiceId,
            'checkoutLink' => 'https://pay.einundzwanzig.space/i/'.$invoiceId,
            'status' => 'New',
        ]),
    ]);
}

/**
 * The three methods a healthy membership invoice carries, in the order and
 * with the values the live store returned.
 *
 * `BTC-LNURL` first and with an EMPTY destination on purpose: an
 * implementation that takes the first entry with a `destination` key picks
 * this one and hands out `''`.
 *
 * @return list<array<string, mixed>>
 */
function bolt11MethodsWithLightning(): array
{
    return [
        ['paymentMethodId' => 'BTC-LNURL', 'destination' => '', 'currency' => 'BTC'],
        ['paymentMethodId' => 'BTC-CHAIN', 'destination' => 'bc1qgz2fepxwlc5cxq8a8z8scuvk563rgw5hexample', 'currency' => 'BTC'],
        ['paymentMethodId' => 'BTC-LN', 'destination' => BOLT11_PAYMENT_REQUEST, 'currency' => 'BTC'],
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function bolt11MemberFor(string $privkey, array $attributes = []): EinundzwanzigPleb
{
    $pubkey = (new Key)->getPublicKey($privkey);

    return EinundzwanzigPleb::factory()->create($attributes + [
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
    ]);
}

/**
 * @return array{response: TestResponse, pubkey: string, privkey: string}
 */
function bolt11InvoiceCall(string $privkey): array
{
    return apiV1SignedRequest(
        'POST',
        '/api/v1/membership/payments/'.now()->year.'/invoice',
        BOLT11_CLIENT_KEY,
        $privkey,
    );
}

it('hands the lightning payment request of a freshly created invoice to the client', function () {
    bolt11FakeBtcPay(Http::response(bolt11MethodsWithLightning()));

    $privkey = (new Key)->generatePrivateKey();
    bolt11MemberFor($privkey);

    $call = bolt11InvoiceCall($privkey);

    $call['response']->assertOk()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.bolt11', BOLT11_PAYMENT_REQUEST)
        // Additive: the checkout is untouched and still the way to pay for a
        // client that has no wallet.
        ->assertJsonPath('data.checkout_url', 'https://pay.einundzwanzig.space/i/inv-new');

    // The payment methods were read from the invoice that was just created,
    // through the store-scoped path this installation's BTCPay answers.
    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://pay.einundzwanzig.space/api/v1/stores/test-store/invoices/inv-new/payment-methods');
});

it('hands back the same lightning payment request on the idempotent second call', function () {
    /*
     * THE POINT OF THE WHOLE FEATURE, and the case a purely additive field
     * does not cover on its own. The second call gets no BTCPay payload —
     * `createInvoice()` returns `invoice: null` once an invoice exists — so
     * the payment request has to be reloaded through the STORED invoice id.
     * A client that reloads its page must not be told that Lightning is
     * suddenly unavailable, because the only other way back to a payment
     * request would be a second invoice, and the invoice endpoint allows a
     * handful of calls per pubkey and day.
     */
    bolt11FakeBtcPay(Http::response(bolt11MethodsWithLightning()));

    $privkey = (new Key)->generatePrivateKey();
    bolt11MemberFor($privkey);

    $first = bolt11InvoiceCall($privkey);
    $second = bolt11InvoiceCall($privkey);

    $first['response']->assertOk()->assertJsonPath('data.created', true);
    $second['response']->assertOk()->assertJsonPath('data.created', false);

    expect($second['response']->json('data.bolt11'))
        ->toBe(BOLT11_PAYMENT_REQUEST)
        ->toBe($first['response']->json('data.bolt11'))
        ->and($second['response']->json('data.checkout_url'))
        ->toBe($first['response']->json('data.checkout_url'));

    // Exactly one invoice was ordered at BTCPay across both calls; the second
    // call only READ. The reload is a GET, and a GET creates nothing.
    $orders = Http::recorded(fn (ClientRequest $request): bool => $request->method() === 'POST');
    $reads = Http::recorded(fn (ClientRequest $request): bool => $request->method() === 'GET');

    expect($orders)->toHaveCount(1)
        ->and($reads)->toHaveCount(2)
        ->and(PaymentEvent::query()->count())->toBe(1);
});

it('answers null, never an empty string, when the invoice carries no lightning method', function () {
    /*
     * The on-chain-only invoice. Measured on the real store: 4 of 239, one of
     * them a membership fee invoice of this application, so this is the shape
     * of a live case and not of a hypothetical one. `BTC-LNURL` is present
     * with `destination` as the empty string — the value that looks like a
     * payment request, passes a truthiness check in a naive client, and is
     * none.
     */
    bolt11FakeBtcPay(Http::response([
        ['paymentMethodId' => 'BTC-CHAIN', 'destination' => 'bc1qgz2fepxwlc5cxq8a8z8scuvk563rgw5hexample', 'currency' => 'BTC'],
        ['paymentMethodId' => 'BTC-LNURL', 'destination' => '', 'currency' => 'BTC'],
    ]));

    $privkey = (new Key)->generatePrivateKey();
    bolt11MemberFor($privkey);

    $call = bolt11InvoiceCall($privkey);

    $call['response']->assertOk()
        ->assertJsonPath('data.bolt11', null)
        ->assertJsonPath('data.checkout_url', 'https://pay.einundzwanzig.space/i/inv-new');

    expect($call['response']->json('data.bolt11'))->toBeNull()->not->toBe('');
});

it('reads the lightning method by its id and not by the first destination it finds', function (array $methods) {
    /*
     * The discriminating test for finding 1. Every dataset below puts a method
     * that HAS a `destination` key in front of the Lightning one, so an
     * implementation selecting on "has a destination" answers the wrong value
     * or an empty string and fails here, while `paymentMethodId === 'BTC-LN'`
     * passes.
     */
    bolt11FakeBtcPay(Http::response($methods));

    $privkey = (new Key)->generatePrivateKey();
    bolt11MemberFor($privkey);

    bolt11InvoiceCall($privkey)['response']
        ->assertOk()
        ->assertJsonPath('data.bolt11', BOLT11_PAYMENT_REQUEST);
})->with([
    'lnurl with an empty destination first' => [[
        ['paymentMethodId' => 'BTC-LNURL', 'destination' => ''],
        ['paymentMethodId' => 'BTC-LN', 'destination' => BOLT11_PAYMENT_REQUEST],
    ]],
    'an on-chain address first' => [[
        ['paymentMethodId' => 'BTC-CHAIN', 'destination' => 'bc1qgz2fepxwlc5cxq8a8z8scuvk563rgw5hexample'],
        ['paymentMethodId' => 'BTC-LN', 'destination' => BOLT11_PAYMENT_REQUEST],
    ]],
    'both of them first' => [[
        ['paymentMethodId' => 'BTC-LNURL', 'destination' => ''],
        ['paymentMethodId' => 'BTC-CHAIN', 'destination' => 'bc1qgz2fepxwlc5cxq8a8z8scuvk563rgw5hexample'],
        ['paymentMethodId' => 'BTC-LN', 'destination' => BOLT11_PAYMENT_REQUEST],
    ]],
]);

it('treats a lightning method with a blank destination as no lightning method', function (mixed $destination) {
    bolt11FakeBtcPay(Http::response([
        ['paymentMethodId' => 'BTC-LN', 'destination' => $destination],
    ]));

    $privkey = (new Key)->generatePrivateKey();
    bolt11MemberFor($privkey);

    $call = bolt11InvoiceCall($privkey);

    $call['response']->assertOk();

    expect($call['response']->json('data.bolt11'))->toBeNull();
})->with([
    'empty string' => '',
    'whitespace' => "  \n ",
    'null' => null,
    'not a string' => 42,
]);

it('still hands out the checkout when the payment-methods call fails', function (mixed $stub) {
    /*
     * FAIL-SOFT, AND DELIBERATELY SO. `bolt11` is a convenience; the checkout
     * URL next to it is the payment path and is unaffected by this outage.
     * Failing the request instead would turn "no shortcut right now" into "the
     * association cannot take your money", which is a strictly worse answer to
     * the same broken upstream — and it would do so for a call whose real work
     * (the invoice) has already succeeded and been recorded.
     */
    bolt11FakeBtcPay($stub);

    $privkey = (new Key)->generatePrivateKey();
    bolt11MemberFor($privkey);

    $call = bolt11InvoiceCall($privkey);

    $call['response']->assertOk()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.bolt11', null)
        ->assertJsonPath('data.checkout_url', 'https://pay.einundzwanzig.space/i/inv-new');

    // The invoice was recorded all the same — the failed read did not roll
    // back work that succeeded.
    expect(PaymentEvent::query()->value('btc_pay_invoice'))->toBe('inv-new')
        // And nothing about the upstream failure reached the client.
        ->and($call['response']->getContent())
        ->not->toContain('test-store')
        ->not->toContain('boom');
})->with([
    'a 500' => fn () => Http::response(['error' => 'boom'], 500),
    'a 404' => fn () => Http::response(['error' => 'boom'], 404),
    'a 403' => fn () => Http::response(['error' => 'boom'], 403),
    'a timeout' => fn () => fn () => throw new ConnectionException('Connection timed out after 4 seconds'),
    'an empty body' => fn () => Http::response(null),
    'a body that is not a list of methods' => fn () => Http::response(['id' => 'inv-new', 'status' => 'New']),
]);

it('reports the lightning payment request on the refresh endpoint too', function () {
    /*
     * One field, one meaning, across the whole surface. A client that lost its
     * payment request — a reload, a second device — gets it back from the
     * endpoint it is already polling, instead of spending one of its few
     * daily invoice creations on re-reading something that exists.
     */
    Http::swap(new HttpFactory);

    Http::fake([
        'pay.einundzwanzig.space/api/v1/stores/test-store/invoices/inv-open/payment-methods' => Http::response(bolt11MethodsWithLightning()),
        'pay.einundzwanzig.space/api/v1/stores/test-store/invoices/inv-open' => Http::response([
            'id' => 'inv-open',
            'status' => 'New',
            'amount' => '21000',
            'currency' => 'SATS',
        ]),
    ]);

    $privkey = (new Key)->generatePrivateKey();
    $pleb = bolt11MemberFor($privkey);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => 'inv-open',
    ]);

    apiV1SignedRequest(
        'POST',
        '/api/v1/membership/payments/'.now()->year.'/refresh',
        BOLT11_CLIENT_KEY,
        $privkey,
    )['response']
        ->assertOk()
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.bolt11', BOLT11_PAYMENT_REQUEST);
});
