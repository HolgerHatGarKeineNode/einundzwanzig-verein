<?php

use App\Services\BtcPayClient;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/*
 * `BtcPayClient::invoicePaymentMethods()` — the only call in this application
 * that can produce a BOLT11.
 *
 * Two properties are pinned here rather than only at the endpoint that uses
 * it: the store-scoped path (the form this installation's BTCPay is known to
 * answer, matching `getInvoice()`), and that the method THROWS. Whether a
 * failure is fatal is the caller's decision, and `MembershipService` makes it
 * — a client that swallowed the failure would take that choice away from every
 * future caller and hide an outage behind a plausible-looking empty result.
 */

beforeEach(function (): void {
    config()->set('services.btc_pay.base_url', 'https://btcpay.test');
    config()->set('services.btc_pay.store_id', 'store-from-config');
});

it('reads the payment methods from the store-scoped path', function () {
    Http::fake(['btcpay.test/*' => Http::response([
        ['paymentMethodId' => 'BTC-LN', 'destination' => 'lnbc1example'],
    ])]);

    $methods = app(BtcPayClient::class)->invoicePaymentMethods('inv-abc');

    expect($methods)->toBe([
        ['paymentMethodId' => 'BTC-LN', 'destination' => 'lnbc1example'],
    ]);

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://btcpay.test/api/v1/stores/store-from-config/invoices/inv-abc/payment-methods');
});

it('answers an empty list rather than a TypeError when the body is unusable', function (mixed $body) {
    /*
     * The `?? []` is not cosmetic, for the same reason it is not in
     * `getInvoice()`: a 200 with an empty or non-JSON body makes `json()`
     * return null, and the declared array return type would turn an upstream
     * oddity into a TypeError deep inside the caller instead of into "this
     * invoice has no methods to report".
     */
    Http::fake(['btcpay.test/*' => Http::response($body)]);

    expect(app(BtcPayClient::class)->invoicePaymentMethods('inv-abc'))->toBe([]);
})->with([
    'no body at all' => null,
    'an empty list' => [[]],
]);

it('drops entries that are not objects so a caller may iterate without rechecking', function () {
    Http::fake(['btcpay.test/*' => Http::response([
        'not-an-object',
        ['paymentMethodId' => 'BTC-LN', 'destination' => 'lnbc1example'],
        42,
    ])]);

    expect(app(BtcPayClient::class)->invoicePaymentMethods('inv-abc'))->toBe([
        ['paymentMethodId' => 'BTC-LN', 'destination' => 'lnbc1example'],
    ]);
});

it('throws on an upstream failure instead of deciding for its caller', function (int $status) {
    Http::fake(['btcpay.test/*' => Http::response(['error' => 'boom'], $status)]);

    expect(fn () => app(BtcPayClient::class)->invoicePaymentMethods('inv-abc'))
        ->toThrow(RequestException::class);
})->with([
    'not found' => 404,
    'forbidden' => 403,
    'server error' => 500,
]);

it('is capped by a timeout of its own, shorter than the default', function () {
    /*
     * Every caller treats a failure of this call as "no BOLT11" and answers
     * anyway, so a long wait buys nothing but a slow invoice endpoint — the
     * checkout URL is already known by then. The constants are asserted rather
     * than the wire behaviour because a real hang is not something a test may
     * wait for; what is checked is that the cap exists and is the tighter one.
     */
    expect(BtcPayClient::PAYMENT_METHODS_TIMEOUT)->toBeLessThan(30)
        ->and(BtcPayClient::PAYMENT_METHODS_CONNECT_TIMEOUT)
        ->toBeLessThanOrEqual(BtcPayClient::PAYMENT_METHODS_TIMEOUT);
});
