<?php

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Models\Profile;
use App\Services\Election\CloseRound;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\OpenRound;
use App\Support\NostrAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';

/*
 * The meeting part of the board's election page: opening, closing and
 * annulling rounds, the turnout as a count, the share view, the result, the
 * run-off, the minutes, the test-election purge and the meeting fields that
 * stay editable. Counts only — no screen names a voter.
 */

function livePage(): Testable
{
    return Livewire::test('association.election.admin');
}

/**
 * A board of one, logged in.
 */
function liveBoard(): EinundzwanzigPleb
{
    [$board] = electionBoard(1);
    NostrAuth::login($board->pubkey);

    return $board;
}

/**
 * @return list<string>
 */
function liveAudit(Election $election): array
{
    return ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->pluck('action')->map->value->all();
}

/**
 * A voter who cast a vote in `$round` and then got a profile name — the name
 * that must never appear on a screen.
 */
function liveNamedVoter(ElectionRound $round, EinundzwanzigPleb $voter, array $selection, string $name): void
{
    castElectionBallot($round, $voter, $selection);
    Profile::factory()->create(['pubkey' => $voter->fresh()->pubkey, 'name' => $name]);
}

/**
 * The share view itself, without the frame (the toast region of the layout
 * carries a button of its own).
 */
function liveShareBody(string $html): string
{
    $start = strpos($html, 'data-test="share-screen"');
    $end = strpos($html, '</footer>', (int) $start);

    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($html, $start, $end - $start);
}

it('opens, ends early and annuls rounds through the page, each with its audit line', function () {
    $board = liveBoard();
    $election = Election::factory()->announced()->create();
    $first = draftElectionRound(['position' => 1, 'title' => 'Präsidium'], election: $election);
    $second = draftElectionRound(['position' => 2, 'title' => 'Revisionsstelle'], election: $election);

    $page = livePage()
        ->assertSet('section', 'live')
        ->assertSee('„Präsidium“ öffnen')
        ->call('startRoundAction', $first->id, 'open')
        ->assertSet('pendingRoundAction', 'open')
        ->call('confirmRoundAction')
        ->assertHasNoErrors()
        ->assertSee('„Präsidium“ ist offen.');

    expect($first->fresh()->opened_at)->not->toBeNull();

    $page->call('startRoundAction', $first->id, 'close')
        ->assertSet('roundReason', 'Abstimmung in der Sitzung beendet.')
        ->set('roundReason', '')
        ->call('confirmRoundAction')
        ->assertHasErrors(['roundReason'])
        ->set('roundReason', 'Alle haben abgestimmt')
        ->call('confirmRoundAction')
        ->assertHasNoErrors();

    expect($first->fresh()->closed_at)->not->toBeNull();

    $page->call('startRoundAction', $second->id, 'open')->call('confirmRoundAction')
        ->call('startRoundAction', $second->id, 'annul')
        ->set('roundReason', 'Falsche Kandidatenliste')
        ->call('confirmRoundAction')
        ->assertHasNoErrors()
        ->assertSee('Annulliert um');

    expect($second->fresh()->annulled_at)->not->toBeNull()
        ->and(liveAudit($election))->toBe(['round_opened', 'round_closed_early', 'round_opened', 'round_annulled'])
        ->and(ElectionAuditEntry::query()->pluck('actor_pleb_id')->unique()->values()->all())->toBe([$board->id])
        ->and(ElectionAuditEntry::query()->where('action', 'round_annulled')->sole()->details['reason'])->toBe('Falsche Kandidatenliste');
});

/**
 * One live action, set up so that it WOULD succeed for the board: the page as
 * the board left it, the call that completes the action, and a check that
 * the action left its target untouched. Prepared as the board, so an action
 * that forgot to check its actor would change the database — the 403 of the
 * re-render alone proves nothing, the computed properties throw it anyway.
 *
 * @return array{0: Testable, 1: Closure(Testable): Testable, 2: Closure(): bool}
 */
function liveRefusalScenario(string $kind, EinundzwanzigPleb $board): array
{
    $election = Election::factory()->test()->announced()->create(['call_url' => 'https://meet.example.org/alt']);

    return match ($kind) {
        'open' => (function () use ($election): array {
            $round = draftElectionRound(election: $election);

            return [livePage()->call('startRoundAction', $round->id, 'open'), fn (Testable $page) => $page->call('confirmRoundAction'), fn (): bool => $round->fresh()->opened_at === null];
        })(),
        'close early', 'annul' => (function () use ($election, $kind): array {
            $round = openElectionRound(election: $election);
            $action = $kind === 'annul' ? 'annul' : 'close';

            return [livePage()->call('startRoundAction', $round->id, $action)->set('roundReason', 'Grund'), fn (Testable $page) => $page->call('confirmRoundAction'),
                fn (): bool => $round->fresh()->closed_at === null && $round->fresh()->annulled_at === null];
        })(),
        'seal' => (function () use ($election): array {
            $round = openElectionRound(election: $election);
            Carbon::setTestNow(now()->addMinutes(61));

            return [livePage(), fn (Testable $page) => $page->call('sealRound', $round->id), fn (): bool => $round->fresh()->closed_at === null];
        })(),
        'run-off' => (function () use ($election, $board): array {
            $round = openElectionRound(['counting_rule' => ElectionCountingRule::YesNoPerCandidate], election: $election);
            [$a, $b] = $round->candidates()->pluck('id')->all();
            castElectionBallot($round, $board, [$a => 'yes', $b => 'yes']);
            app(ElectionStewardship::class)->closeEarly($round, $board, 'Fertig');

            return [livePage(), fn (Testable $page) => $page->call('createRunOff', $round->id), fn (): bool => ! ElectionRound::query()->where('parent_round_id', $round->id)->exists()];
        })(),
        'repeat' => (function () use ($election, $board): array {
            $round = app(ElectionStewardship::class)->annul(openElectionRound(election: $election), $board, 'Falsche Liste');

            return [livePage()->call('startRoundAction', $round->id, 'repeat'), fn (Testable $page) => $page->call('confirmRoundAction'),
                fn (): bool => ! ElectionRound::query()->where('election_event_id', $election->id)->whereNull('opened_at')->exists()];
        })(),
        'meeting' => (function () use ($election): array {
            openElectionRound(election: $election);

            return [livePage()->set('form.call_url', 'https://meet.example.org/neu'), fn (Testable $page) => $page->call('saveMeeting'),
                fn (): bool => $election->fresh()->call_url === 'https://meet.example.org/alt'];
        })(),
        'purge' => (function () use ($election): array {
            draftElectionRound(election: $election);

            return [livePage(), fn (Testable $page) => $page->call('purgeTestElection'), fn (): bool => Election::query()->whereKey($election->id)->exists()];
        })(),
    };
}

it('refuses each live action itself once the session belongs to a member, and writes nothing', function (string $kind) {
    [$board] = electionBoard(1);
    $member = electionVoter();
    NostrAuth::login($board->pubkey);

    [$page, $act, $untouched] = liveRefusalScenario($kind, $board);
    $audit = ElectionAuditEntry::query()->count();
    $rounds = ElectionRound::query()->count();

    NostrAuth::login($member->pubkey);
    $act($page)->assertForbidden();

    expect($untouched())->toBeTrue()
        ->and(ElectionAuditEntry::query()->count())->toBe($audit)
        ->and(ElectionRound::query()->count())->toBe($rounds);
})->with(['open', 'close early', 'annul', 'seal', 'run-off', 'repeat', 'meeting', 'purge']);

it('asks before a round action, and the question itself is board-only', function () {
    [$board] = electionBoard(1);
    $round = openElectionRound(election: Election::factory()->test()->create());
    NostrAuth::login($board->pubkey);
    $page = livePage();
    NostrAuth::login(electionVoter()->pubkey);

    $page->call('startRoundAction', $round->id, 'close')->assertForbidden();

    expect(ElectionAuditEntry::query()->count())->toBe(0);
});

it('shows the turnout as a count and never a voter', function () {
    liveBoard();
    $election = Election::factory()->announced()->create();
    $voters = [electionVoter(), electionVoter()];
    $round = openElectionRound(election: $election);
    $candidate = $round->candidates()->value('id');

    liveNamedVoter($round, $voters[0], [$candidate => 'for'], 'Geheime Wählerin');

    // The board of one and both voters are on the roll.
    Livewire::test('association.election.turnout', ['roundId' => $round->id])
        ->assertSee('1 von 3')
        ->assertSee('hat abgestimmt')
        ->assertSeeHtml('wire:poll.3s')
        ->assertDontSee('Geheime Wählerin');

    castElectionBallot($round, $voters[1]);

    Livewire::test('association.election.turnout', ['roundId' => $round->id])->assertSee('2 von 3')->assertSee('haben abgestimmt');

    livePage()->assertSee('Offen seit')->assertDontSee('Geheime Wählerin');
});

it('serves the share view to the board only, without controls and without voters', function () {
    [$board] = electionBoard(1);
    $member = electionVoter();
    $election = Election::factory()->test()->announced()->create(['name' => 'Probe am Abend']);
    $round = openElectionRound(['title' => 'Präsidium'], election: $election);
    $candidate = $round->candidates()->with('pleb')->first();
    Profile::factory()->create(['pubkey' => $candidate->pleb->pubkey, 'name' => 'Kandidatin Anna']);
    liveNamedVoter($round, $member, [$candidate->id => 'for'], 'Geheime Wählerin');
    $url = route('association.election.admin.screen', $election);

    $this->get($url)->assertForbidden();

    NostrAuth::login($member->fresh()->pubkey);
    $this->get($url)->assertForbidden();

    NostrAuth::login($board->pubkey);
    $open = $this->get($url)->assertOk()
        ->assertSee('Präsidium')
        ->assertSee('Probewahl')
        ->assertSee('Offen. Stimmt jetzt auf der Wahlseite ab.')
        ->assertSee('von 2')
        ->assertDontSee('Geheime Wählerin');

    expect(liveShareBody($open->getContent()))->not->toContain('wire:click')->not->toContain('<button')->not->toContain('<form')->not->toContain('href=');

    app(ElectionStewardship::class)->closeEarly($round, $board, 'Fertig');

    $sealed = $this->get($url)->assertOk()
        ->assertSee('Ausgezählt um')
        ->assertSee('Gewählt ist Kandidatin Anna.')
        ->assertSee('1 Stimme')
        ->assertSee('1 von 2 haben abgestimmt', false)
        ->assertDontSee('Geheime Wählerin');

    expect(liveShareBody($sealed->getContent()))->not->toContain('wire:click')->not->toContain('<button')->not->toContain('<form')->not->toContain('href=');
});

it('shows the result after the seal on the board page', function () {
    $board = liveBoard();
    $election = Election::factory()->announced()->create();
    $voters = [electionVoter(), electionVoter(), electionVoter()];
    $round = openElectionRound(['title' => 'Präsidium'], election: $election);
    [$a, $b] = $round->candidates()->with('pleb')->get()->all();
    Profile::factory()->create(['pubkey' => $a->pleb->pubkey, 'name' => 'Anna Vorn']);
    Profile::factory()->create(['pubkey' => $b->pleb->pubkey, 'name' => 'Ben Hinten']);

    castElectionBallot($round, $voters[0], [$a->id => 'for']);
    castElectionBallot($round, $voters[1], [$a->id => 'for']);
    castElectionBallot($round, $voters[2], [$b->id => 'for']);

    livePage()->assertDontSee('Gewählt ist Anna Vorn.');

    app(ElectionStewardship::class)->closeEarly($round, $board, 'Fertig');

    livePage()
        ->assertSee('Gewählt ist Anna Vorn.')
        ->assertSee('2 Stimmen')
        ->assertSee('3 von 4 haben abgestimmt, davon 0 mit Enthaltung.')
        ->assertSee(ElectionManifest::query()->where('election_round_id', $round->id)->value('hash'));
});

it('offers a run-off on a tie and sets it up right after its round', function () {
    $board = liveBoard();
    $election = Election::factory()->announced()->create();
    $voters = [electionVoter(), electionVoter()];
    $round = openElectionRound(['title' => 'Vorstand', 'position' => 1, 'counting_rule' => ElectionCountingRule::YesNoPerCandidate], election: $election);
    $later = draftElectionRound(['title' => 'Revisionsstelle', 'position' => 2], election: $election);
    [$a, $b] = $round->candidates()->pluck('id')->all();

    foreach ($voters as $voter) {
        castElectionBallot($round, $voter, [$a => 'yes', $b => 'yes']);
    }

    app(ElectionStewardship::class)->closeEarly($round, $board, 'Fertig');

    $page = livePage()
        ->assertSee('Gleichstand um einen Sitz.')
        ->assertSee('Stichwahl anlegen')
        ->call('createRunOff', $round->id);

    $runOff = ElectionRound::query()->where('parent_round_id', $round->id)->sole();

    expect($runOff->title)->toBe('Stichwahl Vorstand')
        ->and($runOff->counting_rule)->toBe(ElectionCountingRule::MostVotes)
        ->and($runOff->seats)->toBe(1)
        ->and($runOff->position)->toBe(2)
        ->and($later->fresh()->position)->toBe(3)
        ->and($runOff->candidates()->where('status', ElectionCandidateStatus::Approved)->pluck('einundzwanzig_pleb_id')->sort()->values()->all())
        ->toBe($round->candidates()->pluck('einundzwanzig_pleb_id')->sort()->values()->all())
        ->and(liveAudit($election))->toBe(['round_closed_early', 'run_off_created']);

    $page->assertSee('„Stichwahl Vorstand“ öffnen')
        ->call('createRunOff', $round->id);

    expect(ElectionRound::query()->where('parent_round_id', $round->id)->count())->toBe(1);

    $page->call('startRoundAction', $runOff->id, 'open')->call('confirmRoundAction')->assertHasNoErrors();

    expect($runOff->fresh()->opened_at)->not->toBeNull();
});

it('writes the minutes with the audit check and the manifest hash, as page and as plain text', function () {
    $board = liveBoard();
    $election = Election::factory()->test()->announced()->create(['name' => 'Probe Protokoll']);
    $voter = electionVoter();
    $round = openElectionRound(['title' => 'Präsidium'], election: $election);
    liveNamedVoter($round, $voter, [$round->candidates()->value('id') => 'for'], 'Geheime Wählerin');
    app(ElectionStewardship::class)->closeEarly($round, $board, 'Alle haben abgestimmt');
    $hash = ElectionManifest::query()->where('election_round_id', $round->id)->value('hash');
    $url = route('association.election.admin.protocol', $election);

    NostrAuth::login($voter->fresh()->pubkey);
    $this->get($url)->assertForbidden();

    NostrAuth::login($board->pubkey);
    $this->get($url)->assertOk()
        ->assertSee('Probewahl, keine echte Wahl')
        ->assertSee('Protokollkette unversehrt, 1 Einträge.')
        ->assertSee($hash)
        ->assertSee('stimmt mit den gespeicherten Stimmen überein.')
        ->assertSee('Grund: Alle haben abgestimmt')
        ->assertDontSee('Geheime Wählerin');

    $text = Livewire::test('association.election.protocol', ['election' => $election])->instance()->plainText;

    expect($text)->toContain('PROBEWAHL')
        ->toContain('Protokollkette: unversehrt')
        ->toContain('Prüfsumme (SHA-256): '.$hash)
        ->not->toContain('Geheime Wählerin');

    DB::table('election_audit_entries')->where('election_event_id', $election->id)->update(['previous_hash' => str_repeat('f', 64)]);

    $this->get($url)->assertSee('Protokollkette beschädigt');
});

it('purges a test election with every row, and refuses a real one', function () {
    $board = liveBoard();
    $real = Election::factory()->announced()->create(['name' => 'Echte Wahl']);
    $realRound = openElectionRound(election: $real);
    $test = Election::factory()->test()->announced()->create(['name' => 'Probewahl Abend']);
    $testRound = openElectionRound(election: $test);
    castElectionBallot($testRound, $board);
    app(ElectionStewardship::class)->closeEarly($testRound, $board, 'Fertig');

    livePage()->call('selectElection', $real->id)
        ->assertDontSeeHtml('data-test="purge-election"')
        ->call('purgeTestElection');

    expect(Election::query()->whereKey($real->id)->exists())->toBeTrue()
        ->and(ElectionRound::query()->whereKey($realRound->id)->exists())->toBeTrue();

    $screen = Livewire::test('association.election.screen', ['election' => $test->id])->assertSeeHtml('wire:poll');

    livePage()->call('selectElection', $test->id)
        ->assertSeeHtml('data-test="live-purge-election"')
        ->call('purgeTestElection')
        ->assertSet('selectedElectionId', $real->id);

    expect(Election::query()->whereKey($test->id)->exists())->toBeFalse()
        ->and(DB::table('election_rounds')->where('election_event_id', $test->id)->count())->toBe(0)
        ->and(DB::table('election_votes')->where('election_round_id', $testRound->id)->count())->toBe(0)
        ->and(DB::table('election_audit_entries')->where('election_event_id', $test->id)->count())->toBe(0);
    // The share view still open in the call says so and stops polling, instead of a 404 on every poll.
    $screen->call('$refresh')->assertOk()->assertSee('Diese Wahl gibt es nicht mehr.')->assertDontSeeHtml('wire:poll');
});

it('keeps the meeting time and the call link editable after a round opened, and nothing else', function () {
    $board = liveBoard();
    $election = Election::factory()->announced()->create(['name' => 'Fest', 'meeting_at' => '2027-03-20 18:00:00', 'call_url' => 'https://meet.example.org/alt']);
    openElectionRound(election: $election);

    livePage()
        ->call('showSection', 'settings')
        ->assertSee('Termin und Link speichern')
        ->set('form.meeting_time', '20:15')
        ->set('form.call_url', 'https://meet.example.org/neu')
        ->call('saveMeeting')
        ->assertHasNoErrors()
        ->set('form.call_url', 'kein-link')
        ->call('saveMeeting')
        ->assertHasErrors(['form.call_url'])
        ->set('form.call_url', 'https://meet.example.org/neu')
        ->set('form.name', 'Umbenannt')
        ->call('save')
        ->assertHasErrors(['form']);

    $election->refresh();

    expect($election->call_url)->toBe('https://meet.example.org/neu')
        ->and($election->meeting_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-20 19:15')
        ->and($election->name)->toBe('Fest')
        ->and(liveAudit($election))->toBe(['election_meeting_updated'])
        ->and(ElectionAuditEntry::query()->value('actor_pleb_id'))->toBe($board->id);
});

it('shows and reads every time in German time while storing UTC', function () {
    liveBoard();
    // 20 March 2027 is winter time (UTC+1), 20 June summer time (UTC+2).
    $winter = Election::factory()->create(['name' => 'Winter', 'meeting_at' => Carbon::parse('2027-03-20 18:30', 'UTC')]);
    draftElectionRound(election: $winter);

    livePage()->call('selectElection', $winter->id)
        ->assertSee('Sa, 20. März 2027, 19:30 Uhr')
        ->assertSet('form.meeting_time', '19:30')
        ->set('form.meeting_date', '2027-06-20')
        ->set('form.meeting_time', '19:30')
        ->call('save')
        ->assertHasNoErrors();

    expect($winter->fresh()->meeting_at->utc()->format('Y-m-d H:i'))->toBe('2027-06-20 17:30');
});

it('sends an election without rounds to its settings, and a finished one to the minutes', function () {
    $board = liveBoard();
    $empty = Election::factory()->announced()->create(['name' => 'Ohne Wahlgang']);

    livePage()->call('selectElection', $empty->id)
        ->assertSee('Diese Wahl hat noch keinen Wahlgang.')
        ->assertDontSee('Alle Wahlgänge sind abgeschlossen.');

    $done = Election::factory()->test()->announced()->create(['name' => 'Fertig']);
    $round = openElectionRound(election: $done);
    app(ElectionStewardship::class)->closeEarly($round, $board, 'Fertig');

    livePage()->call('selectElection', $done->id)
        ->assertSee('Alle Wahlgänge sind abgeschlossen.')
        ->assertSeeHtml(route('association.election.admin.protocol', $done));
});

it('stores 19:00 entered by the board as 18:00 UTC in March and shows 19:00 on both pages', function () {
    $board = liveBoard();
    $election = Election::factory()->announced()->create(['name' => 'Märzsitzung']);
    draftElectionRound(election: $election);

    livePage()->call('selectElection', $election->id)
        ->call('showSection', 'settings')
        ->set('form.meeting_date', '2027-03-20')
        ->set('form.meeting_time', '19:00')
        ->call('save')
        ->assertHasNoErrors();

    expect($election->fresh()->meeting_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-20 18:00');

    livePage()->call('selectElection', $election->id)->assertSee('20. März 2027, 19:00 Uhr')->assertSet('form.meeting_time', '19:00');

    NostrAuth::login(electionVoter()->pubkey);
    Livewire::test('association.election.index')->assertSee('Am 20.03.2027 um 19:00 Uhr')->assertDontSee('um 18:00 Uhr');
});

/**
 * Two live rounds that share a candidate: "Vorsitz" (one seat, most votes,
 * A against B — so 2:2 is a tie) and "Kasse" (B against C), with four voters
 * on the roll.
 *
 * @return array{0: Election, 1: ElectionRound, 2: ElectionRound, 3: list<EinundzwanzigPleb>, 4: array{a: int, b: int}}
 */
function liveSharedCandidateEvening(): array
{
    $election = Election::factory()->announced()->create();
    $voters = [electionVoter(), electionVoter(), electionVoter(), electionVoter()];
    $presidency = draftElectionRound(['title' => 'Vorsitz', 'position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], election: $election);
    $treasury = draftElectionRound(['title' => 'Kasse', 'position' => 2], candidates: 1, election: $election);
    [$a, $b] = $presidency->candidates()->orderBy('id')->get()->all();
    ElectionCandidate::factory()->create(['election_round_id' => $treasury->id, 'einundzwanzig_pleb_id' => $b->einundzwanzig_pleb_id]);

    return [$election, $presidency, $treasury, $voters, ['a' => (int) $a->id, 'b' => (int) $b->id]];
}

/**
 * Open `$round` on the page, cast one ballot per entry of `$ballots` (voter
 * index => candidate id) and close it on the page.
 *
 * @param  list<EinundzwanzigPleb>  $voters
 * @param  array<int, int>  $ballots
 */
function liveVoteRound(Testable $page, ElectionRound $round, array $voters, array $ballots): Testable
{
    $page->call('startRoundAction', $round->id, 'open')->call('confirmRoundAction')->assertHasNoErrors();

    foreach ($ballots as $index => $candidateId) {
        castElectionBallot($round->fresh(), $voters[$index], [$candidateId => 'for']);
    }

    return $page->call('refreshRounds')
        ->call('startRoundAction', $round->id, 'close')
        ->call('confirmRoundAction')
        ->assertHasNoErrors();
}

it('opens the next round once a run-off has settled the tie of a candidate who stands in it', function () {
    liveBoard();
    [, $presidency, $treasury, $voters, $ids] = liveSharedCandidateEvening();

    $page = liveVoteRound(livePage(), $presidency, $voters, [$ids['a'], $ids['a'], $ids['b'], $ids['b']])
        ->assertSee('„Vorsitz“ ist ohne Entscheidung ausgezählt.')
        ->call('createRunOff', $presidency->id);

    $runOff = ElectionRound::query()->where('parent_round_id', $presidency->id)->sole();
    $runOffA = (int) $runOff->candidates()
        ->where('einundzwanzig_pleb_id', ElectionCandidate::query()->findOrFail($ids['a'])->einundzwanzig_pleb_id)
        ->value('id');

    liveVoteRound($page, $runOff, $voters, [$runOffA, $runOffA, $runOffA])
        ->assertSee('„Kasse“ öffnen')
        ->call('startRoundAction', $treasury->id, 'open')
        ->call('confirmRoundAction')
        ->assertHasNoErrors();

    expect($treasury->fresh()->opened_at)->not->toBeNull()
        ->and(liveAudit($treasury->election))->toBe([
            'round_opened', 'round_closed_early', 'run_off_created', 'round_opened', 'round_closed_early', 'round_opened',
        ]);
});

it('says in German which earlier result keeps the next round shut', function (string $earlier, string $sentence) {
    liveBoard();
    [, $presidency, $treasury, $voters, $ids] = liveSharedCandidateEvening();
    $page = livePage();

    match ($earlier) {
        'tie' => liveVoteRound($page, $presidency, $voters, [$ids['a'], $ids['a'], $ids['b'], $ids['b']]),
        'elected' => liveVoteRound($page, $presidency, $voters, [$ids['b'], $ids['b'], $ids['b'], $ids['a']]),
        'not counted' => null,
    };

    $page->call('startRoundAction', $treasury->id, 'open')
        ->call('confirmRoundAction')
        ->assertHasErrors(['roundReason'])
        ->assertSee($sentence);

    expect($treasury->fresh()->opened_at)->toBeNull();
})->with([
    'tie without run-off' => ['tie', 'steht in einem früheren Wahlgang im Gleichstand, und dafür ist noch keine Stichwahl ausgezählt'],
    'elected earlier' => ['elected', 'ist in einem früheren Wahlgang schon gewählt'],
    'earlier not counted' => ['not counted', 'kandidiert auch in einem früheren Wahlgang, der noch nicht ausgezählt ist. Zählt ihn zuerst aus'],
]);

it('repeats an annulled round from the page right after it, and then offers opening the repeat', function () {
    $board = liveBoard();
    $election = Election::factory()->announced()->create();
    $presidency = draftElectionRound(['title' => 'Vorsitz', 'position' => 1], election: $election);
    $treasury = draftElectionRound(['title' => 'Kasse', 'position' => 2], election: $election);

    $page = livePage()
        ->call('startRoundAction', $presidency->id, 'open')->call('confirmRoundAction')
        ->call('startRoundAction', $presidency->id, 'annul')->set('roundReason', 'Falsche Liste')->call('confirmRoundAction')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="repeat-round-'.$presidency->id.'"')
        ->call('startRoundAction', $presidency->id, 'repeat')
        ->assertSet('pendingRoundAction', 'repeat')
        ->assertSee('„Vorsitz“ wiederholen?')
        ->assertSee('Legt „Vorsitz (Wiederholung)“ als neuen Wahlgang an')
        ->call('confirmRoundAction')
        ->assertHasNoErrors()
        ->assertSee('„Vorsitz (Wiederholung)“ öffnen')
        ->assertDontSeeHtml('data-test="repeat-round-'.$presidency->id.'"');

    $repeat = ElectionRound::query()->where('title', 'Vorsitz (Wiederholung)')->sole();

    expect($repeat->position)->toBe(2)
        ->and($treasury->fresh()->position)->toBe(3)
        ->and(liveAudit($election))->toBe(['round_opened', 'round_annulled', 'round_repeated'])
        ->and(ElectionAuditEntry::query()->where('action', 'round_repeated')->sole()->actor_pleb_id)->toBe($board->id);

    // A second repeat of the same round is refused, and writes nothing.
    $page->call('startRoundAction', $presidency->id, 'repeat')
        ->call('confirmRoundAction')
        ->assertHasErrors(['roundReason'])
        ->assertSee('„Vorsitz“ ist schon wiederholt oder nicht annulliert.');

    expect(ElectionRound::query()->where('election_event_id', $election->id)->count())->toBe(3)
        ->and(liveAudit($election))->toBe(['round_opened', 'round_annulled', 'round_repeated']);

    $page->call('startRoundAction', $repeat->id, 'open')->call('confirmRoundAction')->assertHasNoErrors();

    expect($repeat->fresh()->opened_at)->not->toBeNull();

    $this->get(route('association.election.admin.protocol', $election))->assertOk()->assertSee('Wahlgang zur Wiederholung angelegt');
});

it('never calls the evening finished while an annulled round has no repeat', function () {
    liveBoard();
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(['title' => 'Vorsitz'], election: $election);

    livePage()
        ->call('startRoundAction', $round->id, 'open')->call('confirmRoundAction')
        ->call('startRoundAction', $round->id, 'annul')->set('roundReason', 'Falsche Liste')->call('confirmRoundAction')
        ->assertSee('„Vorsitz“ ist annulliert und noch nicht wiederholt.')
        ->assertDontSee('Alle Wahlgänge sind abgeschlossen.')
        ->assertSeeHtml("startRoundAction({$round->id}, &#039;repeat&#039;)");
});

it('keeps every other round shut while one is open, on the page and in the action', function () {
    liveBoard();
    $election = Election::factory()->announced()->create();
    $first = draftElectionRound(['title' => 'Vorsitz', 'position' => 1], election: $election);
    $second = draftElectionRound(['title' => 'Kasse', 'position' => 2], election: $election);

    $page = livePage()
        ->assertSeeHtml('data-test="open-round-'.$second->id.'"')
        ->call('startRoundAction', $first->id, 'open')->call('confirmRoundAction')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="open-round-'.$second->id.'"')
        ->assertSee('Öffnen geht, sobald „Vorsitz“ geschlossen ist.')
        ->call('startRoundAction', $second->id, 'open')
        ->call('confirmRoundAction')
        ->assertHasErrors(['roundReason'])
        ->assertSee('„Vorsitz“ ist noch offen. Es ist immer nur ein Wahlgang offen');

    expect($second->fresh()->opened_at)->toBeNull()
        ->and(liveAudit($election))->toBe(['round_opened']);

    $page->call('startRoundAction', $first->id, 'close')->call('confirmRoundAction')
        ->assertSeeHtml('data-test="open-round-'.$second->id.'"')
        ->call('startRoundAction', $second->id, 'open')->call('confirmRoundAction')
        ->assertHasNoErrors();

    expect($second->fresh()->opened_at)->not->toBeNull();
});

it('offers the run-off of a tie once, in the next-step card', function () {
    $board = liveBoard();
    $election = Election::factory()->announced()->create();
    $voter = electionVoter();
    $round = openElectionRound(['title' => 'Vorstand', 'counting_rule' => ElectionCountingRule::YesNoPerCandidate], election: $election);
    [$a, $b] = $round->candidates()->pluck('id')->all();
    castElectionBallot($round, $voter, [$a => 'yes', $b => 'yes']);
    app(ElectionStewardship::class)->closeEarly($round, $board, 'Fertig');

    $html = livePage()->assertSee('Gleichstand um einen Sitz.')->html();
    // From the next-step card to the round list of the meeting.
    $cardStart = (int) strpos($html, 'data-test="next-step"');
    $card = substr($html, $cardStart, (int) strpos($html, 'data-test="live"') - $cardStart);

    expect($cardStart)->toBeGreaterThan(0)
        ->and(substr_count($html, 'Stichwahl anlegen'))->toBe(1)
        ->and($card)->toContain('Stichwahl anlegen')
        ->and($card)->toContain("createRunOff({$round->id})")
        ->and($html)->not->toContain('data-test="run-off-'.$round->id.'"');
});

/*
 * Annulled means void on every screen: a run-off annulled along with its round
 * before it was opened is never offered as the next step and is never shown
 * as opened. Mutations that must turn this red: let the next-step card pick
 * an annulled draft; render every annulled round as "Geöffnet …".
 */
it('treats a run-off annulled along with its round as void on the card, the live list and the minutes', function () {
    $board = liveBoard();
    $election = Election::factory()->announced()->create();
    $voters = [electionVoter(), electionVoter()];
    $chair = draftElectionRound(['title' => 'Vorsitz', 'position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes], election: $election);
    [$first, $second] = $chair->candidates()->orderBy('id')->pluck('id')->all();

    $chair = app(OpenRound::class)->handle($chair);
    castElectionBallot($chair, $voters[0], [$first => 'for']);
    castElectionBallot($chair, $voters[1], [$second => 'for']);
    $chair = app(CloseRound::class)->handle($chair);
    $runOff = app(CreateRunOff::class)->handle($chair, $board);

    $page = livePage()
        ->assertSee('„'.$runOff->title.'“ öffnen')
        ->call('startRoundAction', $chair->id, 'annul')->set('roundReason', 'Falsche Liste')->call('confirmRoundAction')
        ->assertHasNoErrors()
        ->assertSee('„Vorsitz“ ist annulliert und noch nicht wiederholt.')
        ->assertDontSee('„'.$runOff->title.'“ öffnen')
        ->assertSee('Nicht geöffnet, annulliert.')
        ->assertSee('Grund: Mit „Vorsitz“ annulliert');

    expect($runOff->fresh()->annulled_at)->not->toBeNull();

    $this->get(route('association.election.admin.protocol', $election))->assertOk()
        ->assertSee('Nicht geöffnet, annulliert')
        ->assertDontSee('Geöffnet , annulliert');

    $page->call('startRoundAction', $chair->id, 'repeat')->call('confirmRoundAction')
        ->assertHasNoErrors()
        ->assertSee('„Vorsitz (Wiederholung)“ öffnen');
});

/*
 * After the board annuls a round whose run-off was already held, the card and
 * the share view name the round the board annulled, not the run-off taken
 * along. Mutation that must turn this red: sort by `closed_at` first again.
 */
it('names the annulled round, not its run-off, as the last one on the card and the share view', function () {
    $board = liveBoard();
    $election = Election::factory()->announced()->create();
    $voters = [electionVoter(), electionVoter()];
    $chair = draftElectionRound(['title' => 'Vorsitz', 'position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes], election: $election);
    draftElectionRound(['title' => 'Kasse', 'position' => 2], election: $election);

    $level = function (ElectionRound $round) use ($voters): ElectionRound {
        $round = app(OpenRound::class)->handle($round);
        $ids = $round->candidates()->orderBy('id')->pluck('id')->all();
        castElectionBallot($round, $voters[0], [$ids[0] => 'for']);
        castElectionBallot($round, $voters[1], [$ids[1] => 'for']);

        return app(CloseRound::class)->handle($round);
    };

    $chair = $level($chair);
    $this->travel(5)->minutes();
    $level(app(CreateRunOff::class)->handle($chair, $board));
    $this->travel(5)->minutes();

    // The cascade crosses a second boundary right after the chair is stamped.
    ElectionRound::saved(function (ElectionRound $round) use ($chair): void {
        if ($round->is($chair) && $round->wasChanged('annulled_at')) {
            $this->travel(1)->seconds();
        }
    });

    livePage()
        ->call('startRoundAction', $chair->id, 'annul')->set('roundReason', 'Falsche Liste')->call('confirmRoundAction')
        ->assertHasNoErrors()
        ->assertSee('„Vorsitz“ ist annulliert. Als Nächstes: „Kasse“.');

    expect(Livewire::test('association.election.screen', ['election' => $election->id])->instance()->current['report']['round']->title)->toBe('Vorsitz');
});
