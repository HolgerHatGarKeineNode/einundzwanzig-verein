<?php

namespace App\Console\Commands\Dm;

use App\Models\DmInboxMessage;
use App\Models\EinundzwanzigPleb;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\GiftWrapOpener;
use App\Services\Nostr\NotificationKey;
use App\Services\Nostr\NotificationKeyUnavailable;
use App\Services\Nostr\RelayTransport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Reads the members' replies to the notification key into the board inbox.
 *
 * Asks each relay of `einundzwanzig.config.dm_inbox_relays` for the gift
 * wraps tagged for the key over the last LOOKBACK_DAYS. That window is wider
 * than the two days a wrap may be back-dated (NIP-59), so a run that was
 * missed for a few days still finds every reply. A reply several relays
 * return is stored once (unique rumor id). Wraps that do not open, and
 * messages from a pubkey that is no portal member, are counted and dropped.
 */
class FetchDmInbox extends Command
{
    protected $signature = 'dm:fetch-inbox';

    protected $description = 'Read members\' replies to the notification key into the board inbox';

    public const int LOOKBACK_DAYS = 7;

    public const int LIMIT_PER_RELAY = 500;

    public function handle(RelayTransport $transport, NotificationKey $key, GiftWrapOpener $opener): int
    {
        /** @var list<string> $relays */
        $relays = config('einundzwanzig.config.dm_inbox_relays', []);

        if ($relays === []) {
            $this->line('Board inbox is off: einundzwanzig.config.dm_inbox_relays (NOSTR_DM_INBOX_RELAYS) is empty.');

            return self::SUCCESS;
        }

        try {
            $own = $key->publicKey();
        } catch (NotificationKeyUnavailable) {
            $this->error('No usable notification key (NOSTR_NOTIFICATION_KEY); replies cannot be opened.');

            return self::FAILURE;
        }

        $filter = [
            'kinds' => [DirectMessageSigner::KIND_GIFT_WRAP],
            '#p' => [$own],
            'since' => CarbonImmutable::now()->subDays(self::LOOKBACK_DAYS)->getTimestamp(),
            'limit' => self::LIMIT_PER_RELAY,
        ];

        /** @var array<string, array<string, mixed>> $wraps */
        $wraps = [];

        foreach ($relays as $url) {
            $result = $transport->fetch($url, $filter);

            if (! $result->complete) {
                $this->warn("{$url}: {$result->message}");
            }

            foreach ($result->events as $event) {
                if (is_array($event) && is_string($event['id'] ?? null)) {
                    $wraps[$event['id']] = $event;
                }
            }
        }

        $stored = $unreadable = $strangers = 0;

        foreach ($wraps as $wrap) {
            $message = $opener->open($wrap);

            if ($message === null) {
                $unreadable++;

                continue;
            }

            $member = EinundzwanzigPleb::query()->notErased()->where('pubkey', $message['sender'])->first();

            if ($member === null) {
                $strangers++;

                continue;
            }

            if (DmInboxMessage::query()->where('rumor_id', $message['rumor_id'])->exists()) {
                continue;
            }

            try {
                DmInboxMessage::query()->create([
                    'einundzwanzig_pleb_id' => $member->id,
                    'direction' => DmInboxMessage::IN,
                    'rumor_id' => $message['rumor_id'],
                    'body' => $message['body'],
                    // A rumor's own time is the sender's claim; a future one is capped at now.
                    'sent_at' => CarbonImmutable::createFromTimestamp(min($message['created_at'], CarbonImmutable::now()->getTimestamp())),
                ]);
                $stored++;
            } catch (UniqueConstraintViolationException) {
                // A parallel run stored it first.
            }
        }

        $this->line('wraps='.count($wraps)." stored={$stored} unreadable={$unreadable} not_members={$strangers}");

        return self::SUCCESS;
    }
}
