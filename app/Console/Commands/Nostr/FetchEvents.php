<?php

namespace App\Console\Commands\Nostr;

use App\Models\EinundzwanzigPleb;
use App\Models\Event;
use App\Traits\NostrEventRendererTrait;
use Illuminate\Console\Command;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;

class FetchEvents extends Command
{
    use NostrEventRendererTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fetch:events';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $subscription = new Subscription;
        $subscriptionId = $subscription->setId();

        $filter1 = new Filter;
        $filter1->setKinds([1]); // You can add multiple kind numbers
        $filter1->setAuthors($this->subjectPubkeys()); // You can add multiple authors
        $filter1->setLimit(25); // Limit to fetch only a maximum of 25 events
        $filters = [$filter1]; // You can add multiple filters.

        $requestMessage = new RequestMessage($subscriptionId, $filters);

        $relays = [
            new Relay('wss://nostr.einundzwanzig.space'),
            new Relay('wss://nostr.wine'),
            new Relay('wss://nos.lol'),
        ];
        $relaySet = new RelaySet;
        $relaySet->setRelays($relays);

        $request = new Request($relaySet, $requestMessage);
        $response = $request->send();

        $uniqueEvents = [];

        foreach ($response as $relay => $events) {
            foreach ($events as $event) {
                if (! isset($uniqueEvents[$event->event->id])) {
                    $uniqueEvents[$event->event->id] = $event;
                }
            }
        }

        foreach ($uniqueEvents as $id => $uniqueEvent) {
            $type = $this->isReplyOrRoot($uniqueEvent->event);
            $parentEventId = $this->getParentEventId($uniqueEvent->event);

            $event = Event::query()->updateOrCreate(
                ['event_id' => $id],
                [
                    'pubkey' => $uniqueEvent->event->pubkey,
                    'parent_event_id' => $parentEventId,
                    'json' => json_encode($uniqueEvent->event, JSON_THROW_ON_ERROR),
                    'type' => $type,
                ]
            );

            $this->renderContentToHtml($event);
        }
    }

    /**
     * Die Pubkeys, nach denen bei den Relays gefragt wird.
     *
     * Eigene Methode, damit genau dieser Wert pruefbar ist, ohne die
     * Subskription wirklich aufzubauen — `handle()` verbindet sich mit drei
     * echten Relays, ein Test darf das nicht.
     *
     * OHNE GELOESCHTE MITGLIEDER, aus zwei unabhaengig ausreichenden Gruenden:
     *
     * 1. Datenschutz — die Anfrage traegt die Pubkeys nach draussen an fremde
     *    Relays. Ein Tombstone einer Person, die verlangt hat, nicht mehr
     *    gefuehrt zu werden, gehoert dort nicht hin.
     * 2. Wohlgeformtheit — NIP-01 verlangt im `authors`-Filter 64-stelliges
     *    Kleinbuchstaben-Hex. `deleted-6cf4d58ac8f2…` ist keines. Wie ein Relay
     *    auf einen spezifikationswidrigen Eintrag reagiert, ist UNGEPRUEFT: es
     *    kann ihn ignorieren oder das ganze REQ ablehnen — und das REQ ist die
     *    gemeinsame Subskription fuer ALLE Mitglieder, ein abgelehntes traefe
     *    also jeden.
     *
     * @return array<int, string>
     */
    protected function subjectPubkeys(): array
    {
        return EinundzwanzigPleb::query()
            ->notErased()
            ->pluck('pubkey')
            ->all();
    }

    private function getParentEventId($event)
    {
        foreach ($event->tags as $tag) {
            if ($tag[0] === 'e') {
                if ((isset($tag[2]) && $tag[2] === '') || (isset($tag[3]) && $tag[3] === 'reply')) {
                    return $tag[1];
                }
            }
        }

        return null;
    }

    private function isReplyOrRoot($event)
    {
        foreach ($event->tags as $tag) {
            if ($tag[0] === 'e') {
                if ((isset($tag[3]) && $tag[3] === 'reply') || (! isset($tag[3]) && isset($tag[2]) && $tag[2] === '')) {
                    return 'reply';
                }
            }
        }

        return 'root';
    }
}
