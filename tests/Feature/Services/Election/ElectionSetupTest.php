<?php

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionRoundMode;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\ElectionSetup;
use App\Services\Election\OpenRound;
use App\Support\Board;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

/*
 * The board sets up an election with its rounds and changes it while every
 * round is a draft. Each act writes one audit line; a refused act writes
 * none and changes nothing.
 */

function electionSetup(): ElectionSetup
{
    return app(ElectionSetup::class);
}

/**
 * @return array<string, mixed>
 */
function setupAttributes(array $overrides = []): array
{
    return [
        'name' => 'Vorstandswahl 2027',
        'meeting_at' => Carbon::parse('2027-03-20 19:00'),
        'call_url' => '  https://meet.example.org/vorstandswahl  ',
        'fee_rule' => ElectionFeeRule::Year,
        'fee_year' => 2027,
        'honorary_without_fee' => false,
        'is_test' => false,
        ...$overrides,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function setupRounds(): array
{
    return [
        ['title' => 'Präsidium', 'seats' => 1, 'counting_rule' => ElectionCountingRule::SingleMajority],
        ['title' => 'Vorstand', 'seats' => 4, 'counting_rule' => ElectionCountingRule::YesNoPerCandidate],
    ];
}

/**
 * @return list<string>
 */
function setupAuditActions(Election $election): array
{
    return ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->pluck('action')->map->value->all();
}

it('creates an election with live rounds in the given order and an audit line naming the board member', function () {
    [$board] = electionBoard(1);

    $election = electionSetup()->create($board, setupAttributes(['is_test' => true]), setupRounds());

    expect($election->name)->toBe('Vorstandswahl 2027')
        ->and($election->meeting_at->format('Y-m-d H:i'))->toBe('2027-03-20 19:00')
        ->and($election->call_url)->toBe('https://meet.example.org/vorstandswahl')
        ->and($election->is_test)->toBeTrue()
        ->and($election->rounds->map->only(['position', 'title', 'seats'])->all())->toBe([
            ['position' => 1, 'title' => 'Präsidium', 'seats' => 1],
            ['position' => 2, 'title' => 'Vorstand', 'seats' => 4],
        ])
        ->and($election->rounds->every(fn ($round) => $round->mode === ElectionRoundMode::Live))->toBeTrue()
        ->and(setupAuditActions($election))->toBe([ElectionAuditAction::ElectionCreated->value])
        ->and(ElectionAuditEntry::query()->where('election_event_id', $election->id)->value('actor_pleb_id'))->toBe($board->id)
        ->and(app(ElectionAuditLog::class)->verify($election))->toBeTrue();
});

it('edits a draft election: attributes, order, a new round and a removed one, with one audit line', function () {
    [$board] = electionBoard(1);
    $election = electionSetup()->create($board, setupAttributes(), setupRounds());
    [$presidency, $board_round] = $election->rounds->all();

    $edited = electionSetup()->update($election, $board, setupAttributes([
        'name' => 'Vorstandswahl 2027 (neu)',
        'fee_rule' => ElectionFeeRule::LastTwelveMonths,
        'fee_year' => null,
        'call_url' => '',
        'is_test' => true,
    ]), [
        ['id' => $board_round->id, 'title' => 'Vorstand', 'seats' => 3, 'counting_rule' => ElectionCountingRule::MostVotes],
        ['id' => null, 'title' => 'Revisionsstelle', 'seats' => 1, 'counting_rule' => ElectionCountingRule::YesNoPerCandidate],
    ]);

    expect($edited->name)->toBe('Vorstandswahl 2027 (neu)')
        ->and($edited->fee_rule)->toBe(ElectionFeeRule::LastTwelveMonths)
        ->and($edited->fee_year)->toBeNull()
        ->and($edited->call_url)->toBeNull()
        ->and($edited->is_test)->toBeFalse()
        ->and($edited->rounds->map->only(['id', 'position', 'title', 'seats'])->all())->toBe([
            ['id' => $board_round->id, 'position' => 1, 'title' => 'Vorstand', 'seats' => 3],
            ['id' => $edited->rounds[1]->id, 'position' => 2, 'title' => 'Revisionsstelle', 'seats' => 1],
        ])
        ->and($presidency->fresh())->toBeNull()
        ->and(setupAuditActions($election))->toBe(['election_created', 'election_updated']);
});

it('refuses to edit an election once one of its rounds has been opened, and writes nothing', function () {
    [$board] = electionBoard(1);
    $election = electionSetup()->create($board, setupAttributes(), setupRounds());
    ElectionCandidate::factory()->create(['election_round_id' => $election->rounds[0]->id]);
    app(OpenRound::class)->handle($election->rounds[0]);

    expect(fn () => electionSetup()->update($election, $board, setupAttributes(['name' => 'Anders']), setupRounds()))
        ->toThrow(ElectionRoundStateException::class);

    expect($election->fresh()->name)->toBe('Vorstandswahl 2027')
        ->and(setupAuditActions($election))->toBe(['election_created']);
});

it('refuses to remove a round that holds candidacies', function () {
    [$board] = electionBoard(1);
    $election = electionSetup()->create($board, setupAttributes(), setupRounds());
    ElectionCandidate::factory()->pending()->create(['election_round_id' => $election->rounds[1]->id]);

    expect(fn () => electionSetup()->update($election, $board, setupAttributes(), [
        ['id' => $election->rounds[0]->id, 'title' => 'Präsidium', 'seats' => 1, 'counting_rule' => ElectionCountingRule::SingleMajority],
    ]))->toThrow(ElectionActionRefusedException::class);

    expect($election->rounds()->count())->toBe(2)
        ->and(setupAuditActions($election))->toBe(['election_created']);
});

it('refuses a member outside the board to create or edit', function () {
    [$board] = electionBoard(1);
    $member = electionVoter();
    $election = electionSetup()->create($board, setupAttributes(), setupRounds());

    expect(fn () => electionSetup()->create($member, setupAttributes(['name' => 'Fremd']), setupRounds()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => electionSetup()->update($election, $member, setupAttributes(['name' => 'Fremd']), setupRounds()))
        ->toThrow(AuthorizationException::class);

    expect(Election::query()->where('name', 'Fremd')->exists())->toBeFalse()
        ->and($election->fresh()->name)->toBe('Vorstandswahl 2027')
        ->and(setupAuditActions($election))->toBe(['election_created']);
});

it('asks at the moment of the act: a member who left the board since is refused', function () {
    [$leaving, $staying] = electionBoard(2);
    $election = electionSetup()->create($leaving, setupAttributes(), setupRounds());

    config(['einundzwanzig.config.current_board' => [$staying->npub]]);
    Board::flush();

    expect(fn () => electionSetup()->update($election, $leaving, setupAttributes(['name' => 'Zu spät']), setupRounds()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => electionSetup()->create($leaving, setupAttributes(['name' => 'Zu spät']), setupRounds()))
        ->toThrow(AuthorizationException::class);

    expect(Election::query()->where('name', 'Zu spät')->exists())->toBeFalse()
        ->and(EinundzwanzigPleb::query()->find($leaving->id)->isBoardMember())->toBeFalse();
});
