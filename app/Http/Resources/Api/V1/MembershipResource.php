<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AssociationStatus;
use App\Enums\MembershipStatus;
use App\Services\BtcPayClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The membership of the calling end user — and of nobody else.
 *
 * The field list is an allowlist by construction: it names what goes out
 * instead of removing what must not. `email`, `application_text`,
 * `archived_application_text` and the internal row id are absent, and the
 * model's `$hidden` is the second line behind that, not the first.
 *
 * Both status fields are reported side by side on purpose. `association_status`
 * is the category the board assigned; `membership_status` is whether the person
 * is a member right now. They differ exactly when a fee year goes unpaid, and a
 * consumer that reads only the first will call a lapsed member active — which
 * is why the second exists and why the API states both rather than picking one.
 *
 * Reused in run 2 for the `POST /membership/applications` response, which
 * answers with the very same shape (201 with `membership_status:
 * "awaiting_payment"`, 200 on a repeat application).
 *
 * @property-read array{pubkey: string, status: array<string, mixed>} $resource
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $status */
        $status = $this->resource['status'];

        /** @var AssociationStatus $associationStatus */
        $associationStatus = $status['association_status'];

        /** @var MembershipStatus $membershipStatus */
        $membershipStatus = $status['membership_status'];

        $invoiceId = $status['invoice_id'];

        return [
            'pubkey' => $this->resource['pubkey'],
            'association_status' => $associationStatus->name,
            'association_status_value' => $associationStatus->value,
            'membership_status' => $membershipStatus->value,
            'statutes_accepted_at' => $status['statutes_accepted_at']?->toIso8601String(),
            'applied_at' => $status['applied_at']?->toIso8601String(),
            'current_year' => [
                'year' => $status['year'],
                'fee' => $status['fee'],
                'currency' => $status['currency'],
                'paid' => $status['paid'],
                'receipt_url' => $status['paid'] && is_string($invoiceId) && $invoiceId !== ''
                    ? app(BtcPayClient::class)->receiptUrl($invoiceId)
                    : null,
            ],
        ];
    }
}
