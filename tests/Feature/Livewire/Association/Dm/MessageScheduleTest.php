<?php

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Models\DmMessage;
use App\Models\DmSchedule;
use App\Models\Election;
use App\Services\Dm\DirectMessagePause;
use App\Support\NostrAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../../../Feature/Jobs/DirectMessageHelpers.php';
require_once __DIR__.'/helpers.php';

/*
 * "Zeitplan" of the board's message centre, a child component of its own:
 * the global pause, an election's planned messages and a new planned row. A
 * changed schedule or pause is handed to the page as `messages-changed`.
 */

beforeEach(fn () => armDirectMessages());

it('answers 403 on every public method once the session belongs to a member who is not on the board', function () {
    Queue::fake();
    [$board] = electionBoard(1);
    $member = electionVoter();
    $row = DmSchedule::factory()->create();

    $methods = expectEveryMessageMethodBoardOnly(
        fn () => messageSchedule()->set('newDate', '2099-01-01')->set('newTime', '09:00'),
        $board,
        $member,
        ['scheduleId' => $row->id],
        pure: ['scheduleTypes', 'variableLabel', 'variableHint', 'longDate', 'shortDate'],
        updates: ['paused' => true, 'scheduleElectionId' => null, 'newType' => 'fee_reminder'],
    );

    expect($methods)->toBe(['addSchedule', 'elections', 'pausedAt', 'removeSchedule', 'scheduleElection', 'scheduleRows'])
        ->and(DmSchedule::query()->whereKey($row->id)->exists())->toBeTrue()
        ->and(DmSchedule::query()->count())->toBe(1)
        ->and(app(DirectMessagePause::class)->isPaused())->toBeFalse();
    Queue::assertNothingPushed();

    NostrAuth::login($member->pubkey);
    messageSchedule()->assertForbidden();
});

it('adds a planned row in German time, says to how many it would go today, and removes it again', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2027-02-01 08:00:00'));
    messageCentreBoard(2);
    $election = Election::factory()->create();

    $page = messageSchedule()
        ->set('scheduleElectionId', $election->id)
        ->set('newType', DmMessageType::ElectionAnnouncement->value)
        ->set('newDate', '2027-02-10')
        ->set('newTime', '09:00')
        ->set('newVariables.assembly_date', '20. März 2027, 19:30 Uhr')
        ->call('addSchedule')
        ->assertHasNoErrors()
        ->assertDispatched('messages-changed');

    $row = DmSchedule::query()->sole();

    expect($row->send_at->toDateTimeString())->toBe('2027-02-10 08:00:00')
        ->and($row->audience)->toBe(DmAudience::Members)
        ->and($row->message->type)->toBe(DmMessageType::ElectionAnnouncement);
    $page->assertSee('würde heute an 3 gehen');

    $page->call('removeSchedule', $row->id);

    expect(DmSchedule::query()->count())->toBe(0)->and(DmMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a planned row in the past and keeps a started row', function () {
    $this->travelTo(Carbon::parse('2027-02-01 08:00:00'));
    messageCentreBoard();
    $election = Election::factory()->create();
    $started = DmSchedule::factory()->create(['election_event_id' => $election->id]);
    $started->forceFill(['dm_run_id' => DmMessage::factory()->create()->freezeRun([])->id])->save();

    messageSchedule()
        ->set('scheduleElectionId', $election->id)
        ->set('newDate', '2027-01-31')
        ->set('newTime', '09:00')
        ->set('newVariables.assembly_date', 'morgen')
        ->call('addSchedule')
        ->assertHasErrors(['newDate'])
        ->call('removeSchedule', $started->id);

    expect(DmSchedule::query()->count())->toBe(1);
});

it('pauses and resumes the sending with the switch and says so', function () {
    Queue::fake();
    messageCentreBoard();

    $page = messageSchedule()
        ->assertSee('Versand läuft')
        ->set('paused', true)
        ->assertDispatched('messages-changed');

    expect(app(DirectMessagePause::class)->isPaused())->toBeTrue();
    // The page's own pause line follows through `messages-changed` (MessageCentreTest).
    $page->assertSee('Versand pausiert')->assertSee('geht nichts raus: keine neue, keine geplante und keine wartende Nachricht.');

    $page->set('paused', false);

    expect(app(DirectMessagePause::class)->isPaused())->toBeFalse();
    $page->assertSee('Versand läuft');
});

it('hands a removed row to the page, whose tab count follows', function () {
    $this->travelTo(Carbon::parse('2027-02-01 08:00:00'));
    messageCentreBoard();
    $row = DmSchedule::factory()->create(['send_at' => now()->addDay()]);

    $page = messageCentre()->assertSee('1 geplant');

    messageSchedule()->call('removeSchedule', $row->id)->assertDispatched('messages-changed');

    $page->assertSee('1 geplant')
        ->dispatch('messages-changed')
        ->assertDontSee('1 geplant');
});
