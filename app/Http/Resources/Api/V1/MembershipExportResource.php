<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\MembershipStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The data-subject access response: everything the association stores about
 * the caller, to the caller and to nobody else.
 *
 * THE ONE PLACE WHERE `email`, `application_text` AND
 * `archived_application_text` LEAVE THE SYSTEM. Every other response on this
 * surface excludes them, and the model's `$hidden` excludes them a second
 * time. Both stay exactly as they are: this class reads the attributes
 * DIRECTLY off the model rather than serialising it, and `$hidden` only ever
 * affected `toArray()`/`toJson()`. So the completeness required of an access
 * request (revDSG Art. 25; Art. 15 GDPR where EU members are concerned) is
 * bought without weakening the protection anywhere else — no `makeVisible()`,
 * no shortened `$hidden`, nothing another endpoint could inherit by accident.
 *
 * Restraint elsewhere would be its own defect. An access response that quietly
 * omitted the free-form text somebody wrote about themselves would be the one
 * kind of incompleteness a data subject cannot detect, and the fields the
 * other endpoints hide are precisely the fields worth asking for.
 *
 * SCOPE, STATED PLAINLY: this is the membership record — identity, contact
 * data, application, annual fees, the grants those fees caused, and the cached
 * public Nostr profile. Data the same person may have produced in other parts
 * of the application (funding proposals, meetup entries) is outside
 * `/api/v1/membership` and is not claimed here.
 *
 * @property-read array{pubkey: string, pleb: EinundzwanzigPleb|null, membership_status: MembershipStatus, profile: Profile|null} $resource
 */
class MembershipExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pleb = $this->resource['pleb'];
        $profile = $this->resource['profile'];

        return [
            'subject' => [
                'pubkey' => $this->resource['pubkey'],
                'npub' => $pleb?->npub,
            ],
            'membership_status' => $this->resource['membership_status']->value,
            'member' => $pleb ? $this->member($pleb) : null,
            'payments' => $pleb
                ? $pleb->paymentEvents->map(fn (PaymentEvent $event): array => $this->payment($event))->all()
                : [],
            'membership_grants' => $pleb
                ? $pleb->membershipGrants->map(fn (MembershipGrant $grant): array => $this->grant($grant))->all()
                : [],
            'nostr_profile' => $profile ? $this->profile($profile) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function member(EinundzwanzigPleb $pleb): array
    {
        return [
            'association_status' => $pleb->association_status->name,
            'association_status_value' => $pleb->association_status->value,
            'applied_at' => $pleb->applied_at?->toIso8601String(),
            'statutes_accepted_at' => $pleb->statutes_accepted_at?->toIso8601String(),
            'email' => $pleb->email,
            'no_email' => (bool) $pleb->no_email,
            'nip05_handle' => $pleb->nip05_handle,
            'application_text' => $pleb->application_text,
            'archived_application_text' => $pleb->archived_application_text,
            'application_for' => $pleb->application_for,
            'created_at' => $pleb->created_at?->toIso8601String(),
            'updated_at' => $pleb->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Fuller than `PaymentEventResource`, and deliberately so: the BTCPay
     * invoice id and the Nostr event id are withheld from ordinary responses
     * because no client needs them, not because they are somebody else's — to
     * their own subject they are simply part of the record.
     *
     * @return array<string, mixed>
     */
    private function payment(PaymentEvent $event): array
    {
        return [
            'year' => (int) $event->year,
            'amount' => (int) $event->amount,
            'currency' => (string) config('einundzwanzig.config.currency'),
            'paid' => (bool) $event->paid,
            'btc_pay_invoice' => $event->btc_pay_invoice,
            'nostr_event_id' => $event->event_id,
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grant(MembershipGrant $grant): array
    {
        return [
            'year' => (int) $grant->year,
            'from_status' => $grant->from_status->name,
            'to_status' => $grant->to_status->name,
            'granted_at' => $grant->granted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Profile $profile): array
    {
        return [
            'name' => $profile->name,
            'display_name' => $profile->display_name,
            'picture' => $profile->picture,
            'banner' => $profile->banner,
            'website' => $profile->website,
            'about' => $profile->about,
            'nip05' => $profile->nip05,
            'lud16' => $profile->lud16,
            'lud06' => $profile->lud06,
            'created_at' => $profile->created_at?->toIso8601String(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];
    }
}
