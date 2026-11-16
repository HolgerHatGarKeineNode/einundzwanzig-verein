<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionCountingRule;
use App\Exceptions\ElectionBallotRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Models\ElectionVote;
use App\Services\Election\CastBallot;
use App\Services\Election\CloseRound;
use App\Services\Election\OpenRound;
use App\Services\Election\VoteEvent;
use App\Services\MembershipService;
use App\Services\Nostr\EventSigning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/*
 * Taking in a signed vote: who may, when, how often, with which event — and
 * what must not happen around it.
 *
 * Mutations that must turn this file red (patches in the plan folder):
 *  - P2C-signature  the signature check removed from `VoteEvent::failures()`
 *  - P2C-pubkey     the signer check removed
 *  - P2C-binding    the election/round binding check removed
 *  - P2C-unique     the unique index on round and member removed
 */

it('takes in a vote as one row with member, pubkey, the signed event and the choices', function () {
    $voter = electionVoter();
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 3);
    [$first, $second] = $round->candidates()->pluck('id')->all();

    $event = castElectionBallot($round, $voter, [$first => 'for', $second => 'for']);
    $vote = ElectionVote::query()->sole();

    expect($vote->only(['election_round_id', 'einundzwanzig_pleb_id', 'pubkey', 'event_id', 'event', 'choices']))->toBe([
        'election_round_id' => $round->id,
        'einundzwanzig_pleb_id' => $voter->id,
        'pubkey' => $voter->fresh()->pubkey,
        'event_id' => $event['id'],
        'event' => $event,
        'choices' => [$first => 'for', $second => 'for'],
    ]);
});

it('returns nothing to the caller', function () {
    expect((string) (new ReflectionMethod(CastBallot::class, 'handle'))->getReturnType())->toBe('void');
});

it('accepts a blank vote and stores it with no choices', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    castElectionBallot($round, $voter, []);

    expect(electionVoteCount($round))->toBe(1)
        ->and(ElectionVote::query()->sole()->choices)->toBe([]);
});

it('refuses a second vote from the same member, also with other choices, and keeps the first', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    [$first, $second] = $round->candidates()->orderBy('id')->pluck('id')->all();

    castElectionBallot($round, $voter, [$first => 'for']);

    expect(electionRefusal(fn () => castElectionBallot($round, $voter, [$second => 'for'])))
        ->toBe(ElectionBallotRefusal::AlreadyVoted)
        ->and(electionVoteCount($round))->toBe(1)
        ->and(ElectionVote::query()->sole()->choices)->toBe([$first => 'for']);
});

it('refuses an event whose signature does not hold', function (Closure $tamper) {
    $voter = electionVoter();
    $round = openElectionRound();
    $event = $tamper(signedElectionVote($round, $voter, [$round->candidates()->value('id') => 'for']), $round);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, $event)))
        ->toBe(ElectionBallotRefusal::InvalidSignature)
        ->and(electionVoteCount($round))->toBe(0);
})->with([
    'a forged signature' => [fn (array $event): array => [...$event, 'sig' => str_repeat('ab', 64)]],
    'the choice changed after signing' => [function (array $event, ElectionRound $round): array {
        $event['tags'][2][1] = (string) $round->candidates()->orderByDesc('id')->value('id');

        return $event;
    }],
    'the id changed' => [fn (array $event): array => [...$event, 'id' => str_repeat('0', 64)]],
    'no signature' => [fn (array $event): array => array_diff_key($event, ['sig' => true])],
]);

it('refuses an event signed with another key than the member\'s', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    $template = VoteEvent::template($round, [], now()->getTimestamp());
    $event = EventSigning::sign(EventSigning::freshSecret(), $template['kind'], $template['tags'], $template['content'], $template['created_at']);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, $event)))
        ->toBe(ElectionBallotRefusal::ForeignSigner)
        ->and(electionVoteCount($round))->toBe(0);
});

it('refuses another member\'s signed vote', function () {
    [$voter, $other] = [electionVoter(), electionVoter()];
    $round = openElectionRound();
    $othersEvent = signedElectionVote($round, $other);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, $othersEvent)))
        ->toBe(ElectionBallotRefusal::ForeignSigner)
        ->and(electionVoteCount($round))->toBe(0);
});

it('refuses an event of another kind', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, signedElectionVote($round, $voter, overrides: ['kind' => 1]))))
        ->toBe(ElectionBallotRefusal::WrongKind)
        ->and(electionVoteCount($round))->toBe(0);
});

it('refuses a vote signed for another round when it is replayed into this one', function () {
    $voter = electionVoter();
    $election = Election::factory()->create();
    $first = openElectionRound(election: $election);
    $second = openElectionRound(['position' => 2], election: $election);

    $signedForFirst = signedElectionVote($first, $voter);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($second, $voter, $signedForFirst)))
        ->toBe(ElectionBallotRefusal::WrongRound)
        ->and(electionVoteCount($second))->toBe(0);

    app(CastBallot::class)->handle($first, $voter, $signedForFirst);

    expect(electionVoteCount($first))->toBe(1);
});

it('refuses an event that does not name exactly this election and this round', function (Closure $tags) {
    $voter = electionVoter();
    $round = openElectionRound();
    $event = signedElectionVote($round, $voter, overrides: ['tags' => $tags($round)]);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, $event)))
        ->toBe(ElectionBallotRefusal::WrongRound);
})->with([
    'another election' => [fn (ElectionRound $round): array => [['election', (string) ($round->election_event_id + 1)], ['round', (string) $round->id]]],
    'no election' => [fn (ElectionRound $round): array => [['round', (string) $round->id]]],
    'no round' => [fn (ElectionRound $round): array => [['election', (string) $round->election_event_id]]],
    'a second round' => [fn (ElectionRound $round): array => [['election', (string) $round->election_event_id], ['round', (string) $round->id], ['round', (string) ($round->id + 1)]]],
]);

it('refuses an event signed outside the open period, beyond the clock tolerance', function (int $offset, ?ElectionBallotRefusal $refusal) {
    $this->freezeTime();
    $voter = electionVoter();
    $round = openElectionRound();

    $event = signedElectionVote($round, $voter, overrides: ['created_at' => $round->opens_at->getTimestamp() + $offset]);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, $event)))->toBe($refusal);
})->with([
    'before the beginning, beyond the tolerance' => [-VoteEvent::CLOCK_TOLERANCE_SECONDS - 1, ElectionBallotRefusal::OutsideOpenPeriod],
    'before the beginning, within the tolerance' => [-VoteEvent::CLOCK_TOLERANCE_SECONDS, null],
    'ahead of the clock, beyond the tolerance' => [VoteEvent::CLOCK_TOLERANCE_SECONDS + 1, ElectionBallotRefusal::OutsideOpenPeriod],
    'ahead of the clock, within the tolerance' => [VoteEvent::CLOCK_TOLERANCE_SECONDS, null],
]);

it('refuses an event signed before a window round began, though the window is open now', function () {
    $voter = electionVoter();
    $round = app(OpenRound::class)->handle(ElectionRound::factory()->withCandidates()->window(now()->addHour(), now()->addHours(3))->create());
    $early = signedElectionVote($round, $voter);

    $this->travelTo(now()->addHours(2));

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, $early)))
        ->toBe(ElectionBallotRefusal::OutsideOpenPeriod)
        ->and(electionRefusal(fn () => castElectionBallot($round, $voter)))->toBeNull();
});

it('refuses vote tags that are malformed or name a candidate twice', function () {
    $voter = electionVoter();
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::YesNoPerCandidate]);
    $candidate = (string) $round->candidates()->value('id');
    $tags = [...VoteEvent::template($round, [], 0)['tags'], ['vote', $candidate, 'yes'], ['vote', $candidate, 'no']];

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, signedElectionVote($round, $voter, overrides: ['tags' => $tags]))))
        ->toBe(ElectionBallotRefusal::InvalidChoices)
        ->and(electionVoteCount($round))->toBe(0);
});

it('lets every member on the roll vote once', function () {
    $voters = collect([electionVoter(), electionVoter(), electionVoter()]);
    $round = openElectionRound();

    $voters->each(fn (EinundzwanzigPleb $voter) => castElectionBallot($round, $voter));

    expect(electionVoteCount($round))->toBe(3);
});

/**
 * Rounds that must refuse a ballot, by name. A function rather than a dataset
 * of closures, so each round is built inside the test, after the voter.
 */
function electionRoundThatIsNotOpen(string $kind): ElectionRound
{
    $alter = function (array $columns): ElectionRound {
        $round = openElectionRound();
        DB::table('election_rounds')->where('id', $round->id)->update($columns);

        return $round;
    };

    return match ($kind) {
        'a draft' => draftElectionRound(),
        'a window that has not begun' => app(OpenRound::class)->handle(
            ElectionRound::factory()->withCandidates()->window(now()->addDay(), now()->addDays(2))->create()
        ),
        'a window that has run out' => tap(app(OpenRound::class)->handle(
            ElectionRound::factory()->withCandidates()->window(now(), now()->addHour())->create()
        ), fn () => test()->travelTo(now()->addHour())),
        'a live round past its maximum duration' => tap(
            openElectionRound(),
            fn () => test()->travelTo(now()->addMinutes(OpenRound::LIVE_MAX_MINUTES)),
        ),
        'a sealed round' => app(CloseRound::class)->handle(openElectionRound()),
        'a round without an end' => $alter(['closes_at' => null]),
        'a round without a beginning' => $alter(['opens_at' => null]),
        'an annulled round' => $alter(['annulled_at' => now()]),
        'a round that no longer exists' => tap(
            draftElectionRound(),
            fn (ElectionRound $round) => DB::table('election_rounds')->where('id', $round->id)->delete(),
        ),
    };
}

it('refuses a ballot for a round that is not open', function (string $kind) {
    $voter = electionVoter();
    $round = electionRoundThatIsNotOpen($kind);

    expect(electionRefusal(fn () => castElectionBallot($round, $voter)))
        ->toBe(ElectionBallotRefusal::RoundNotOpen)
        ->and(electionVoteCount($round))->toBe(0);
})->with([
    'a draft',
    'a window that has not begun',
    'a window that has run out',
    'a live round past its maximum duration',
    'a sealed round',
    'a round without an end',
    'a round without a beginning',
    'an annulled round',
    'a round that no longer exists',
]);

it('decides on the stored round, not on the instance the caller holds', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    $loadedWhileOpen = ElectionRound::query()->findOrFail($round->id);

    app(CloseRound::class)->handle($round);

    expect(electionRefusal(fn () => castElectionBallot($loadedWhileOpen, $voter)))
        ->toBe(ElectionBallotRefusal::RoundNotOpen);
});

it('refuses a member who is not on the roll', function (string $kind) {
    $year = (int) now()->year;

    $member = match ($kind) {
        'a passive member who has paid' => EinundzwanzigPleb::factory()
            ->withPaidYear($year)
            ->create(['association_status' => AssociationStatus::PASSIVE]),
        'an active member who has not paid' => EinundzwanzigPleb::factory()->active()->create(),
        'an active member who paid for another year' => electionVoter($year - 1),
        'a non-member who has paid' => EinundzwanzigPleb::factory()->withPaidYear($year)->create(),
    };
    $round = openElectionRound();

    expect(electionRefusal(fn () => castElectionBallot($round, $member)))
        ->toBe(ElectionBallotRefusal::NotOnRoll)
        ->and(electionVoteCount($round))->toBe(0);
})->with([
    'a passive member who has paid',
    'an active member who has not paid',
    'an active member who paid for another year',
    'a non-member who has paid',
]);

it('refuses a member who became entitled only after the round opened, without a payment', function () {
    $round = openElectionRound();
    $latecomer = electionVoter();

    expect(electionRefusal(fn () => castElectionBallot($round, $latecomer)))->toBe(ElectionBallotRefusal::NotOnRoll);
});

it('keeps the right to vote across a change of year', function () {
    $this->travelTo(Carbon::parse('2026-12-30 12:00:00'));

    $voter = electionVoter(2026);
    $election = Election::factory()->create(['fee_year' => 2026]);
    $round = app(OpenRound::class)->handle(
        ElectionRound::factory()
            ->withCandidates()
            ->window(now(), Carbon::parse('2027-01-05 12:00:00'))
            ->create(['election_event_id' => $election->id])
    );

    $this->travelTo(Carbon::parse('2027-01-02 12:00:00'));

    expect($voter->fresh()->hasPaidMembership())->toBeFalse();

    castElectionBallot($round, $voter);

    expect(electionVoteCount($round))->toBe(1);
});

it('keeps the right to vote when the payment is reversed while the round is open', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    $reversal = app(MembershipService::class)->reversePayment($voter->paymentEvents()->sole(), 'invalid', 'test');

    expect($reversal['reversed'])->toBeTrue()
        ->and($voter->fresh()->hasPaidMembership())->toBeFalse();

    castElectionBallot($round, $voter);

    expect(electionVoteCount($round))->toBe(1);
});

it('keeps the right to vote when the member is made passive while the round is open', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    $voter->association_status = AssociationStatus::PASSIVE;
    $voter->save();

    castElectionBallot($round, $voter);

    expect(electionVoteCount($round))->toBe(1);
});

it('refuses a ballot that does not fit the frozen rule and writes no mark', function (string $kind) {
    $voter = electionVoter();
    $round = openElectionRound();
    $own = $round->candidates()->pluck('id');

    $selection = match ($kind) {
        'more votes than the rule allows' => $own->mapWithKeys(fn (int $id): array => [$id => 'for'])->all(),
        'a candidate of another round' => [draftElectionRound()->candidates()->value('id') => 'for'],
        'a choice the rule does not know' => [$own->first() => 'yes'],
    };

    expect(electionRefusal(fn () => castElectionBallot($round, $voter, $selection)))
        ->toBe(ElectionBallotRefusal::InvalidChoices)
        ->and(electionVoteCount($round))->toBe(0);

    castElectionBallot($round, $voter);

    expect(electionVoteCount($round))->toBe(1);
})->with([
    'more votes than the rule allows',
    'a candidate of another round',
    'a choice the rule does not know',
]);

it('pushes no job and sends no notification when a ballot is cast', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    Queue::fake();
    Notification::fake();

    castElectionBallot($round, $voter, [$round->candidates()->value('id') => 'for']);

    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

it('dispatches no event of its own when a ballot is cast', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    $event = signedElectionVote($round, $voter, [$round->candidates()->value('id') => 'for']);

    Event::fake();

    app(CastBallot::class)->handle($round, $voter, $event);

    /*
     * With the dispatcher faked, the framework's own events are still
     * recorded: Eloquent's lifecycle events for the models that were READ,
     * and the database's transaction and query events. Anything beyond those
     * would be something `CastBallot` dispatched — and no Eloquent event may
     * concern the vote or the member. A class being
     * booted is not a row event: it fires once per process and carries the
     * class name only. The cache events are the per-member throttle's
     * (`CastBallot::throttle()`), raised inside the telemetry blackout.
     */
    $dispatched = array_keys((new ReflectionProperty(Event::getFacadeRoot(), 'events'))->getValue(Event::getFacadeRoot()));

    $own = array_values(array_filter($dispatched, fn (string $event): bool => ! str_starts_with($event, 'eloquent.')
        && ! str_starts_with($event, 'Illuminate\\Database\\Events\\')
        && ! str_starts_with($event, 'Illuminate\\Cache\\Events\\')));

    $aboutSecretRows = array_values(array_filter($dispatched, fn (string $event): bool => str_starts_with($event, 'eloquent.')
        && preg_match('/^eloquent\.boot(ing|ed): /', $event) !== 1
        && preg_match('/ElectionVote|EinundzwanzigPleb/', $event) === 1));

    expect($own)->toBe([])
        ->and($aboutSecretRows)->toBe([])
        ->and($dispatched)->not->toBe([]);
});

it('writes no log line when a ballot is cast or refused', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message;
    });

    castElectionBallot($round, $voter);
    electionRefusal(fn () => castElectionBallot($round, $voter));

    Log::info('positive control');

    expect($logged)->toBe(['positive control']);
});

it('names neither the member nor the round nor the choice when it refuses', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    castElectionBallot($round, $voter);

    try {
        castElectionBallot($round, $voter, [$round->candidates()->value('id') => 'for']);
    } catch (ElectionBallotRefusedException $exception) {
        expect($exception->getMessage())->toBe('Ballot refused: already_voted')
            ->and($exception->getPrevious())->toBeNull()
            ->and(get_object_vars($exception))->toBe(['reason' => ElectionBallotRefusal::AlreadyVoted]);

        return;
    }

    $this->fail('The second ballot was not refused.');
});

it('tells the member why a ballot was refused, in German under any locale', function () {
    app()->setLocale('en');

    expect(ElectionBallotRefusal::AlreadyVoted->label())->toBe('Du hast in diesem Wahlgang bereits gewählt.')
        ->and(collect(ElectionBallotRefusal::cases())->map->label()->unique())->toHaveCount(count(ElectionBallotRefusal::cases()));
});

it('does not report a refused ballot, nor a refusal of a subclass', function () {
    Exceptions::fake();

    $round = openElectionRound();
    $stranger = electionVoter();

    try {
        castElectionBallot($round, $stranger);
    } catch (ElectionBallotRefusedException $refusal) {
        report($refusal);
    }

    expect($refusal ?? null)->toBeInstanceOf(ElectionBallotRefusedException::class);

    report(new class(ElectionBallotRefusal::AlreadyVoted) extends ElectionBallotRefusedException {});

    Exceptions::assertNothingReported();

    // The fake does record what is reportable — the assertion above can fail.
    report(new RuntimeException('reportable'));

    Exceptions::assertReported(RuntimeException::class);
    expect(Exceptions::reported())->toHaveCount(1);
});

/**
 * Every argument of every frame of `$exception`'s trace as text: scalars and
 * arrays as they are, closures as the variables they use, any other object as
 * its class name.
 */
function electionTraceArguments(Throwable $exception): string
{
    $flatten = function (mixed $value) use (&$flatten): mixed {
        return match (true) {
            $value instanceof Closure => array_map($flatten, (new ReflectionFunction($value))->getClosureUsedVariables()),
            is_object($value) => $value::class,
            is_array($value) => array_map($flatten, $value),
            default => $value,
        };
    };

    return print_r(array_map(fn (array $frame): mixed => $flatten($frame['args'] ?? []), $exception->getTrace()), true);
}

it('shows the signed event in a stack trace only as SensitiveParameterValue', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    $event = signedElectionVote($round, $voter, [$round->candidates()->value('id') => 'for']);
    app(CloseRound::class)->handle($round);

    // CLI defaults to dropping arguments from traces; a production server may not.
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        app(CastBallot::class)->handle($round, $voter, $event);
    } catch (ElectionBallotRefusedException $refusal) {
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    expect($refusal ?? null)->toBeInstanceOf(ElectionBallotRefusedException::class);

    $frame = collect($refusal->getTrace())
        ->first(fn (array $frame): bool => ($frame['class'] ?? null) === CastBallot::class && $frame['function'] === 'handle');

    // The first two arguments are there as they are: the trace does carry arguments.
    expect($frame['args'][0])->toBeInstanceOf(ElectionRound::class)
        ->and($frame['args'][1])->toBeInstanceOf(EinundzwanzigPleb::class)
        ->and($frame['args'][2])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and(electionTraceArguments($refusal))->toContain('[ballot] => SensitiveParameterValue')
        ->not->toContain($event['sig']);
});

/*
 * Jury run 1, H1: without a random component the event id on the receipt
 * hashed back to the ballot in 0.06 s — every other field is guessable. The
 * browser adds a `nonce` tag of at least 128 bits before signing; a vote
 * without one is not taken in.
 */

it('refuses a vote signed without a random nonce tag', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    $template = VoteEvent::template($round, [], now()->getTimestamp());
    $event = EventSigning::sign(electionSecretFor($voter), $template['kind'], $template['tags'], $template['content'], $template['created_at']);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, $event)))
        ->toBe(ElectionBallotRefusal::MissingNonce)
        ->and(ElectionBallotRefusal::MissingNonce->label())->toBe('Deiner Stimme fehlt der Zufallswert, der sie unkenntlich macht. Bitte lade die Seite neu und signiere noch einmal.')
        ->and(electionVoteCount($round))->toBe(0);
});

it('refuses a nonce tag that is too short, not lowercase hex, doubled or malformed', function (Closure $nonceTags) {
    $voter = electionVoter();
    $round = openElectionRound();
    $event = signedElectionVote($round, $voter, overrides: ['tags' => [...VoteEvent::template($round, [], 0)['tags'], ...$nonceTags()]]);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, $event)))
        ->toBe(ElectionBallotRefusal::MissingNonce)
        ->and(electionVoteCount($round))->toBe(0);
})->with([
    '124 bits' => [fn () => [['nonce', str_repeat('a', 31)]]],
    'upper case' => [fn () => [['nonce', strtoupper(bin2hex(random_bytes(16)))]]],
    'not hex' => [fn () => [['nonce', str_repeat('g', 32)]]],
    'longer than 256 bits' => [fn () => [['nonce', str_repeat('a', 66)]]],
    'two nonce tags' => [fn () => [['nonce', bin2hex(random_bytes(16))], ['nonce', bin2hex(random_bytes(16))]]],
    'a third element' => [fn () => [['nonce', bin2hex(random_bytes(16)), 'extra']]],
]);

it('takes in a vote with a nonce of 128 or 256 bits and keeps the nonce in the stored event', function (int $bytes) {
    $voter = electionVoter();
    $round = openElectionRound();
    $nonce = bin2hex(random_bytes($bytes));
    $event = signedElectionVote($round, $voter, overrides: ['tags' => [...VoteEvent::template($round, [], 0)['tags'], ['nonce', $nonce]]]);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, $event)))->toBeNull()
        ->and(DB::table('election_votes')->where('event_id', $event['id'])->value('event'))->toContain('["nonce","'.$nonce.'"]');
})->with(['128 bits' => 16, '256 bits' => 32]);

it('gives the same ballot signed in the same second a different receipt each time', function () {
    $voter = electionVoter();
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 3);
    $candidate = $round->candidates()->orderBy('id')->value('id');

    $first = signedElectionVote($round, $voter, [$candidate => 'for']);
    $second = signedElectionVote($round, $voter, [$candidate => 'for'], ['created_at' => $first['created_at']]);

    expect($second['created_at'])->toBe($first['created_at'])
        ->and($second['id'])->not->toBe($first['id']);
});
