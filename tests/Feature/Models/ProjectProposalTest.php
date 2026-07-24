<?php

use App\Enums\ProjectProposalStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Models\Vote;
use Illuminate\Support\Collection;

/**
 * Der Vorstand wird je Test frisch angelegt, weil ProjectProposal::boardPlebIds()
 * die npubs aus der Konfiguration bei jedem Aufruf gegen die Datenbank auflöst.
 * Ohne passende Pleb-Zeilen zählt keine Stimme als Vorstandsstimme, und die
 * Statusableitung hätte nichts zu prüfen.
 *
 * Bewusst KEINE Annahme über Aufrufreihenfolge oder Memoisierung: die Methode
 * cacht absichtlich nicht (ein prozessweiter Cache überlebt bei langlebigen
 * Workern den Request und würde Stimmen verschlucken).
 */
beforeEach(function () {
    $this->board = collect(config('einundzwanzig.config.current_board'))
        ->map(fn (string $npub) => EinundzwanzigPleb::factory()->create(['npub' => $npub]))
        ->values();
});

/**
 * Casts $count board votes of $value for $project, using the first $count
 * seeded board plebs.
 */
function castBoardVotes(ProjectProposal $project, Collection $board, int $count, bool $value): void
{
    $board->take($count)->each(fn (EinundzwanzigPleb $pleb) => Vote::create([
        'project_proposal_id' => $project->id,
        'einundzwanzig_pleb_id' => $pleb->id,
        'value' => $value,
    ]));
}

it('derives the board vote threshold from the configured board size, not a hardcoded number', function () {
    $expected = intdiv(count(config('einundzwanzig.config.current_board')), 2) + 1;

    expect(ProjectProposal::boardVoteThreshold())->toBe($expected);
});

it('stays "new" while approvals are below the majority threshold', function () {
    $project = ProjectProposal::factory()->create();
    $threshold = ProjectProposal::boardVoteThreshold();

    castBoardVotes($project, $this->board, $threshold - 1, true);
    $project->load('votes');

    expect($project->status())->toBe(ProjectProposalStatus::InVoting);

    $inVoting = ProjectProposal::query()->withStatus(ProjectProposalStatus::InVoting->value)->pluck('id');
    expect($inVoting)->toContain($project->id);

    $accepted = ProjectProposal::query()->withStatus(ProjectProposalStatus::Accepted->value)->pluck('id');
    expect($accepted)->not->toContain($project->id);
});

it('flips to accepted the moment approvals reach the majority threshold, and leaves "new"', function () {
    $project = ProjectProposal::factory()->create();
    $threshold = ProjectProposal::boardVoteThreshold();

    castBoardVotes($project, $this->board, $threshold, true);
    $project->load('votes');

    expect($project->status())->toBe(ProjectProposalStatus::Accepted);

    $accepted = ProjectProposal::query()->withStatus(ProjectProposalStatus::Accepted->value)->pluck('id');
    expect($accepted)->toContain($project->id);

    $inVoting = ProjectProposal::query()->withStatus(ProjectProposalStatus::InVoting->value)->pluck('id');
    expect($inVoting)->not->toContain($project->id);
});

it('flips to rejected once rejections reach the majority threshold, and never shows under "new" too', function () {
    // Regression: the previous status logic defined "new" as "fewer than 3
    // approvals". A fully rejected project has 0 approvals, so it satisfied
    // that condition as well and showed up under both "new" and "rejected"
    // at once.
    $project = ProjectProposal::factory()->create();
    $threshold = ProjectProposal::boardVoteThreshold();

    castBoardVotes($project, $this->board, $threshold, false);
    $project->load('votes');

    expect($project->status())->toBe(ProjectProposalStatus::Rejected);

    $rejected = ProjectProposal::query()->withStatus(ProjectProposalStatus::Rejected->value)->pluck('id');
    expect($rejected)->toContain($project->id);

    $inVoting = ProjectProposal::query()->withStatus(ProjectProposalStatus::InVoting->value)->pluck('id');
    expect($inVoting)->not->toContain($project->id);
});

it('stays "new" while rejections are below the majority threshold', function () {
    $project = ProjectProposal::factory()->create();
    $threshold = ProjectProposal::boardVoteThreshold();

    castBoardVotes($project, $this->board, $threshold - 1, false);
    $project->load('votes');

    expect($project->status())->toBe(ProjectProposalStatus::InVoting);

    $rejected = ProjectProposal::query()->withStatus(ProjectProposalStatus::Rejected->value)->pluck('id');
    expect($rejected)->not->toContain($project->id);
});

it('is "supported" once a payout is recorded, outranking a majority of rejection votes', function () {
    $project = ProjectProposal::factory()->create(['sats_paid' => 50000]);
    $threshold = ProjectProposal::boardVoteThreshold();

    castBoardVotes($project, $this->board, $threshold, false);
    $project->load('votes');

    expect($project->status())->toBe(ProjectProposalStatus::Supported);

    $supported = ProjectProposal::query()->withStatus(ProjectProposalStatus::Supported->value)->pluck('id');
    expect($supported)->toContain($project->id);

    $rejected = ProjectProposal::query()->withStatus(ProjectProposalStatus::Rejected->value)->pluck('id');
    expect($rejected)->not->toContain($project->id);
});

it('surfaces the paid sats once a payout is recorded, otherwise the requested amount', function () {
    $unpaid = ProjectProposal::factory()->create([
        'support_in_sats' => 1_250_000,
        'sats_paid' => null,
    ]);
    $zeroPaid = ProjectProposal::factory()->create([
        'support_in_sats' => 1_250_000,
        'sats_paid' => 0,
    ]);
    $paid = ProjectProposal::factory()->create([
        'support_in_sats' => 1_250_000,
        'sats_paid' => 392_613,
    ]);

    expect($unpaid->displayedSats())->toBe(1_250_000);
    expect($zeroPaid->displayedSats())->toBe(1_250_000);
    expect($paid->displayedSats())->toBe(392_613);
});

it('does not let votes from non-board members reach the board threshold', function () {
    $project = ProjectProposal::factory()->create();
    $threshold = ProjectProposal::boardVoteThreshold();

    $outsiders = EinundzwanzigPleb::factory()->count($threshold)->create();
    $outsiders->each(fn (EinundzwanzigPleb $pleb) => Vote::create([
        'project_proposal_id' => $project->id,
        'einundzwanzig_pleb_id' => $pleb->id,
        'value' => true,
    ]));
    $project->load('votes');

    expect($project->status())->toBe(ProjectProposalStatus::InVoting);
    expect($project->supporters())->toBe($threshold);
});
