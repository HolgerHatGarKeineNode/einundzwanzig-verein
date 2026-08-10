<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the BTCPay Greenfield API.
 *
 * The base URL and the store id used to be string literals inside the Volt
 * component while the webhook already compared against
 * `config('services.btc_pay.store_id')`. Changing BTC_PAY_STORE_ID therefore
 * moved the webhook to the new store but left invoice creation on the old one —
 * after which the webhook rejected every delivery with 403. Both sides now read
 * the same configuration.
 */
class BtcPayClient
{
    /**
     * Connect and overall timeout, in seconds, for `invoicePaymentMethods()`.
     *
     * DELIBERATELY SHORTER than the client's default. Every caller of that
     * method treats a failure as "no BOLT11" and answers anyway, so a long
     * wait buys nothing but a slow invoice endpoint: the checkout URL is
     * already known at that point and the payer can be sent on their way. The
     * default timeout would let a hanging BTCPay stall a request that has
     * nothing left to learn from it.
     */
    public const PAYMENT_METHODS_CONNECT_TIMEOUT = 2;

    public const PAYMENT_METHODS_TIMEOUT = 4;

    public function baseUrl(): string
    {
        return rtrim((string) config('services.btc_pay.base_url'), '/');
    }

    public function storeId(): string
    {
        return (string) config('services.btc_pay.store_id');
    }

    /**
     * Public checkout page of an invoice — where a payer is sent to.
     */
    public function checkoutUrl(string $invoiceId): string
    {
        return $this->baseUrl().'/i/'.$invoiceId;
    }

    /**
     * Public receipt of a settled invoice.
     */
    public function receiptUrl(string $invoiceId): string
    {
        return $this->checkoutUrl($invoiceId).'/receipt';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload): array
    {
        return $this->request()
            ->post($this->storeEndpoint('/invoices'), $payload)
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoice(string $invoiceId): array
    {
        /*
         * The `?? []` is not cosmetic. A 200 with an empty or non-JSON body
         * makes json() return null, and the declared array return type turned
         * that into a TypeError deep inside the payment verification — an
         * upstream oddity surfacing as an internal error instead of as "this
         * invoice cannot be verified". An empty array reaches the checks and
         * is refused there, which is the answer that belongs to it.
         */
        return $this->request()
            ->get($this->storeEndpoint('/invoices/'.$invoiceId))
            ->throw()
            ->json() ?? [];
    }

    /**
     * The ways one invoice can be paid — one entry per payment method.
     *
     * THE ONLY PLACE THE LIGHTNING PAYMENT REQUEST (BOLT11) COMES FROM.
     * Neither the create response nor `getInvoice()` carries it, which is why
     * reading it costs this extra round trip rather than falling out of a call
     * that already happens. Measured against the live store before it was
     * written (P2, `p2-machbarkeit.md` section (a)): the BOLT11 is the
     * `destination` of the entry whose `paymentMethodId` is exactly `BTC-LN`.
     *
     * The store-scoped path is used deliberately, matching `getInvoice()` and
     * `updateInvoiceMetadata()`: it is the form this installation's BTCPay is
     * known to answer.
     *
     * The `?? []` is not cosmetic, for the same reason it is not in
     * `getInvoice()`: a 200 with an empty or non-JSON body makes json() return
     * null, and the declared array return type would turn that into a
     * TypeError instead of into "this invoice has no methods to report". An
     * empty list reaches the caller and is answered with a null BOLT11, which
     * is the answer that belongs to it.
     *
     * Non-array entries are dropped the same way `listInvoices()` drops them,
     * so a caller may iterate without re-checking every element.
     *
     * THIS METHOD THROWS like its neighbours. Whether a failure is fatal is
     * not the client's decision to make — `MembershipService` catches it and
     * answers with a null BOLT11, because the field is additive and the
     * checkout URL still works. Swallowing the failure here would take that
     * choice away from every future caller.
     *
     * @return list<array<string, mixed>>
     */
    public function invoicePaymentMethods(string $invoiceId): array
    {
        $methods = $this->request()
            ->connectTimeout(self::PAYMENT_METHODS_CONNECT_TIMEOUT)
            ->timeout(self::PAYMENT_METHODS_TIMEOUT)
            ->get($this->storeEndpoint('/invoices/'.$invoiceId.'/payment-methods'))
            ->throw()
            ->json() ?? [];

        if (! is_array($methods)) {
            return [];
        }

        return array_values(array_filter($methods, 'is_array'));
    }

    /**
     * Invoices of the store, newest first.
     *
     * The only way to find an invoice that exists at BTCPay and nowhere here:
     * an orphan is by definition unreachable from this database.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function listInvoices(array $query = []): array
    {
        $invoices = $this->request()
            ->get($this->storeEndpoint('/invoices'), $query)
            ->throw()
            ->json() ?? [];

        return array_values(array_filter($invoices, 'is_array'));
    }

    /**
     * Replace an invoice's metadata.
     *
     * Used by the reconciliation command to strip the pubkey, the npub and the
     * naming itemDesc out of invoices belonging to erased members —
     * `erasePersonalData()` drops this side's pointer but cannot reach into
     * the payment provider, and the erasure must not fail because BTCPay is
     * down (see the residual gaps documented there).
     *
     * The store-scoped path is used deliberately, matching `getInvoice()`: it
     * is the form this installation's BTCPay is known to answer. Newer
     * versions also accept `/api/v1/invoices/{id}` and resolve the store
     * themselves, but switching a verified call site to an unverified one to
     * chase a shorter URL would be a change with no upside.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function updateInvoiceMetadata(string $invoiceId, array $metadata): array
    {
        return $this->request()
            ->put($this->storeEndpoint('/invoices/'.$invoiceId), ['metadata' => $metadata])
            ->throw()
            ->json() ?? [];
    }

    protected function storeEndpoint(string $path): string
    {
        return $this->baseUrl().'/api/v1/stores/'.$this->storeId().$path;
    }

    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'token '.config('services.btc_pay.api_key'),
        ]);
    }
}
