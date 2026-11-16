<?php

declare(strict_types=1);

/**
 * Data for the election/DM e2e specs. Called by tests/e2e/support/election.js,
 * once per test, against the throwaway SQLite file of scripts/e2e-election.sh:
 *
 *   php tests/e2e/support/election-fixture.php <scenario>
 *       copies the migrated base file over the live one, builds the scenario
 *       and prints it as JSON (ids, names and the throwaway keys)
 *   php tests/e2e/support/election-fixture.php cast <roundId> <memberId> <count>
 *       casts <count> votes for the candidate with member id <memberId> through CastBallot, signed by
 *       voters of the scenario who have not voted in that round yet
 *   php tests/e2e/support/election-fixture.php close <roundId>
 *       closes the round early as the board, through ElectionStewardship
 *
 * Every key here is made up on the spot and dies with the run. The board the
 * server believes in is the one written to $E2E_STATE_DIR/board.json, read by
 * tests/e2e/support/e2e-server.php.
 *
 * Rounds are opened through ElectionStewardship and votes cast through
 * CastBallot: no row is written that the application could not have written.
 *
 * Refuses to run without E2E_STATE_DIR and a DB_DATABASE inside it, so it can
 * never wipe the developer's database.
 */

use App\Enums\DmDeliveryStatus;
use App\Enums\DmMessageType;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmDelivery;
use App\Models\DmInboxMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Models\PlebDmRelay;
use App\Models\Profile;
use App\Services\Dm\ComposeDirectMessage;
use App\Services\Election\Candidacies;
use App\Services\Election\CastBallot;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\RecordCastingVote;
use App\Services\Election\VoteEvent;
use App\Services\Nostr\EventSigning;
use App\Support\Board;
use Illuminate\Contracts\Console\Kernel;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

$state = getenv('E2E_STATE_DIR');
$database = getenv('DB_DATABASE');

if (! is_string($state) || $state === '' || ! is_dir($state) || ! is_string($database) || dirname($database) !== rtrim($state, '/')) {
    fwrite(STDERR, "election-fixture.php: E2E_STATE_DIR and a DB_DATABASE inside it are required (scripts/e2e-election.sh sets both).\n");
    exit(2);
}

$command = $argv[1] ?? '';

if (! in_array($command, ['cast', 'close'], true)) {
    if (! is_file($state.'/base.sqlite')) {
        fwrite(STDERR, "election-fixture.php: {$state}/base.sqlite is missing.\n");
        exit(2);
    }

    foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
        @unlink($database.$suffix);
    }

    copy($state.'/base.sqlite', $database);
}

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * A member with a throwaway key, an inbox relay for DMs and a profile name
 * (no picture: a picture URL would make the browser dial a foreign host).
 *
 * @return array{id: int, pubkey: string, npub: string, secret: string, name: string}
 */
function e2eMember(string $name, bool $paid = true, string $status = 'active'): array
{
    $secret = EventSigning::freshSecret();
    $key = new Key;
    $pubkey = $key->getPublicKey($secret);
    $npub = $key->convertPublicKeyToBech32($pubkey);

    $factory = EinundzwanzigPleb::factory();
    $factory = $status === 'active' ? $factory->active() : $factory;
    $factory = $paid ? $factory->withPaidYear((int) now()->year) : $factory;

    $pleb = $factory->create(['pubkey' => $pubkey, 'npub' => $npub]);

    Profile::query()->create([
        'pubkey' => $pubkey,
        'name' => $name,
        'display_name' => $name,
        'picture' => null,
        'banner' => null,
        'website' => null,
        'about' => null,
        'nip05' => null,
        'lud16' => null,
        'lud06' => null,
        'deleted' => false,
    ]);

    PlebDmRelay::query()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'relays' => ['wss://inbox.e2e.invalid/'],
        'event_id' => bin2hex(random_bytes(32)),
        'event_created_at' => now()->getTimestamp() - 60,
    ]);

    return ['id' => (int) $pleb->id, 'pubkey' => $pubkey, 'npub' => $npub, 'secret' => $secret, 'name' => $name];
}

/**
 * The throwaway board: one member the server treats as the whole board.
 *
 * @return array{id: int, pubkey: string, npub: string, secret: string, name: string}
 */
function e2eBoard(string $state): array
{
    $board = e2eMember('Bea Vorstand');

    file_put_contents($state.'/board.json', json_encode([$board['npub']]));
    config(['einundzwanzig.config.current_board' => [$board['npub']]]);
    Board::flush();

    return $board;
}

/**
 * @param  list<array{id: int}>  $candidates
 */
function e2eRound(Election $election, string $title, int $position, array $candidates, ElectionCountingRule $rule = ElectionCountingRule::SingleMajority, int $seats = 1): ElectionRound
{
    $round = ElectionRound::factory()->countingRule($rule, $seats)->create([
        'election_event_id' => $election->id,
        'title' => $title,
        'position' => $position,
    ]);

    foreach ($candidates as $candidate) {
        ElectionCandidate::factory()->create([
            'election_round_id' => $round->id,
            'einundzwanzig_pleb_id' => $candidate['id'],
            'status' => ElectionCandidateStatus::Approved,
        ]);
    }

    return $round;
}

function e2eElection(string $name = 'Vorstandswahl 2026'): Election
{
    return Election::factory()->announced()->create([
        'name' => $name,
        'meeting_at' => now()->addHour(),
    ]);
}

/**
 * @param  array<string, mixed>  $voters  name => member
 */
function e2eRemember(string $state, array $voters): void
{
    file_put_contents($state.'/voters.json', json_encode(array_values($voters)));
}

/**
 * One vote of `$voter` for the member `$candidate` stands as, signed with a nonce like the browser signs it.
 *
 * @param  array{id: int, secret: string}  $voter
 * @param  array{id: int}  $candidate  the candidate's member row
 */
function e2eCast(ElectionRound $round, array $voter, array $candidate): string
{
    $pleb = EinundzwanzigPleb::query()->findOrFail($voter['id']);
    $candidateId = (int) $round->candidates()->where('einundzwanzig_pleb_id', $candidate['id'])->value('id');
    $selection = $round->counting_rule === ElectionCountingRule::YesNoPerCandidate ? [$candidateId => 'yes'] : [$candidateId => 'for'];
    $template = VoteEvent::template($round->fresh(), $selection, now()->getTimestamp());
    $template['tags'][] = ['nonce', bin2hex(random_bytes(32))];
    $event = EventSigning::sign($voter['secret'], $template['kind'], $template['tags'], $template['content'], $template['created_at']);

    app(CastBallot::class)->handle($round->fresh(), $pleb, $event);

    return $event['id'];
}

/**
 * One vote of `$voter` with an explicit selection: member id of the candidate => for|yes|no.
 *
 * @param  array{id: int, secret: string}  $voter
 * @param  array<int, string>  $choices
 */
function e2eCastChoices(ElectionRound $round, array $voter, array $choices): string
{
    $pleb = EinundzwanzigPleb::query()->findOrFail($voter['id']);
    $selection = [];

    foreach ($choices as $memberId => $choice) {
        $selection[(int) $round->candidates()->where('einundzwanzig_pleb_id', $memberId)->value('id')] = $choice;
    }

    $template = VoteEvent::template($round->fresh(), $selection, now()->getTimestamp());
    $template['tags'][] = ['nonce', bin2hex(random_bytes(32))];
    $event = EventSigning::sign($voter['secret'], $template['kind'], $template['tags'], $template['content'], $template['created_at']);

    app(CastBallot::class)->handle($round->fresh(), $pleb, $event);

    return $event['id'];
}

/** @return array<string, mixed> */
function e2eScenario(string $name, string $state): array
{
    $board = e2eBoard($state);
    $stewardship = app(ElectionStewardship::class);
    $boardPleb = EinundzwanzigPleb::query()->findOrFail($board['id']);

    $anna = e2eMember('Anna Albrecht');
    $bernd = e2eMember('Bernd Brückner-Oberhausen');
    $carla = e2eMember('Carla Christiansen');
    $voters = ['voter1' => e2eMember('Dora Dietrich'), 'voter2' => e2eMember('Emil Engel'), 'voter3' => e2eMember('Fritz Falk')];
    e2eRemember($state, $voters);

    $result = ['board' => $board, 'candidates' => compact('anna', 'bernd', 'carla'), 'voters' => $voters];

    switch ($name) {
        case 'member':
            // One open round. The voter exists before the round opens, so it is on the roll.
            $election = e2eElection();
            $round = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);
            e2eRound($election, 'Kasse', 2, [$carla]);
            $stewardship->open($round, $boardPleb);

            return [...$result, 'election' => $election->id, 'round' => $round->id];

        case 'board-close':
            $election = e2eElection();
            $round = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);
            e2eRound($election, 'Kasse', 2, [$carla]);

            return [...$result, 'election' => $election->id, 'round' => $round->id];

        case 'board-annul':
            $election = e2eElection();
            $round = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);
            $stewardship->open($round, $boardPleb);

            return [...$result, 'election' => $election->id, 'round' => $round->id];

        case 'board-strike':
            // "Schriftführung" has no candidate left: a dead end the board strikes.
            $election = e2eElection();
            $first = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);
            $dead = e2eRound($election, 'Schriftführung', 2, []);
            $stewardship->open($first, $boardPleb);
            e2eCast($first, $voters['voter1'], $anna);
            $stewardship->closeEarly($first->fresh(), $boardPleb, 'Alle haben abgestimmt');

            return [...$result, 'election' => $election->id, 'round' => $first->id, 'dead' => $dead->id];

        case 'board-entitle':
            // Gerda paid her fee in cash: active, but no paid year in the portal.
            $cash = e2eMember('Gerda Barzahlerin', paid: false);
            $election = e2eElection();
            $round = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);

            return [...$result, 'election' => $election->id, 'round' => $round->id, 'cash' => $cash];

        case 'share':
            $election = e2eElection();
            $round = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);

            return [...$result, 'election' => $election->id, 'round' => $round->id];

        case 'tie':
            // A counted Stimmengleichheit between a 63-character name and Anna, and
            // a chair name at the 60-character limit for the casting vote.
            $long = e2eMember('Maximiliane Theodora Freifrau von Hohenzollern-Sigmaringen-Wied');
            $chair = 'Erika Magdalena Mustermann-Vorsitz von der Leyen-Schwarzwald';
            $election = e2eElection();
            $round = e2eRound($election, 'Vorsitz', 1, [$long, $anna]);
            $stewardship->open($round, $boardPleb);
            e2eCast($round, $voters['voter1'], $long);
            e2eCast($round, $voters['voter2'], $anna);
            $stewardship->closeEarly($round->fresh(), $boardPleb, 'Alle haben abgestimmt');

            return [...$result, 'election' => $election->id, 'round' => $round->id, 'long' => $long, 'chair' => $chair];

        case 'share-yesno':
        case 'share-yesno-long':
            // A counted yes/no round with five people: the densest share view.
            // The long variant has a 63-character name in it (one column) and a
            // yes = no tie on that name, decided by a chair at the 60-character limit.
            $long = $name === 'share-yesno-long';
            $fifth = e2eMember($long ? 'Maximiliane Theodora Freifrau von Hohenzollern-Sigmaringen-Wied' : 'Erik Eberhardt');
            $daniel = e2eMember('Daniel Dorn');
            $people = [$anna, $bernd, $carla, $daniel, $fifth];
            $election = e2eElection();
            $round = e2eRound($election, 'Vorstand', 1, $people, ElectionCountingRule::YesNoPerCandidate, 5);
            $stewardship->open($round, $boardPleb);
            $everyoneYes = array_fill_keys(array_column($people, 'id'), 'yes');
            // array_replace, not a spread: a spread renumbers the integer member ids.
            $split = array_replace($everyoneYes, [$fifth['id'] => 'no']);
            e2eCastChoices($round, $board, $everyoneYes);
            e2eCastChoices($round, $voters['voter1'], $everyoneYes);
            e2eCastChoices($round, $voters['voter2'], $long ? $split : $everyoneYes);
            e2eCastChoices($round, $voters['voter3'], $long ? $split : $everyoneYes);
            $stewardship->closeEarly($round->fresh(), $boardPleb, 'Alle haben abgestimmt');
            $chair = 'Erika Magdalena Mustermann-Vorsitz von der Leyen-Schwarzwald';

            if ($long) {
                $fifthCandidate = (int) $round->candidates()->where('einundzwanzig_pleb_id', $fifth['id'])->value('id');
                app(RecordCastingVote::class)->handle($round->fresh(), $boardPleb, [$fifthCandidate], $chair);
            }

            return [...$result, 'election' => $election->id, 'round' => $round->id, 'fifth' => $fifth, 'chair' => $chair];

        case 'tie-decided':
            $long = e2eMember('Maximiliane Theodora Freifrau von Hohenzollern-Sigmaringen-Wied');
            $chair = 'Erika Magdalena Mustermann-Vorsitz von der Leyen-Schwarzwald';
            $election = e2eElection();
            $round = e2eRound($election, 'Vorsitz', 1, [$long, $anna]);
            $stewardship->open($round, $boardPleb);
            e2eCast($round, $voters['voter1'], $long);
            e2eCast($round, $voters['voter2'], $anna);
            $stewardship->closeEarly($round->fresh(), $boardPleb, 'Alle haben abgestimmt');
            $longCandidate = (int) $round->candidates()->where('einundzwanzig_pleb_id', $long['id'])->value('id');
            app(RecordCastingVote::class)->handle($round->fresh(), $boardPleb, [$longCandidate], $chair);

            return [...$result, 'election' => $election->id, 'round' => $round->id, 'long' => $long, 'chair' => $chair];

        case 'off-roll':
            // Lena paid, but only after the round opened and outside the payment
            // path: entitled by the fee rule, not on the round's roll.
            $election = e2eElection();
            $round = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);
            $stewardship->open($round, $boardPleb);
            $late = e2eMember('Lena Spätzahlerin');

            return [...$result, 'election' => $election->id, 'round' => $round->id, 'late' => $late];

        case 'runoff-pending':
            // Closed without an absolute majority (2 of 4), no run-off created yet.
            $election = e2eElection();
            $round = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd, $carla]);
            $stewardship->open($round, $boardPleb);
            e2eCast($round, $voters['voter1'], $anna);
            e2eCast($round, $voters['voter2'], $anna);
            e2eCast($round, $voters['voter3'], $bernd);
            e2eCast($round, $board, $carla);
            $stewardship->closeEarly($round->fresh(), $boardPleb, 'Alle haben abgestimmt');

            return [...$result, 'election' => $election->id, 'round' => $round->id];

        case 'repeat':
            // Vorsitz counted, annulled after the count and repeated; the repeat
            // elected Anna. Kasse counted normally. Every round is closed.
            $election = e2eElection();
            $chairRound = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);
            $cash = e2eRound($election, 'Kasse', 2, [$carla]);
            $stewardship->open($chairRound, $boardPleb);
            e2eCast($chairRound, $voters['voter1'], $bernd);
            e2eCast($chairRound, $voters['voter2'], $bernd);
            $stewardship->closeEarly($chairRound->fresh(), $boardPleb, 'Alle haben abgestimmt');
            $stewardship->annul($chairRound->fresh(), $boardPleb, 'Falsche Liste ausgegeben');
            $repeat = $stewardship->repeat($chairRound->fresh(), $boardPleb);
            $stewardship->open($repeat->fresh(), $boardPleb);
            e2eCast($repeat, $voters['voter1'], $anna);
            e2eCast($repeat, $voters['voter2'], $anna);
            e2eCast($repeat, $voters['voter3'], $bernd);
            $stewardship->closeEarly($repeat->fresh(), $boardPleb, 'Alle haben abgestimmt');
            $stewardship->open($cash->fresh(), $boardPleb);
            e2eCast($cash, $voters['voter1'], $carla);
            e2eCast($cash, $voters['voter2'], $carla);
            $stewardship->closeEarly($cash->fresh(), $boardPleb, 'Alle haben abgestimmt');

            return [...$result, 'election' => $election->id, 'round' => $chairRound->id, 'repeat' => $repeat->id, 'cash' => $cash->id];

        case 'unreadable':
            // Vorsitz counted normally; Kasse sealed with one stored vote that no
            // longer fits its candidates (a corrupted row), so it cannot be counted.
            $election = e2eElection();
            $chairRound = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);
            $cash = e2eRound($election, 'Kasse', 2, [$carla]);
            $stewardship->open($chairRound, $boardPleb);
            e2eCast($chairRound, $voters['voter1'], $anna);
            $stewardship->closeEarly($chairRound->fresh(), $boardPleb, 'Alle haben abgestimmt');
            $stewardship->open($cash->fresh(), $boardPleb);
            e2eCast($cash, $voters['voter1'], $carla);
            $stewardship->closeEarly($cash->fresh(), $boardPleb, 'Alle haben abgestimmt');
            $cash->votes()->toBase()->update(['choices' => json_encode(['999999' => 'for'])]);

            return [...$result, 'election' => $election->id, 'round' => $chairRound->id, 'unreadable' => $cash->id];

        case 'npub':
            // A candidate without any profile name: the page prints the whole npub.
            // Counted (not elected) in Vorsitz, where voter1 voted; on the ballot of the open Kasse.
            $bare = e2eMember('Ohne Namen');
            Profile::query()->where('pubkey', $bare['pubkey'])->delete();
            $election = e2eElection();
            $chairRound = e2eRound($election, 'Vorsitz', 1, [$bare, $anna]);
            $cash = e2eRound($election, 'Kasse', 2, [$bare, $carla]);
            $stewardship->open($chairRound, $boardPleb);
            e2eCast($chairRound, $voters['voter1'], $anna);
            e2eCast($chairRound, $voters['voter2'], $anna);
            e2eCast($chairRound, $voters['voter3'], $bare);
            $stewardship->closeEarly($chairRound->fresh(), $boardPleb, 'Alle haben abgestimmt');
            $stewardship->open($cash->fresh(), $boardPleb);
            // A call link, so the page has a plain link to focus (never followed).
            $election->forceFill(['call_url' => 'https://call.e2e.invalid/vorstandswahl'])->save();

            return [...$result, 'election' => $election->id, 'round' => $cash->id, 'sealed' => $chairRound->id, 'bare' => $bare];

        case 'tie-opened-elsewhere':
            // The tie of „Vorsitz“ is the chair's again while the 63-character
            // name stands in the opened „Kassenprüfung und Revision“: the
            // assembly resolved a run-off, the other round opened while that
            // run-off was a draft, and the run-off — the name withdrawn from
            // it — was opened and annulled (DoubleElectionInvariantTest, D2).
            $long = e2eMember('Maximiliane Theodora Freifrau von Hohenzollern-Sigmaringen-Wied');
            $election = e2eElection();
            // The other round comes first: an earlier round's tie holds back a
            // later one even with a run-off. The name stands there only once
            // „Vorsitz“ is counted, through the member's candidacy and the board's approval.
            $other = e2eRound($election, 'Kassenprüfung und Revision', 1, [$carla]);
            $round = e2eRound($election, 'Vorsitz', 2, [$long, $anna]);
            $stewardship->open($round, $boardPleb);
            e2eCast($round, $voters['voter1'], $long);
            e2eCast($round, $voters['voter2'], $anna);
            $stewardship->closeEarly($round->fresh(), $boardPleb, 'Alle haben abgestimmt');
            $longPleb = EinundzwanzigPleb::query()->findOrFail($long['id']);
            app(Candidacies::class)->approve(app(Candidacies::class)->submit($other, $longPleb), $boardPleb);
            $runOff = app(CreateRunOff::class)->handle($round->fresh(), $boardPleb, resolvedByAssembly: true);
            $stewardship->open($other, $boardPleb);
            app(Candidacies::class)->withdraw($runOff->candidates()->where('einundzwanzig_pleb_id', $long['id'])->firstOrFail(), $boardPleb, 'Steht in der Kassenprüfung');
            $stewardship->annul($stewardship->open($runOff->fresh(), $boardPleb), $boardPleb, 'Zurück an die Vorsitzende');

            return [...$result, 'election' => $election->id, 'round' => $round->id, 'other' => $other->id, 'long' => $long];

        case 'dm':
            $election = e2eElection();
            e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);

            return [...$result, 'election' => $election->id];

        case 'inbox':
            // Two messages to Ida through the application's own send path
            // (ComposeDirectMessage, a frozen run, DispatchBulkDirectMessages on
            // the sync queue, the in-memory relay): the first while she has an
            // inbox relay (sent), the second after she removed it (no_inbox,
            // "only here"). Each carries a marker sentence the spec looks for.
            $viewer = e2eMember('Ida Imhof');
            $viewerPleb = EinundzwanzigPleb::query()->findOrFail($viewer['id']);
            FakeRelayTransport::bind();
            $messages = [];

            foreach (['sent' => ['Die Mitgliederversammlung beginnt um 19 Uhr.', DmDeliveryStatus::Sent], 'noInbox' => ['Der Beitrag für dieses Jahr ist fällig.', DmDeliveryStatus::NoInbox]] as $key => [$marker, $expected]) {
                if ($expected === DmDeliveryStatus::NoInbox) {
                    PlebDmRelay::query()->where('einundzwanzig_pleb_id', $viewer['id'])->delete();
                }

                $message = app(ComposeDirectMessage::class)->handle(DmMessageType::Individual, $boardPleb, [], "Hallo {name},\n{$marker} Mehr dazu: https://verein.example/beitrag");
                $run = $message->freezeRun([$viewerPleb]);
                DispatchBulkDirectMessages::dispatchSync($run->id);
                $status = DmDelivery::query()->where('dm_run_id', $run->id)->sole()->status;

                if ($status !== $expected) {
                    fwrite(STDERR, "election-fixture.php: the {$key} message ended as {$status->value}, not {$expected->value}.\n");
                    exit(2);
                }

                $messages[$key] = ['marker' => $marker, 'status' => $status->value];
            }

            return [...$result, 'viewer' => $viewer, 'messages' => $messages];

        case 'board-inbox':
            // Members' replies in the board inbox: Ida (inbox relay set) wrote
            // twice, Bernd (long name, no inbox relay) once with a long text.
            $ida = e2eMember('Ida Imhof');
            PlebDmRelay::query()->updateOrCreate(['einundzwanzig_pleb_id' => $ida['id']], ['relays' => ['wss://inbox.ida.example/'], 'event_id' => str_repeat('a', 64), 'event_created_at' => now()->getTimestamp()]);
            PlebDmRelay::query()->where('einundzwanzig_pleb_id', $bernd['id'])->delete();

            foreach ([[$ida, 'Danke für die Einladung!', 40], [$ida, 'Kann ich auch per Video teilnehmen?', 30], [$bernd, str_repeat('Ich habe eine längere Frage zur Kandidatur und zum Ablauf der Wahl im Frühjahr. ', 6), 20]] as [$author, $body, $minutesAgo]) {
                DmInboxMessage::query()->create(['einundzwanzig_pleb_id' => $author['id'], 'direction' => DmInboxMessage::IN, 'body' => $body, 'sent_at' => now()->subMinutes($minutesAgo)]);
            }

            // Someone without a portal account wrote too.
            $strangerPubkey = (new Key)->getPublicKey(EventSigning::freshSecret());
            DmInboxMessage::query()->create(['counterparty_pubkey' => $strangerPubkey, 'direction' => DmInboxMessage::IN, 'body' => 'Wie werde ich Mitglied im Verein?', 'sent_at' => now()->subMinutes(10)]);

            return [...$result, 'ida' => [...$ida, 'pubkey' => EinundzwanzigPleb::query()->whereKey($ida['id'])->value('pubkey')], 'bernd' => EinundzwanzigPleb::query()->whereKey($bernd['id'])->value('pubkey'), 'stranger' => $strangerPubkey];

        case 'measure':
            // Every page with content: a counted round with a result, an open
            // round with a vote in it, and a third round still waiting.
            $election = e2eElection('Ordentliche Vorstandswahl des Vereins 2026 mit einem langen Namen');
            $sealed = e2eRound($election, 'Vorsitz', 1, [$anna, $bernd]);
            $open = e2eRound($election, 'Kassenprüfung und Revision', 2, [$bernd, $carla], ElectionCountingRule::MostVotes, 2);
            e2eRound($election, 'Schriftführung', 3, [$carla]);
            $stewardship->open($sealed, $boardPleb);
            e2eCast($sealed, $voters['voter1'], $anna);
            e2eCast($sealed, $voters['voter2'], $anna);
            e2eCast($sealed, $voters['voter3'], $bernd);
            $stewardship->closeEarly($sealed->fresh(), $boardPleb, 'Alle haben abgestimmt');
            $stewardship->open($open, $boardPleb);
            e2eCast($open, $voters['voter1'], $carla);

            return [...$result, 'election' => $election->id, 'round' => $open->id, 'sealed' => $sealed->id];
    }

    fwrite(STDERR, "election-fixture.php: unknown scenario '{$name}'.\n");
    exit(2);
}

if ($command === 'close') {
    // Closes <roundId> early as the board, the way admin-live does.
    $round = ElectionRound::query()->findOrFail((int) ($argv[2] ?? 0));
    $boardNpubs = json_decode((string) file_get_contents($state.'/board.json'), true);
    config(['einundzwanzig.config.current_board' => $boardNpubs]);
    Board::flush();
    $board = EinundzwanzigPleb::query()->whereIn('npub', $boardNpubs)->firstOrFail();
    app(ElectionStewardship::class)->closeEarly($round, $board, 'Alle haben abgestimmt');

    echo json_encode(['closed' => $round->id]);
    exit(0);
}

if ($command === 'cast') {
    [$roundId, $memberId, $count] = [(int) ($argv[2] ?? 0), (int) ($argv[3] ?? 0), (int) ($argv[4] ?? 1)];
    $round = ElectionRound::query()->findOrFail($roundId);
    $voters = json_decode((string) file_get_contents($state.'/voters.json'), true);
    $cast = [];

    foreach ($voters as $voter) {
        if (count($cast) >= $count) {
            break;
        }

        if ($round->voteEventIdOf(EinundzwanzigPleb::query()->findOrFail($voter['id'])) !== null) {
            continue;
        }

        $cast[] = e2eCast($round, $voter, ['id' => $memberId]);
    }

    echo json_encode(['cast' => $cast]);
    exit(0);
}

echo json_encode(e2eScenario($command, $state));
