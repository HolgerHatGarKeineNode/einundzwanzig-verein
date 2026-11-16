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

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Models\PlebDmRelay;
use App\Models\Profile;
use App\Services\Election\Candidacies;
use App\Services\Election\CastBallot;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\VoteEvent;
use App\Services\Nostr\EventSigning;
use App\Support\Board;
use Illuminate\Contracts\Console\Kernel;
use swentel\nostr\Key\Key;

$state = getenv('E2E_STATE_DIR');
$database = getenv('DB_DATABASE');

if (! is_string($state) || $state === '' || ! is_dir($state) || ! is_string($database) || dirname($database) !== rtrim($state, '/')) {
    fwrite(STDERR, "election-fixture.php: E2E_STATE_DIR and a DB_DATABASE inside it are required (scripts/e2e-election.sh sets both).\n");
    exit(2);
}

$command = $argv[1] ?? '';

if ($command !== 'cast') {
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
