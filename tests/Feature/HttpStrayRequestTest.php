<?php

use App\Services\BtcPayClient;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

/*
 * The stray-request guard from tests/Pest.php, proven by its effect.
 *
 * Asserting Http::preventingStrayRequests() would only restate that a setter
 * was called. What has to hold is that an unfaked request ENDS the test — with
 * an exception, right where it was fired, and not as a timeout minutes later
 * against the association's live payment provider. So every case below either
 * catches the exception or proves it does not fly.
 *
 * The host is the real BTCPay origin on purpose: it is the one this suite
 * would actually reach if the guard failed, because config/services.php falls
 * back to it and phpunit.xml does not override BTC_PAY_BASE_URL. Should any of
 * these tests ever go green for the wrong reason, they go green without a
 * packet leaving the machine — a stray request that is not refused is caught
 * one line later by the missing exception.
 */

it('turns an unfaked outgoing request into a failure instead of a network call', function () {
    expect(fn () => Http::get('https://pay.einundzwanzig.space/api/v1/stores/store-1/invoices'))
        ->toThrow(
            StrayRequestException::class,
            'Attempted request to [https://pay.einundzwanzig.space/api/v1/stores/store-1/invoices] without a matching fake.',
        );
});

it('leaves a request the test did fake completely untouched', function () {
    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-1'], 200)]);

    expect(Http::get('https://pay.einundzwanzig.space/api/v1/stores/store-1/invoices')->json())
        ->toBe(['id' => 'inv-1']);
});

/*
 * The realistic mistake is not "no fake at all", it is a fake for the wrong
 * host: a test stubs btcpay.test while the code under test still reads the
 * configured base URL. Without the guard that request goes out for real even
 * though the file is full of Http::fake().
 */
it('still refuses a host the test forgot to fake', function () {
    Http::fake(['btcpay.test/*' => Http::response(['id' => 'inv-1'], 200)]);

    expect(fn () => Http::get('https://pay.einundzwanzig.space/i/inv-1'))
        ->toThrow(StrayRequestException::class);
});

/*
 * Through the production client rather than the facade directly: BtcPayClient
 * builds its request via Http::withHeaders(), and only createPendingRequest()
 * carries the factory's flag over to the PendingRequest that actually sends.
 * A guard that covered Http::get() but not the wrapper would protect nothing
 * that matters.
 */
it('reaches requests made by the real BTCPay client', function () {
    config()->set('services.btc_pay.base_url', 'https://pay.einundzwanzig.space');
    config()->set('services.btc_pay.store_id', 'store-1');

    expect(fn () => app(BtcPayClient::class)->getInvoice('inv-1'))
        ->toThrow(StrayRequestException::class);
});

/*
 * Http::swap(new Factory) hands the facade a factory that has never been
 * armed, which is how three BTCPay test files get a fake that outranks an
 * earlier one. The rebinding() hook in tests/Pest.php re-arms it; without that
 * hook this test performs a real request to the payment provider.
 */
it('survives a test swapping the HTTP factory out from under it', function () {
    Http::swap(new HttpClientFactory);

    expect(fn () => Http::get('https://pay.einundzwanzig.space/i/inv-1'))
        ->toThrow(StrayRequestException::class);
});

it('keeps a fake registered after a swap working', function () {
    Http::swap(new HttpClientFactory);

    Http::fake(['pay.einundzwanzig.space/*' => Http::response(['id' => 'inv-2'], 200)]);

    expect(Http::get('https://pay.einundzwanzig.space/i/inv-2')->json())
        ->toBe(['id' => 'inv-2']);
});
