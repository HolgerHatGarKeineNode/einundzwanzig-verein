<?php

namespace App\Console\Commands\Dm;

use App\Services\Nostr\NotificationKey;
use App\Services\Nostr\NotificationKeyUnavailable;
use App\Services\Nostr\RelayPublishStatus;
use App\Services\Nostr\RelayTransport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Publishes the notification key's DM inbox relay list (kind 10050).
 *
 * Without it a member's client does not know where a reply goes, and NIP-17
 * clients then send nothing. Published to the inbox relays themselves and to
 * the profile relays, where clients look such lists up. Run once after the
 * key is set, and again whenever NOSTR_DM_INBOX_RELAYS changes.
 */
class PublishDmInboxRelays extends Command
{
    protected $signature = 'dm:publish-inbox-relays';

    protected $description = 'Publish the notification key\'s DM inbox relay list (kind 10050, NIP-17)';

    public function handle(RelayTransport $transport, NotificationKey $key): int
    {
        /** @var list<string> $inbox */
        $inbox = config('einundzwanzig.config.dm_inbox_relays', []);

        if ($inbox === []) {
            $this->error('Nothing to publish: einundzwanzig.config.dm_inbox_relays (NOSTR_DM_INBOX_RELAYS) is empty.');

            return self::FAILURE;
        }

        try {
            $event = $key->signInboxRelayList($inbox, CarbonImmutable::now()->getTimestamp());
        } catch (NotificationKeyUnavailable) {
            $this->error('No usable notification key (NOSTR_NOTIFICATION_KEY).');

            return self::FAILURE;
        }

        /** @var list<string> $profileRelays */
        $profileRelays = config('einundzwanzig.config.profile_relays', []);
        $accepted = 0;

        foreach (array_values(array_unique([...$inbox, ...$profileRelays])) as $url) {
            $result = $transport->publish($url, $event);
            $this->line("{$url}: {$result->status->value}");

            if ($result->status === RelayPublishStatus::Accepted) {
                $accepted++;
            }
        }

        if ($accepted === 0) {
            $this->error('No relay accepted the list.');

            return self::FAILURE;
        }

        $this->info("Published to {$accepted} relays.");

        return self::SUCCESS;
    }
}
