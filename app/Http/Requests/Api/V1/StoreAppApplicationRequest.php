<?php

namespace App\Http\Requests\Api\V1;

use App\Models\EinundzwanzigPleb;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The body of `POST /api/v1/app/membership/applications` — the NATIVE APP
 * branch of the membership API.
 *
 * The difference to {@see StoreApplicationRequest} is exactly one thing, and
 * it is the whole reason this class exists instead of a flag on the other:
 * the subject. On the NIP-98 surface the pubkey comes from a verified
 * signature and can never be a body field; on the app surface there IS no
 * signature — deliberate (the native app already knows its signer's pubkey
 * and the payment itself is the proof that matters). So here the pubkey is a
 * REQUIRED body field, canonically validated, and the trust boundary shifts
 * accordingly: the calling application (client key) vouches for the value,
 * not the key holder.
 *
 * What that costs, said plainly: a caller with a valid client key can create
 * or update a member record for a pubkey of their choosing and order an
 * invoice on the association's BTCPay quota — never getting access, because
 * paying somebody else's fee is a gift, not an attack. Read endpoints
 * (/me, /payments, /export) do not exist on this surface at all: without a
 * signature they would be a membership-roll oracle for foreign pubkeys.
 *
 * `statutes_accepted` keeps the `accepted` rule from the web branch: the
 * consent is recorded by THIS endpoint and an explicit refusal must fail
 * validation, not silently record a non-consent.
 */
class StoreAppApplicationRequest extends FormRequest
{
    /**
     * Authorisation is the middleware's job: VerifyApiClient establishes which
     * application is calling. Whether that application may act for the named
     * pubkey is the app branch's design answer (see class docblock) — there
     * is no second check here.
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
             * NIP-01 spelling, enforced as everywhere else in this API: hex,
             * 64 characters, lowercase. A pubkey in any other spelling is a
             * different identity in every byte comparison downstream (relay
             * member list, rate limiter, record lookup).
             */
            'pubkey' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],

            /*
             * Same semantics as the web branch: required for the first
             * application, `sometimes` afterwards so a repeat neither needs
             * nor refreshes the joining document.
             */
            'statutes_accepted' => [
                $this->hasRecordedConsent() ? 'sometimes' : 'required',
                'accepted',
            ],

            'application_text' => ['sometimes', 'nullable', 'string', 'max:'.StoreApplicationRequest::APPLICATION_TEXT_MAX_LENGTH],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'no_email' => ['sometimes', 'boolean'],

            /*
             * Literally the same rules as everywhere else, from the same
             * constant on the model — an API surface that accepts what the UI
             * refuses is a second definition of a NIP-05 handle. Unique check
             * ignores the named subject's own record, mirroring the web
             * branch's ignore of the signer's.
             */
            'nip05_handle' => array_merge(
                ['sometimes', 'nullable'],
                explode('|', EinundzwanzigPleb::NIP05_HANDLE_RULES),
                [Rule::unique('einundzwanzig_plebs', 'nip05_handle')->ignore($this->subjectRecord()?->getKey())],
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'statutes_accepted.required' => 'The statutes must be accepted to apply for membership.',
            'statutes_accepted.accepted' => 'The statutes must be accepted to apply for membership.',
        ];
    }

    /**
     * Has the named pubkey already consented to the statutes?
     */
    private function hasRecordedConsent(): bool
    {
        return $this->subjectRecord()?->statutes_accepted_at !== null;
    }

    /**
     * The record the BODY pubkey names, read once.
     *
     * A read, never a create: validation must not bring a record into
     * existence. An unparsable pubkey answers null, which makes the consent
     * mandatory rather than optional — the safe direction.
     */
    private function subjectRecord(): ?EinundzwanzigPleb
    {
        if ($this->subjectResolved) {
            return $this->subject;
        }

        $this->subjectResolved = true;

        $pubkey = (string) $this->input('pubkey');

        $this->subject = preg_match('/^[0-9a-f]{64}$/', $pubkey) === 1
            ? EinundzwanzigPleb::query()->where('pubkey', $pubkey)->first()
            : null;

        return $this->subject;
    }

    private bool $subjectResolved = false;

    private ?EinundzwanzigPleb $subject = null;
}
