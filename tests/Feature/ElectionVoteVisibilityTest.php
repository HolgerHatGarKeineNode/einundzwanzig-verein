<?php

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/*
 * Who voted how lives in `election_votes` and is for the board's database
 * access only: no page shows it (Kurskorrektur 2026-11-14). A Livewire
 * component or a Blade view may reach the votes of a round as a count and in
 * no other way — never the model, the table, a vote row, its choices or its
 * signed event.
 */

/**
 * What in `$source` reads election votes other than as a count.
 *
 * @return list<string>
 */
function electionVoteReads(string $source): array
{
    $reads = [];

    if (preg_match('/ElectionVote\b|election_votes/', $source) === 1) {
        $reads[] = 'names the vote model or table';
    }

    // `votes` is also the relation of project proposals; only files about an
    // election are held to it.
    if (preg_match('/Election/', $source) === 1
        && preg_match('/->votes\b(?!\(\)->count\(\))/', $source) === 1) {
        $reads[] = 'reads the votes of a round other than as a count';
    }

    return $reads;
}

/**
 * Every Livewire component and Blade view with what it reads, relative to the
 * base path; files that read nothing are left out.
 *
 * @return array<string, list<string>>
 */
function electionVoteReadersInTheInterface(): array
{
    $files = Finder::create()->files()->in([app_path('Livewire'), resource_path('views')])->name('*.php');

    return collect($files)
        ->mapWithKeys(fn (SplFileInfo $file): array => [str_replace(base_path().'/', '', $file->getPathname()) => electionVoteReads($file->getContents())])
        ->filter()
        ->all();
}

it('lets no Livewire component and no Blade view read election votes other than as a count', function () {
    $scanned = iterator_count(Finder::create()->files()->in([app_path('Livewire'), resource_path('views')])->name('*.php'));

    expect($scanned)->toBeGreaterThan(50)
        ->and(electionVoteReadersInTheInterface())->toBe([]);
});

it('catches a vote read and lets a count through', function (string $source, array $reads) {
    expect(electionVoteReads($source))->toBe($reads);
})->with([
    'the choices of the votes with their members' => [
        '<?php $round = ElectionRound::find($id); foreach ($round->votes as $vote) { echo $vote->pleb->name.$vote->choices; }',
        ['reads the votes of a round other than as a count'],
    ],
    'the vote model' => ['<?php ElectionVote::query()->get();', ['names the vote model or table']],
    'the vote table' => ["<?php DB::table('election_votes')->pluck('choices');", ['names the vote model or table']],
    'the relation queried' => ['<?php /* ElectionRound */ $round->votes()->with(\'pleb\')->get();', ['reads the votes of a round other than as a count']],
    'a count for the board' => ['<?php /* ElectionRound */ $votesCast = $round->votes()->count();', []],
    'the votes of a project proposal' => ['<?php $project->votes()->where(\'value\', true)->count(); $project->votes;', []],
]);
