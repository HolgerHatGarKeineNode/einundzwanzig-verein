<?php

use App\Http\Resources\Api\V1\InvoiceResource;
use App\Models\PaymentEvent;
use Illuminate\Http\Request;

/*
 * InvoiceResource has no route yet — run 2 wires
 * POST /membership/payments/{year}/invoice and .../refresh to it. It is built
 * and pinned here anyway, because its shape is not a guess:
 * MembershipService::createInvoice() already returns exactly
 * {payment_event, invoice, checkout_url, created}, and both endpoints must
 * answer the same way about the same thing.
 */

beforeEach(function () {
    config([
        'services.btc_pay.base_url' => 'https://pay.einundzwanzig.space',
        'einundzwanzig.config.currency' => 'SATS',
    ]);
});

it('shapes an invoice without leaking the upstream payload', function () {
    $paymentEvent = PaymentEvent::factory()->create([
        'year' => 2026,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => 'inv-abc',
        'event_id' => 'nostr-event-id-of-the-payment',
    ]);

    $shaped = (new InvoiceResource([
        'payment_event' => $paymentEvent,
        'checkout_url' => 'https://pay.einundzwanzig.space/i/inv-abc',
        'created' => true,
    ]))->toArray(Request::create('/api/v1/membership/payments/2026/invoice', 'POST'));

    expect($shaped)->toHaveKeys(['checkout_url', 'created', 'payment'])
        ->and($shaped['created'])->toBeTrue()
        ->and($shaped['payment'])->toHaveKeys(['year', 'amount', 'currency', 'paid', 'receipt_url'])
        // The internal id, the raw BTCPay invoice id and the Nostr event id
        // stay inside: passing an upstream body through unfiltered is how the
        // next field BTCPay adds becomes part of this API unnoticed.
        ->and($shaped['payment'])->not->toHaveKeys(['id', 'btc_pay_invoice', 'event_id'])
        ->and($shaped['payment']['receipt_url'])->toBeNull();
});

it('reports an existing invoice as not created', function () {
    /*
     * `created: false` is the honest report of an idempotent call — the caller
     * is handed the invoice that already existed for that year rather than a
     * second one. A client must not read it as a failure.
     */
    $paymentEvent = PaymentEvent::factory()->paid()->create([
        'year' => 2026,
        'amount' => 21000,
        'btc_pay_invoice' => 'inv-abc',
    ]);

    $shaped = (new InvoiceResource([
        'payment_event' => $paymentEvent,
        'checkout_url' => 'https://pay.einundzwanzig.space/i/inv-abc',
        'created' => false,
    ]))->toArray(Request::create('/api/v1/membership/payments/2026/invoice', 'POST'));

    expect($shaped['created'])->toBeFalse()
        ->and($shaped['payment']['paid'])->toBeTrue()
        ->and($shaped['payment']['receipt_url'])
        ->toBe('https://pay.einundzwanzig.space/i/inv-abc/receipt');
});
