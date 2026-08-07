<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Requests\Api\V1\StoreApplicationRequest;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Models\EinundzwanzigPleb;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use swentel\nostr\Key\Key;

/**
 * POST /api/v1/membership/applications — record an application.
 *
 * It changes NO status. The statutes are explicit that the membership begins
 * with the payment of the annual fee (Art. 4), so an application is exactly
 * two things: the data a member wants on record, and the consent that carries
 * the later membership. Everything else waits for the money.
 *
 * THE ONLY ENDPOINT ON THIS SURFACE THAT BRINGS A MEMBER RECORD INTO
 * EXISTENCE, and it may, because it is a POST behind a verified signature: the
 * caller has proven possession of the private key of the pubkey being written.
 * That is the same bar `NostrAuth::ensurePleb()` clears on the web side. The
 * read endpoints deliberately do not create anything — an unauthenticated GET
 * writing member rows was the defect P1 removed.
 *
 * 201 or 200 turns on the consent timestamp, not on whether a row was created:
 * a long-standing member from before this field existed applies for the first
 * time too, and what comes into being in that request is the consent, not the
 * person. A repeat application answers 200, leaves the timestamp untouched
 * (it is the joining document and must not be backdated or refreshed) and
 * updates only the contact data that was actually sent.
 */
class StoreApplicationController extends ApiV1Controller
{
    /**
     * The request fields that may reach the member record, and the complete
     * list of them.
     *
     * An allowlist rather than the validated array wholesale: `paid`,
     * `association_status`, `pubkey` and `npub` cannot be written through this
     * endpoint because they are not named here, and a field added to the
     * form request later cannot start writing to the model by accident.
     *
     * @var list<string>
     */
    private const CONTACT_FIELDS = ['application_text', 'email', 'no_email', 'nip05_handle'];

    /**
     * Apply for membership.
     *
     * Records the application and the consent to the statutes. IT CHANGES NO
     * STATUS: the statutes are explicit that the membership begins with the
     * payment of the annual fee (Art. 4), so the next step after a successful
     * application is `POST /api/v1/membership/payments/{year}/invoice`.
     *
     * 201 when the consent is recorded for the first time, 200 on a repeat.
     * The distinction is the consent, not the record: a long-standing member
     * from before this field existed applies for the first time too. A repeat
     * application leaves `statutes_accepted_at` untouched — it is the joining
     * document and must not be backdated or refreshed — and updates only the
     * contact fields actually sent.
     *
     * An absent field is left alone; an explicit `null` clears the stored
     * value. The difference matters because the body is signed as a whole:
     * were absence treated as "clear it", dropping a field from the payload
     * would wipe data the user never touched.
     *
     * `association_status`, `paid`, `pubkey` and `npub` cannot be written
     * through this endpoint. The subject is the signing pubkey and the
     * membership category is raised by a settled fee and by nothing else.
     */
    public function __invoke(StoreApplicationRequest $request): JsonResponse
    {
        $pubkey = $this->subjectPubkey($request);
        $pleb = $this->resolveOrCreateSubject($pubkey);

        $isFirstConsent = $pleb->statutes_accepted_at === null;

        $contact = $this->contactAttributes($request);

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
     * Only the fields the client actually sent.
     *
     * An absent key is left alone, an explicit `null` clears the value. The
     * difference matters because the body is signed as a whole: were absence
     * treated as "clear it", a client could drop a field from the payload
     * without breaking the signature and wipe data the user never touched
     * (audit item 6). Absence means "no instruction", and no instruction means
     * no write.
     *
     * @return array<string, mixed>
     */
    private function contactAttributes(StoreApplicationRequest $request): array
    {
        $validated = $request->validated();

        return array_intersect_key(
            $validated,
            array_flip(array_intersect(self::CONTACT_FIELDS, array_keys($validated)))
        );
    }

    /**
     * The signer's record, created if this is their first contact.
     *
     * `firstOrCreate` on the uniquely indexed pubkey, with the race settled by
     * the index rather than by the application: two applications arriving at
     * once must end up as one member, not as two records each with their own
     * membership and their own rate-limit bucket. Nothing but the identity is
     * written here — `association_status` keeps its column default DEFAULT(1),
     * because only a paid fee may raise it.
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
