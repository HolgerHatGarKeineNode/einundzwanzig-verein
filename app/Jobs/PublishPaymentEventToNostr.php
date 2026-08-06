<?php

namespace App\Jobs;

use App\Models\PaymentEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use swentel\nostr\Event\Event as NostrEvent;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Sign\Sign;

/**
 * Publishes the kind-32121 fee event for a payment event and writes the
 * resulting event id back.
 *
 * This ran synchronously inside the web request before, which made an
 * unreachable relay decide whether an application succeeded. The record is now
 * written first and the event id follows asynchronously — `event_id` is
 * nullable, and the BTCPay webhook matches on `btc_pay_invoice`, never on the
 * `posData.event` that used to carry it.
 */
class PublishPaymentEventToNostr implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $paymentEventId) {}

    public function handle(): void
    {
        $paymentEvent = PaymentEvent::query()->find($this->paymentEventId);

        if (! $paymentEvent) {
            return;
        }

        /*
         * Never overwrite an existing event id. The job is retried on relay
         * failures, and a second published event would orphan the id that
         * members already see next to their fee.
         */
        if ($paymentEvent->event_id !== null && $paymentEvent->event_id !== '') {
            return;
        }

        $relayUrl = (string) config('services.relay');
        $signingKey = (string) config('services.nostr');

        if ($relayUrl === '' || $signingKey === '') {
            Log::warning('Skipping kind-32121 publication: relay or signing key not configured.', [
                'payment_event_id' => $paymentEvent->id,
            ]);

            return;
        }

        $note = new NostrEvent;
        $note->setKind(32121);
        $note->setContent(
            'Dieses Event dient der Zahlung des Mitgliedsbeitrags für das Jahr '
            .$paymentEvent->year
            .'. Bitte bezahle den Betrag von '
            .number_format((int) $paymentEvent->amount, 0, ',', '.')
            .' Satoshis.',
        );
        $note->setTags([
            ['d', $paymentEvent->pleb?->pubkey.','.$paymentEvent->year],
            ['zap', 'daf83d92768b5d0005373f83e30d4203c0b747c170449e02fea611a0da125ee6', $relayUrl, '1'],
        ]);

        (new Sign)->signEvent($note, $signingKey);

        $relay = new Relay($relayUrl);
        $relay->setMessage(new EventMessage($note));
        $result = $relay->send();

        $eventId = $result->eventId ?? null;

        if (! $eventId) {
            return;
        }

        /*
         * Re-read under the same guard: a concurrent run may have finished
         * while this one was talking to the relay.
         */
        PaymentEvent::query()
            ->whereKey($paymentEvent->id)
            ->whereNull('event_id')
            ->update(['event_id' => $eventId]);
    }
}
