<?php

use App\Enums\ElectionCandidateStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\Profile;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\OpenRound;
use App\Services\Election\RunSheet;

require_once __DIR__.'/helpers.php';

/*
 * The run sheet of the board's election page, read off an election: the
 * stations and the one next step, with the call or section that takes it.
 * The page renders what this returns; the steps themselves are tested on the
 * components that take them.
 */

/**
 * @return array{text: string, hint: string|null, action: string|null, label: string|null, click: string|null, href: string|null, secondary_label: string|null, secondary_click: string|null}
 */
function runSheetStep(Election $election): array
{
    return app(RunSheet::class)->nextStepOf($election->fresh());
}

/**
 * @return list<string>
 */
function runSheetStates(Election $election): array
{
    return array_column(app(RunSheet::class)->stationsOf($election->fresh()), 'state');
}

it('walks a draft from its first round over the meeting date to the announcement', function () {
    $election = Election::factory()->create(['meeting_at' => null]);

    expect(runSheetStep($election))->toMatchArray(['text' => 'Lege mindestens einen Wahlgang an.', 'action' => 'settings', 'click' => null])
        ->and(runSheetStates($election))->toBe(['current', 'ahead', 'ahead', 'ahead', 'ahead']);

    draftElectionRound(candidates: 0, election: $election);

    expect(runSheetStep($election))->toMatchArray(['text' => 'Trage den Sitzungstermin ein.', 'action' => 'settings']);

    $election->update(['meeting_at' => now()->addMonth()]);

    expect(runSheetStep($election))->toMatchArray(['text' => 'Wahl ankündigen.', 'action' => 'announce', 'label' => 'Wahl ankündigen']);
});

it('sends pending candidacies to the candidacies section and offers striking a first round nobody stands in', function () {
    $election = Election::factory()->announced()->create();
    $first = draftElectionRound(['title' => 'Präsidium', 'position' => 1], candidates: 0, election: $election);
    $pending = ElectionCandidate::factory()->pending()->create(['election_round_id' => $first->id]);

    expect(runSheetStep($election))->toMatchArray(['text' => 'Eine Kandidatur wartet auf Prüfung.', 'action' => 'candidacies', 'secondary_click' => null])
        ->and(runSheetStates($election))->toBe(['done', 'done', 'current', 'ahead', 'ahead']);

    $pending->update(['status' => ElectionCandidateStatus::Rejected]);

    expect(runSheetStep($election))->toMatchArray([
        'text' => '„Präsidium“ hat noch keine freigegebene Kandidatur.',
        'action' => 'candidacies',
        'secondary_label' => 'Wahlgang streichen',
        'secondary_click' => runSheetClick('start-round-action', $first->id, 'strike'),
    ]);
});

it('offers closing the open round, then opening the next, then the minutes', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->announced()->create();
    $first = draftElectionRound(['title' => 'Präsidium', 'position' => 1], election: $election);
    $second = draftElectionRound(['title' => 'Vorstand', 'position' => 2], election: $election);

    expect(runSheetStep($election))->toMatchArray(['label' => '„Präsidium“ öffnen', 'click' => runSheetClick('start-round-action', $first->id, 'open')]);

    app(OpenRound::class)->handle($first->fresh());

    expect(runSheetStep($election))->toMatchArray(['text' => '„Präsidium“ ist offen.', 'click' => runSheetClick('start-round-action', $first->id, 'close')])
        ->and(runSheetStates($election))->toBe(['done', 'done', 'done', 'current', 'ahead']);

    $stewardship = app(ElectionStewardship::class);
    $stewardship->closeEarly($first->fresh(), $board, 'Alle haben abgestimmt');
    $stewardship->strike($second->fresh(), $board, 'Niemand tritt an');

    expect(runSheetStep($election))->toMatchArray([
        'text' => 'Alle Wahlgänge sind abgeschlossen.',
        'click' => null,
        'href' => route('association.election.admin.protocol', $election),
    ]);
});

it('names the person and the way out when the next round cannot be opened', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->announced()->create();
    $presidency = draftElectionRound(['title' => 'Präsidium', 'position' => 1], candidates: 1, election: $election);
    $beisitz = draftElectionRound(['title' => 'Beisitz', 'position' => 2], candidates: 0, election: $election);
    $anna = $presidency->candidates()->sole();
    Profile::factory()->create(['pubkey' => $anna->pleb->pubkey, 'name' => 'Anna Beispiel']);
    ElectionCandidate::factory()->create(['election_round_id' => $beisitz->id, 'einundzwanzig_pleb_id' => $anna->einundzwanzig_pleb_id]);
    ElectionCandidate::factory()->pending()->create(['election_round_id' => $beisitz->id, 'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory()->active()->create()->id]);

    $voter = electionVoter();
    $round = app(OpenRound::class)->handle($presidency->fresh());
    castElectionBallot($round, $voter, [(int) $anna->id => 'for']);
    app(ElectionStewardship::class)->closeEarly($round->fresh(), $board, 'Alle haben abgestimmt');

    expect(runSheetStep($election))->toMatchArray([
        'text' => '„Beisitz“ lässt sich nicht öffnen.',
        'action' => 'candidacies',
        'click' => null,
        'secondary_click' => runSheetClick('start-round-action', $beisitz->id, 'strike'),
    ])->and(runSheetStep($election)['hint'])->toStartWith('Anna Beispiel ist in einem früheren Wahlgang schon gewählt.');
});
