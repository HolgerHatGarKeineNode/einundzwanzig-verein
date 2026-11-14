<?php

use App\Enums\DmDeliveryStatus;
use App\Enums\DmMessageType;
use App\Models\DmDelivery;
use App\Models\DmDeliveryRelay;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\EinundzwanzigPleb;
use App\Services\Nostr\RelayPublishStatus;
use Illuminate\Database\UniqueConstraintViolationException;

it('freezes a run as a sorted list of unique member ids', function () {
    [$first, $second, $third] = EinundzwanzigPleb::factory()->count(3)->create();
    $message = DmMessage::factory()->create();

    $run = $message->freezeRun([$third, $first, $third, $second]);

    expect($run->fresh()->recipient_ids)->toBe([$first->id, $second->id, $third->id])
        ->and($run->recipient_count)->toBe(3)
        ->and($run->fanned_out_at)->toBeNull()
        ->and($run->message->is($message))->toBeTrue();
});

it('keeps the recipient list of a run when the audience changes afterwards', function () {
    $member = EinundzwanzigPleb::factory()->create();
    $run = DmMessage::factory()->create()->freezeRun([$member]);

    EinundzwanzigPleb::factory()->create();

    expect($run->fresh()->recipient_ids)->toBe([$member->id]);
});

it('refuses a second delivery for the same member in the same run at the database', function () {
    $delivery = DmDelivery::factory()->create();

    DmDelivery::query()->create([
        'dm_run_id' => $delivery->dm_run_id,
        'einundzwanzig_pleb_id' => $delivery->einundzwanzig_pleb_id,
        'status' => DmDeliveryStatus::Pending,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows the same member once in each of two runs', function () {
    $member = EinundzwanzigPleb::factory()->create();

    DmDelivery::factory()->for($member, 'recipient')->create();
    DmDelivery::factory()->for($member, 'recipient')->create();

    expect(DmDelivery::query()->where('einundzwanzig_pleb_id', $member->id)->count())->toBe(2);
});

it('refuses a second row for the same relay of the same delivery at the database', function () {
    $row = DmDeliveryRelay::factory()->create();

    DmDeliveryRelay::query()->create([
        'dm_delivery_id' => $row->dm_delivery_id,
        'relay_url' => $row->relay_url,
        'status' => RelayPublishStatus::Failed,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('casts type, status, wrap and relay status to their types', function () {
    $delivery = DmDelivery::factory()->create([
        'wrap' => ['id' => str_repeat('a', 64), 'kind' => 1059],
        'rumor_created_at' => 1_700_000_000,
    ]);
    $relay = DmDeliveryRelay::factory()->for($delivery, 'delivery')->create(['status' => RelayPublishStatus::Rejected]);

    $delivery = $delivery->fresh();

    expect($delivery->status)->toBe(DmDeliveryStatus::Pending)
        ->and($delivery->wrap)->toBe(['id' => str_repeat('a', 64), 'kind' => 1059])
        ->and($delivery->rumor_created_at)->toBe(1_700_000_000)
        ->and($delivery->run)->toBeInstanceOf(DmRun::class)
        ->and($delivery->run->message->type)->toBe(DmMessageType::Individual)
        ->and($delivery->relays->first()->is($relay))->toBeTrue()
        ->and($relay->fresh()->status)->toBe(RelayPublishStatus::Rejected);
});

it('lets only pending and failed deliveries be claimed', function (DmDeliveryStatus $status, bool $claimable) {
    expect($status->isClaimable())->toBe($claimable);
})->with([
    'pending' => [DmDeliveryStatus::Pending, true],
    'failed' => [DmDeliveryStatus::Failed, true],
    'claimed' => [DmDeliveryStatus::Claimed, false],
    'sent' => [DmDeliveryStatus::Sent, false],
    'no inbox' => [DmDeliveryStatus::NoInbox, false],
]);
