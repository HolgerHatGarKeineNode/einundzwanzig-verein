<?php

namespace App\Http\Requests\Api\V1;

use App\Models\EinundzwanzigPleb;
use App\Support\ApiIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The body of `POST /api/v1/membership/applications`.
 *
 * Two things are deliberately NOT in here. The subject: it comes from the
 * NIP-98 signature and never from the body, so there is no `pubkey` field to
 * validate — a body that names one is stopped by
 * `ApiIdentity::guardClaimedSubject()` before this class runs. And the
 * membership category: `association_status` is raised by a paid fee and by
 * nothing else, so accepting it here — even to reject it — would suggest it
 * were negotiable.
 *
 * `sometimes` on every optional field is the whole point rather than a
 * shorthand: it distinguishes "the client did not mention this field" from
 * "the client sent null". Only the second clears a stored value. Without that
 * distinction a client could quietly drop a field from a signed body and erase
 * data that the user never asked to erase (audit item 6).
 */
class StoreApplicationRequest extends FormRequest
{
    /**
     * The maximum length of the free-form application text, published to
     * clients as `application.application_text_max_length` by
     * `GET /membership/config`. The two must not drift apart: a client that
     * renders a counter from the config would otherwise let people write past
     * a limit this class then refuses.
     */
    public const APPLICATION_TEXT_MAX_LENGTH = 2000;

    private ?EinundzwanzigPleb $subject = null;

    private bool $subjectResolved = false;

    /**
     * Authorisation is the middleware's job: VerifyApiClient establishes which
     * application is calling, VerifyNip98 who it is calling for. There is no
     * third question left for this class to answer — the endpoint acts on the
     * signer's own record and can act on no other.
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
             * Required for a first application, optional afterwards.
             *
             * The consent is given once and is not an annual ritual (plan step
             * 26): the stored timestamp is the document the membership rests
             * on, so a second application neither needs it again nor may
             * overwrite it. Demanding it again would also be a lie about what
             * happens — the value would be discarded.
             *
             * `accepted` rather than `boolean`: an explicit `false` is a
             * refusal and must fail, not silently record a non-consent.
             */
            'statutes_accepted' => [
                $this->hasRecordedConsent() ? 'sometimes' : 'required',
                'accepted',
            ],

            'application_text' => ['sometimes', 'nullable', 'string', 'max:'.self::APPLICATION_TEXT_MAX_LENGTH],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'no_email' => ['sometimes', 'boolean'],

            /*
             * Literally the same rules as the two Livewire write paths, read
             * from one constant on the model — see
             * `EinundzwanzigPleb::NIP05_HANDLE_RULES` for what each part is
             * for. An API that accepts what the UI refuses does not add a
             * feature, it adds a second definition of a NIP-05 handle.
             *
             * The shape is what makes the value a handle at all. It becomes
             * the local part of `<handle>@einundzwanzig.space` served from a
             * public .well-known/nostr.json, so an `@` or a `/` breaks that
             * construction outright — and the value is echoed by the
             * UNAUTHENTICATED `GET /api/members/{year}`, where
             * `<script>alert(1)</script>`, `../../etc/passwd` and
             * `alice@evil.example` were all accepted and handed straight back
             * before this rule existed.
             *
             * Unique because the column is, and a duplicate would otherwise
             * surface as an unhandled database error — a 500 for what is
             * plainly a client-side mistake. Refusing a taken handle discloses
             * nothing: it is a public identifier by design.
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
     * Has this pubkey already consented to the statutes?
     */
    private function hasRecordedConsent(): bool
    {
        return $this->subjectRecord()?->statutes_accepted_at !== null;
    }

    /**
     * The signer's member record, read once.
     *
     * Read through the same verified pubkey the controller uses, never through
     * a request field. `pubkeyOrNull()` can only be null if this class were
     * ever mounted outside the api.v1 group, and then the safe reading is "no
     * record", which makes the consent mandatory rather than optional.
     */
    private function subjectRecord(): ?EinundzwanzigPleb
    {
        if ($this->subjectResolved) {
            return $this->subject;
        }

        $this->subjectResolved = true;

        $pubkey = ApiIdentity::pubkeyOrNull($this);

        $this->subject = $pubkey === null
            ? null
            : EinundzwanzigPleb::query()->where('pubkey', $pubkey)->first();

        return $this->subject;
    }
}
