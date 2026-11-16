<?php

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Jobs\Dm\SendDirectMessage;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionRound;
use App\Models\PlebDmRelay;
use App\Models\Profile;
use App\Services\Dm\DirectMessageDryRun;
use App\Services\Dm\DirectMessageText;
use App\Services\Dm\StartDirectMessageRun;
use App\Services\Election\OpenRound;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\EventHash;
use App\Services\Nostr\EventVerification;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use swentel\nostr\Encryption\Nip44;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';
require_once __DIR__.'/../Election/helpers.php';

/*
 * DoD 464 / E20 on the whole send path: everything anybody can observe of a
 * member's direct message is the same whether or not that member voted,
 * except what is random by design.
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
 * votes. Compared, per sending, by default everything, minus named fields:
 *
 *  - rows: every row of every table the sending inserted, updated or deleted,
 *    found by comparing the whole database before and after it. This is what
 *    the board and the inbox page read (status, `seen_at`, the time received,
 *    the stored text, relay answers, the run). Every column is compared except
 *    those named in DM_SEND_PATH_ROW_FIELDS and DM_SEND_PATH_ROW_RULES, each
 *    replaced by a check (dmRowAsSeen); a column added later is compared
 *    without anyone naming it (see the second test).
 *  - the relay operator: every publish in call order (relay URL, answer,
 *    which member's delivery it carries) with the wrap as the relay sees it;
 *  - the member's client: the wrap, the seal opened with the member's key and
 *    the rumor inside it, every field except DM_SEND_PATH_RANDOM_FIELDS;
 *  - the send time: the delay of every send job queued for the delivery.
 *
 * A status is where a skip would show: `not_sent`, `no_inbox`, `failed`, a
 * delivery left `pending`, or no delivery at all.
 *
 * Jury gate j2f, round 3 (O6, O9, O12): until 2026-11-16 the text and the
 * delay were not compared. Gate j2h, round 1 (O3, O4, O5, O5b, O8): then the
 * seal, the wrap's kind and tags, and the number of publishes were not.
 * Gate j2h, round 2 (M6b, M7b, M9c): then the delivery rows were an
 * allow-list (a `seen_at` or `created_at` set for voters passed), and seal and
 * wrap were dated against the whole sending, so "not backdated" passed.
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
        'created_at' => 'random, 1 s to two days before the rumor\'s own time (NIP-17); replaced by "created_at_in_window", counted from the rumor (dmBackdateWindow)',
        'content' => 'NIP-44 ciphertext under a random nonce; replaced by "content_length"',
        'sig' => 'a Schnorr signature over the random id; replaced by "authentic"',
    ],
    'seal' => [
        'id' => 'a hash over the random created_at and ciphertext; replaced by "authentic"',
        'created_at' => 'random, 1 s to two days before the rumor\'s own time (NIP-17); replaced by "created_at_in_window", counted from the rumor (dmBackdateWindow)',
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
 * Columns of rows the send path writes that differ between two sendings by
 * design, per table, and why. Each is replaced by a check (dmRowAsSeen).
 * Every other column of every table, including one added later, is compared
 * as it is, after the general rules in DM_SEND_PATH_ROW_RULES.
 */
const DM_SEND_PATH_ROW_FIELDS = [
    'dm_deliveries' => [
        'wrap' => 'the gift wrap, random by design (DM_SEND_PATH_RANDOM_FIELDS); replaced by "stored", and compared as the relay and the client see it in "observers"',
        'plaintext' => 'carries the sentence that differs between two sendings; replaced by the text relative to the message',
        'rumor_id' => 'a hash over the rumor\'s time and text; replaced by whether it is the id of the rumor inside the stored wrap',
        'rumor_created_at' => 'the time the rumor was built; replaced by whether it is the time of the rumor inside the stored wrap',
    ],
];

/**
 * The general rules, applied to every table after DM_SEND_PATH_ROW_FIELDS.
 */
const DM_SEND_PATH_ROW_RULES = [
    'id' => 'auto-increment, differs per sending; replaced by a label that names the row by what it is (run, member, relay)',
    'dm_run_id, dm_delivery_id, dm_message_id' => 'point at rows made per sending; replaced by the label of the row they point at',
    '*_at' => 'wall-clock time; replaced by "in window" (inside the sending), "outside window", or null. Nobody opens a message here, so `seen_at` stays null',
];

/** Foreign keys to rows made per sending, and the table each points into. */
const DM_SEND_PATH_PER_SENDING_KEYS = ['dm_run_id' => 'dm_runs', 'dm_delivery_id' => 'dm_deliveries', 'dm_message_id' => 'dm_messages'];

/**
 * Every row of every table, keyed by table and then by id (or, for a table
 * without one, by the row itself).
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function dmDatabaseState(): array
{
    $state = [];

    foreach (array_column(Schema::getTables(), 'name') as $table) {
        foreach (DB::table($table)->get() as $row) {
            $row = (array) $row;
            $state[$table][array_key_exists('id', $row) ? 'id:'.$row['id'] : 'row:'.md5(serialize($row))] = $row;
        }
    }

    return $state;
}

/**
 * Every row inserted, updated or deleted between two states, per table.
 *
 * @param  array<string, array<string, array<string, mixed>>>  $before
 * @param  array<string, array<string, array<string, mixed>>>  $after
 * @return array<string, array{inserted: list<array<string, mixed>>, updated: list<array{row: array<string, mixed>, changed: list<string>}>, deleted: list<array<string, mixed>>}>
 */
function dmDatabaseWrites(array $before, array $after): array
{
    $writes = [];

    foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $table) {
        $old = $before[$table] ?? [];
        $new = $after[$table] ?? [];
        $updated = [];

        foreach (array_intersect_key($new, $old) as $key => $row) {
            if ($row !== $old[$key]) {
                // A wall-clock column changes or not by which second the
                // write fell into; its value is compared as a window class
                // in the row itself, so it is left out of the list here.
                $changed = array_keys(array_diff_assoc(array_map('serialize', $row), array_map('serialize', $old[$key])));
                $updated[] = ['row' => $row, 'changed' => array_values(array_filter($changed, fn (string $column): bool => ! str_ends_with($column, '_at')))];
            }
        }

        $entry = ['inserted' => array_values(array_diff_key($new, $old)), 'updated' => $updated, 'deleted' => array_values(array_diff_key($old, $new))];

        if ($entry['inserted'] !== [] || $entry['updated'] !== [] || $entry['deleted'] !== []) {
            $writes[$table] = $entry;
        }
    }

    ksort($writes);

    return $writes;
}

/**
 * A wall-clock value as "in window", "outside window" or null.
 */
function dmTimeClass(mixed $value, array $window): ?string
{
    if ($value === null) {
        return null;
    }

    $seconds = is_int($value) || ctype_digit((string) $value) ? (int) $value : Carbon::parse((string) $value)->getTimestamp();

    return $seconds >= $window[0] && $seconds <= $window[1] ? 'in window' : 'outside window';
}

/**
 * One written row with every column compared as it is, except those in
 * DM_SEND_PATH_ROW_FIELDS and DM_SEND_PATH_ROW_RULES.
 *
 * @param  array<string, mixed>  $row
 * @param  array<string, array<int, string>>  $labels  table => id => label
 * @param  array<int, array<string, mixed>|null>  $rumors  delivery id => the rumor inside its stored wrap
 * @param  array{0: int, 1: int}  $window
 * @return array<string, mixed>
 */
function dmRowAsSeen(string $table, array $row, array $labels, array $rumors, array $window, string $sentence): array
{
    $seen = [];

    foreach ($row as $column => $value) {
        $seen[$column] = match (true) {
            $table === 'dm_deliveries' && $column === 'wrap' => $value === null ? null : 'stored',
            $table === 'dm_deliveries' && $column === 'plaintext' => dmTextRelativeTo($value, $sentence),
            $table === 'dm_deliveries' && $column === 'rumor_id' => $value === null ? null : $value === ($rumors[$row['id']]['id'] ?? false),
            $table === 'dm_deliveries' && $column === 'rumor_created_at' => $value === null ? null : (int) $value === ($rumors[$row['id']]['created_at'] ?? false),
            $column === 'id' => $labels[$table][$value] ?? '<a row of another sending or the fixture>',
            isset(DM_SEND_PATH_PER_SENDING_KEYS[$column]) => $value === null ? null : ($labels[DM_SEND_PATH_PER_SENDING_KEYS[$column]][$value] ?? '<a row of another sending or the fixture>'),
            str_ends_with($column, '_at') => dmTimeClass($value, $window),
            default => $value,
        };
    }

    return $seen;
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
 * The window seal and wrap must be dated in. The signer dates seal and wrap
 * 1 s to MAX_BACKDATE_SECONDS before the second it builds them (NIP-17: "up
 * to two days in the past"), and on a first build, the only kind this test
 * makes, that second is the rumor's own time. So one delivery decides it,
 * including "not backdated at all" (gate j2h r2, M9c: against the whole
 * sending that passed in 1 of 4 datasets). A re-wrap of a stored rumor is
 * dated from its own build time instead; this window does not fit it.
 * Without a rumor to count from, the whole sending stands in.
 *
 * @param  array<string, mixed>|null  $rumor
 * @param  array{0: int, 1: int}  $sendWindow
 * @return array{0: int, 1: int}
 */
function dmBackdateWindow(?array $rumor, array $sendWindow): array
{
    $builtAt = is_int($rumor['created_at'] ?? null) ? $rumor['created_at'] : null;

    return $builtAt === null
        ? [$sendWindow[0] - DirectMessageSigner::MAX_BACKDATE_SECONDS, $sendWindow[1] - 1]
        : [$builtAt - DirectMessageSigner::MAX_BACKDATE_SECONDS, $builtAt - 1];
}

/**
 * Sets up the fixture, sends once, lets one member vote, sends again, and
 * returns what every observer saw of both sendings. `$prepare` runs after
 * the fixture is in place and before the first sending.
 *
 * @return array{before: array<string, mixed>, after: array<string, mixed>, voted: EinundzwanzigPleb, silent: EinundzwanzigPleb, unreachable: EinundzwanzigPleb, voteCount: int}
 */
function dmSendPathScenario(DmAudience $audience, ?Closure $prepare = null): array
{
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

    if ($prepare !== null) {
        $prepare($round);
    }

    $send = function (string $sentence) use ($audience, $election, $relay, $secrets, $delays, $usedKeys): array {
        // The window opens before the messages and the schedule are made, so
        // their times fall inside it whichever second the clock turns in.
        $from = now()->getTimestamp();
        $text = "Hallo {name},\n\n{$sentence}";
        $startedMessage = DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => $text, 'variables' => []]);
        $scheduledMessage = DmMessage::factory()->create(['type' => DmMessageType::Individual, 'plaintext' => $text, 'variables' => []]);
        $schedule = DmSchedule::factory()->create([
            'election_event_id' => $election->id,
            'dm_message_id' => $scheduledMessage->id,
            'audience' => $audience,
            'send_at' => now()->subMinute(),
        ]);

        // From here on only the send path writes.
        $stateBefore = dmDatabaseState();
        $published = count($relay->published());

        $dryRun = app(DirectMessageDryRun::class)->handle(DmMessageType::Individual, $audience, $election, plaintext: $text);
        $started = app(StartDirectMessageRun::class)->handle($startedMessage, $audience, $election, $dryRun->recipientIds);
        $publishes = ['started' => array_slice($relay->published(), $published)];
        $published = count($relay->published());

        test()->artisan('dm:send-due')->assertSuccessful();
        $publishes['scheduled'] = array_slice($relay->published(), $published);
        $writes = dmDatabaseWrites($stateBefore, dmDatabaseState());
        $window = [$from, now()->getTimestamp()];

        $runs = ['started' => $started->id, 'scheduled' => (int) $schedule->fresh()->dm_run_id];
        $labels = [
            'dm_runs' => [$runs['started'] => 'started run', $runs['scheduled'] => 'scheduled run'],
            'dm_messages' => [$startedMessage->id => 'started message', $scheduledMessage->id => 'scheduled message'],
            'dm_schedules' => [$schedule->id => 'schedule'],
        ];
        $rumors = [];
        $observers = [];

        foreach ($runs as $path => $runId) {
            $deliveries = DmDelivery::query()->with('relays')->where('dm_run_id', $runId)->orderBy('einundzwanzig_pleb_id')->get();
            $wrapOwners = [];
            $rumorsByMember = [];

            foreach ($deliveries as $delivery) {
                $memberId = (int) $delivery->einundzwanzig_pleb_id;
                $labels['dm_deliveries'][$delivery->id] = "{$path} run: delivery to member {$memberId}";

                foreach ($delivery->relays as $row) {
                    $labels['dm_delivery_relays'][$row->id] = "{$path} run: delivery to member {$memberId}: {$row->relay_url}";
                }

                $secret = $secrets[$memberId] ?? throw new LogicException("No secret key for member {$memberId}.");
                $wrap = is_array($delivery->wrap) ? $delivery->wrap : null;
                $seal = dmOpenLayer($wrap, $secret);
                $rumor = dmOpenLayer($seal, $secret);
                $rumors[$delivery->id] = $rumor;
                $rumorsByMember[$memberId] = $rumor;

                if (is_string($wrap['id'] ?? null)) {
                    $wrapOwners[$wrap['id']] = $memberId;
                }

                // One-time means a key no other wrap of this test was signed
                // with: a key reused for voters would let a relay link them.
                $wrapKey = is_string($wrap['pubkey'] ?? null) ? $wrap['pubkey'] : null;
                $wrapKeyIsNew = $wrapKey === null ? null : ! isset($usedKeys[$wrapKey]);

                if ($wrapKey !== null) {
                    $usedKeys[$wrapKey] = true;
                }

                $observers[$path]['deliveries'][$memberId] = [
                    'wrap' => $wrap === null ? null : dmLayerAsSeen($wrap, 'wrap', dmBackdateWindow($rumor, $window), $sentence),
                    'wrap_key_is_new' => $wrapKeyIsNew,
                    'seal' => $seal === null ? null : dmLayerAsSeen($seal, 'seal', dmBackdateWindow($rumor, $window), $sentence),
                    'rumor' => $rumor === null ? null : dmLayerAsSeen($rumor, 'rumor', $window, $sentence),
                    'send_delays' => $delays[$delivery->id] ?? [],
                ];
            }

            $observers[$path]['publishes'] = array_map(function (array $publish) use ($wrapOwners, $rumorsByMember, $window, $sentence): array {
                $owner = $wrapOwners[$publish['event']['id'] ?? ''] ?? null;

                return [
                    'relay' => $publish['relay'],
                    'accepted' => $publish['accepted'],
                    'delivery_of' => $owner,
                    'wrap' => dmLayerAsSeen($publish['event'], 'wrap', dmBackdateWindow($owner === null ? null : $rumorsByMember[$owner], $window), $sentence),
                ];
            }, $publishes[$path]);
        }

        $rows = [];

        foreach ($writes as $table => $entry) {
            $asSeen = fn (array $row): array => dmRowAsSeen($table, $row, $labels, $rumors, $window, $sentence);
            $sorted = function (array $rows): array {
                usort($rows, fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));

                return $rows;
            };
            $rows[$table] = [
                'inserted' => $sorted(array_map($asSeen, $entry['inserted'])),
                'updated' => $sorted(array_map(fn (array $update): array => ['row' => $asSeen($update['row']), 'changed' => $update['changed']], $entry['updated'])),
                'deleted' => $sorted(array_map($asSeen, $entry['deleted'])),
            ];
        }

        return ['rows' => $rows, 'observers' => $observers];
    };

    // Of equal length, so the ciphertext lengths are comparable byte for byte.
    $sentenceBefore = 'Erinnerung, bevor jemand abgestimmt hat.';
    $sentenceAfter = 'Erinnerung, nachdem eine abgestimmt hat.';
    expect(strlen($sentenceAfter))->toBe(strlen($sentenceBefore));

    $before = $send($sentenceBefore);
    castElectionBallot($round, $voted);
    $after = $send($sentenceAfter);

    return ['before' => $before, 'after' => $after, 'voted' => $voted, 'silent' => $silent, 'unreachable' => $unreachable, 'voteCount' => electionVoteCount($round)];
}

/**
 * The one written row of `$table` whose label is `$label`, or null.
 *
 * @param  array<string, mixed>  $sending
 * @return array<string, mixed>|null
 */
function dmWrittenRow(array $sending, string $table, string $label): ?array
{
    foreach ([...$sending['rows'][$table]['inserted'] ?? [], ...array_column($sending['rows'][$table]['updated'] ?? [], 'row')] as $row) {
        if (($row['id'] ?? null) === $label) {
            return $row;
        }
    }

    return null;
}

it('shows every observer the same delivery of the same text at the same time whether or not a member already voted', function (DmAudience $audience) {
    ['before' => $before, 'after' => $after, 'voted' => $voted, 'silent' => $silent, 'unreachable' => $unreachable, 'voteCount' => $voteCount] = dmSendPathScenario($audience);

    $notificationPubkey = (new Key)->getPublicKey(config('services.nostr_notification_key'));
    $votersText = "Hallo Mitglied Nummer {$voted->id},\n\n".DM_SEND_PATH_MESSAGE."\n\n".app(DirectMessageText::class)->footer();
    $votersInbox = "wss://inbox-{$voted->id}.example/";

    // The send path writes runs, deliveries, relay answers and the schedule,
    // and every one of those rows is in the comparison.
    expect(array_keys($before['rows']))->toContain('dm_runs', 'dm_deliveries', 'dm_delivery_relays', 'dm_schedules');

    // Not a no-op, on both paths. Before the vote the voter has a run row
    // that names them, a delivery row and a relay row, every column of which
    // is there, ids replaced by labels and times by windows; `seen_at` is
    // null. Exactly one publish goes to their inbox, of their own stored
    // wrap; wrap and seal are authentic, dated inside the window counted from
    // the rumor, and carry ciphertext; the seal is signed by the notification
    // key; the rumor opens with the voter's key to a text that names them.
    // One send job, no delay (these four audiences are not spread over time;
    // see dmRecordSendDelays and the self-check below).
    foreach (['started', 'scheduled'] as $path) {
        $run = dmWrittenRow($before, 'dm_runs', "{$path} run");
        $delivery = dmWrittenRow($before, 'dm_deliveries', "{$path} run: delivery to member {$voted->id}");
        $relayRow = dmWrittenRow($before, 'dm_delivery_relays', "{$path} run: delivery to member {$voted->id}: {$votersInbox}");
        $observed = $before['observers'][$path]['deliveries'][$voted->id] ?? null;
        $votersPublishes = array_values(array_filter($before['observers'][$path]['publishes'], fn (array $publish): bool => $publish['relay'] === $votersInbox));

        expect($run)->toBeArray()
            ->and($run['dm_message_id'])->toBe("{$path} message")
            ->and(json_decode((string) $run['recipient_ids'], true))->toContain($voted->id)
            ->and($run['fanned_out_at'])->toBe('in window')
            ->and($delivery)->toBe([
                'id' => "{$path} run: delivery to member {$voted->id}",
                'dm_run_id' => "{$path} run",
                'einundzwanzig_pleb_id' => $voted->id,
                'status' => 'sent',
                'plaintext' => $votersText,
                'rumor_id' => true,
                'rumor_created_at' => true,
                'wrap' => 'stored',
                'attempts' => 1,
                'claimed_at' => null,
                'sent_at' => 'in window',
                'error' => null,
                'created_at' => 'in window',
                'updated_at' => 'in window',
                'seen_at' => null,
            ])
            ->and($relayRow)->toMatchArray([
                'dm_delivery_id' => "{$path} run: delivery to member {$voted->id}",
                'relay_url' => $votersInbox,
                'status' => 'accepted',
                'attempts' => 1,
                'created_at' => 'in window',
            ])
            ->and($observed)->toBeArray()
            ->and($observed['wrap'])->toMatchArray([
                'kind' => DirectMessageSigner::KIND_GIFT_WRAP,
                'tags' => [['p', $voted->pubkey]],
                'random_fields' => ['id', 'pubkey', 'created_at', 'content', 'sig'],
                'created_at_in_window' => true,
                'authentic' => true,
            ])
            ->and($observed['wrap']['content_length'])->toBeGreaterThan(0)
            ->and($observed['wrap_key_is_new'])->toBeTrue()
            ->and($observed['seal'])->toMatchArray([
                'pubkey' => $notificationPubkey,
                'kind' => DirectMessageSigner::KIND_SEAL,
                'tags' => [],
                'random_fields' => ['id', 'created_at', 'content', 'sig'],
                'created_at_in_window' => true,
                'authentic' => true,
            ])
            ->and($observed['seal']['content_length'])->toBeGreaterThan(0)
            ->and($observed['rumor'])->toBe([
                'pubkey' => $notificationPubkey,
                'kind' => DirectMessageSigner::KIND_CHAT_MESSAGE,
                'tags' => [['p', $voted->pubkey]],
                'content' => $votersText,
                'random_fields' => ['id', 'created_at'],
                'created_at_in_window' => true,
                'id_matches' => true,
            ])
            ->and($observed['send_delays'])->toBe([0])
            ->and($votersPublishes)->toHaveCount(1)
            ->and($votersPublishes[0])->toMatchArray(['accepted' => true, 'delivery_of' => $voted->id])
            ->and($votersPublishes[0]['wrap'])->toBe($observed['wrap']);
    }

    expect($voteCount)->toBe(1)
        ->and($after)->toBe($before);

    if (in_array($audience, [DmAudience::EntitledVoters, DmAudience::Members], true)) {
        $silentsText = "Hallo Mitglied Nummer {$silent->id},\n\n".DM_SEND_PATH_MESSAGE."\n\n".app(DirectMessageText::class)->footer();
        $silents = dmWrittenRow($before, 'dm_deliveries', "started run: delivery to member {$silent->id}");
        $unreachables = dmWrittenRow($before, 'dm_deliveries', "started run: delivery to member {$unreachable->id}");

        // Two members, two texts: {name} resolves per member, so the
        // comparison above is per member and not over one shared string.
        expect($silents)->toBeArray()
            ->and($silents['status'])->toBe('sent')
            ->and($silents['plaintext'])->toBe($silentsText)
            ->and($before['observers']['started']['deliveries'][$silent->id]['rumor']['content'] ?? null)->toBe($silentsText)
            ->and($silentsText)->not->toBe($votersText)
            ->and($unreachables)->toBeArray()
            ->and($unreachables['status'])->toBe('no_inbox')
            ->and($unreachables['wrap'])->toBeNull()
            ->and($unreachables['plaintext'])->toBeNull()
            ->and($before['observers']['started']['deliveries'][$unreachable->id] ?? null)->toHaveKey('rumor')
            ->and($before['observers']['started']['deliveries'][$unreachable->id]['rumor'])->toBeNull()
            ->and($before['observers']['started']['deliveries'][$unreachable->id]['send_delays'] ?? null)->toBe([0]);
    }
})->with([
    'entitled voters' => DmAudience::EntitledVoters,
    'members' => DmAudience::Members,
    'candidates' => DmAudience::Candidates,
    'board' => DmAudience::Board,
]);

it('compares a column nobody named: one added later and written only for voters makes the sendings differ', function () {
    // The column does not exist in the schema; the test adds it to its own
    // database (rolled back with the test) and writes it, for voters only, at
    // the place the send path saves a delivery. No list in this file names
    // it, so it can only be seen because every column is compared.
    $outcome = dmSendPathScenario(DmAudience::EntitledVoters, function (ElectionRound $round): void {
        Schema::table('dm_deliveries', fn (Blueprint $table) => $table->string('added_later')->nullable());
        DmDelivery::saving(function (DmDelivery $delivery) use ($round): void {
            $delivery->forceFill(['added_later' => DB::table('election_votes')
                ->where('election_round_id', $round->getKey())
                ->where('einundzwanzig_pleb_id', $delivery->einundzwanzig_pleb_id)
                ->exists() ? 'voted' : null]);
        });
    });

    $votersDelivery = "started run: delivery to member {$outcome['voted']->id}";

    expect(dmWrittenRow($outcome['before'], 'dm_deliveries', $votersDelivery))->toHaveKey('added_later')
        ->and(dmWrittenRow($outcome['before'], 'dm_deliveries', $votersDelivery)['added_later'])->toBeNull()
        ->and(dmWrittenRow($outcome['after'], 'dm_deliveries', $votersDelivery)['added_later'] ?? null)->toBe('voted')
        ->and($outcome['after'])->not->toBe($outcome['before']);

    // And nothing else differs: with the column dropped from both, they match.
    $withoutColumn = function (array $sending): array {
        foreach ($sending['rows']['dm_deliveries'] as $kind => $rows) {
            foreach ($rows as $index => $row) {
                if ($kind === 'updated') {
                    unset($sending['rows']['dm_deliveries'][$kind][$index]['row']['added_later']);
                } else {
                    unset($sending['rows']['dm_deliveries'][$kind][$index]['added_later']);
                }
            }
        }

        return $sending;
    };

    expect($withoutColumn($outcome['after']))->toBe($withoutColumn($outcome['before']));
});

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
