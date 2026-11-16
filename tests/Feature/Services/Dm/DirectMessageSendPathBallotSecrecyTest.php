<?php

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Jobs\Dm\SendDirectMessage;
use App\Models\DmDelivery;
use App\Models\DmDeliveryRelay;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\PlebDmRelay;
use App\Models\Profile;
use App\Services\Dm\DirectMessageDryRun;
use App\Services\Dm\DirectMessageText;
use App\Services\Dm\StartDirectMessageRun;
use App\Services\Election\OpenRound;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';
require_once __DIR__.'/../Election/helpers.php';

/*
 * DoD 464 / E20 on the whole send path: who gets a direct message, what each
 * member reads, when it is sent, and what becomes of each delivery, do not
 * depend on who voted.
 *
 * Jury gate j2f, round 2 (D2, mutant R7): DispatchBulkDirectMessages dropped
 * every member for whom `$round->voteEventIdOf($pleb)` was set, between the
 * frozen recipient list and the deliveries. The whole suite stayed green:
 * DirectMessageDryRunTest looks at the frozen list under Queue::fake(), so the
 * fan-out never ran, and the static scan did not count voteEventIdOf as a
 * vote read.
 *
 * Here nothing is faked but the relay, at the RelayTransport seam, and the
 * queue is a sync queue that also writes down the delay each job was
 * dispatched with (the plain sync connection drops it). The start the board
 * confirms and a scheduled reminder started by `dm:send-due` each run through
 * the fan-out and every SendDirectMessage. The frozen list, the skipped list,
 * and per member the delivery's status, error, attempts, relay answers,
 * whether its wrap reached the relay, the text the member's client opens from
 * that wrap, the text stored with the delivery, and the delays of the send
 * jobs queued for it must be the same before and after a member votes.
 * A status is where a skip would show: `not_sent`, `no_inbox`, `failed`, a
 * delivery left `pending`, or no delivery at all.
 *
 * Jury gate j2f, round 3 (O6, O9, O12): until 2026-11-16 the text and the
 * delay were not compared, so a vote read that changed only the wording or
 * the send time for voters passed.
 */

beforeEach(fn () => armDirectMessages());

/** Stands in for the message-specific sentence when two sendings' texts are compared. */
const DM_SEND_PATH_MESSAGE = '<message>';

/**
 * Makes the default queue a sync queue that runs every job at once, like the
 * sync connection of phpunit.xml, and records the delay each SendDirectMessage
 * was dispatched with: delivery id => seconds, one entry per dispatch. No delay
 * and a delay of 0 are the same send time and both count as 0.
 *
 * @return ArrayObject<int, list<int>>
 */
function dmRecordSendDelays(): ArrayObject
{
    $delays = new ArrayObject;

    $queue = new class($delays) extends SyncQueue
    {
        /** @param  ArrayObject<int, list<int>>  $delays */
        public function __construct(private ArrayObject $delays)
        {
            parent::__construct();
        }

        public function push($job, $data = '', $queue = null)
        {
            $this->record($job, null);

            return parent::push($job, $data, $queue);
        }

        public function later($delay, $job, $data = '', $queue = null)
        {
            $this->record($job, $delay);

            // Not parent::later(): it calls $this->push(), which would record again.
            return parent::push($job, $data, $queue);
        }

        private function record(mixed $job, mixed $delay): void
        {
            if ($job instanceof SendDirectMessage) {
                $this->delays[$job->deliveryId] = [...($this->delays[$job->deliveryId] ?? []), $delay === null ? 0 : (int) $this->secondsUntil($delay)];
            }
        }
    };

    app('queue')->extend('recording-sync', fn (): ConnectorInterface => new class($queue) implements ConnectorInterface
    {
        public function __construct(private Queue $queue) {}

        public function connect(array $config): Queue
        {
            return $this->queue;
        }
    });

    config([
        'queue.connections.recording-sync' => ['driver' => 'recording-sync'],
        'queue.default' => 'recording-sync',
    ]);

    return $delays;
}

/**
 * The text relative to the message it was rendered from: the sentence that
 * differs between two sendings by design is replaced by a marker, everything
 * else (the member's name, the greeting, the footer, every byte of
 * whitespace) stays as it is.
 */
function dmTextRelativeTo(?string $text, string $sentence): ?string
{
    return $text === null ? null : str_replace($sentence, DM_SEND_PATH_MESSAGE, $text);
}

/**
 * What a run did for each member, keyed by member id. Ids of runs and
 * deliveries and timestamps are left out, and so are the parts of a wrap and
 * its rumor that are random or time-bound: they differ between two sendings
 * by design. Texts are compared relative to their message (dmTextRelativeTo).
 *
 * @param  array<int, string>  $secrets  member id => secret key
 * @param  ArrayObject<int, list<int>>  $delays  from dmRecordSendDelays()
 * @return array{recipient_ids: list<int>, skipped_ids: list<int>|null, deliveries: array<int, array{status: string, error: string|null, attempts: int, relays: array<string, string>, wrap_published: bool, received: array<string, mixed>|null, stored_text: string|null, send_delays: list<int>}>}
 */
function dmSendOutcome(DmRun $run, FakeRelayTransport $relay, string $sentence, array $secrets, ArrayObject $delays): array
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
            ->mapWithKeys(function (DmDelivery $delivery) use ($publishedIds, $sentence, $secrets, $delays): array {
                $memberId = (int) $delivery->einundzwanzig_pleb_id;
                $received = null;

                if (is_array($delivery->wrap)) {
                    // What the member's client shows: the rumor inside the wrap,
                    // opened with the member's own key.
                    $rumor = openWrapWith($delivery->wrap, $secrets[$memberId] ?? throw new LogicException("No secret key for member {$memberId}."));
                    $received = [
                        'pubkey' => $rumor['pubkey'],
                        'kind' => $rumor['kind'],
                        'tags' => $rumor['tags'],
                        'content' => dmTextRelativeTo($rumor['content'], $sentence),
                    ];
                }

                return [$memberId => [
                    'status' => $delivery->status->value,
                    'error' => $delivery->error,
                    'attempts' => (int) $delivery->attempts,
                    'relays' => $delivery->relays
                        ->sortBy('relay_url')
                        ->mapWithKeys(fn (DmDeliveryRelay $row): array => [$row->relay_url => $row->status->value])
                        ->all(),
                    'wrap_published' => is_array($delivery->wrap) && in_array($delivery->wrap['id'], $publishedIds, true),
                    'received' => $received,
                    'stored_text' => dmTextRelativeTo($delivery->plaintext, $sentence),
                    'send_delays' => $delays[$delivery->id] ?? [],
                ]];
            })
            ->all(),
    ];
}

it('delivers to the same members the same text at the same time with the same outcome whether or not one of them already voted', function (DmAudience $audience) {
    $relay = FakeRelayTransport::bind();
    $delays = dmRecordSendDelays();
    // Entitled (active, paid), on the board, a candidate, and the one who votes.
    [$voted] = electionBoard(1);
    $silent = electionVoter(); // entitled, never votes
    $unreachable = electionVoter(); // entitled, no inbox list: `no_inbox` for a reason that is not a vote
    $round = draftElectionRound(['seats' => 1]);
    $round->candidates()->orderBy('id')->firstOrFail()->update(['einundzwanzig_pleb_id' => $voted->id]);

    // A real key, a profile name for {name} and an inbox for every member, so
    // each outcome is a decision of SendDirectMessage and not of a factory
    // pubkey that is off the curve, and each text names its own member.
    $secrets = [];
    EinundzwanzigPleb::query()->orderBy('id')->each(function (EinundzwanzigPleb $pleb) use ($unreachable, &$secrets): void {
        $secrets[$pleb->id] = electionSecretFor($pleb);
        Profile::factory()->create(['pubkey' => $pleb->pubkey, 'display_name' => "Mitglied Nummer {$pleb->id}"]);

        if (! $pleb->is($unreachable)) {
            PlebDmRelay::factory()->for($pleb, 'pleb')->create(['relays' => ["wss://inbox-{$pleb->id}.example/"]]);
        }
    });
    $voted->refresh();

    $round = app(OpenRound::class)->handle($round);
    $election = $round->election;

    $send = function (string $sentence) use ($audience, $election, $relay, $secrets, $delays): array {
        $text = "Hallo {name},\n\n{$sentence}";
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
            'started' => dmSendOutcome($started, $relay, $sentence, $secrets, $delays),
            'scheduled' => dmSendOutcome(DmRun::query()->findOrFail($schedule->fresh()->dm_run_id), $relay, $sentence, $secrets, $delays),
        ];
    };

    $before = $send('Erinnerung, bevor jemand abgestimmt hat.');
    castElectionBallot($round, $voted);
    $after = $send('Erinnerung, nachdem jemand abgestimmt hat.');

    $votersText = "Hallo Mitglied Nummer {$voted->id},\n\n".DM_SEND_PATH_MESSAGE."\n\n".app(DirectMessageText::class)->footer();
    $votersDelivery = [
        'status' => 'sent',
        'error' => null,
        'attempts' => 1,
        'relays' => ["wss://inbox-{$voted->id}.example/" => 'accepted'],
        'wrap_published' => true,
        'received' => [
            'pubkey' => (new Key)->getPublicKey(config('services.nostr_notification_key')),
            'kind' => 14,
            'tags' => [['p', $voted->pubkey]],
            'content' => $votersText,
        ],
        'stored_text' => $votersText,
        'send_delays' => [0],
    ];

    // Not a no-op: before the vote the voter is frozen, has a delivery on
    // both paths, their wrap reached their inbox relay, opens with their key
    // to a text that names them and carries this sending's sentence, and one
    // send job was queued for it, with no delay (the four audiences here are
    // not spread over time; the delay compared is the one the dispatcher hands
    // to the queue, see dmRecordSendDelays and the self-check below).
    expect(electionVoteCount($round))->toBe(1)
        ->and($before['started']['recipient_ids'])->toContain($voted->id)
        ->and($before['started']['deliveries'][$voted->id] ?? null)->toBe($votersDelivery)
        ->and($before['scheduled']['deliveries'][$voted->id] ?? null)->toBe($votersDelivery)
        ->and(array_keys($before['started']['deliveries']))->toBe($before['started']['recipient_ids'])
        ->and(array_keys($before['scheduled']['deliveries']))->toBe($before['scheduled']['recipient_ids'])
        ->and($after)->toBe($before);

    if (in_array($audience, [DmAudience::EntitledVoters, DmAudience::Members], true)) {
        $silentsText = "Hallo Mitglied Nummer {$silent->id},\n\n".DM_SEND_PATH_MESSAGE."\n\n".app(DirectMessageText::class)->footer();
        $silents = $before['started']['deliveries'][$silent->id] ?? null;
        $unreachables = $before['started']['deliveries'][$unreachable->id] ?? null;

        // Two members, two texts: {name} resolves per member, so the
        // comparison above is per member and not over one shared string.
        expect($silents)->toBeArray()
            ->and($silents['status'])->toBe('sent')
            ->and($silents['received']['content'] ?? null)->toBe($silentsText)
            ->and($silents['stored_text'])->toBe($silentsText)
            ->and($silents['send_delays'])->toBe([0])
            ->and($silentsText)->not->toBe($votersText)
            ->and($unreachables)->toBeArray()
            ->and($unreachables['status'])->toBe('no_inbox')
            ->and($unreachables['received'])->toBeNull()
            ->and($unreachables['stored_text'])->toBeNull()
            ->and($unreachables['send_delays'])->toBe([0]);
    }
})->with([
    'entitled voters' => DmAudience::EntitledVoters,
    'members' => DmAudience::Members,
    'candidates' => DmAudience::Candidates,
    'board' => DmAudience::Board,
]);

it('records the delay a send job is dispatched with, so a delay for voters cannot hide behind the sync queue', function () {
    $delays = dmRecordSendDelays();

    // Ids no delivery has: each job claims nothing and ends. The delays are
    // the shapes the dispatcher passes on: none, seconds, a point in time.
    $this->freezeTime(function () use ($delays): void {
        SendDirectMessage::dispatch(PHP_INT_MAX);
        SendDirectMessage::dispatch(PHP_INT_MAX - 1)->delay(60);
        SendDirectMessage::dispatch(PHP_INT_MAX - 1)->delay(now()->addMinutes(5));

        expect($delays->getArrayCopy())->toBe([PHP_INT_MAX => [0], PHP_INT_MAX - 1 => [60, 300]]);
    });
});
