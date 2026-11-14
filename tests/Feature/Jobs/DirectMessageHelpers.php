<?php

use App\Enums\DmDeliveryStatus;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\PlebDmRelay;
use swentel\nostr\Encryption\Nip44;
use swentel\nostr\Key\Key;

/*
 * Shared by the two direct message job tests. Recipients get a real key pair,
 * so a test can open the wrap the way the member's client does.
 */

/**
 * A member with a known secret key and, unless $relays is null, a kind 10050
 * inbox list.
 *
 * @param  list<string>|null  $relays
 * @return array{0: EinundzwanzigPleb, 1: string}
 */
function dmRecipient(?array $relays = ['wss://inbox.example.com/']): array
{
    $secret = testPrivateKey();
    $pleb = EinundzwanzigPleb::factory()->active()->create(['pubkey' => (new Key)->getPublicKey($secret)]);

    if ($relays !== null) {
        PlebDmRelay::factory()->for($pleb, 'pleb')->create(['relays' => $relays]);
    }

    return [$pleb, $secret];
}

function dmDelivery(EinundzwanzigPleb $recipient, ?DmMessage $message = null): DmDelivery
{
    $message ??= DmMessage::factory()->create();

    return DmDelivery::query()->create([
        'dm_run_id' => $message->freezeRun([$recipient])->id,
        'einundzwanzig_pleb_id' => $recipient->id,
        'status' => DmDeliveryStatus::Pending,
    ]);
}

/**
 * Open a gift wrap with a member's secret key: wrap → seal → rumor.
 *
 * Throws when the key is not the one the wrap was made for (NIP-44 MAC).
 *
 * @param  array<string, mixed>  $wrap
 * @return array<string, mixed> the rumor
 */
function openWrapWith(array $wrap, string $secret): array
{
    $seal = json_decode(
        Nip44::decrypt($wrap['content'], Nip44::getConversationKey($secret, $wrap['pubkey'])),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return json_decode(
        Nip44::decrypt($seal['content'], Nip44::getConversationKey($secret, $seal['pubkey'])),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function armDirectMessages(): void
{
    config()->set('services.nostr_notification_key', testPrivateKey());
    config()->set('einundzwanzig.config.dm_reply_contact', 'vorstand@example.org');
}
