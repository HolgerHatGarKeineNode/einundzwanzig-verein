<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Requests\Api\V1\StoreAppApplicationRequest;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Models\EinundzwanzigPleb;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use swentel\nostr\Key\Key;

/**
 * POST /api/v1/app/membership/applications — record an application, APP branch.
 *
 * Semantics are those of {@see StoreApplicationController}: records the
 * application and the consent to the statutes, changes NO status, answers 201
 * on first consent and 200 on a repeat which leaves the timestamp untouched.
 * The ONLY difference is where the subject comes from: the validated body
 * pubkey (see {@see StoreAppApplicationRequest} for the trust that implies)
 * instead of the verified NIP-98 signature.
 */
class StoreAppApplicationController extends ApiV1Controller
{
    /**
     * @see StoreApplicationController::CONTACT_FIELDS
     *
     * @var list<string>
     */
    private const CONTACT_FIELDS = ['application_text', 'email', 'no_email', 'nip05_handle'];

    /**
     * Apply for membership on behalf of a named pubkey (app branch).
     *
     * The application of `POST /api/v1/membership/applications` without a
     * signature: the subject is the `pubkey` field of the body, and the
     * calling application vouches for it. Everything else is identical — it
     * records the data and the consent to the statutes, and it changes NO
     * status. The statutes tie the membership to the payment of the annual fee
     * (Art. 4), so this call alone makes nobody a member.
     *
     * 201 the first time consent is recorded, 200 on a repeat. The consent
     * timestamp is the joining document: a repeat leaves it untouched and
     * updates only the contact fields actually sent, with an absent field left
     * alone and an explicit `null` clearing the stored value.
     *
     * `association_status` and `paid` cannot be written through this endpoint.
     * The membership category is raised by a settled fee and by nothing else —
     * which is what keeps an unsigned surface from being able to grant
     * anything.
     */
    public function __invoke(StoreAppApplicationRequest $request): JsonResponse
    {
        $pubkey = (string) $request->validated('pubkey');
        $pleb = $this->resolveOrCreateSubject($pubkey);

        $isFirstConsent = $pleb->statutes_accepted_at === null;

        $contact = array_intersect_key(
            $request->validated(),
            array_flip(self::CONTACT_FIELDS)
        );

        if ($contact !== []) {
            $pleb->fill($contact)->save();
        }

        $pleb = $this->membership->apply($pleb);

        return (new MembershipResource([
            'pubkey' => $pubkey,
            'status' => $this->membership->status($pleb->refresh()),
        ]))->response()->setStatusCode($isFirstConsent ? 201 : 200);
    }

    /**
     * @see StoreApplicationController::resolveOrCreateSubject()
     */
    private function resolveOrCreateSubject(string $pubkey): EinundzwanzigPleb
    {
        try {
            return EinundzwanzigPleb::query()->firstOrCreate(
                ['pubkey' => $pubkey],
                ['npub' => (new Key)->convertPublicKeyToBech32($pubkey)]
            );
        } catch (UniqueConstraintViolationException) {
            return EinundzwanzigPleb::query()->where('pubkey', $pubkey)->firstOrFail();
        }
    }
}
