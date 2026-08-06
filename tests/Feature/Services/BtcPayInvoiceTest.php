<?php

use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use App\Services\BtcPayClient;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.btc_pay.base_url', 'https://btcpay.test');
    config()->set('services.btc_pay.store_id', 'store-from-config');

    Http::fake([
        'btcpay.test/*' => Http::sequence()
            ->push(['id' => 'invoice-1', 'checkoutLink' => 'https://btcpay.test/i/invoice-1'], 200)
            ->push(['id' => 'invoice-2', 'checkoutLink' => 'https://btcpay.test/i/invoice-2'], 200),
    ]);
});

it('sends the fee and the currency from the config to btcpay', function () {
    config()->set('einundzwanzig.config.membership_fee', 4242);
    config()->set('einundzwanzig.config.currency', 'SATS');

    $pleb = EinundzwanzigPleb::factory()->create();

    app(MembershipService::class)->createInvoice($pleb, (int) date('Y'), '2026:'.$pleb->pubkey);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://btcpay.test/api/v1/stores/store-from-config/invoices'
            && array_key_exists('currency', $request->data())
            && $request->data()['currency'] === 'SATS'
            && $request->data()['amount'] === 4242;
    });
});

it('takes amount and currency from the config, never from the caller', function () {
    config()->set('einundzwanzig.config.membership_fee', 4242);
    config()->set('einundzwanzig.config.currency', 'SATS');

    $pleb = EinundzwanzigPleb::factory()->create();

    /*
     * Everything a caller controls is in the order id — a request value in the
     * API phase. It must not reach amount or currency.
     */
    app(MembershipService::class)->createInvoice(
        $pleb,
        (int) date('Y'),
        '2026:amount=1&currency=EUR',
    );

    Http::assertSent(function ($request) {
        return $request->data()['amount'] === 4242
            && $request->data()['currency'] === 'SATS';
    });
});

it('reuses the existing invoice instead of creating a second one at btcpay', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $service = app(MembershipService::class);

    $first = $service->createInvoice($pleb, (int) date('Y'), 'first');
    $second = $service->createInvoice($pleb, (int) date('Y'), 'second');

    expect($first['created'])->toBeTrue()
        ->and($second['created'])->toBeFalse()
        ->and($second['payment_event']->btc_pay_invoice)->toBe($first['payment_event']->btc_pay_invoice)
        ->and($second['checkout_url'])->toBe('https://btcpay.test/i/invoice-1');

    // Exactly one invoice was created at BTCPay, not two.
    Http::assertSentCount(1);

    expect(PaymentEvent::query()->where('einundzwanzig_pleb_id', $pleb->id)->count())->toBe(1);
});

it('keeps a single payment event per year when the row already exists', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $service = app(MembershipService::class);

    $service->resolvePaymentEvent($pleb, (int) date('Y'));
    $service->resolvePaymentEvent($pleb, (int) date('Y'));

    expect(PaymentEvent::query()->where('einundzwanzig_pleb_id', $pleb->id)->count())->toBe(1);
});

it('builds checkout and receipt urls from the configured base url', function () {
    $client = app(BtcPayClient::class);

    expect($client->checkoutUrl('abc'))->toBe('https://btcpay.test/i/abc')
        ->and($client->receiptUrl('abc'))->toBe('https://btcpay.test/i/abc/receipt');
});
