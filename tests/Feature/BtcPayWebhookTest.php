<?php

use App\Models\PaymentEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('services.btc_pay.webhook_secret', 'test-secret');
    config()->set('services.btc_pay.store_id', 'store-123');
    config()->set('services.btc_pay.base_url', 'https://pay.einundzwanzig.space');

    /*
     * THE ONLY CHANGE TO THIS FILE'S SETUP IN P5, and it is forced by what a
     * BTCPay webhook actually contains: nothing about the amount.
     *
     * Verified against the BTCPay Server sources (master, fetched 2026-08-07):
     * `BTCPayServer.Client/Models/WebhookInvoiceEvent.cs` and the project's own
     * `swagger.template.webhooks.json` give an InvoiceSettled event exactly
     * storeId, invoiceId, metadata, manuallyMarked and overPaid on top of the
     * delivery fields — no amount, no currency. So the controller has to ask
     * the store, and every delivery that books something now performs one
     * authenticated GET.
     *
     * The six cases below are otherwise untouched, character for character.
     * They were written against a controller that booked without asking
     * anybody, and they still pass against one that asks — which is the point
     * of leaving them alone.
     */
    Http::fake([
        'pay.einundzwanzig.space/*' => Http::response([
            'id' => 'inv-abc',
            'status' => 'Settled',
            'amount' => '21000',
            'currency' => 'SATS',
        ]),
    ]);
});

function btcPayPost(array $payload, ?string $secret = 'test-secret'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_BTCPAY_SIG'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', '/webhooks/btcpay', [], [], [], $server, $body);
}

it('marks the payment event as paid on a valid InvoiceSettled event', function () {
    $event = PaymentEvent::factory()->create([
        'btc_pay_invoice' => 'inv-abc',
        'paid' => false,
    ]);

    btcPayPost([
        'type' => 'InvoiceSettled',
        'storeId' => 'store-123',
        'invoiceId' => 'inv-abc',
    ])->assertNoContent();

    expect((bool) $event->fresh()->paid)->toBeTrue();
});

it('rejects a request with an invalid signature', function () {
    $event = PaymentEvent::factory()->create([
        'btc_pay_invoice' => 'inv-abc',
        'paid' => false,
    ]);

    btcPayPost([
        'type' => 'InvoiceSettled',
        'storeId' => 'store-123',
        'invoiceId' => 'inv-abc',
    ], secret: 'wrong-secret')->assertForbidden();

    expect((bool) $event->fresh()->paid)->toBeFalse();
});

it('rejects a request without a signature header', function () {
    btcPayPost([
        'type' => 'InvoiceSettled',
        'storeId' => 'store-123',
        'invoiceId' => 'inv-abc',
    ], secret: null)->assertForbidden();
});

it('rejects an event from an unknown store', function () {
    $event = PaymentEvent::factory()->create([
        'btc_pay_invoice' => 'inv-abc',
        'paid' => false,
    ]);

    btcPayPost([
        'type' => 'InvoiceSettled',
        'storeId' => 'someone-elses-store',
        'invoiceId' => 'inv-abc',
    ])->assertForbidden();

    expect((bool) $event->fresh()->paid)->toBeFalse();
});

it('ignores non-settled event types', function () {
    $event = PaymentEvent::factory()->create([
        'btc_pay_invoice' => 'inv-abc',
        'paid' => false,
    ]);

    btcPayPost([
        'type' => 'InvoiceProcessing',
        'storeId' => 'store-123',
        'invoiceId' => 'inv-abc',
    ])->assertNoContent();

    expect((bool) $event->fresh()->paid)->toBeFalse();
});

it('returns 503 when no webhook secret is configured', function () {
    config()->set('services.btc_pay.webhook_secret', null);

    btcPayPost([
        'type' => 'InvoiceSettled',
        'storeId' => 'store-123',
        'invoiceId' => 'inv-abc',
    ])->assertStatus(503);
});
