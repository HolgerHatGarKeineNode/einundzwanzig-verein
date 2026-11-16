<?php

namespace App\Jobs\Dm;

use App\Models\DmInboxMessage;
use App\Services\Nostr\DirectMessageNotBuildable;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\NotificationKeyUnavailable;
use App\Services\Nostr\RelayPublishStatus;
use App\Services\Nostr\RelayTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one answer of the board as a NIP-17 direct message from the
 * notification key to the member's kind 10050 inbox relays.
 *
 * Runs once (`tries = 1`): a relay that took the message must not get it a
 * second time from a retry. The row's status says what happened: `sent` when
 * at least one relay accepted it, `no_inbox` when the member has no relay
 * list, `failed` otherwise. Only a `queued` row is sent, so a job that runs
 * twice sends once.
 */
class SendInboxReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $messageId) {}

    public function handle(RelayTransport $transport, DirectMessageSigner $signer): void
    {
        $claimed = DmInboxMessage::query()
            ->whereKey($this->messageId)
            ->where('direction', DmInboxMessage::OUT)
            ->where('status', DmInboxMessage::STATUS_QUEUED)
            ->update(['status' => 'sending']);

        if ($claimed !== 1) {
            return;
        }

        $message = DmInboxMessage::query()->with('member.dmRelays')->findOrFail($this->messageId);
        $member = $message->member;
        $relays = $member?->dmRelays?->relays ?? [];

        if ($member === null || $member->isErased() || $relays === []) {
            $message->update(['status' => DmInboxMessage::STATUS_NO_INBOX]);

            return;
        }

        try {
            $wrapped = $signer->wrapFor($member, $message->body);
        } catch (DirectMessageNotBuildable|NotificationKeyUnavailable) {
            $message->update(['status' => DmInboxMessage::STATUS_FAILED]);

            return;
        }

        $accepted = false;

        foreach ($relays as $url) {
            try {
                $accepted = $transport->publish($url, $wrapped->wrap)->status === RelayPublishStatus::Accepted || $accepted;
            } catch (Throwable) {
                // One relay that throws does not stop the others.
            }
        }

        $message->update([
            'rumor_id' => $wrapped->rumorId,
            'status' => $accepted ? DmInboxMessage::STATUS_SENT : DmInboxMessage::STATUS_FAILED,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        DmInboxMessage::query()->whereKey($this->messageId)->where('status', 'sending')->update(['status' => DmInboxMessage::STATUS_FAILED]);
    }
}
