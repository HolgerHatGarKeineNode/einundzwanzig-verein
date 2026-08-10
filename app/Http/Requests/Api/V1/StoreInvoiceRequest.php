<?php

namespace App\Http\Requests\Api\V1;

use App\Support\InvoiceReturnUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `POST /api/v1/membership/payments/{year}/invoice`.
 *
 * ONE FIELD, AND THE REST STAYS IGNORED. Amount, currency and fee year are
 * still not request values and are not validated here — not even to be
 * rejected, because a client sending `{"amount": 1}` is not to be helped with
 * an error message but to be charged the correct fee. The controller's docblock
 * says so and that has not changed.
 *
 * `return_url` is the exception, and it is one because it is the only value a
 * client can send that LEAVES THIS APPLICATION: it becomes
 * `checkout.redirectURL` in the BTCPay payload, so a BTCPay page will send the
 * payer's browser there. Everything about how it is checked lives in
 * `InvoiceReturnUrl`; what lives here is the decision to refuse rather than
 * correct.
 *
 * WHY 422 AND NOT A SILENT FALLBACK TO THE DEFAULT: an unlisted address is
 * either somebody probing for an open redirect or a deployment whose allowlist
 * was never configured. Quietly using the profile page instead makes those two
 * look exactly alike — both "work", the client sees a 200, and nobody ever
 * finds out. The refusal is the only thing that tells them apart.
 *
 * The field is validated even on the idempotent second call, where it cannot
 * take effect (there is no new BTCPay payload to put it in). Checking it
 * anyway is the fail-closed order: a client must never learn that a rejected
 * address is accepted whenever an invoice happens to exist already.
 */
class StoreInvoiceRequest extends FormRequest
{
    /**
     * Authorisation is the middleware's job, exactly as in
     * `StoreApplicationRequest`: VerifyApiClient establishes which application
     * is calling, VerifyNip98 who it is calling for. The endpoint acts on the
     * signer's own fee year and can act on no other.
     */
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
            /*
             * `sometimes` and `nullable` both mean "keep today's behaviour":
             * an absent field and an explicit null are the case every caller
             * that existed before this parameter had, and both keep the
             * association's own profile page as the redirect target.
             *
             * `max` before the closure so that an absurdly long value is
             * refused by a rule rather than by a parser.
             */
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
     * The validated return address, or null when none was sent.
     *
     * Reading it through one named method rather than through
     * `validated('return_url')` at the call site keeps the controller from
     * ever reaching for `input()` by habit — which would be the unvalidated
     * value, and the whole point of this class.
     */
    public function returnUrl(): ?string
    {
        $returnUrl = $this->validated('return_url');

        return is_string($returnUrl) && $returnUrl !== '' ? $returnUrl : null;
    }
}
