<?php

namespace App\Http\Resources\Api\V1;

use App\Services\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What it costs to join and what an application has to carry — the only
 * /api/v1 response that is not about a person.
 *
 * That is precisely the condition under which this endpoint may skip NIP-98
 * (plan step 27): a client has to be able to show the fee before anybody has
 * signed anything. Every field below is therefore association-wide by
 * construction; the moment one of them depends on the caller, the exception
 * stops being defensible.
 *
 * @property-read MembershipService $resource
 */
class MembershipConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statutes = (array) config('einundzwanzig.config.statutes', []);

        return [
            'fee' => $this->resource->fee(),
            'currency' => $this->resource->currency(),
            'year' => $this->resource->currentYear(),
            'statutes' => [
                'url' => (string) ($statutes['url'] ?? ''),
                'version' => (string) ($statutes['version'] ?? ''),
                'adopted_at' => (string) ($statutes['adopted_at'] ?? ''),
            ],
            'application' => [
                /*
                 * The statutes name no mandatory personal detail — no legal
                 * name, no address, no date of birth (Art. 4/6, invitations
                 * travel electronically or by online publication). The consent
                 * is the one thing an application must carry, because it is the
                 * only document behind a membership that a payment alone
                 * constitutes.
                 */
                'required_fields' => ['statutes_accepted'],
                'optional_fields' => ['application_text', 'email', 'no_email', 'nip05_handle'],
                'application_text_max_length' => 2000,
            ],
        ];
    }
}
