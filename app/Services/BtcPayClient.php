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
