<?php

use App\Console\Commands\Election\CloseEndedRounds;
use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionManifest;
use App\Services\Election\CloseRound;
use App\Services\Election\OpenRound;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

require_once __DIR__.'/helpers.php';

/*
 * The scheduled seal of rounds whose window has run out, and the order of a
 * run-off behind the round it belongs to.
 *
 * Mutations that must turn this file red (patches in the plan folder):
 *  - B1-C1 the command selects rounds whose end is one second ahead
 *  - B1-C2 the command stops at the first round it cannot seal
 *  - B1-C3 the command is not scheduled
 *  - B1-R1 `OpenRound` opens a run-off whose round is not sealed
 */

function closeEnded(): int
{
    return Artisan::call('election:close-ended');
}

it('seals a window round at the end of its window and not a second before', function () {
    $this->travelTo(Carbon::parse('2027-03-20 10:00:00'));
    $voter = electionVoter();
    $round = app(OpenRound::class)->handle(windowElectionRound(now(), Carbon::parse('2027-03-20 18:00:00')));
    castElectionBallot($round, $voter);

    $this->travelTo(Carbon::parse('2027-03-20 17:59:59'));

    expect(closeEnded())->toBe(0)
        ->and($round->fresh()->closed_at)->toBeNull();

    $this->travelTo(Carbon::parse('2027-03-20 18:00:00'));

    expect(closeEnded())->toBe(0)
        ->and($round->fresh()->isSealed())->toBeTrue()
        ->and($round->fresh()->closed_at->toDateTimeString())->toBe('2027-03-20 18:00:00')
        ->and(ElectionManifest::query()->where('election_round_id', $round->id)->count())->toBe(1);
});

it('changes nothing when it runs again', function () {
    $this->travelTo(Carbon::parse('2027-03-20 10:00:00'));
    $round = app(OpenRound::class)->handle(windowElectionRound(now(), now()->addHour()));

    $this->travelTo(Carbon::parse('2027-03-20 11:00:00'));
    closeEnded();
    $manifest = ElectionManifest::query()->where('election_round_id', $round->id)->sole();

    $this->travelTo(Carbon::parse('2027-03-20 11:05:00'));

    expect(closeEnded())->toBe(0)
        ->and(Artisan::output())->toBe('')
        ->and($round->fresh()->closed_at->toDateTimeString())->toBe('2027-03-20 11:00:00')
        ->and(ElectionManifest::query()->where('election_round_id', $round->id)->pluck('hash')->all())->toBe([$manifest->hash]);
});

it('seals a live round once its maximum duration is over', function () {
    $this->travelTo(Carbon::parse('2027-03-20 14:00:00'));
    $round = openElectionRound();

    $this->travelTo($round->closes_at->copy()->subSecond());
    closeEnded();

    expect($round->fresh()->closed_at)->toBeNull();

    $this->travelTo($round->closes_at);
    closeEnded();

    expect($round->fresh()->isSealed())->toBeTrue();
});

it('leaves drafts, scheduled, open, annulled and sealed rounds alone', function () {
    $this->travelTo(Carbon::parse('2027-03-20 10:00:00'));

    $draft = windowElectionRound(now()->subDays(2), now()->subDay());
    $scheduled = app(OpenRound::class)->handle(windowElectionRound(now()->addDay(), now()->addDays(2)));
    $open = app(OpenRound::class)->handle(windowElectionRound(now(), now()->addHour()));
    $annulled = app(OpenRound::class)->handle(windowElectionRound(now(), now()->addMinute()));
    $annulled->annulled_at = now();
    $annulled->save();
    $sealed = app(CloseRound::class)->handle(openElectionRound());

    $this->travelTo(Carbon::parse('2027-03-20 10:30:00'));

    expect(closeEnded())->toBe(0)
        ->and($draft->fresh()->opened_at)->toBeNull()
        ->and($scheduled->fresh()->closed_at)->toBeNull()
        ->and($open->fresh()->closed_at)->toBeNull()
        ->and($annulled->fresh()->closed_at)->toBeNull()
        ->and($sealed->fresh()->closed_at->toDateTimeString())->toBe('2027-03-20 10:00:00');
});

it('seals the other rounds when one of them cannot be sealed, and reports it', function () {
    Exceptions::fake();
    $this->travelTo(Carbon::parse('2027-03-20 10:00:00'));

    $first = app(OpenRound::class)->handle(windowElectionRound(now(), now()->addMinutes(10)));
    $second = app(OpenRound::class)->handle(windowElectionRound(now(), now()->addMinutes(20)));

    // The first round's manifest cannot be written, so its seal rolls back.
    DB::statement("CREATE TRIGGER refuse_first_manifest BEFORE INSERT ON election_manifests WHEN NEW.election_round_id = {$first->id} BEGIN SELECT RAISE(ABORT, 'manifest refused'); END");

    $this->travelTo(Carbon::parse('2027-03-20 10:30:00'));

    expect(closeEnded())->toBe(1)
        ->and(Artisan::output())->toContain('Could not seal round '.$first->id.'.')
        ->and($first->fresh()->closed_at)->toBeNull()
        ->and($second->fresh()->isSealed())->toBeTrue();

    Exceptions::assertReported(fn (QueryException $exception): bool => str_contains($exception->getMessage(), 'manifest refused'));
});

it('runs every minute without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'election:close-ended'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and(class_exists(CloseEndedRounds::class))->toBeTrue();
});

it('opens a run-off only after the round it belongs to is sealed', function () {
    $this->travelTo(Carbon::parse('2027-03-20 10:00:00'));
    $parent = app(OpenRound::class)->handle(windowElectionRound(now(), now()->addHour()));
    $runOff = draftElectionRound(['parent_round_id' => $parent->id], election: $parent->election);

    expect(fn () => app(OpenRound::class)->handle($runOff))
        ->toThrow(ElectionRoundStateException::class, 'A run-off can only be opened once the round it belongs to is sealed.');

    // Over by the clock is not enough; the seal is.
    $this->travelTo(Carbon::parse('2027-03-20 11:00:00'));

    expect(fn () => app(OpenRound::class)->handle($runOff))
        ->toThrow(ElectionRoundStateException::class, 'A run-off can only be opened once the round it belongs to is sealed.');

    closeEnded();

    expect(app(OpenRound::class)->handle($runOff)->opened_at)->not->toBeNull();
});
