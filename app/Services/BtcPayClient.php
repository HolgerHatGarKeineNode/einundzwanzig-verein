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
        return $this->request()
            ->get($this->storeEndpoint('/invoices/'.$invoiceId))
            ->throw()
            ->json();
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
