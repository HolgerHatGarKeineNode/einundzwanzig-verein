<?php

use App\Enums\DmAudience;
use App\Enums\DmDeliveryStatus;
use App\Enums\DmMessageType;
use App\Enums\ElectionFeeRule;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Jobs\Dm\SendDirectMessage;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Services\Dm\DirectMessagePause;
use App\Services\Dm\DirectMessageTextInvalid;
use App\Services\Dm\ScheduleDirectMessage;
use App\Services\Dm\StartDirectMessageRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/../Jobs/DirectMessageHelpers.php';

/*
 * Scheduled messages along the election timeline, the fee reminder and the
 * global pause.
 *
 * Mutations that must turn this file red (patches in the plan folder):
 *  - P3B2-due-off-by-one   the due check also selects rows one second ahead
 *  - P3B2-pause-fan-out    the fan-out ignores the pause
 *  - P3B2-resume-pending-only  resume() re-queues runs with pending deliveries only
 */

beforeEach(fn () => armDirectMessages());

function sendDue(): int
{
    return Artisan::call('dm:send-due');
}

it('starts only the rows whose moment has come, and each of them once', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2027-02-01 09:00:00'));
    EinundzwanzigPleb::factory()->active()->count(2)->create();
    $past = DmSchedule::factory()->create(['send_at' => now()->subMinute()]);
    $exactlyNow = DmSchedule::factory()->create(['send_at' => now()]);
    $oneSecondAhead = DmSchedule::factory()->create(['send_at' => now()->addSecond()]);

    expect(sendDue())->toBe(0)
        ->and($past->fresh()->dm_run_id)->not->toBeNull()
        ->and($exactlyNow->fresh()->dm_run_id)->not->toBeNull()
        ->and($oneSecondAhead->fresh()->dm_run_id)->toBeNull()
        ->and(DmRun::query()->count())->toBe(2)
        ->and($past->fresh()->run->recipient_count)->toBe(2);
    Queue::assertPushed(DispatchBulkDirectMessages::class, 2);

    expect(sendDue())->toBe(0)
        ->and(DmRun::query()->count())->toBe(2);
    Queue::assertPushed(DispatchBulkDirectMessages::class, 2);

    $this->travelTo(Carbon::parse('2027-02-01 09:00:01'));
    sendDue();

    expect($oneSecondAhead->fresh()->dm_run_id)->not->toBeNull()
        ->and(DmRun::query()->count())->toBe(3);
});

it('does not start a row a second time when it was started between selection and start', function () {
    Queue::fake();
    $schedule = DmSchedule::factory()->create(['send_at' => now()->subMinute()]);
    $start = app(StartDirectMessageRun::class);

    $first = $start->forSchedule($schedule);
    $second = $start->forSchedule($schedule);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(DmRun::query()->count())->toBe(1);
});

it('is scheduled every minute without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'dm:send-due'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('plans the fee reminder for the members the fee rule does not entitle and counts them without writing', function () {
    Queue::fake();
    $election = Election::factory()->feeRule(ElectionFeeRule::Year, 2027)->create();
    $unpaid = EinundzwanzigPleb::factory()->active()->withPaidYear(2026)->create();
    EinundzwanzigPleb::factory()->active()->withPaidYear(2027)->create();

    $schedule = app(ScheduleDirectMessage::class)->handle(
        $election,
        DmMessageType::FeeReminder,
        DmAudience::FeeOutstanding,
        now()->addWeek(),
        ['year' => '2027', 'link' => 'https://verein.example/beitrag'],
    );

    $runsBefore = DmRun::query()->count();

    expect($schedule->recipientCountToday())->toBe(1)
        ->and(DmRun::query()->count())->toBe($runsBefore)
        ->and($schedule->message->type)->toBe(DmMessageType::FeeReminder);

    $this->travel(8)->days();
    sendDue();

    expect($schedule->fresh()->run->recipient_ids)->toBe([$unpaid->id]);
});

it('refuses to plan a text with a broken placeholder and writes no row', function () {
    expect(fn () => app(ScheduleDirectMessage::class)->handle(
        Election::factory()->create(),
        DmMessageType::ElectionStartsNow,
        DmAudience::Members,
        now()->addDay(),
        [],
    ))->toThrow(DirectMessageTextInvalid::class);

    expect(DmSchedule::query()->count())->toBe(0)
        ->and(DmMessage::query()->count())->toBe(0);
});

it('starts nothing while paused and starts the due rows once the pause is lifted', function () {
    Queue::fake();
    EinundzwanzigPleb::factory()->active()->create();
    $schedule = DmSchedule::factory()->create(['send_at' => now()->subMinute()]);
    $pause = app(DirectMessagePause::class);

    $pause->pause();

    expect(sendDue())->toBe(0)
        ->and($schedule->fresh()->dm_run_id)->toBeNull()
        ->and(app(StartDirectMessageRun::class)->handle(DmMessage::factory()->create(), DmAudience::Members))->toBeNull()
        ->and(DmRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    $pause->resume();
    sendDue();

    expect($schedule->fresh()->dm_run_id)->not->toBeNull();
});

it('lets the fan-out create and queue nothing while paused, and resumes it when the pause is lifted', function () {
    Queue::fake();
    $members = EinundzwanzigPleb::factory()->active()->count(3)->create();
    $run = DmMessage::factory()->create()->freezeRun($members);
    $pause = app(DirectMessagePause::class);

    $pause->pause();
    (new DispatchBulkDirectMessages($run->id))->handle();

    expect(DmDelivery::query()->count())->toBe(0)
        ->and($run->fresh()->fanned_out_at)->toBeNull();
    Queue::assertNotPushed(SendDirectMessage::class);

    $pause->resume();
    Queue::assertPushed(DispatchBulkDirectMessages::class, fn (DispatchBulkDirectMessages $job): bool => $job->runId === $run->id);

    (new DispatchBulkDirectMessages($run->id))->handle();

    expect(DmDelivery::query()->count())->toBe(3);
    Queue::assertPushed(SendDirectMessage::class, 3);
});

it('leaves an already queued delivery pending while paused and publishes nothing', function () {
    $relay = FakeRelayTransport::bind();
    [$recipient] = dmRecipient();
    $delivery = dmDelivery($recipient);

    app(DirectMessagePause::class)->pause();
    SendDirectMessage::dispatchSync($delivery->id);

    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Pending)
        ->and($delivery->fresh()->attempts)->toBe(0)
        ->and($relay->published())->toBe([]);

    Queue::fake();
    app(DirectMessagePause::class)->resume();
    Queue::assertPushed(DispatchBulkDirectMessages::class, fn (DispatchBulkDirectMessages $job): bool => $job->runId === $delivery->dm_run_id);
});

it('keeps the pause in the database, so a cleared cache does not lift it', function () {
    app(DirectMessagePause::class)->pause();
    app(DirectMessagePause::class)->pause();
    Artisan::call('cache:clear');

    expect(app(DirectMessagePause::class)->isPaused())->toBeTrue()
        ->and(DB::table('dm_settings')->count())->toBe(1);

    app(DirectMessagePause::class)->resume();

    expect(app(DirectMessagePause::class)->isPaused())->toBeFalse();
});

/*
 * The database queue: a job that returns is deleted there, so a send the
 * pause swallowed is only retried if resume() finds its run. Mutation
 * P3B2-resume-pending-only → both red.
 */
function workDatabaseQueue(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--quiet' => true]);
}

it('sends a failed delivery whose queued retry was swallowed by the pause once the pause is lifted', function () {
    $relay = FakeRelayTransport::bind();
    config()->set('queue.default', 'database');
    [$member] = dmRecipient();
    $relay->thenFail('timeout')->thenAccept();

    DispatchBulkDirectMessages::dispatch(DmMessage::factory()->create()->freezeRun([$member])->id);
    workDatabaseQueue();
    $delivery = DmDelivery::query()->sole();

    expect($delivery->status)->toBe(DmDeliveryStatus::Failed)
        ->and(DB::table('jobs')->count())->toBe(1);

    app(DirectMessagePause::class)->pause();
    $this->travel(61)->seconds();
    workDatabaseQueue();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($delivery->fresh()->status)->toBe(DmDeliveryStatus::Failed);

    app(DirectMessagePause::class)->resume();
    workDatabaseQueue();

    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Sent)
        ->and($relay->published())->toHaveCount(2)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('sends an expired claim whose redelivery was swallowed by the pause once the pause is lifted', function () {
    $relay = FakeRelayTransport::bind();
    config()->set('queue.default', 'database');
    [$member] = dmRecipient();
    $delivery = dmDelivery($member);
    $delivery->run->forceFill(['fanned_out_at' => now()->subMinutes(5)])->save();
    $delivery->forceFill(['status' => DmDeliveryStatus::Claimed, 'claimed_at' => now()->subMinutes(5), 'attempts' => 1])->save();

    app(DirectMessagePause::class)->pause();
    SendDirectMessage::dispatch($delivery->id);
    workDatabaseQueue();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($delivery->fresh()->status)->toBe(DmDeliveryStatus::Claimed);

    app(DirectMessagePause::class)->resume();
    workDatabaseQueue();

    expect($delivery->fresh()->status)->toBe(DmDeliveryStatus::Sent)
        ->and($relay->published())->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});
