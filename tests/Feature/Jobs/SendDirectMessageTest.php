<?php

use App\Enums\DmDeliveryStatus;
use App\Jobs\Dm\SendDirectMessage;
use App\Models\DmDelivery;
use App\Models\DmDeliveryRelay;
use App\Models\DmMessage;
use App\Models\PlebDmRelay;
use App\Services\MembershipService;
use App\Services\Nostr\NostrRelayTransport;
use App\Services\Nostr\NotificationKeyUnavailable;
use App\Services\Nostr\RelayPublishStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/DirectMessageHelpers.php';

beforeEach(fn () => armDirectMessages());

it('stores the text, the rumor and the wrap once and hands the wrap to every inbox relay', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient(['wss://one.example/', 'wss://two.example/']);
    $delivery = dmDelivery($member);

    SendDirectMessage::dispatchSync($delivery->id);

    $delivery->refresh();
    $published = $relay->published();

    expect($delivery->status)->toBe(DmDeliveryStatus::Sent)
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($delivery->claimed_at)->toBeNull()
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->rumor_id)->toBeNostrHexKey()
        ->and($delivery->plaintext)->toStartWith('Hallo Mitglied, die Mitgliederversammlung beginnt um 19 Uhr.')
        ->and($delivery->plaintext)->toEndWith('Fragen bitte an: vorstand@example.org')
        ->and(array_column($published, 'relay'))->toBe(['wss://one.example/', 'wss://two.example/'])
        ->and($published[0]['event'])->toBe($delivery->wrap)
        ->and($published[1]['event'])->toBe($delivery->wrap)
        ->and($delivery->wrap['kind'])->toBe(1059)
        ->and($delivery->relays()->pluck('status', 'relay_url')->all())->toBe([
            'wss://one.example/' => RelayPublishStatus::Accepted,
            'wss://two.example/' => RelayPublishStatus::Accepted,
        ]);
});

/*
 * I10 single. Mutation M10e: wrap for the sender (the notification key)
 * instead of the recipient → the recipient's key no longer opens it → red.
 */
it('wraps the message so that the recipient opens it and no other member does', function () {
    $relay = FakeRelayTransport::bind();
    [$member, $secret] = dmRecipient();
    [$author, $authorSecret] = dmRecipient();
    [, $bystanderSecret] = dmRecipient();
    $delivery = dmDelivery($member, DmMessage::factory()->for($author, 'author')->create());

    SendDirectMessage::dispatchSync($delivery->id);

    $wrap = $relay->published()[0]['event'];

    expect(fn () => openWrapWith($wrap, $secret))->not->toThrow(Exception::class);

    $rumor = openWrapWith($wrap, $secret);

    expect($rumor['id'])->toBe($delivery->fresh()->rumor_id)
        ->and($rumor['content'])->toBe($delivery->fresh()->plaintext)
        ->and($rumor['tags'])->toBe([['p', $member->pubkey]])
        ->and($wrap['tags'])->toBe([['p', $member->pubkey]]);

    foreach ([$authorSecret, $bystanderSecret] as $otherSecret) {
        expect(fn () => openWrapWith($wrap, $otherSecret))->toThrow(Exception::class);
    }
});

/*
 * I9 (i). Mutation M9a: remove the claim at the top of handle() → the
 * second run reaches the relay the member added after the send → red.
 */
it('publishes nothing when the job runs again after a confirmed send', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient(['wss://one.example/']);
    $delivery = dmDelivery($member);

    SendDirectMessage::dispatchSync($delivery->id);

    // The member publishes a new kind 10050 list after the message went out.
    PlebDmRelay::query()->where('einundzwanzig_pleb_id', $member->id)
        ->update(['relays' => json_encode(['wss://one.example/', 'wss://new.example/'])]);

    SendDirectMessage::dispatchSync($delivery->id);

    expect($relay->published())->toHaveCount(1)
        ->and($delivery->fresh()->status)->toBe(DmDeliveryStatus::Sent)
        ->and($delivery->fresh()->attempts)->toBe(1);
});

it('leaves a delivery alone while another worker holds a live claim', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient();
    $delivery = dmDelivery($member);
    $delivery->forceFill(['status' => DmDeliveryStatus::Claimed, 'claimed_at' => now()->subSeconds(30)])->save();

    SendDirectMessage::dispatchSync($delivery->id);

    $relay->assertNothingPublished();
    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Claimed);
});

it('takes over a claim left behind by a worker that died and publishes only to relays without OK true', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient(['wss://one.example/', 'wss://two.example/']);
    $delivery = dmDelivery($member);

    // First worker: relay one takes the wrap, then the key goes missing.
    $relay->thenAccept()->thenThrow(NotificationKeyUnavailable::notConfigured());

    expect(fn () => SendDirectMessage::dispatchSync($delivery->id))->toThrow(NotificationKeyUnavailable::class);

    $firstWrapId = $delivery->fresh()->wrap['id'];

    // Pretend that worker died while holding the claim.
    $delivery->forceFill([
        'status' => DmDeliveryStatus::Claimed,
        'claimed_at' => now()->subSeconds(SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS + 1),
    ])->save();

    SendDirectMessage::dispatchSync($delivery->id);

    $published = $relay->published();

    expect(array_column($published, 'relay'))->toBe(['wss://one.example/', 'wss://two.example/', 'wss://two.example/'])
        ->and($published[2]['event']['id'])->toBe($firstWrapId)
        ->and($delivery->fresh()->status)->toBe(DmDeliveryStatus::Sent)
        ->and($delivery->fresh()->attempts)->toBe(2);
});

/*
 * I9 (ii). Mutation M9b: build and sign the wrap on every attempt instead of
 * reusing the stored one → the retry carries a second event id → red.
 */
it('republishes the identical event after the relay took it and the answer got lost', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient(['wss://one.example/']);
    $delivery = dmDelivery($member);

    $relay->thenAcceptAndThrow(new RuntimeException('connection reset by peer'));

    SendDirectMessage::dispatchSync($delivery->id);

    $afterFirst = $delivery->fresh();

    expect($afterFirst->status)->toBe(DmDeliveryStatus::Failed)
        ->and($afterFirst->error)->toBe('No inbox relay accepted the message (0 of 1).')
        ->and(DmDeliveryRelay::query()->sole()->message)->toBe('RuntimeException: connection reset by peer');

    SendDirectMessage::dispatchSync($delivery->id);

    [$first, $retry] = $relay->published();

    expect($retry['event']['id'])->toBe($first['event']['id'])
        ->and($retry['event'])->toBe($first['event'])
        ->and($delivery->fresh()->rumor_id)->toBe($afterFirst->rumor_id)
        ->and($delivery->fresh()->status)->toBe(DmDeliveryStatus::Sent);
});

/*
 * I9 (iii). A relay's failure stays on that relay's row; nothing reaches
 * whoever dispatched the job.
 */
it('ends as a failed delivery whatever the relay does', function (Closure $script, RelayPublishStatus $relayStatus, string $message) {
    $relay = FakeRelayTransport::bind();
    $script($relay);
    [$member] = dmRecipient(['wss://one.example/']);
    $delivery = dmDelivery($member);

    SendDirectMessage::dispatchSync($delivery->id);

    $row = DmDeliveryRelay::query()->sole();

    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Failed)
        ->and($delivery->fresh()->claimed_at)->toBeNull()
        ->and($row->status)->toBe($relayStatus)
        ->and($row->message)->toBe($message)
        ->and($row->attempts)->toBe(1);
})->with([
    'unreachable' => [fn (FakeRelayTransport $r) => $r->thenFail('timeout: no OK within 10 s'), RelayPublishStatus::Failed, 'timeout: no OK within 10 s'],
    'OK false' => [fn (FakeRelayTransport $r) => $r->thenReject('blocked: not on the allow list'), RelayPublishStatus::Rejected, 'blocked: not on the allow list'],
    'refused address' => [fn (FakeRelayTransport $r) => $r->thenRefuse('private address'), RelayPublishStatus::Refused, 'private address'],
    'vendor TypeError' => [fn (FakeRelayTransport $r) => $r->thenThrow(new TypeError('Return value must be of type RelayResponse, array returned')), RelayPublishStatus::Failed, 'TypeError: Return value must be of type RelayResponse, array returned'],
]);

it('keeps going to the next relay after one fails and counts the delivery as sent', function () {
    $relay = FakeRelayTransport::bind();
    $relay->thenThrow(new TypeError('array returned'))->thenAccept();
    [$member] = dmRecipient(['wss://one.example/', 'wss://two.example/']);
    $delivery = dmDelivery($member);

    SendDirectMessage::dispatchSync($delivery->id);

    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Sent)
        ->and($delivery->relays()->pluck('status', 'relay_url')->all())->toBe([
            'wss://one.example/' => RelayPublishStatus::Failed,
            'wss://two.example/' => RelayPublishStatus::Accepted,
        ]);
});

/*
 * No fake is bound: the refusing default transport and the socket tripwire
 * fail this test if anything is dialled.
 */
it('marks a member without a kind 10050 list as no_inbox without building or dialling anything', function (?array $relays) {
    [$member] = dmRecipient($relays);
    $delivery = dmDelivery($member);

    SendDirectMessage::dispatchSync($delivery->id);

    $delivery->refresh();

    expect($delivery->status)->toBe(DmDeliveryStatus::NoInbox)
        ->and($delivery->wrap)->toBeNull()
        ->and($delivery->rumor_id)->toBeNull()
        ->and($delivery->relays()->count())->toBe(0);
})->with([
    'no list' => [null],
    'empty list' => [[]],
]);

it('fails visibly when the installation has no notification key', function () {
    $relay = FakeRelayTransport::bind();
    config()->set('services.nostr_notification_key', null);
    [$member] = dmRecipient();
    $delivery = dmDelivery($member);

    expect(fn () => SendDirectMessage::dispatchSync($delivery->id))
        ->toThrow(NotificationKeyUnavailable::class);

    $relay->assertNothingPublished();
    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Failed)
        ->and($delivery->fresh()->error)->toContain('NOSTR_NOTIFICATION_KEY')
        ->and($delivery->fresh()->wrap)->toBeNull();
});

it('sends nothing to an erased member', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient();
    $delivery = dmDelivery($member);
    app(MembershipService::class)->erasePersonalData($member);
    PlebDmRelay::factory()->for($member, 'pleb')->create();

    SendDirectMessage::dispatchSync($delivery->id);

    $relay->assertNothingPublished();
    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Failed)
        ->and($delivery->fresh()->error)->toBe('The recipient has been erased.');
});

it('fails a delivery whose text can no longer be filled in, without dialling', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient();
    $delivery = dmDelivery($member, DmMessage::factory()->create(['plaintext' => 'Hallo {npub}']));

    SendDirectMessage::dispatchSync($delivery->id);

    $relay->assertNothingPublished();
    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Failed)
        ->and($delivery->fresh()->error)->toContain('unknown placeholder {npub}');
});

it('addresses at most three inbox relays, each once', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient(['wss://a.example/', 'wss://a.example/', 'wss://b.example/', 'wss://c.example/', 'wss://d.example/']);

    SendDirectMessage::dispatchSync(dmDelivery($member)->id);

    expect(array_column($relay->published(), 'relay'))->toBe(['wss://a.example/', 'wss://b.example/', 'wss://c.example/']);
});

it('has a hard time limit that fits three relay deadlines and ends before a claim expires or the queue retries', function () {
    $job = new SendDirectMessage(1);

    expect($job->timeout)->toBeGreaterThanOrEqual((int) ceil(SendDirectMessage::MAX_INBOX_RELAYS * NostrRelayTransport::DEADLINE_SECONDS))
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->timeout)->toBeLessThan(SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS)
        ->and(SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

it('releases its claim as failed when the worker gives up on the job', function () {
    [$member] = dmRecipient();
    $delivery = dmDelivery($member);
    $delivery->forceFill(['status' => DmDeliveryStatus::Claimed, 'claimed_at' => now()])->save();

    (new SendDirectMessage($delivery->id))->failed(new RuntimeException('job timed out'));

    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Failed)
        ->and($delivery->fresh()->claimed_at)->toBeNull()
        ->and($delivery->fresh()->error)->toBe('job timed out');
});

/*
 * The real database queue, not the sync one: a job that returns is deleted as
 * done there, and failed() runs only after the last attempt. A delivery must
 * still never stay `claimed`. Mutations "catch-all removed" and "expiry back
 * to 120" → red.
 */
function workDatabaseQueueOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--quiet' => true]);
}

it('turns the claim into failed when the wrap write throws and sends on the queue retry', function () {
    $relay = FakeRelayTransport::bind();
    config()->set('queue.default', 'database');
    [$member] = dmRecipient();
    $delivery = dmDelivery($member);

    $diskFull = true;
    DmDelivery::saving(function (DmDelivery $saved) use (&$diskFull): void {
        if ($diskFull && $saved->isDirty('wrap')) {
            throw new RuntimeException('disk full');
        }
    });

    SendDirectMessage::dispatch($delivery->id);
    workDatabaseQueueOnce();

    $relay->assertNothingPublished();
    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Failed)
        ->and($delivery->fresh()->claimed_at)->toBeNull()
        ->and($delivery->fresh()->error)->toBe(RuntimeException::class)
        ->and(DB::table('jobs')->count())->toBe(1);

    $diskFull = false;
    $this->travel(SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS + 1)->seconds();
    workDatabaseQueueOnce();

    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Sent)
        ->and($relay->published())->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('sends a delivery whose worker died holding the claim once the queue hands the job out again', function () {
    $relay = FakeRelayTransport::bind();
    config()->set('queue.default', 'database');
    [$member] = dmRecipient();
    $delivery = dmDelivery($member);

    SendDirectMessage::dispatch($delivery->id);

    // A worker reserved the job, claimed the delivery and was killed.
    DB::table('jobs')->update(['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);
    $delivery->forceFill(['status' => DmDeliveryStatus::Claimed, 'claimed_at' => now(), 'attempts' => 1])->save();

    $this->travel((int) config('queue.connections.database.retry_after') + 1)->seconds();
    workDatabaseQueueOnce();

    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Sent)
        ->and($delivery->fresh()->claimed_at)->toBeNull()
        ->and($relay->published())->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('puts a job back on the database queue while another worker holds a live claim', function () {
    $relay = FakeRelayTransport::bind();
    config()->set('queue.default', 'database');
    [$member] = dmRecipient();
    $delivery = dmDelivery($member);
    $delivery->forceFill(['status' => DmDeliveryStatus::Claimed, 'claimed_at' => now()])->save();

    SendDirectMessage::dispatch($delivery->id);
    workDatabaseQueueOnce();

    $relay->assertNothingPublished();
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('available_at'))->toBe(now()->getTimestamp() + SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS);
});
