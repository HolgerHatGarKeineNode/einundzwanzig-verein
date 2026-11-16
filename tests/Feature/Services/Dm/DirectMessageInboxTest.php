<?php

use App\Enums\DmDeliveryStatus;
use App\Enums\DmMessageType;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Services\Dm\DirectMessageInbox;
use App\Services\MembershipService;
use Illuminate\Support\Carbon;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';

/*
 * The member's portal inbox and its "seen".
 *
 * Mutation that must turn this file red (patch in the plan folder):
 *  - P3B2-inbox-leak   the inbox query drops the member condition
 */

beforeEach(fn () => armDirectMessages());

function inboxDelivery(EinundzwanzigPleb $member, DmDeliveryStatus $status, ?string $plaintext = null, ?DmMessage $message = null): DmDelivery
{
    $message ??= DmMessage::factory()->create();

    return DmDelivery::query()->create([
        'dm_run_id' => $message->freezeRun([$member])->id,
        'einundzwanzig_pleb_id' => $member->id,
        'status' => $status,
        'plaintext' => $plaintext,
    ]);
}

it('shows the member exactly their own finished messages, newest first, with the text as sent', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient();
    [$other] = dmRecipient();
    Profile::factory()->create(['pubkey' => $member->pubkey, 'display_name' => 'Satoshi']);

    $relay->thenAccept()->thenAccept();
    DispatchBulkDirectMessages::dispatchSync(DmMessage::factory()->create()->freezeRun([$member, $other])->id);
    $sent = DmDelivery::query()->where('einundzwanzig_pleb_id', $member->id)->sole();

    $noInbox = inboxDelivery($member, DmDeliveryStatus::NoInbox, message: DmMessage::factory()->create([
        'type' => DmMessageType::ElectionStartsNow,
        'plaintext' => DmMessageType::ElectionStartsNow->defaultText(),
        'variables' => ['link' => 'https://verein.example/wahl'],
    ]));
    $failed = inboxDelivery($member, DmDeliveryStatus::Failed, 'Hallo Satoshi, gescheitert auf Nostr.');
    inboxDelivery($member, DmDeliveryStatus::Pending);
    inboxDelivery($member, DmDeliveryStatus::Claimed);
    inboxDelivery($other, DmDeliveryStatus::NoInbox, 'Hallo Fremder, nur für dich.');

    $messages = app(DirectMessageInbox::class)->messages($member);

    expect($messages->pluck('delivery_id')->all())->toBe([$failed->id, $noInbox->id, $sent->id])
        ->and($messages->last()['text'])->toBe($sent->plaintext)
        ->and($messages->last()['text'])->toStartWith('Hallo Satoshi, die Mitgliederversammlung')
        ->and($messages[1]['text'])->toStartWith("Hallo Satoshi,\n\ndie Wahl des neuen Vorstands beginnt jetzt")
        ->and($messages[1]['text'])->toContain('https://verein.example/wahl')
        ->and($messages[1]['label'])->toBe('Die Wahl beginnt jetzt')
        ->and($messages->pluck('text')->implode("\n"))->not->toContain('Fremder');
});

it('counts unread messages and keeps the first moment a message was seen', function () {
    $this->travelTo(Carbon::parse('2027-02-01 10:00:00'));
    $member = EinundzwanzigPleb::factory()->active()->create();
    $other = EinundzwanzigPleb::factory()->active()->create();
    $first = inboxDelivery($member, DmDeliveryStatus::Sent, 'eins');
    inboxDelivery($member, DmDeliveryStatus::NoInbox, 'zwei');
    inboxDelivery($member, DmDeliveryStatus::Pending, 'noch nicht');
    $othersMessage = inboxDelivery($other, DmDeliveryStatus::Sent, 'fremd');
    $inbox = app(DirectMessageInbox::class);

    expect($inbox->unreadCount($member))->toBe(2)
        ->and($inbox->markSeen($member, $first->id))->toBeTrue()
        ->and($inbox->unreadCount($member))->toBe(1);

    $this->travelTo(Carbon::parse('2027-02-02 10:00:00'));

    expect($inbox->markSeen($member, $first->id))->toBeFalse()
        ->and($first->fresh()->seen_at->toDateTimeString())->toBe('2027-02-01 10:00:00')
        ->and($inbox->markSeen($member, $othersMessage->id))->toBeFalse()
        ->and($othersMessage->fresh()->seen_at)->toBeNull()
        ->and($inbox->unreadCount($other))->toBe(1)
        ->and($inbox->messages($member)->firstWhere('delivery_id', $first->id)['seen_at'])->toBe($first->fresh()->seen_at->toIso8601String());
});

it('clears the inbox data of an erased member', function () {
    $member = EinundzwanzigPleb::factory()->active()->create();
    $seen = inboxDelivery($member, DmDeliveryStatus::Sent, 'Hallo Satoshi');
    inboxDelivery($member, DmDeliveryStatus::NoInbox);
    $inbox = app(DirectMessageInbox::class);
    $inbox->markSeen($member, $seen->id);

    expect($seen->fresh()->seen_at)->not->toBeNull()
        ->and($inbox->messages($member))->toHaveCount(2);

    app(MembershipService::class)->erasePersonalData($member);
    $member->refresh();

    expect($seen->fresh()->seen_at)->toBeNull()
        ->and($seen->fresh()->plaintext)->toBeNull()
        ->and($inbox->messages($member))->toHaveCount(0)
        ->and($inbox->unreadCount($member))->toBe(0)
        ->and($inbox->markSeen($member, $seen->id))->toBeFalse();
});
