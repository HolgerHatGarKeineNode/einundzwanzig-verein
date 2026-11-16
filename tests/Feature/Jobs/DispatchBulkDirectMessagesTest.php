<?php

use App\Enums\DmAudience;
use App\Enums\DmDeliveryStatus;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Jobs\Dm\SendDirectMessage;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/DirectMessageHelpers.php';

beforeEach(fn () => armDirectMessages());

/**
 * @return array{0: list<EinundzwanzigPleb>, 1: int} the recipients in id order and the run id
 */
function fiveRecipientRun(): array
{
    $recipients = [];

    foreach (range(1, 5) as $i) {
        [$recipients[]] = dmRecipient(["wss://inbox{$i}.example/"]);
    }

    return [$recipients, DmMessage::factory()->create()->freezeRun($recipients)->id];
}

/**
 * How often each relay accepted an event, and how many distinct event ids
 * it was given.
 *
 * @return array<string, array{accepted: int, ids: int}>
 */
function acceptedPerRelay(FakeRelayTransport $relay): array
{
    return collect($relay->published())
        ->groupBy('relay')
        ->map(fn ($publishes): array => [
            'accepted' => $publishes->where('accepted', true)->count(),
            'ids' => $publishes->pluck('event.id')->unique()->count(),
        ])
        ->sortKeys()
        ->all();
}

/*
 * I9 bulk. Mutation M9c: drop the unique index on (dm_run_id,
 * einundzwanzig_pleb_id) or insert with a plain create → the rerun adds a
 * second delivery per member → red.
 */
it('resumes a fan-out that broke at recipient 3 of 5 so that each recipient is addressed once', function () {
    $relay = FakeRelayTransport::bind();
    [, $runId] = fiveRecipientRun();

    // The queue breaks while handing over the third send.
    $handedOver = 0;
    $queueBroken = true;
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$handedOver, &$queueBroken): void {
        if ($queueBroken && $event->job->resolveName() === SendDirectMessage::class && ++$handedOver === 3) {
            throw new RuntimeException('queue connection lost');
        }
    });

    expect(fn () => DispatchBulkDirectMessages::dispatchSync($runId))->toThrow(RuntimeException::class, 'queue connection lost');

    expect(DmDelivery::query()->where('dm_run_id', $runId)->orderBy('id')->pluck('status')->all())->toBe([
        DmDeliveryStatus::Sent, DmDeliveryStatus::Sent, DmDeliveryStatus::Pending, DmDeliveryStatus::Pending, DmDeliveryStatus::Pending,
    ]);

    $queueBroken = false;
    DispatchBulkDirectMessages::dispatchSync($runId);

    expect(DmDelivery::query()->where('dm_run_id', $runId)->count())->toBe(5)
        ->and(DmDelivery::query()->where('dm_run_id', $runId)->pluck('status')->unique()->all())->toBe([DmDeliveryStatus::Sent])
        ->and($relay->published())->toHaveCount(5)
        ->and(acceptedPerRelay($relay))->toBe(array_fill_keys(
            array_map(fn (int $i): string => "wss://inbox{$i}.example/", range(1, 5)),
            ['accepted' => 1, 'ids' => 1],
        ));
});

it('adds no delivery and publishes nothing when a finished fan-out runs again', function () {
    $relay = FakeRelayTransport::bind();
    [, $runId] = fiveRecipientRun();

    DispatchBulkDirectMessages::dispatchSync($runId);

    $before = DmDelivery::query()->where('dm_run_id', $runId)->orderBy('id')->get(['id', 'status', 'attempts', 'sent_at'])->toArray();

    $this->travel(5)->minutes();
    DispatchBulkDirectMessages::dispatchSync($runId);

    expect(DmDelivery::query()->where('dm_run_id', $runId)->orderBy('id')->get(['id', 'status', 'attempts', 'sent_at'])->toArray())->toBe($before)
        ->and($relay->published())->toHaveCount(5);
});

it('never moves a sent delivery back to pending', function () {
    FakeRelayTransport::bind();
    [$recipients, $runId] = fiveRecipientRun();

    DispatchBulkDirectMessages::dispatchSync($runId);

    $sent = DmDelivery::query()->where('einundzwanzig_pleb_id', $recipients[0]->id)->sole();

    DispatchBulkDirectMessages::dispatchSync($runId);
    SendDirectMessage::dispatchSync($sent->id);

    expect($sent->fresh()->status)->toBe(DmDeliveryStatus::Sent)
        ->and($sent->fresh()->sent_at?->equalTo($sent->sent_at))->toBeTrue();
});

it('sends again what failed and leaves sent and no_inbox deliveries alone', function () {
    $relay = FakeRelayTransport::bind();
    [$withInbox] = dmRecipient(['wss://one.example/']);
    [$unreachable] = dmRecipient(['wss://two.example/']);
    [$withoutInbox] = dmRecipient(null);
    $runId = DmMessage::factory()->create()->freezeRun([$withInbox, $unreachable, $withoutInbox])->id;

    $relay->thenAccept()->thenFail('timeout: no OK within 10 s');
    DispatchBulkDirectMessages::dispatchSync($runId);

    $statusOf = fn (EinundzwanzigPleb $member): DmDeliveryStatus => DmDelivery::query()
        ->where('dm_run_id', $runId)->where('einundzwanzig_pleb_id', $member->id)->sole()->status;

    expect($statusOf($withInbox))->toBe(DmDeliveryStatus::Sent)
        ->and($statusOf($unreachable))->toBe(DmDeliveryStatus::Failed)
        ->and($statusOf($withoutInbox))->toBe(DmDeliveryStatus::NoInbox);

    DispatchBulkDirectMessages::dispatchSync($runId);

    expect(array_column($relay->published(), 'relay'))->toBe(['wss://one.example/', 'wss://two.example/', 'wss://two.example/'])
        ->and($relay->published()[2]['event']['id'])->toBe($relay->published()[1]['event']['id'])
        ->and($statusOf($unreachable))->toBe(DmDeliveryStatus::Sent)
        ->and($statusOf($withoutInbox))->toBe(DmDeliveryStatus::NoInbox);
});

it('addresses exactly the frozen list, once each, and stamps the run once', function () {
    FakeRelayTransport::bind();
    [$recipients, $runId] = fiveRecipientRun();
    EinundzwanzigPleb::factory()->active()->create();

    DispatchBulkDirectMessages::dispatchSync($runId);
    $stamped = DmDelivery::query()->where('dm_run_id', $runId)->first()->run->fanned_out_at;

    $this->travel(1)->hours();
    DispatchBulkDirectMessages::dispatchSync($runId);

    $run = DmDelivery::query()->where('dm_run_id', $runId)->first()->run;

    expect(DmDelivery::query()->where('dm_run_id', $runId)->orderBy('einundzwanzig_pleb_id')->pluck('einundzwanzig_pleb_id')->all())
        ->toBe(array_map(fn (EinundzwanzigPleb $member): int => $member->id, $recipients))
        ->and($stamped)->not->toBeNull()
        ->and($run->fanned_out_at->equalTo($stamped))->toBeTrue();
});

it('queues a delivery again whose claim has expired and leaves a live claim alone', function () {
    $relay = FakeRelayTransport::bind();
    [$abandoned] = dmRecipient(['wss://one.example/']);
    [$working] = dmRecipient(['wss://two.example/']);
    $runId = DmMessage::factory()->create()->freezeRun([$abandoned, $working])->id;

    foreach ([[$abandoned, SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS + 1], [$working, 30]] as [$member, $age]) {
        DmDelivery::query()->create([
            'dm_run_id' => $runId,
            'einundzwanzig_pleb_id' => $member->id,
            'status' => DmDeliveryStatus::Claimed,
            'claimed_at' => now()->subSeconds($age),
        ]);
    }

    DispatchBulkDirectMessages::dispatchSync($runId);

    expect(array_column($relay->published(), 'relay'))->toBe(['wss://one.example/'])
        ->and(DmDelivery::query()->where('einundzwanzig_pleb_id', $abandoned->id)->sole()->status)->toBe(DmDeliveryStatus::Sent)
        ->and(DmDelivery::query()->where('einundzwanzig_pleb_id', $working->id)->sole()->status)->toBe(DmDeliveryStatus::Claimed);
});

/*
 * Jury run 1, M2 / plan E19: the targeted fee reminder went out in one burst.
 * Relay operators then see wraps from one server address to exactly the
 * npubs that have not paid, within a minute.
 */

/**
 * The delay of every queued send, in delivery order.
 *
 * @return list<int|null>
 */
function queuedSendDelays(?DmAudience $audience): array
{
    Queue::fake();
    $recipients = [];

    foreach (range(1, 5) as $i) {
        [$recipients[]] = dmRecipient(["wss://inbox{$i}.example/"]);
    }

    $run = DmMessage::factory()->create()->freezeRun($recipients, $audience);
    (new DispatchBulkDirectMessages($run->id))->handle();

    return Queue::pushed(SendDirectMessage::class)->map(fn (SendDirectMessage $job): mixed => $job->delay)->values()->all();
}

it('spreads the sends of a run to members with an outstanding fee over the configured time', function () {
    config()->set('services.nostr_dm_targeted_spread_seconds', 3600);

    $delays = queuedSendDelays(DmAudience::FeeOutstanding);

    expect($delays)->toHaveCount(5)
        ->each->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(3600)
        ->and(array_unique($delays))->not->toHaveCount(1);
});

it('sends at once to an untargeted audience, or when the spread is configured to 0', function (?DmAudience $audience, int $spread) {
    config()->set('services.nostr_dm_targeted_spread_seconds', $spread);

    expect(queuedSendDelays($audience))->toBe([null, null, null, null, null]);
})->with([
    'all members, spread configured' => [DmAudience::Members, 3600],
    'no audience (a test to one board member)' => [null, 3600],
    'fee outstanding, spread 0' => [DmAudience::FeeOutstanding, 0],
]);

it('spreads the fee reminder over hours by default', function () {
    expect(config('services.nostr_dm_targeted_spread_seconds'))->toBeGreaterThanOrEqual(3600);
});
