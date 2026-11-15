<?php

use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Models\Profile;
use App\Services\Election\CastBallot;
use App\Services\Election\CloseRound;
use App\Services\Election\CreateRunOff;
use App\Services\Election\OpenRound;
use App\Services\Election\VoteEvent;
use App\Services\Nostr\EventSigning;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../Services/Election/helpers.php';

/*
 * The live ballot and the result on the member election page
 * (resources/views/livewire/association/election/index.blade.php).
 * Ballots are signed with the throwaway keys of the election helpers and
 * cast through the page's own methods.
 */

if (! function_exists('primaryButtons')) {
    /**
     * How many elements carry the primary button variant (also in IndexTest).
     */
    function primaryButtons(Testable $page): int
    {
        return preg_match_all('/<(?:a|button)\b[^>]*class="[^"]*bg-orange-primary[^"]*"/', $page->html());
    }
}

function ballotPage(EinundzwanzigPleb $viewer): Testable
{
    // The member's key is made up here, before the page reads their pubkey.
    electionSecretFor($viewer);
    NostrAuth::login($viewer->pubkey);

    return Livewire::test('association.election.index');
}

/**
 * A draft round in an announced election, with named candidates.
 *
 * @param  list<string>  $names
 */
function namedDraftRound(array $names = ['Alice Ambrosius', 'Bob Brenner'], ElectionCountingRule $rule = ElectionCountingRule::SingleMajority, int $seats = 1, ?Election $election = null, string $title = 'Präsidium'): ElectionRound
{
    $election ??= Election::factory()->announced()->create(['name' => 'Vorstandswahl 2027']);
    $round = ElectionRound::factory()->countingRule($rule, $seats)->create(['election_event_id' => $election->id, 'title' => $title]);

    foreach ($names as $name) {
        $pleb = EinundzwanzigPleb::factory()->active()->create();
        Profile::factory()->create(['pubkey' => $pleb->pubkey, 'name' => $name]);
        ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $pleb->id]);
    }

    return $round->fresh();
}

function candidateIdOf(ElectionRound $round, string $name): int
{
    return (int) $round->candidates()->get()->first(fn (ElectionCandidate $candidate): bool => $candidate->pleb->profile?->name === $name)->id;
}

it('shows the ballot only for an open round and a member on its roll', function () {
    $round = namedDraftRound();
    $member = electionVoter();

    ballotPage($member)->assertDontSee('Stimmzettel')->assertDontSee('Signieren und abgeben');

    app(OpenRound::class)->handle($round);
    $lateComer = electionVoter();
    $unpaid = EinundzwanzigPleb::factory()->active()->create();

    $page = ballotPage($member)
        ->assertSee('Stimmzettel')
        ->assertSee('Alice Ambrosius')
        ->assertSee('Leer abgeben, für keine Person')
        ->assertSee('Prüfen');

    expect($page->instance()->primaryAction)->toBe('ballot')
        ->and(primaryButtons($page))->toBeGreaterThanOrEqual(1);

    ballotPage($lateComer)
        ->assertDontSee('Leer abgeben, für keine Person')
        ->assertSee('Du stehst für diesen Wahlgang nicht im Wählerverzeichnis.');

    ballotPage($unpaid)->assertDontSee('Leer abgeben, für keine Person');
});

it('offers the input the counting rule asks for', function (ElectionCountingRule $rule, int $seats, string $instruction) {
    $round = namedDraftRound(['Alice Ambrosius', 'Bob Brenner', 'Carla Conrad'], $rule, $seats);
    $member = electionVoter();
    app(OpenRound::class)->handle($round);

    ballotPage($member)->assertSee($instruction);
})->with([
    'one of them' => [ElectionCountingRule::SingleMajority, 1, 'Kreuze eine Person an. Du kannst den Stimmzettel auch leer abgeben.'],
    'up to two' => [ElectionCountingRule::MostVotes, 2, 'Kreuze höchstens 2 Personen an.'],
    'yes, no or abstain' => [ElectionCountingRule::YesNoPerCandidate, 2, 'Kreuze bei jeder Person Ja, Nein oder Enthaltung an.'],
]);

it('prepares the event to sign with a readable summary and the server clock', function () {
    $round = namedDraftRound(['Alice Ambrosius', 'Bob Brenner'], ElectionCountingRule::YesNoPerCandidate, 2);
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $alice = candidateIdOf($round, 'Alice Ambrosius');

    $page = ballotPage($member);
    $answer = $page->instance()->prepareBallot($round->id, [(string) $alice => 'yes']);

    expect($answer['event']['kind'])->toBe(VoteEvent::KIND)
        ->and($answer['event']['created_at'])->toBe(now()->getTimestamp())
        ->and($answer['event']['tags'])->toBe([['election', (string) $round->election_event_id], ['round', (string) $round->id], ['vote', (string) $alice, 'yes']])
        ->and($answer['event']['content'])->toBe("Meine Stimme bei „Vorstandswahl 2027“\nWahlgang: Präsidium\nJa: Alice Ambrosius\nEnthaltung: Bob Brenner");

    expect($page->instance()->prepareBallot($round->id, [])['event']['content'])->toEndWith("\nEnthaltung (leer)");
});

it('refuses to prepare a ballot that does not fit the rule', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $ids = $round->candidates()->pluck('id')->all();

    $answer = ballotPage($member)->instance()->prepareBallot($round->id, [$ids[0] => 'for', $ids[1] => 'for']);

    expect($answer)->toBe(['message' => 'Dieser Stimmzettel ist nicht gültig ausgefüllt.']);
});

it('casts a signed vote and says it is counted, with the short event id', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $page = ballotPage($member);
    $event = signedElectionVote($round, $member, [candidateIdOf($round, 'Alice Ambrosius') => 'for']);

    $page->call('castBallot', $round->id, $event)
        ->assertReturned(['status' => 'counted'])
        ->assertSee('Deine Stimme ist gezählt.')
        ->assertSee(substr($event['id'], 0, 8).'…'.substr($event['id'], -8))
        ->assertDontSee('Leer abgeben, für keine Person');

    expect(electionVoteCount($round))->toBe(1)
        ->and($page->instance()->primaryAction)->toBeNull();
});

it('keeps neither the choice nor the event in the component state after a cast', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $page = ballotPage($member);
    $event = signedElectionVote($round, $member, [candidateIdOf($round, 'Bob Brenner') => 'for']);

    $page->call('castBallot', $round->id, $event);

    $snapshot = json_encode($page->snapshot);

    expect($snapshot)->not->toContain($event['sig'])
        ->and($snapshot)->not->toContain($event['id'])
        ->and($snapshot)->not->toContain('"for"');
});

it('shows a member who already voted that their vote is counted', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $event = castElectionBallot($round, $member);

    ballotPage($member)
        ->assertSee('Du hast in diesem Wahlgang schon gewählt. Deine Stimme ist gezählt.')
        ->assertSee(substr($event['id'], 0, 8))
        ->assertDontSee('Leer abgeben, für keine Person');
});

it('treats a retry of the same signed vote as counted, and a second vote as already voted', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $event = castElectionBallot($round, $member);

    // The answer of the first cast got lost; the browser sends the same event again.
    ballotPage($member)->call('castBallot', $round->id, $event)
        ->assertReturned(['status' => 'counted'])
        ->assertSee('Deine Stimme war schon angekommen. Sie ist gezählt.');

    $this->travel(2)->seconds();
    $second = signedElectionVote($round, $member, [candidateIdOf($round, 'Bob Brenner') => 'for']);

    ballotPage($member)->call('castBallot', $round->id, $second)
        ->assertReturned(['status' => 'voted'])
        ->assertSee('Du hast in diesem Wahlgang schon gewählt.');

    expect(electionVoteCount($round))->toBe(1);
});

it('asks after a lost answer whether the vote arrived', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $event = signedElectionVote($round, $member);

    $page = ballotPage($member);

    expect($page->instance()->checkBallot($round->id, $event['id']))->toBe('none');

    app(CastBallot::class)->handle($round, $member, $event);

    $page->call('checkBallot', $round->id, $event['id'])
        ->assertReturned('counted')
        ->assertSee('Deine Stimme war schon angekommen. Sie ist gezählt.');
});

it('tells the member in German why a vote was refused', function (ElectionBallotRefusal $reason) {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $page = ballotPage($member);
    $now = now()->getTimestamp();

    $event = match ($reason) {
        ElectionBallotRefusal::InvalidSignature => [...signedElectionVote($round, $member), 'sig' => str_repeat('0', 128)],
        ElectionBallotRefusal::ForeignSigner => EventSigning::sign(EventSigning::freshSecret(), VoteEvent::KIND, VoteEvent::template($round, [], $now)['tags'], '', $now),
        ElectionBallotRefusal::WrongKind => signedElectionVote($round, $member, [], ['kind' => 1]),
        ElectionBallotRefusal::WrongRound => signedElectionVote($round, $member, [], ['tags' => [['election', (string) $round->election_event_id], ['round', '999999']]]),
        ElectionBallotRefusal::OutsideOpenPeriod => signedElectionVote($round, $member, [], ['created_at' => now()->subHours(3)->getTimestamp()]),
        ElectionBallotRefusal::InvalidChoices => signedElectionVote($round, $member, [999999 => 'for']),
        ElectionBallotRefusal::MissingNonce => (function () use ($round, $member, $now): array {
            $template = VoteEvent::template($round, [], $now);

            return EventSigning::sign(electionSecretFor($member), $template['kind'], $template['tags'], $template['content'], $template['created_at']);
        })(),
        ElectionBallotRefusal::TooManyAttempts => (function () use ($round, $member): array {
            for ($i = 0; $i < CastBallot::MAX_ATTEMPTS; $i++) {
                RateLimiter::hit('election-cast:'.$member->getKey(), CastBallot::DECAY_SECONDS);
            }

            return signedElectionVote($round, $member);
        })(),
    };

    $page->call('castBallot', $round->id, $event)
        ->assertReturned(['status' => 'refused', 'message' => $reason->label()]);

    expect(electionVoteCount($round))->toBe(0);
})->with([
    'invalid signature' => [ElectionBallotRefusal::InvalidSignature],
    'foreign signer' => [ElectionBallotRefusal::ForeignSigner],
    'wrong kind' => [ElectionBallotRefusal::WrongKind],
    'wrong round' => [ElectionBallotRefusal::WrongRound],
    'outside the open period' => [ElectionBallotRefusal::OutsideOpenPeriod],
    'invalid choices' => [ElectionBallotRefusal::InvalidChoices],
    'missing nonce' => [ElectionBallotRefusal::MissingNonce],
    'too many attempts' => [ElectionBallotRefusal::TooManyAttempts],
]);

it('says so when a member not on the roll tries to vote', function () {
    $round = namedDraftRound();
    app(OpenRound::class)->handle($round);
    $lateComer = electionVoter();
    $page = ballotPage($lateComer);

    $page->call('castBallot', $round->id, signedElectionVote($round, $lateComer))
        ->assertReturned(['status' => 'refused', 'message' => ElectionBallotRefusal::NotOnRoll->label()]);
});

it('says so when the board closed the round before the vote arrived', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $page = ballotPage($member)->assertSee('Leer abgeben, für keine Person');
    $event = signedElectionVote($round, $member);

    app(CloseRound::class)->handle($round);

    $page->call('castBallot', $round->id, $event)
        ->assertReturned(['status' => 'closed', 'message' => 'Der Vorstand hat diesen Wahlgang inzwischen geschlossen. Deine Stimme ist nicht abgegeben.'])
        ->assertDontSee('Leer abgeben, für keine Person');

    expect($page->instance()->prepareBallot($round->id, []))
        ->toBe(['message' => 'Der Vorstand hat diesen Wahlgang inzwischen geschlossen. Deine Stimme ist nicht abgegeben.']);
});

it('tells a member whose sheet the board closed mid-review that their vote is not in', function () {
    $round = namedDraftRound();
    $reviewer = electionVoter();
    $bystander = electionVoter();
    $voter = electionVoter();
    app(OpenRound::class)->handle($round);
    castElectionBallot($round, $voter, [candidateIdOf($round, 'Alice Ambrosius') => 'for']);
    $line = 'Der Wahlgang wurde geschlossen, bevor du abgegeben hast. Deine Stimme ist nicht dabei.';

    $page = ballotPage($reviewer);
    $page->call('prepareBallot', $round->id, []);
    $idle = ballotPage($bystander);

    app(CloseRound::class)->handle($round);

    $page->call('syncRounds')
        ->assertSee($line)
        ->assertSee('Gewählt ist Alice Ambrosius.')
        ->assertDontSee('Deine Stimme ist dabei.');
    $idle->call('syncRounds')
        ->assertSee('Gewählt ist Alice Ambrosius.')
        ->assertDontSee($line);
    // Session-only: a fresh page load does not know about the review.
    ballotPage($reviewer)->assertDontSee($line);
});

it('does not claim a missing vote when the member reached the review step and the vote still arrived', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $line = 'Der Wahlgang wurde geschlossen, bevor du abgegeben hast. Deine Stimme ist nicht dabei.';

    $page = ballotPage($member);
    $page->call('prepareBallot', $round->id, [candidateIdOf($round, 'Alice Ambrosius') => 'for']);
    // The vote arrives from another device (or the answer to this page got lost).
    castElectionBallot($round, $member, [candidateIdOf($round, 'Alice Ambrosius') => 'for']);

    app(CloseRound::class)->handle($round);

    $page->call('syncRounds')
        ->assertSee('Gewählt ist Alice Ambrosius.')
        ->assertSee('Deine Stimme ist dabei.')
        ->assertDontSee($line);
});

it('notices on the poll when the board opens a round, and stays put while nothing changes', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    $page = ballotPage($member)->assertDontSee('Leer abgeben, für keine Person');
    $before = $page->get('fingerprint');

    $page->call('syncRounds');
    expect($page->get('fingerprint'))->toBe($before);

    app(OpenRound::class)->handle($round);

    $page->call('syncRounds')
        ->assertSee('Leer abgeben, für keine Person');

    expect($page->get('fingerprint'))->not->toBe($before);
});

it('shows the result only after the round is sealed, as counts without voters', function () {
    $round = namedDraftRound(['Alice Ambrosius', 'Bob Brenner']);
    $voters = collect(['Wählerin Wiebke', 'Wähler Walter', 'Wählerin Xenia'])->map(function (string $name): EinundzwanzigPleb {
        $voter = electionVoter();
        Profile::factory()->create(['pubkey' => $voter->pubkey, 'name' => $name]);

        return $voter;
    });
    app(OpenRound::class)->handle($round);
    $alice = candidateIdOf($round, 'Alice Ambrosius');
    castElectionBallot($round, $voters[0], [$alice => 'for']);
    castElectionBallot($round, $voters[1], [$alice => 'for']);
    castElectionBallot($round, $voters[2]);

    ballotPage($voters[0])
        ->assertDontSee('Ausgezählt')
        ->assertDontSee('Gewählt ist');

    app(CloseRound::class)->handle($round);

    $page = ballotPage($voters[0])
        ->assertSee('Gewählt ist Alice Ambrosius.')
        ->assertSee('3 Stimmzettel abgegeben, davon 1 Enthaltung (leer).')
        ->assertSee('Deine Stimme ist dabei.')
        ->assertSee('Zustand nach der Wahl')
        ->assertDontSee('Wählerin Xenia')
        ->assertDontSee('Wähler Walter');

    expect($page->instance()->phase)->toBe('result')
        ->and($page->instance()->outcome)->toBe([['title' => 'Präsidium', 'seats' => 1, 'names' => ['Alice Ambrosius'], 'annulled' => false, 'open' => 0]]);
});

it('names a tie, and says when nobody got more than half', function (ElectionCountingRule $rule, string $sentence) {
    $round = namedDraftRound(['Alice Ambrosius', 'Bob Brenner'], $rule);
    $voters = [electionVoter(), electionVoter()];
    app(OpenRound::class)->handle($round);
    castElectionBallot($round, $voters[0], [candidateIdOf($round, 'Alice Ambrosius') => 'for']);
    castElectionBallot($round, $voters[1], [candidateIdOf($round, 'Bob Brenner') => 'for']);
    app(CloseRound::class)->handle($round);

    ballotPage($voters[0])
        ->assertSee($sentence)
        ->assertSee('1 Sitz bleibt unbesetzt');
})->with([
    'most votes, level' => [ElectionCountingRule::MostVotes, 'Gleichstand um 1 Sitz zwischen Alice Ambrosius, Bob Brenner.'],
    'single majority, nobody above half' => [ElectionCountingRule::SingleMajority, 'Niemand hat mehr als die Hälfte der Stimmen für Personen bekommen.'],
]);

it('lands the board on the real election, the newer test election only through the switcher', function () {
    $real = Election::factory()->announced()->create(['name' => 'Vorstandswahl 2027']);
    Election::factory()->test()->create(['name' => 'Probewahl']);
    $board = electionBoard(1)[0];

    NostrAuth::login($board->pubkey);

    $page = Livewire::test('association.election.index')
        ->assertSee('Vorstandswahl 2027')
        ->assertDontSee('Testwahl.');

    expect($page->instance()->election->is($real))->toBeTrue();
});

it('does not claim the first step is done and not announced at the same time', function () {
    $test = Election::factory()->test()->create(['name' => 'Probewahl']);
    ElectionRound::factory()->create(['election_event_id' => $test->id]);
    $board = electionBoard(1)[0];

    NostrAuth::login($board->pubkey);

    Livewire::withQueryParams(['wahl' => $test->id])->test('association.election.index')
        ->assertSee('Übersprungen: Eine Testwahl wird nicht angekündigt')
        ->assertDontSee('Noch nicht angekündigt');
});

it('shows times in Europe/Berlin while storing them in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');

    Election::factory()->create(['name' => 'Vorstandswahl 2027', 'announced_at' => '2026-03-01 23:30:00']);

    ballotPage(electionVoter())->assertSee('Angekündigt am 02.03.2026');
});

it('offers a further candidacy as a secondary action', function () {
    $election = Election::factory()->announced()->create();
    $first = ElectionRound::factory()->create(['election_event_id' => $election->id, 'title' => 'Präsidium']);
    ElectionRound::factory()->create(['election_event_id' => $election->id, 'title' => 'Kasse']);
    $member = electionVoter();
    ElectionCandidate::factory()->create(['election_round_id' => $first->id, 'einundzwanzig_pleb_id' => $member->id, 'status' => ElectionCandidateStatus::Pending]);

    $page = ballotPage($member)
        ->assertSee('Du kannst zusätzlich für ein weiteres Amt kandidieren.')
        ->assertSee('Kandidatur einreichen');

    expect($page->instance()->primaryAction)->toBeNull()
        ->and(primaryButtons($page))->toBe(0);
});

it('labels the payment path on the membership page in German', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();
    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertSee('Klicke auf „Sats bezahlen“, um eine neue Rechnung zu erstellen.')
        ->assertDontSee('„Pay“', false);

    // The button itself needs the fee event from the relay, which a test does
    // not reach; its label is checked in the template.
    $template = file_get_contents(resource_path('views/livewire/association/profile.blade.php'));

    expect($template)->toContain("{{ __(':amount Sats bezahlen', ['amount' => \$amountToPay]) }}")
        ->and($template)->not->toMatch('/>\s*Pay \{\{/');
});

it('shows the meeting date in Europe/Berlin once the election is announced', function () {
    $election = Election::factory()->create(['name' => 'Vorstandswahl 2027', 'meeting_at' => '2027-03-04 18:00:00', 'announced_at' => null]);
    namedDraftRound(election: $election);
    $voter = electionVoter();

    // A member never sees an unannounced real election. The board does see an
    // unannounced test election — and still no date on it.
    $rehearsal = Election::factory()->test()->create(['name' => 'Probewahl', 'meeting_at' => '2027-03-04 18:00:00', 'announced_at' => null]);
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    $boardPage = Livewire::test('association.election.index')->assertDontSee('Am 04.03.2027 um 19:00 Uhr');
    expect($boardPage->instance()->election->is($rehearsal))->toBeTrue();

    $election->forceFill(['announced_at' => now()])->save(); // announced_at is not mass assignable

    ballotPage($voter)
        ->assertSee('Am 04.03.2027 um 19:00 Uhr')
        ->assertDontSee('Termin und Link zum Call kommen vom Vorstand');

    $election->update(['meeting_at' => null]);

    ballotPage($voter)->assertSee('Termin und Link zum Call kommen vom Vorstand');
});

it('shows the call link only on the Berlin meeting day or while a round is open', function () {
    // 23:30 UTC on the 4th is 00:30 on the 5th in Berlin: the meeting day is the 5th.
    $election = Election::factory()->announced()->create(['name' => 'Vorstandswahl 2027', 'meeting_at' => '2027-03-04 23:30:00', 'call_url' => 'https://meet.example.org/einundzwanzig']);
    $round = namedDraftRound(election: $election);
    $voter = electionVoter();

    $this->travelTo('2027-03-04 22:00:00'); // 23:00 on the 4th in Berlin
    ballotPage($voter)
        ->assertDontSee('https://meet.example.org/einundzwanzig')
        ->assertSee('Den Link zum Call findest du am Sitzungstag hier.');

    $this->travelTo('2027-03-04 23:00:00'); // midnight, the 5th in Berlin
    ballotPage($voter)
        ->assertSeeHtml('href="https://meet.example.org/einundzwanzig" target="_blank" rel="noopener noreferrer"');

    $this->travelTo('2027-03-05 23:30:00'); // 00:30 on the 6th in Berlin, the day after
    ballotPage($voter)->assertDontSee('https://meet.example.org/einundzwanzig');

    $this->travelTo('2027-03-02 12:00:00'); // two days early, but the board opened a round
    app(OpenRound::class)->handle($round);
    ballotPage($voter)->assertSeeHtml('href="https://meet.example.org/einundzwanzig"');
});

/*
 * A run-off of a run-off is still part of the evening: the member page lists
 * it, offers its ballot while it is open and counts its winner in the result.
 * Mutation that must turn this red: attach only the direct run-offs of a
 * first-level round again.
 */
it('offers the ballot of a second run-off and counts its winner in the result', function () {
    $round = namedDraftRound(['Alice Ambrosius', 'Bob Brenner'], ElectionCountingRule::MostVotes);
    [$board] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];
    $alice = candidateIdOf($round, 'Alice Ambrosius');

    $level = function (ElectionRound $round) use ($voters, $board): ElectionRound {
        $round = app(OpenRound::class)->handle($round);
        $ids = $round->candidates()->orderBy('id')->pluck('id')->all();
        castElectionBallot($round, $voters[0], [$ids[0] => 'for']);
        castElectionBallot($round, $voters[1], [$ids[1] => 'for']);

        return app(CreateRunOff::class)->handle(app(CloseRound::class)->handle($round), $board);
    };

    $second = app(OpenRound::class)->handle($level($level($round)));

    ballotPage($voters[0])
        ->assertSee($second->title)
        ->assertSee('Stimmzettel')
        ->assertSee('Alice Ambrosius');

    castElectionBallot($second, $voters[0], [$second->candidates()->get()->firstWhere('einundzwanzig_pleb_id', ElectionCandidate::find($alice)->einundzwanzig_pleb_id)->id => 'for']);
    app(CloseRound::class)->handle($second);

    expect(ballotPage($voters[0])->instance()->outcome)
        ->toBe([['title' => 'Präsidium', 'seats' => 1, 'names' => ['Alice Ambrosius'], 'annulled' => false, 'open' => 0]]);
});

it('tells an unpaid member off the roll that paying now adds them, and a paid one whom to ask', function () {
    $round = namedDraftRound();
    app(OpenRound::class)->handle($round);
    $lateComer = electionVoter();
    $unpaid = EinundzwanzigPleb::factory()->active()->create();

    ballotPage($unpaid)
        ->assertSee('Wer den Beitrag bezahlt, solange der Wahlgang offen ist, kommt automatisch dazu.')
        ->assertSee('Jetzt Beitrag bezahlen')
        ->assertSee(route('association.profile', ['from' => 'election']), false)
        ->assertDontSee('Melde dich im Call beim Vorstand');

    ballotPage($lateComer)
        ->assertSee('oder seinen Beitrag bezahlt, solange er offen ist. Melde dich im Call beim Vorstand, wenn das nicht stimmt.')
        ->assertDontSee('Jetzt Beitrag bezahlen');
});

it('names the crosses in the legend, announces the counter and shows a run-off title once', function () {
    $round = namedDraftRound(['Alice Ambrosius', 'Bob Brenner', 'Carla Conrad'], ElectionCountingRule::MostVotes, 2);
    [$board] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];
    app(OpenRound::class)->handle($round);

    $page = ballotPage($voters[0])
        ->assertSeeHtml('<legend class="sr-only">Stimmzettel: Präsidium, höchstens 2 Kreuze</legend>')
        ->assertSeeHtml('aria-live="polite" x-text="summaryLine()"');

    // Two of three tie for the second seat: a run-off follows.
    $ids = $round->candidates()->orderBy('id')->pluck('id')->all();
    castElectionBallot($round, $voters[0], [$ids[0] => 'for', $ids[1] => 'for']);
    castElectionBallot($round, $voters[1], [$ids[0] => 'for', $ids[2] => 'for']);
    $runOff = app(OpenRound::class)->handle(app(CreateRunOff::class)->handle(app(CloseRound::class)->handle($round), $board));

    expect($runOff->title)->toBe('Stichwahl Präsidium');

    ballotPage($voters[0])
        ->assertSee('Stichwahl Präsidium')
        ->assertDontSee('Stichwahl: Stichwahl');
});

it('explains on the review that no screen shows who voted how, without promising anonymity', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);

    ballotPage($member)
        ->assertSee('Keine Seite des Portals zeigt, wer wie gewählt hat, auch nicht dem Vorstand.')
        ->assertSee('Gespeichert wird deine Stimme signiert beim Verein')
        ->assertDontSee('Die Stimme geht nur an den Verein')
        ->assertDontSee('anonym');
});

it('carries the German signer help on the ballot, with the nsec warning, before any vendor dialog', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);

    ballotPage($member)
        ->assertSee('Noch kein Nostr-Signer?')
        ->assertSee('Gib nie deinen nsec in eine Webseite ein')
        ->assertSee('Signer-App verbinden')
        ->assertDontSee('Connect with Nostr')
        ->assertSee('Dein Signer hat nach einer Minute nicht geantwortet.');
});

it('explains the vote id and moves the focus to the receipt right after the cast', function () {
    $round = namedDraftRound();
    $member = electionVoter();
    app(OpenRound::class)->handle($round);
    $page = ballotPage($member);

    $page->call('castBallot', $round->id, signedElectionVote($round, $member, [candidateIdOf($round, 'Alice Ambrosius') => 'for']))
        ->assertSee('Sie ist der Fingerabdruck deiner signierten Stimme')
        ->assertSeeHtml('$refs.receipt.focus(');

    // Coming back later is no fresh cast: no jump.
    ballotPage($member)
        ->assertSee('Du hast in diesem Wahlgang schon gewählt.')
        ->assertDontSeeHtml('$refs.receipt.focus(');
});

it('adds no second full stop after a name that ends in one', function () {
    $round = namedDraftRound(['Erik E.', 'Bob Brenner']);
    $voter = electionVoter();
    app(OpenRound::class)->handle($round);
    castElectionBallot($round, $voter, [candidateIdOf($round, 'Erik E.') => 'for']);
    app(CloseRound::class)->handle($round);

    ballotPage($voter)
        ->assertSee('Gewählt ist Erik E.')
        ->assertDontSee('Erik E..');
});

it('says on a tied round that its run-off decided, not that the seat stays empty', function () {
    $round = namedDraftRound(['Alice Ambrosius', 'Bob Brenner'], ElectionCountingRule::MostVotes);
    [$board] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];
    $round = app(OpenRound::class)->handle($round);
    $ids = $round->candidates()->orderBy('id')->pluck('id')->all();
    castElectionBallot($round, $voters[0], [$ids[0] => 'for']);
    castElectionBallot($round, $voters[1], [$ids[1] => 'for']);
    $runOff = app(OpenRound::class)->handle(app(CreateRunOff::class)->handle(app(CloseRound::class)->handle($round), $board));

    ballotPage($voters[0])->assertSee('Dazu gibt es eine Stichwahl, sie steht darunter.');

    castElectionBallot($runOff, $voters[0], [$runOff->candidates()->orderBy('id')->first()->id => 'for']);
    app(CloseRound::class)->handle($runOff);

    ballotPage($voters[0])
        ->assertSee('Gleichstand um 1 Sitz zwischen Alice Ambrosius, Bob Brenner.')
        ->assertSee('Entschieden hat die Stichwahl darunter.')
        ->assertDontSee('Wie es weitergeht, sagt der Vorstand im Call.');
});
