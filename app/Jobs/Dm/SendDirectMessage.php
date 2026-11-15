<?php

namespace App\Jobs\Dm;

use App\Enums\DmDeliveryStatus;
use App\Models\DmDelivery;
use App\Models\DmDeliveryRelay;
use App\Models\EinundzwanzigPleb;
use App\Services\Dm\DirectMessagePause;
use App\Services\Dm\DirectMessageText;
use App\Services\Dm\DirectMessageTextInvalid;
use App\Services\Nostr\DirectMessageNotBuildable;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\NotificationKeyUnavailable;
use App\Services\Nostr\RelayPublishResult;
use App\Services\Nostr\RelayPublishStatus;
use App\Services\Nostr\RelayTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hands one delivery's gift wrap to the recipient's inbox relays.
 *
 * The order of the steps is what makes a retry harmless:
 *
 *  1. Claim the delivery with one conditional UPDATE. Only `pending`, `failed`
 *     and a claim left behind by a dead worker can be claimed; a second job
 *     for the same delivery, or a job for a delivery that is already `sent`,
 *     changes nothing and publishes nothing; the former comes back once the
 *     other claim has expired.
 *  2. No kind 10050 list → `no_inbox`, before anything is built or dialled
 *     (NIP-17: no list means "not ready to receive messages").
 *  3. Build the wrap once and store it before the first publish. Every later
 *     attempt republishes the stored wrap, so relays and clients see one event
 *     id, never a second copy of the message.
 *  4. Publish to each inbox relay that has not answered `OK true` yet.
 *
 * Relays are foreign servers. Whatever one does — reject, stall, break the
 * connection, make the vendor library throw a TypeError — ends as a result on
 * that relay's row and, if no relay took the wrap, as a `failed` delivery.
 * Only NotificationKeyUnavailable escapes on purpose: then no message of this
 * installation can be signed, and that has to be seen, not counted. Anything
 * else that escapes (a database error, say) first turns the claim into
 * `failed`, so a resume or the queue's retry picks the delivery up again.
 */
class SendDirectMessage implements ShouldQueue
{
    use Queueable;

    /**
     * Hard ceiling for one run, enforced by the queue worker. The transport
     * gives each relay a 10 s deadline (NostrRelayTransport::DEADLINE_SECONDS),
     * so three relays fit; a relay that trickles its handshake past that
     * deadline is stopped here. Must stay below the queue's `retry_after`, or
     * a second worker picks the job up while the first still runs.
     */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** pleb_dm_relays holds at most three; this holds even if that ever changes. */
    public const int MAX_INBOX_RELAYS = 3;

    /**
     * A claim older than this is taken to be from a worker that died
     * mid-run. Longer than $timeout, so a living worker never loses its claim;
     * shorter than the queue's `retry_after` (90 s), so the job the queue
     * hands out again after a dead worker finds the claim expired.
     */
    public const int CLAIM_EXPIRES_AFTER_SECONDS = 75;

    private const int MAX_STORED_MESSAGE_LENGTH = 500;

    private const string ERASED = 'The recipient has been erased.';

    public function __construct(public int $deliveryId) {}

    public function handle(RelayTransport $transport, DirectMessageSigner $signer, DirectMessageText $text, DirectMessagePause $pause): void
    {
        // Paused after this job was queued: the delivery is left in whatever
        // state it has (`pending`, `failed` awaiting its retry, or an expired
        // `claimed`), this job ends, and DirectMessagePause::resume() queues
        // it again through the fan-out.
        if ($pause->isPaused()) {
            return;
        }

        if (! $this->claim()) {
            // Another worker holds a live claim. Returning would let the queue
            // delete this job as done; if that worker then dies, nothing would
            // ever pick the delivery up again. Come back after the claim expired.
            if ($this->isClaimed()) {
                $this->release(self::CLAIM_EXPIRES_AFTER_SECONDS);
            }

            return;
        }

        try {
            $this->send($transport, $signer, $text);
        } catch (Throwable $exception) {
            // Whatever escapes after the claim must not leave it behind: the
            // queue retries this job only if attempts remain, and failed() runs
            // only after the last one. The class alone, a message may quote data.
            $this->failClaim($exception::class);

            throw $exception;
        }
    }

    private function send(RelayTransport $transport, DirectMessageSigner $signer, DirectMessageText $text): void
    {
        $delivery = DmDelivery::query()
            ->with(['recipient.dmRelays', 'run.message', 'relays'])
            ->findOrFail($this->deliveryId);

        $recipient = $delivery->recipient;

        if ($recipient->isErased()) {
            $this->finish($delivery, DmDeliveryStatus::NotSent, self::ERASED);

            return;
        }

        $relayUrls = $this->inboxRelays($recipient);

        if ($relayUrls === []) {
            $this->finish($delivery, DmDeliveryStatus::NoInbox);

            return;
        }

        try {
            $wrap = $this->storedOrNewWrap($delivery, $recipient, $signer, $text);
        } catch (NotificationKeyUnavailable $exception) {
            $this->finish($delivery, DmDeliveryStatus::NoSenderKey, $exception->getMessage());

            throw $exception;
        } catch (DirectMessageNotBuildable|DirectMessageTextInvalid $exception) {
            $this->finish($delivery, DmDeliveryStatus::NotSent, $exception->getMessage());

            return;
        }

        if ($wrap === null) {
            $this->finish($delivery, DmDeliveryStatus::NotSent, self::ERASED);

            return;
        }

        $accepted = 0;
        $retryable = false;

        foreach ($relayUrls as $relayUrl) {
            if ($delivery->relays->firstWhere('relay_url', $relayUrl)?->status === RelayPublishStatus::Accepted) {
                $accepted++;

                continue;
            }

            try {
                $result = $transport->publish($relayUrl, $wrap);
            } catch (NotificationKeyUnavailable $exception) {
                $this->recordRelay($delivery, $relayUrl, RelayPublishResult::failed($exception->getMessage()));
                $this->finish($delivery, DmDeliveryStatus::NoSenderKey, $exception->getMessage());

                throw $exception;
            } catch (Throwable $exception) {
                // The transport promises results, not exceptions; the vendor
                // library underneath does not keep that promise (an array
                // where a RelayResponse is declared surfaces as a TypeError).
                $result = RelayPublishResult::failed($exception::class.': '.$exception->getMessage());
            }

            if (! $this->recordRelay($delivery, $relayUrl, $result)) {
                $this->finish($delivery, DmDeliveryStatus::Failed, self::ERASED);

                return;
            }

            if ($result->accepted) {
                $accepted++;
            } elseif ($result->status === RelayPublishStatus::Failed) {
                $retryable = true;
            }
        }

        if ($accepted > 0) {
            $this->finish($delivery, DmDeliveryStatus::Sent);

            return;
        }

        $this->finish($delivery, DmDeliveryStatus::Failed, 'No inbox relay accepted the message (0 of '.count($relayUrls).').');

        // Rejected and Refused will answer the same again; only Failed
        // (unreachable, timed out) is worth another attempt of the queue.
        if ($retryable) {
            $this->release($this->backoff[min($this->attempts(), count($this->backoff)) - 1]);
        }
    }

    /**
     * The worker gave up on this job (timeout, last attempt threw): a claim it
     * still holds must not stay `claimed` until the expiry.
     */
    public function failed(?Throwable $exception): void
    {
        $this->failClaim($exception?->getMessage() ?? 'The job failed.');
    }

    /**
     * A delivery still `claimed` becomes `failed`; one that already ended in
     * any other state keeps it.
     */
    private function failClaim(string $error): void
    {
        DmDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', DmDeliveryStatus::Claimed->value)
            ->update([
                'status' => DmDeliveryStatus::Failed->value,
                'claimed_at' => null,
                'error' => mb_substr($error, 0, self::MAX_STORED_MESSAGE_LENGTH),
            ]);
    }

    private function isClaimed(): bool
    {
        return DmDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', DmDeliveryStatus::Claimed->value)
            ->exists();
    }

    /**
     * One conditional UPDATE; whoever changes the row owns the delivery.
     */
    private function claim(): bool
    {
        $now = now();

        return DmDelivery::query()
            ->whereKey($this->deliveryId)
            ->where(function ($query) use ($now): void {
                $query->whereIn('status', DmDeliveryStatus::claimableValues())
                    ->orWhere(fn ($query) => $query
                        ->where('status', DmDeliveryStatus::Claimed->value)
                        ->where('claimed_at', '<', $now->copy()->subSeconds(self::CLAIM_EXPIRES_AFTER_SECONDS)));
            })
            ->update([
                'status' => DmDeliveryStatus::Claimed->value,
                'claimed_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
            ]) === 1;
    }

    /**
     * @return list<string>
     */
    private function inboxRelays(EinundzwanzigPleb $recipient): array
    {
        $relays = $recipient->dmRelays?->relays;

        if (! is_array($relays)) {
            return [];
        }

        $relays = array_values(array_unique(array_filter($relays, fn ($relay): bool => is_string($relay) && $relay !== '')));

        return array_slice($relays, 0, self::MAX_INBOX_RELAYS);
    }

    /**
     * Null when the recipient was erased while the wrap was being built; then
     * nothing is written.
     *
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: array<int, array<int, string>>, content: string, sig: string}|null
     */
    private function storedOrNewWrap(
        DmDelivery $delivery,
        EinundzwanzigPleb $recipient,
        DirectMessageSigner $signer,
        DirectMessageText $text,
    ): ?array {
        if (is_array($delivery->wrap)) {
            return $delivery->wrap;
        }

        $plaintext = $text->render($delivery->run->message, $recipient);
        $message = $signer->wrapFor($recipient, $plaintext);

        if ($this->isErasedNow($recipient)) {
            return null;
        }

        $delivery->forceFill([
            'plaintext' => $plaintext,
            'rumor_id' => $message->rumorId,
            'rumor_created_at' => $message->rumorCreatedAt,
            'wrap' => $message->wrap,
        ])->save();

        return $message->wrap;
    }

    /**
     * False, and nothing written, when the recipient has been erased since
     * the run began: the erasure has already cleared this delivery's rows.
     */
    private function recordRelay(DmDelivery $delivery, string $relayUrl, RelayPublishResult $result): bool
    {
        if ($this->isErasedNow($delivery->recipient)) {
            return false;
        }

        $row = DmDeliveryRelay::query()->firstOrNew([
            'dm_delivery_id' => $delivery->id,
            'relay_url' => $relayUrl,
        ]);

        $row->forceFill([
            'status' => $result->status,
            'message' => mb_substr($result->message, 0, self::MAX_STORED_MESSAGE_LENGTH),
            'attempts' => $row->attempts + 1,
        ])->save();

        return true;
    }

    /**
     * Read from the database, not from the model loaded at the start: an
     * erasure can land while this job runs. A row that is gone counts as erased.
     */
    private function isErasedNow(EinundzwanzigPleb $recipient): bool
    {
        return $recipient->fresh()?->isErased() ?? true;
    }

    private function finish(DmDelivery $delivery, DmDeliveryStatus $status, ?string $error = null): void
    {
        $delivery->forceFill([
            'status' => $status,
            'claimed_at' => null,
            'sent_at' => $status === DmDeliveryStatus::Sent ? now() : $delivery->sent_at,
            'error' => $error === null ? null : mb_substr($error, 0, self::MAX_STORED_MESSAGE_LENGTH),
        ])->save();
    }
}
