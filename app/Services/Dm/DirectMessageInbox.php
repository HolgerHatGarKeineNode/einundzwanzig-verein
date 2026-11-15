<?php

namespace App\Services\Dm;

use App\Enums\DmDeliveryStatus;
use App\Models\DmDelivery;
use App\Models\EinundzwanzigPleb;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The member's "Nachrichten vom Verein": every message addressed to them
 * whose sending has finished, whether or not Nostr carried it — a member
 * without a DM inbox (`no_inbox`) or whose relays refused (`failed`) reads it
 * here. Deliveries still `pending` or `claimed` are not shown yet, so a
 * paused sending does not reach the portal either.
 *
 * Always scoped to one member; an erased member has no inbox.
 */
final class DirectMessageInbox
{
    /** @var list<string> */
    public const array SHOWN_STATUSES = [
        DmDeliveryStatus::Sent->value,
        DmDeliveryStatus::NoInbox->value,
        DmDeliveryStatus::Failed->value,
    ];

    public function __construct(private readonly DirectMessageText $text) {}

    /**
     * The member's deliveries in the inbox, newest first.
     *
     * @return Builder<DmDelivery>
     */
    public function query(EinundzwanzigPleb $member): Builder
    {
        $query = DmDelivery::query()
            ->where('einundzwanzig_pleb_id', $member->getKey())
            ->whereIn('status', self::SHOWN_STATUSES)
            ->orderByDesc('id');

        if ($member->isErased()) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * @return Collection<int, array{delivery_id: int, type: string, label: string, text: string|null, received_at: string, seen_at: string|null}>
     */
    public function messages(EinundzwanzigPleb $member): Collection
    {
        return $this->query($member)
            ->with('run.message')
            ->get()
            ->map(fn (DmDelivery $delivery): array => [
                'delivery_id' => (int) $delivery->getKey(),
                'type' => $delivery->run->message->type->value,
                'label' => $delivery->run->message->type->label(),
                'text' => $this->textOf($delivery, $member),
                'received_at' => $delivery->created_at->toIso8601String(),
                'seen_at' => $delivery->seen_at?->toIso8601String(),
            ]);
    }

    public function unreadCount(EinundzwanzigPleb $member): int
    {
        return $this->query($member)->whereNull('seen_at')->count();
    }

    /**
     * Mark one of the member's messages as seen. The first moment stays;
     * someone else's delivery, or one not in the inbox, changes nothing.
     */
    public function markSeen(EinundzwanzigPleb $member, int $deliveryId): bool
    {
        return $this->query($member)
            ->reorder()
            ->whereKey($deliveryId)
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]) === 1;
    }

    /**
     * The text as sent over Nostr; a delivery that never got that far
     * (`no_inbox`, a failure before the wrap) is filled in now for the same
     * member. Null when the stored message no longer passes its rules.
     */
    private function textOf(DmDelivery $delivery, EinundzwanzigPleb $member): ?string
    {
        if ($delivery->plaintext !== null) {
            return $delivery->plaintext;
        }

        try {
            return $this->text->render($delivery->run->message, $member);
        } catch (DirectMessageTextInvalid) {
            return null;
        }
    }
}
