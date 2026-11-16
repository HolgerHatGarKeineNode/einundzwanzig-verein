<?php

use App\Enums\AssociationStatus;
use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Enums\ElectionFeeRule;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\PlebDmRelay;
use App\Models\Profile;
use App\Services\Dm\DirectMessageDryRun;
use App\Services\Dm\StartDirectMessageRun;
use App\Services\Election\OpenRound;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';
require_once __DIR__.'/../Election/helpers.php';

beforeEach(fn () => armDirectMessages());

/**
 * @return array<string, int>
 */
function dmTableCounts(): array
{
    return collect(['dm_messages', 'dm_runs', 'dm_deliveries', 'dm_delivery_relays'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

it('reports count, previews and unreachable members of a dry run and writes nothing', function () {
    $election = Election::factory()->feeRule(ElectionFeeRule::Year, 2027)->create(['name' => 'Vorstandswahl 2027']);
    $withInbox = EinundzwanzigPleb::factory()->active()->create();
    PlebDmRelay::factory()->for($withInbox, 'pleb')->create();
    Profile::query()->create(['pubkey' => $withInbox->pubkey, 'name' => 'Satoshi']);
    $withoutInbox = EinundzwanzigPleb::factory()->active()->create();
    $emptyInbox = EinundzwanzigPleb::factory()->active()->create();
    PlebDmRelay::factory()->for($emptyInbox, 'pleb')->create(['relays' => []]);
    EinundzwanzigPleb::factory()->active()->withPaidYear(2027)->create();

    // An existing message and run, so "nothing written" is not "nothing there".
    DmMessage::factory()->create()->freezeRun([$withInbox]);
    $before = dmTableCounts();

    $result = app(DirectMessageDryRun::class)->handle(
        DmMessageType::FeeReminder,
        DmAudience::FeeOutstanding,
        $election,
        ['year' => '2027', 'link' => 'https://verein.example/beitrag'],
        sampleSize: 2,
    );

    expect(dmTableCounts())->toBe($before)
        ->and($result->count)->toBe(3)
        ->and($result->description)->toBe('3 Mitglieder ohne bezahlten Beitrag für 2027 (Wahl „Vorstandswahl 2027“)')
        ->and($result->problems)->toBe([])
        ->and($result->unreachableIds)->toBe([$withoutInbox->id, $emptyInbox->id])
        ->and(array_column($result->previews, 'recipient_id'))->toBe([$withInbox->id, $withoutInbox->id])
        ->and($result->previews[0]['text'])->toStartWith("Hallo Satoshi,\n\nlaut Vereinsportal ist dein Mitgliedsbeitrag für 2027 noch offen.")
        ->and($result->previews[0]['text'])->toContain('https://verein.example/beitrag')
        ->and($result->previews[1]['text'])->toStartWith('Hallo Mitglied,');
});

it('returns the text problems of a dry run instead of previews', function () {
    EinundzwanzigPleb::factory()->active()->create();

    $result = app(DirectMessageDryRun::class)->handle(DmMessageType::FeeReminder, DmAudience::Members, variables: ['year' => '2027']);

    expect($result->count)->toBe(1)
        ->and($result->problems)->toBe(['no value for {link}'])
        ->and($result->previews)->toBe([])
        ->and(dmTableCounts())->toBe(['dm_messages' => 0, 'dm_runs' => 0, 'dm_deliveries' => 0, 'dm_delivery_relays' => 0]);
});

it('freezes the recipient list when a run starts; a member who qualifies later is not added', function () {
    Queue::fake();
    $first = EinundzwanzigPleb::factory()->active()->create();
    $second = EinundzwanzigPleb::factory()->state(['association_status' => AssociationStatus::HONORARY])->create();
    EinundzwanzigPleb::factory()->create();

    $run = app(StartDirectMessageRun::class)->handle(DmMessage::factory()->create(), DmAudience::Members);

    expect($run->fresh()->recipient_ids)->toBe([$first->id, $second->id])
        ->and($run->recipient_count)->toBe(2);
    Queue::assertPushed(DispatchBulkDirectMessages::class, fn (DispatchBulkDirectMessages $job): bool => $job->runId === $run->id);

    $late = EinundzwanzigPleb::factory()->active()->create();
    (new DispatchBulkDirectMessages($run->id))->handle();

    expect(DmDelivery::query()->where('dm_run_id', $run->id)->orderBy('einundzwanzig_pleb_id')->pluck('einundzwanzig_pleb_id')->all())
        ->toBe([$first->id, $second->id])
        ->and($late->id)->not->toBeIn(DmDelivery::query()->pluck('einundzwanzig_pleb_id')->all());
});

/*
 * Design Q23 / P6 DoD: "zweiter Versand in der Sperrfrist legt 0 neue an".
 * The same message to the same audience within the lock creates no second
 * delivery; the members are stored as skipped. Mutation that must turn this
 * red: let `DirectMessageRepeatLock::skippedIds()` return nothing.
 */
it('creates no delivery for a second sending of the same message to the same audience within the lock', function () {
    $first = EinundzwanzigPleb::factory()->active()->create();
    $second = EinundzwanzigPleb::factory()->active()->create();
    $start = app(StartDirectMessageRun::class);
    $same = fn (): DmMessage => DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => 'Morgen ist Wahl.', 'variables' => []]);

    $run = $start->handle($same(), DmAudience::Members);
    (new DispatchBulkDirectMessages($run->id))->handle();

    $this->travel(23)->hours();
    $dryRun = app(DirectMessageDryRun::class)->handle(DmMessageType::Individual, DmAudience::Members, plaintext: 'Morgen ist Wahl.');
    $again = $start->handle($same(), DmAudience::Members, confirmedRecipientIds: $dryRun->recipientIds);
    (new DispatchBulkDirectMessages($again->id))->handle();

    expect([$dryRun->count, $dryRun->recipientIds, $dryRun->skippedIds])->toBe([0, [], [$first->id, $second->id]])
        ->and($again->recipient_count)->toBe(0)
        ->and($again->fresh()->skipped_ids)->toBe([$first->id, $second->id])
        ->and(DmDelivery::query()->where('dm_run_id', $again->id)->count())->toBe(0)
        ->and(DmDelivery::query()->count())->toBe(2);
});

it('sends again after the lock, with another text and to another audience', function () {
    $member = EinundzwanzigPleb::factory()->active()->withPaidYear((int) now()->year)->create();
    $election = Election::factory()->create();
    $start = app(StartDirectMessageRun::class);
    $message = fn (string $text = 'Morgen ist Wahl.'): DmMessage => DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => $text, 'variables' => []]);

    $start->handle($message(), DmAudience::Members);

    expect($start->handle($message('Übermorgen ist Wahl.'), DmAudience::Members)->recipient_ids)->toBe([$member->id])
        ->and($start->handle($message(), DmAudience::EntitledVoters, $election)->recipient_ids)->toBe([$member->id]);

    $this->travel(25)->hours();

    expect($start->handle($message(), DmAudience::Members)->recipient_ids)->toBe([$member->id]);
});

/*
 * Gate A (r1): "the same audience" and "the same values" are part of the
 * lock. Mutations that must turn these red: drop the audience condition of
 * `DirectMessageRepeatLock::skippedIds()`; let `sameValues()` return true;
 * ignore `dm_repeat_lock_hours = 0`.
 */
it('sends the same text to another audience within the lock', function () {
    [$board] = electionBoard(1);
    $start = app(StartDirectMessageRun::class);
    $same = fn (): DmMessage => DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => 'Gleicher Text.', 'variables' => []]);

    expect($start->handle($same(), DmAudience::Members)->recipient_ids)->toContain($board->id)
        ->and($start->handle($same(), DmAudience::Board)->recipient_ids)->toBe([$board->id]);
});

it('sends the same text with other placeholder values within the lock', function () {
    $member = EinundzwanzigPleb::factory()->active()->create();
    $start = app(StartDirectMessageRun::class);
    $valued = fn (string $value): DmMessage => DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => 'Gleicher Text.', 'variables' => ['x' => $value]]);

    expect($start->handle($valued('1'), DmAudience::Members)->recipient_ids)->toBe([$member->id])
        ->and($start->handle($valued('2'), DmAudience::Members)->recipient_ids)->toBe([$member->id])
        ->and($start->handle($valued('2'), DmAudience::Members)->recipient_ids)->toBe([]);
});

/*
 * Jury run 1 (j1d): the election and the type are part of the lock as well
 * (DirectMessageRepeatLock::skippedIds(), the `election_event_id` and the
 * `type` condition). Each test ends with the repeat that IS locked, so it
 * cannot pass because nothing is locked at all. Mutations that must turn
 * them red: drop the election condition; drop the type condition.
 */
it('sends the same text to the entitled voters of another election within the lock', function () {
    $member = EinundzwanzigPleb::factory()->active()->withPaidYear((int) now()->year)->create();
    [$spring, $autumn] = Election::factory()->count(2)->create()->all();
    $start = app(StartDirectMessageRun::class);
    $same = fn (): DmMessage => DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => 'Gleicher Text.', 'variables' => []]);

    expect($start->handle($same(), DmAudience::EntitledVoters, $spring)->recipient_ids)->toBe([$member->id])
        ->and($start->handle($same(), DmAudience::EntitledVoters, $autumn)->recipient_ids)->toBe([$member->id])
        ->and($start->handle($same(), DmAudience::EntitledVoters, $autumn)->recipient_ids)->toBe([]);
});

it('sends the same text as another type of message within the lock', function () {
    $member = EinundzwanzigPleb::factory()->active()->create();
    $start = app(StartDirectMessageRun::class);
    $typed = fn (DmMessageType $type): DmMessage => DmMessage::factory()->create(['type' => $type, 'plaintext' => 'Gleicher Text.', 'variables' => []]);

    expect($start->handle($typed(DmMessageType::Individual), DmAudience::Members)->recipient_ids)->toBe([$member->id])
        ->and($start->handle($typed(DmMessageType::ElectionStartsNow), DmAudience::Members)->recipient_ids)->toBe([$member->id])
        ->and($start->handle($typed(DmMessageType::ElectionStartsNow), DmAudience::Members)->recipient_ids)->toBe([]);
});

it('locks nothing when the lock period is 0 hours', function () {
    config()->set('einundzwanzig.config.dm_repeat_lock_hours', 0);
    $member = EinundzwanzigPleb::factory()->active()->create();
    $start = app(StartDirectMessageRun::class);
    $same = fn (): DmMessage => DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => 'Morgen ist Wahl.', 'variables' => []]);

    $start->handle($same(), DmAudience::Members);
    $again = $start->handle($same(), DmAudience::Members);

    expect($again->recipient_ids)->toBe([$member->id])
        ->and($again->fresh()->skipped_ids)->toBeNull();
});

/*
 * Gate A (r1): two board members confirming the same sending at once both
 * passed the repeat check before either run existed. The check and the
 * creation of the run now hold `START_LOCK`: every query on `dm_runs` during
 * a start runs while another start could not take it. Mutation that must
 * turn this red: freeze without the lock.
 */
it('holds the start lock while it checks the repeat lock and creates the run', function () {
    EinundzwanzigPleb::factory()->active()->create();
    $freeDuringStart = [];
    $created = false;
    // Up to the insert of the run; the fan-out reads `dm_runs` afterwards.
    DB::listen(function ($query) use (&$freeDuringStart, &$created): void {
        if ($created || ! str_contains($query->sql, 'dm_runs')) {
            return;
        }

        $created = str_starts_with($query->sql, 'insert');
        $other = Cache::lock(StartDirectMessageRun::START_LOCK, 1);
        $freeDuringStart[] = $other->get();
        $other->release();
    });

    app(StartDirectMessageRun::class)->handle(
        DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => 'Morgen ist Wahl.', 'variables' => []]),
        DmAudience::Members,
    );

    expect($created)->toBeTrue()
        ->and(count($freeDuringStart))->toBeGreaterThan(1)
        ->and(array_unique($freeDuringStart))->toBe([false])
        ->and(Cache::lock(StartDirectMessageRun::START_LOCK, 1)->get())->toBeTrue();
});

/*
 * DoD 464 / E20 on the real send path: the frozen recipients of a sending do
 * not depend on who voted. Jury gate j2f (D1, G2b) let StartDirectMessageRun
 * drop every member with a row in `$round->votes()` — reached through the
 * allowed Election::rounds(), no vote model or table named — after the dry
 * run had confirmed them, and the whole suite stayed green. The same sending
 * is frozen once before and once after a member votes: dry run, the start
 * the board confirms, and a scheduled reminder started by `dm:send-due`. All
 * three must be the same both times. tests/Unit/DirectMessageArchitectureTest.php
 * scans for such reads; this holds the outcome, however they are written.
 */
it('freezes the same recipients whether or not a member already voted', function (DmAudience $audience) {
    Queue::fake();
    $voted = electionVoter();
    electionVoter(); // entitled, never votes
    $round = draftElectionRound(['seats' => 1]);
    $round->candidates()->orderBy('id')->firstOrFail()->update(['einundzwanzig_pleb_id' => $voted->id]);
    $round = app(OpenRound::class)->handle($round);
    $election = $round->election;

    $frozen = function (string $text) use ($audience, $election): array {
        $message = fn (): DmMessage => DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => $text, 'variables' => []]);
        $dryRun = app(DirectMessageDryRun::class)->handle(DmMessageType::Individual, $audience, $election, plaintext: $text);
        $started = app(StartDirectMessageRun::class)->handle($message(), $audience, $election, $dryRun->recipientIds);
        $schedule = DmSchedule::factory()->create([
            'election_event_id' => $election->id,
            'dm_message_id' => $message()->id,
            'audience' => $audience,
            'send_at' => now()->subMinute(),
        ]);

        test()->artisan('dm:send-due')->assertSuccessful();

        return [
            'dry run' => $dryRun->recipientIds,
            'started' => $started->fresh()->recipient_ids,
            'scheduled' => DmRun::query()->findOrFail($schedule->fresh()->dm_run_id)->recipient_ids,
        ];
    };

    $before = $frozen('Erinnerung, bevor jemand abgestimmt hat.');
    castElectionBallot($round, $voted);
    $after = $frozen('Erinnerung, nachdem jemand abgestimmt hat.');

    expect(electionVoteCount($round))->toBe(1)
        ->and($before['dry run'])->toContain($voted->id)
        ->and($before['started'])->toBe($before['dry run'])
        ->and($before['scheduled'])->toBe($before['dry run'])
        ->and($after)->toBe($before);
})->with([
    'entitled voters' => DmAudience::EntitledVoters,
    'members' => DmAudience::Members,
    'candidates' => DmAudience::Candidates,
]);
