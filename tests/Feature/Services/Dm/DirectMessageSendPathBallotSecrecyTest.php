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
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\EventHash;
use App\Services\Nostr\EventVerification;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use swentel\nostr\Encryption\Nip44;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';
require_once __DIR__.'/../Election/helpers.php';

/*
 * DoD 464 / E20 on the whole send path: everything anybody can observe of a
 * member's direct message is the same whether or not that member voted,
 * except the fields that are random by design.
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
 * the fan-out and every SendDirectMessage, once before and once after a member
 * votes. Compared, per run:
 *
 *  - the board: the frozen and the skipped list, and per member the
 *    delivery's status, error, attempts and relay answers;
 *  - the relay operator: every publish in call order (relay URL, answer,
 *    which member's delivery it carries) with the wrap as the relay sees it;
 *  - the member's client: the wrap, the seal opened with the member's key and
 *    the rumor inside it;
 *  - the inbox page: the stored text, and whether the stored rumor id and
 *    time are those of the rumor sent;
 *  - the send time: the delay of every send job queued for the delivery.
 *
 * Each layer of the message is compared field by field, except the fields in
 * DM_SEND_PATH_RANDOM_FIELDS, which are replaced by what can be compared about
 * them (dmLayerAsSeen). A field nobody named there is compared as it is.
 *
 * A status is where a skip would show: `not_sent`, `no_inbox`, `failed`, a
 * delivery left `pending`, or no delivery at all.
 *
 * Jury gate j2f, round 3 (O6, O9, O12): until 2026-11-16 the text and the
 * delay were not compared. Gate j2h, round 1 (O3, O4, O5, O5b, O8): then the
 * seal, the wrap's kind and tags, and the number of publishes were not.
 */

beforeEach(fn () => armDirectMessages());

/** Stands in for the message-specific sentence when two sendings' texts are compared. */
const DM_SEND_PATH_MESSAGE = '<message>';

/**
 * The fields of each layer that differ between two sendings by design, and
 * why. Each is left out of the comparison and replaced by a check that does
 * not differ (dmLayerAsSeen). Every other field of a layer, including one
 * that is not there today, is compared as it is, in its order.
 */
const DM_SEND_PATH_RANDOM_FIELDS = [
    'wrap' => [
        'id' => 'a hash over the random pubkey, created_at and ciphertext; replaced by "authentic"',
        'pubkey' => 'a one-time key per wrap (NIP-59); replaced by "authentic" and, per delivery, "wrap_key_is_new"',
        'created_at' => 'random, up to two days in the past (NIP-17); replaced by "created_at_in_window"',
        'content' => 'NIP-44 ciphertext under a random nonce; replaced by "content_length"',
        'sig' => 'a Schnorr signature over the random id; replaced by "authentic"',
    ],
    'seal' => [
        'id' => 'a hash over the random created_at and ciphertext; replaced by "authentic"',
        'created_at' => 'random, up to two days in the past (NIP-17); replaced by "created_at_in_window"',
        'content' => 'NIP-44 ciphertext under a random nonce; replaced by "content_length"',
        'sig' => 'a Schnorr signature over the random id; replaced by "authentic"',
    ],
    'rumor' => [
        'id' => 'a hash over created_at and content; replaced by "id_matches"',
        'created_at' => 'the time the message was built; replaced by "created_at_in_window"',
    ],
];

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
 * The event inside `$outer`, opened the way the member's client opens it, or
 * null when it does not open: a client shows nothing then, which is itself
 * what it observes.
 *
 * @param  array<string, mixed>|null  $outer
 * @return array<string, mixed>|null
 */
function dmOpenLayer(?array $outer, string $secret): ?array
{
    if (! is_string($outer['content'] ?? null) || ! is_string($outer['pubkey'] ?? null)) {
        return null;
    }

    try {
        $inner = json_decode(Nip44::decrypt($outer['content'], Nip44::getConversationKey($secret, $outer['pubkey'])), true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($inner) ? $inner : null;
}

/**
 * One layer (wrap, seal or rumor) as an observer sees it, minus its random
 * fields (DM_SEND_PATH_RANDOM_FIELDS), which are replaced by checks that hold
 * for every sending:
 *
 *  - wrap, seal: `authentic` (id and signature verify, as a relay and a
 *    client check them), `content_length` (the ciphertext's length is fixed
 *    by the length of what it carries: NIP-44 pads deterministically and
 *    every other field inside has a fixed width), `created_at_in_window`;
 *  - rumor: `id_matches` (the id a client deduplicates on is the hash of the
 *    rumor), `created_at_in_window`, and the content relative to the message.
 *
 * @param  array<string, mixed>  $event
 * @param  array{0: int, 1: int}  $window  inclusive bounds for created_at
 * @return array<string, mixed>
 */
function dmLayerAsSeen(array $event, string $layer, array $window, string $sentence): array
{
    $seen = array_diff_key($event, DM_SEND_PATH_RANDOM_FIELDS[$layer]);
    $seen['random_fields'] = array_keys(array_intersect_key($event, DM_SEND_PATH_RANDOM_FIELDS[$layer]));
    $createdAt = $event['created_at'] ?? null;
    $seen['created_at_in_window'] = is_int($createdAt) && $createdAt >= $window[0] && $createdAt <= $window[1];

    if ($layer === 'rumor') {
        $seen['content'] = dmTextRelativeTo(is_string($event['content'] ?? null) ? $event['content'] : null, $sentence);
        $seen['id_matches'] = is_string($event['pubkey'] ?? null) && is_int($createdAt) && is_int($event['kind'] ?? null)
            && is_array($event['tags'] ?? null) && is_string($event['content'] ?? null) && is_string($event['id'] ?? null)
            && hash_equals(EventHash::of($event['pubkey'], $createdAt, $event['kind'], $event['tags'], $event['content']), $event['id']);

        return $seen;
    }

    $seen['content_length'] = is_string($event['content'] ?? null) ? strlen($event['content']) : null;
    // A published wrap is the stored one; verify each event once per process.
    static $authentic = [];
    $seen['authentic'] = $authentic[hash('sha256', serialize($event))] ??= EventVerification::isAuthentic($event);

    return $seen;
}

/**
 * What a run showed every observer, keyed by member id where it concerns one
 * member. Ids of runs and deliveries are left out (they differ by design), and
 * so are the random fields of each layer (dmLayerAsSeen).
 *
 * @param  list<array{relay: string, event: array<string, mixed>, accepted: bool}>  $publishes  this run's publishes, in call order
 * @param  array{0: int, 1: int}  $sendWindow  first and last second of the sending
 * @param  array<int, string>  $secrets  member id => secret key
 * @param  ArrayObject<int, list<int>>  $delays  from dmRecordSendDelays()
 * @param  ArrayObject<string, true>  $usedKeys  every key a wrap must not be signed with: the notification key, the members' keys, and each wrap key seen so far (added here)
 * @return array<string, mixed>
 */
function dmSendOutcome(DmRun $run, array $publishes, array $sendWindow, string $sentence, array $secrets, ArrayObject $delays, ArrayObject $usedKeys): array
{
    $run->refresh();
    // NIP-17: seal and wrap are dated "up to two days in the past"; the signer
    // draws 1 s to MAX_BACKDATE_SECONDS before the moment it builds them.
    $backdated = [$sendWindow[0] - DirectMessageSigner::MAX_BACKDATE_SECONDS, $sendWindow[1] - 1];

    $deliveries = DmDelivery::query()
        ->with('relays')
        ->where('dm_run_id', $run->id)
        ->orderBy('einundzwanzig_pleb_id')
        ->get();

    $wrapOwners = $deliveries
        ->filter(fn (DmDelivery $delivery): bool => is_string($delivery->wrap['id'] ?? null))
        ->mapWithKeys(fn (DmDelivery $delivery): array => [$delivery->wrap['id'] => (int) $delivery->einundzwanzig_pleb_id])
        ->all();

    return [
        'recipient_ids' => $run->recipient_ids,
        'skipped_ids' => $run->skipped_ids,
        'publishes' => array_map(fn (array $publish): array => [
            'relay' => $publish['relay'],
            'accepted' => $publish['accepted'],
            'delivery_of' => $wrapOwners[$publish['event']['id'] ?? ''] ?? null,
            'wrap' => dmLayerAsSeen($publish['event'], 'wrap', $backdated, $sentence),
        ], $publishes),
        'deliveries' => $deliveries
            ->mapWithKeys(function (DmDelivery $delivery) use ($backdated, $sendWindow, $sentence, $secrets, $delays, $usedKeys): array {
                $memberId = (int) $delivery->einundzwanzig_pleb_id;
                $secret = $secrets[$memberId] ?? throw new LogicException("No secret key for member {$memberId}.");
                $wrap = is_array($delivery->wrap) ? $delivery->wrap : null;
                $seal = dmOpenLayer($wrap, $secret);
                $rumor = dmOpenLayer($seal, $secret);
                // One-time means a key no other wrap of this test was signed
                // with: a key reused for voters would let a relay link them.
                $wrapKey = is_string($wrap['pubkey'] ?? null) ? $wrap['pubkey'] : null;
                $wrapKeyIsNew = $wrapKey === null ? null : ! isset($usedKeys[$wrapKey]);

                if ($wrapKey !== null) {
                    $usedKeys[$wrapKey] = true;
                }

                return [$memberId => [
                    'status' => $delivery->status->value,
                    'error' => $delivery->error,
                    'attempts' => (int) $delivery->attempts,
                    'relays' => $delivery->relays
                        ->sortBy('relay_url')
                        ->mapWithKeys(fn (DmDeliveryRelay $row): array => [$row->relay_url => $row->status->value])
                        ->all(),
                    'wrap' => $wrap === null ? null : dmLayerAsSeen($wrap, 'wrap', $backdated, $sentence),
                    'wrap_key_is_new' => $wrapKeyIsNew,
                    'seal' => $seal === null ? null : dmLayerAsSeen($seal, 'seal', $backdated, $sentence),
                    'rumor' => $rumor === null ? null : dmLayerAsSeen($rumor, 'rumor', $sendWindow, $sentence),
                    'stored_text' => dmTextRelativeTo($delivery->plaintext, $sentence),
                    'stored_rumor_is_sent_rumor' => $rumor === null
                        ? null
                        : $delivery->rumor_id === ($rumor['id'] ?? null) && (int) $delivery->rumor_created_at === ($rumor['created_at'] ?? null),
                    'send_delays' => $delays[$delivery->id] ?? [],
                ]];
            })
            ->all(),
    ];
}

it('shows every observer the same delivery of the same text at the same time whether or not a member already voted', function (DmAudience $audience) {
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

    $usedKeys = new ArrayObject(array_fill_keys([
        (new Key)->getPublicKey(config('services.nostr_notification_key')),
        ...EinundzwanzigPleb::query()->pluck('pubkey')->all(),
    ], true));

    $send = function (string $sentence) use ($audience, $election, $relay, $secrets, $delays, $usedKeys): array {
        $text = "Hallo {name},\n\n{$sentence}";
        $message = fn (): DmMessage => DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => $text, 'variables' => []]);
        $from = now()->getTimestamp();
        $published = count($relay->published());

        $dryRun = app(DirectMessageDryRun::class)->handle(DmMessageType::Individual, $audience, $election, plaintext: $text);
        $started = app(StartDirectMessageRun::class)->handle($message(), $audience, $election, $dryRun->recipientIds);
        $startedPublishes = array_slice($relay->published(), $published);
        $published = count($relay->published());

        $schedule = DmSchedule::factory()->create([
            'election_event_id' => $election->id,
            'dm_message_id' => $message()->id,
            'audience' => $audience,
            'send_at' => now()->subMinute(),
        ]);
        test()->artisan('dm:send-due')->assertSuccessful();
        $scheduledPublishes = array_slice($relay->published(), $published);
        $window = [$from, now()->getTimestamp()];

        return [
            'started' => dmSendOutcome($started, $startedPublishes, $window, $sentence, $secrets, $delays, $usedKeys),
            'scheduled' => dmSendOutcome(DmRun::query()->findOrFail($schedule->fresh()->dm_run_id), $scheduledPublishes, $window, $sentence, $secrets, $delays, $usedKeys),
        ];
    };

    // Of equal length, so the ciphertext lengths are comparable byte for byte.
    $sentenceBefore = 'Erinnerung, bevor jemand abgestimmt hat.';
    $sentenceAfter = 'Erinnerung, nachdem eine abgestimmt hat.';
    expect(strlen($sentenceAfter))->toBe(strlen($sentenceBefore));

    $before = $send($sentenceBefore);
    castElectionBallot($round, $voted);
    $after = $send($sentenceAfter);

    $notificationPubkey = (new Key)->getPublicKey(config('services.nostr_notification_key'));
    $votersText = "Hallo Mitglied Nummer {$voted->id},\n\n".DM_SEND_PATH_MESSAGE."\n\n".app(DirectMessageText::class)->footer();
    $votersInbox = "wss://inbox-{$voted->id}.example/";
    $wrapAsSeen = [
        'kind' => DirectMessageSigner::KIND_GIFT_WRAP,
        'tags' => [['p', $voted->pubkey]],
        'random_fields' => ['id', 'pubkey', 'created_at', 'content', 'sig'],
        'created_at_in_window' => true,
        'authentic' => true,
    ];

    // Not a no-op, on both paths: before the vote the voter is frozen, gets
    // exactly one publish, to their inbox, of their own stored wrap; wrap and
    // seal are authentic, dated inside their window and carry ciphertext; the
    // seal is signed by the notification key; the rumor opens with the
    // voter's key to a text that names them; the inbox stores that text and
    // that rumor; one send job, no delay (these four audiences are not spread
    // over time; see dmRecordSendDelays and the self-check below).
    foreach (['started', 'scheduled'] as $path) {
        $votersDelivery = $before[$path]['deliveries'][$voted->id] ?? null;
        $votersPublishes = array_values(array_filter($before[$path]['publishes'], fn (array $publish): bool => $publish['relay'] === $votersInbox));

        expect($before[$path]['recipient_ids'])->toContain($voted->id)
            ->and(array_keys($before[$path]['deliveries']))->toBe($before[$path]['recipient_ids'])
            ->and($votersDelivery)->toBeArray()
            ->and($votersDelivery)->toMatchArray([
                'status' => 'sent',
                'error' => null,
                'attempts' => 1,
                'relays' => [$votersInbox => 'accepted'],
                'stored_text' => $votersText,
                'wrap_key_is_new' => true,
                'stored_rumor_is_sent_rumor' => true,
                'send_delays' => [0],
            ])
            ->and($votersDelivery['wrap'])->toMatchArray($wrapAsSeen)
            ->and($votersDelivery['wrap']['content_length'])->toBeGreaterThan(0)
            ->and($votersDelivery['seal'])->toMatchArray([
                'pubkey' => $notificationPubkey,
                'kind' => DirectMessageSigner::KIND_SEAL,
                'tags' => [],
                'random_fields' => ['id', 'created_at', 'content', 'sig'],
                'created_at_in_window' => true,
                'authentic' => true,
            ])
            ->and($votersDelivery['seal']['content_length'])->toBeGreaterThan(0)
            ->and($votersDelivery['rumor'])->toBe([
                'pubkey' => $notificationPubkey,
                'kind' => DirectMessageSigner::KIND_CHAT_MESSAGE,
                'tags' => [['p', $voted->pubkey]],
                'content' => $votersText,
                'random_fields' => ['id', 'created_at'],
                'created_at_in_window' => true,
                'id_matches' => true,
            ])
            ->and($votersPublishes)->toHaveCount(1)
            ->and($votersPublishes[0])->toMatchArray(['accepted' => true, 'delivery_of' => $voted->id])
            ->and($votersPublishes[0]['wrap'])->toBe($votersDelivery['wrap']);
    }

    expect(electionVoteCount($round))->toBe(1)
        ->and($after)->toBe($before);

    if (in_array($audience, [DmAudience::EntitledVoters, DmAudience::Members], true)) {
        $silentsText = "Hallo Mitglied Nummer {$silent->id},\n\n".DM_SEND_PATH_MESSAGE."\n\n".app(DirectMessageText::class)->footer();
        $silents = $before['started']['deliveries'][$silent->id] ?? null;
        $unreachables = $before['started']['deliveries'][$unreachable->id] ?? null;

        // Two members, two texts: {name} resolves per member, so the
        // comparison above is per member and not over one shared string.
        expect($silents)->toBeArray()
            ->and($silents['status'])->toBe('sent')
            ->and($silents['rumor']['content'] ?? null)->toBe($silentsText)
            ->and($silents['stored_text'])->toBe($silentsText)
            ->and($silents['send_delays'])->toBe([0])
            ->and($silentsText)->not->toBe($votersText)
            ->and($unreachables)->toBeArray()
            ->and($unreachables['status'])->toBe('no_inbox')
            ->and($unreachables['wrap'])->toBeNull()
            ->and($unreachables['seal'])->toBeNull()
            ->and($unreachables['rumor'])->toBeNull()
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
