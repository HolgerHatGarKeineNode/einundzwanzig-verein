<?php

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Models\DmDelivery;
use App\Models\DmDeliveryRelay;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\PlebDmRelay;
use App\Services\Dm\DirectMessageDryRun;
use App\Services\Dm\StartDirectMessageRun;
use App\Services\Election\OpenRound;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';
require_once __DIR__.'/../Election/helpers.php';

/*
 * DoD 464 / E20 on the whole send path: who gets a direct message, and what
 * becomes of each delivery, does not depend on who voted.
 *
 * Jury gate j2f, round 2 (D2, mutant R7): DispatchBulkDirectMessages dropped
 * every member for whom `$round->voteEventIdOf($pleb)` was set, between the
 * frozen recipient list and the deliveries. The whole suite stayed green:
 * DirectMessageDryRunTest looks at the frozen list under Queue::fake(), so the
 * fan-out never ran, and the static scan did not count voteEventIdOf as a
 * vote read.
 *
 * Here nothing is faked but the relay, at the RelayTransport seam (the queue
 * is the sync connection of phpunit.xml): the start the board confirms and a
 * scheduled reminder started by `dm:send-due` each run through the fan-out
 * and every SendDirectMessage. The frozen list, the skipped list, and per
 * member the delivery's status, error, attempts, relay answers and whether its
 * wrap reached the relay must be the same before and after a member votes.
 * A status is where a skip would show: `not_sent`, `no_inbox`, `failed`, a
 * delivery left `pending`, or no delivery at all.
 */

beforeEach(fn () => armDirectMessages());

/**
 * What a run did for each member, keyed by member id. Text, ids of runs and
 * deliveries and timestamps are left out: they differ between two sendings
 * by design.
 *
 * @return array{recipient_ids: list<int>, skipped_ids: list<int>|null, deliveries: array<int, array{status: string, error: string|null, attempts: int, relays: array<string, string>, wrap_published: bool}>}
 */
function dmSendOutcome(DmRun $run, FakeRelayTransport $relay): array
{
    $run->refresh();
    $publishedIds = array_map(fn (array $publish): string => (string) $publish['event']['id'], $relay->published());

    return [
        'recipient_ids' => $run->recipient_ids,
        'skipped_ids' => $run->skipped_ids,
        'deliveries' => DmDelivery::query()
            ->with('relays')
            ->where('dm_run_id', $run->id)
            ->orderBy('einundzwanzig_pleb_id')
            ->get()
            ->mapWithKeys(fn (DmDelivery $delivery): array => [(int) $delivery->einundzwanzig_pleb_id => [
                'status' => $delivery->status->value,
                'error' => $delivery->error,
                'attempts' => (int) $delivery->attempts,
                'relays' => $delivery->relays
                    ->sortBy('relay_url')
                    ->mapWithKeys(fn (DmDeliveryRelay $row): array => [$row->relay_url => $row->status->value])
                    ->all(),
                'wrap_published' => is_array($delivery->wrap) && in_array($delivery->wrap['id'], $publishedIds, true),
            ]])
            ->all(),
    ];
}

it('delivers to the same members with the same outcome whether or not one of them already voted', function (DmAudience $audience) {
    $relay = FakeRelayTransport::bind();
    // Entitled (active, paid), on the board, a candidate, and the one who votes.
    [$voted] = electionBoard(1);
    $silent = electionVoter(); // entitled, never votes
    $unreachable = electionVoter(); // entitled, no inbox list: `no_inbox` for a reason that is not a vote
    $round = draftElectionRound(['seats' => 1]);
    $round->candidates()->orderBy('id')->firstOrFail()->update(['einundzwanzig_pleb_id' => $voted->id]);

    // A real key and an inbox for every member, so each outcome is a decision
    // of SendDirectMessage and not of a factory pubkey that is off the curve.
    EinundzwanzigPleb::query()->orderBy('id')->each(function (EinundzwanzigPleb $pleb) use ($unreachable): void {
        electionSecretFor($pleb);

        if (! $pleb->is($unreachable)) {
            PlebDmRelay::factory()->for($pleb, 'pleb')->create(['relays' => ["wss://inbox-{$pleb->id}.example/"]]);
        }
    });
    $voted->refresh();

    $round = app(OpenRound::class)->handle($round);
    $election = $round->election;

    $send = function (string $text) use ($audience, $election, $relay): array {
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
            'started' => dmSendOutcome($started, $relay),
            'scheduled' => dmSendOutcome(DmRun::query()->findOrFail($schedule->fresh()->dm_run_id), $relay),
        ];
    };

    $before = $send('Erinnerung, bevor jemand abgestimmt hat.');
    castElectionBallot($round, $voted);
    $after = $send('Erinnerung, nachdem jemand abgestimmt hat.');

    $votersDelivery = [
        'status' => 'sent',
        'error' => null,
        'attempts' => 1,
        'relays' => ["wss://inbox-{$voted->id}.example/" => 'accepted'],
        'wrap_published' => true,
    ];

    // Not a no-op: before the vote the voter is frozen, has a delivery on
    // both paths, and their wrap reached their inbox relay.
    expect(electionVoteCount($round))->toBe(1)
        ->and($before['started']['recipient_ids'])->toContain($voted->id)
        ->and($before['started']['deliveries'][$voted->id] ?? null)->toBe($votersDelivery)
        ->and($before['scheduled']['deliveries'][$voted->id] ?? null)->toBe($votersDelivery)
        ->and(array_keys($before['started']['deliveries']))->toBe($before['started']['recipient_ids'])
        ->and(array_keys($before['scheduled']['deliveries']))->toBe($before['scheduled']['recipient_ids'])
        ->and($after)->toBe($before);

    if (in_array($audience, [DmAudience::EntitledVoters, DmAudience::Members], true)) {
        expect($before['started']['deliveries'][$silent->id]['status'] ?? null)->toBe('sent')
            ->and($before['started']['deliveries'][$unreachable->id]['status'] ?? null)->toBe('no_inbox');
    }
})->with([
    'entitled voters' => DmAudience::EntitledVoters,
    'members' => DmAudience::Members,
    'candidates' => DmAudience::Candidates,
    'board' => DmAudience::Board,
]);
