<?php

namespace App\Http\Requests\Api\V1;

use App\Support\InvoiceReturnUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `POST /api/v1/app/membership/payments/{year}/invoice` — the
 * NATIVE APP branch of the invoice endpoint.
 *
 * Identical to {@see StoreInvoiceRequest} except for the one field the app
 * branch cannot do without: `pubkey`, required and canonically validated,
 * because there is no signature to name the subject (see
 * {@see StoreAppApplicationRequest} for what that means).
 *
 * `return_url` keeps the allowlist: it is the one value a client sends that
 * leaves the association (BTCPay redirects the payer's browser there), so it
 * is checked against the server-side list — for the app the entry
 * `http://127.0.0.1/verein/zurueck`, the NativePHP local server the checkout
 * can reach on the same device.
 */
class StoreAppInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pubkey' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],

            'return_url' => [
                'sometimes',
                'nullable',
                'string',
                'max:'.InvoiceReturnUrl::MAX_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! InvoiceReturnUrl::isAllowed($value)) {
                        $fail('The :attribute is not an allowed return address.');
                    }
                },
            ],
        ];
    }

    /**
     * The canonically validated subject of the invoice, from the body.
     */
    public function subjectPubkey(): string
    {
        return (string) $this->validated('pubkey');
    }

    public function returnUrl(): ?string
    {
        $returnUrl = $this->validated('return_url');

        return is_string($returnUrl) && $returnUrl !== '' ? $returnUrl : null;
    }
}
