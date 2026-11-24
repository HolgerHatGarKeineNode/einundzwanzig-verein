<?php

use App\Enums\ProjectProposalStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Models\Vote;
use App\Support\NostrAuth;
use Livewire\Livewire;
use swentel\nostr\Key\Key;

/*
 * The votes on a project proposal, a child component of its page: the own vote,
 * the board panel with the payout and the tallies. Moved here from
 * ProjectSupportTest with the component.
 */

/**
 * Der Vorstand wird je Test frisch angelegt, weil ProjectProposal::boardPlebIds()
 * die npubs aus der Konfiguration bei jedem Aufruf gegen die Datenbank auflöst.
 * Ohne passende Pleb-Zeilen zählt keine Stimme als Vorstandsstimme.
 *
 * Bewusst KEINE Annahme über Aufrufreihenfolge oder Memoisierung: die Methode
 * cacht absichtlich nicht (ein prozessweiter Cache überlebt bei langlebigen
 * Workern den Request und würde Stimmen verschlucken).
 */
beforeEach(function () {
    collect(config('einundzwanzig.config.current_board'))->each(
        fn (string $npub) => EinundzwanzigPleb::factory()->create(['npub' => $npub, 'pubkey' => (new Key)->convertToHex($npub)])
    );
});

it('denies access to show when not authenticated', function () {
    $project = ProjectProposal::factory()->create();

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('currentPubkey', null);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertSet('currentPleb', null);
});

it('initializes currentPleb when authenticated', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertSet('currentPleb.id', $pleb->id);
});

it('initializes ownVoteExists to false when no vote exists', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertSet('ownVoteExists', false)
        ->assertSee('Zustimmen')
        ->assertSee('Ablehnen');
});

it('initializes ownVoteExists to true when vote exists', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();
    Vote::create([
        'project_proposal_id' => $project->id,
        'einundzwanzig_pleb_id' => $pleb->id,
        'value' => true,
    ]);

    NostrAuth::login($pleb->pubkey);

    // Eine abgegebene Stimme ist endgültig: canVote (VotePolicy::create)
    // wird false. Das Panel bleibt aber sichtbar und nennt die eigene
    // Stimme — vorher stand dort nur "Du hast bereits abgestimmt", ohne zu
    // sagen, wie. Sichtbarkeit hängt deshalb an isVoter, nicht an canVote.
    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertSet('ownVoteExists', true)
        ->assertSet('canVote', false)
        ->assertSet('isVoter', true)
        ->assertSee('Deine Stimme')
        ->assertSee('Du hast zugestimmt.')
        ->assertSee('Eine abgegebene Stimme lässt sich nicht mehr ändern.')
        ->assertDontSee('Zustimmen')
        ->assertDontSee('Ablehnen');
});

it('can handle approve vote', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->call('castVote', true)
        ->assertHasNoErrors();

    $vote = Vote::query()
        ->where('project_proposal_id', $project->id)
        ->where('einundzwanzig_pleb_id', $pleb->id)
        ->first();

    expect($vote)->not->toBeNull()
        ->and($vote->value)->toBeTrue();
});

it('can handle not approve vote', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->call('castVote', false)
        ->assertHasNoErrors();

    $vote = Vote::query()
        ->where('project_proposal_id', $project->id)
        ->where('einundzwanzig_pleb_id', $pleb->id)
        ->first();

    expect($vote)->not->toBeNull()
        ->and($vote->value)->toBeFalse();
});

it('ignores an approval from a guest', function () {
    $project = ProjectProposal::factory()->create();

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->call('castVote', true)
        ->assertHasNoErrors();

    expect(Vote::where('project_proposal_id', $project->id)->exists())->toBeFalse();
});

it('ignores a rejection from a guest', function () {
    $project = ProjectProposal::factory()->create();

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->call('castVote', false)
        ->assertHasNoErrors();

    expect(Vote::where('project_proposal_id', $project->id)->exists())->toBeFalse();
});

it('offers a nostr login in place of the voting card for a logged out visitor', function () {
    $project = ProjectProposal::factory()->create();

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertSet('isVoter', false)
        // Der Slot behaelt seine Beschriftung ueber alle Anmeldezustaende.
        ->assertSee('Deine Stimme')
        ->assertSee('Mit einer Nostr-Anmeldung kannst du diesem Antrag zustimmen oder ihn ablehnen.')
        // Der Auslöser steht IN der Karte, nicht nur im Menue.
        ->assertSee('Mit Nostr verbinden')
        // Ohne diesen Satz endet „melde dich an" auf dem Telefon im Nichts.
        ->assertSee('Auf dem Handy gibt es meist keine Browser-Erweiterung.')
        ->assertSee('bunker://')
        // Eigener location-Wert: sonst kollidierten die Overlay-IDs mit der
        // Instanz in der Sidebar, die auf derselben Seite gemountet wird.
        ->assertSee('nostr-login-progress-heading-project-vote', escape: false)
        ->assertDontSee('Zustimmen')
        ->assertDontSee('Ablehnen');
});

it('names the missing member record instead of answering with an error', function () {
    $project = ProjectProposal::factory()->create();

    // Eine angemeldete Sitzung haelt nur den Pubkey. Fehlt der Mitgliedseintrag
    // dazu (nach dem Login entfernt), lief mount() vorher in
    // „Attempt to read property id on null" — die Seite antwortete mit einer
    // 500 statt mit dem Hinweis.
    $pubkey = str_repeat('ab', 32);
    expect(EinundzwanzigPleb::query()->where('pubkey', $pubkey)->exists())->toBeFalse();

    NostrAuth::login($pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertStatus(200)
        ->assertSet('isVoter', false)
        ->assertSee('Deine Stimme')
        ->assertSee('Zu deinem Schlüssel ist kein Mitgliedseintrag hinterlegt.')
        ->assertSee('Bitte kontaktiere den Vorstand, wenn du denkst, dass du berechtigt sein solltest.')
        // Wer angemeldet ist, bekommt keinen zweiten Anmelde-Knopf vorgesetzt.
        ->assertDontSee('Mit Nostr verbinden')
        ->assertDontSee('Zustimmen')
        ->assertDontSee('Ablehnen');
});

it('leaves the voting card untouched for a member who may vote', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertSet('isVoter', true)
        ->assertSee('Deine Stimme')
        ->assertSee('Zustimmen')
        ->assertSee('Ablehnen')
        // Keiner der beiden Ersatzzustaende darf daneben stehen.
        ->assertDontSee('Mit Nostr verbinden')
        ->assertDontSee('Zu deinem Schlüssel ist kein Mitgliedseintrag hinterlegt.');
});

// Stimmungsbild der Nicht-Vorstandsmitglieder

it('lets a non-board member vote without affecting the majority', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    $component = Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertSet('voteCountsTowardsMajority', false)
        ->assertSee('Zählt zum Stimmungsbild der Mitglieder, nicht zur Mehrheit.')
        ->call('castVote', true)
        ->assertHasNoErrors();

    expect(Vote::where('project_proposal_id', $project->id)->count())->toBe(1);
    // Die Stimme ist da, der Status bleibt unberührt.
    expect($component->get('status'))->toBe(ProjectProposalStatus::InVoting);
    expect($project->fresh()->boardApprovals())->toBe(0);
});

it('marks a board member vote as counting towards the majority', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertSet('voteCountsTowardsMajority', true)
        ->assertSee('Zählt zur bindenden Mehrheit des Vorstands.');
});

it('separates board votes from the members sentiment', function () {
    $project = ProjectProposal::factory()->create();
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $member = EinundzwanzigPleb::factory()->create();

    Vote::create(['project_proposal_id' => $project->id, 'einundzwanzig_pleb_id' => $board->id, 'value' => true]);
    Vote::create(['project_proposal_id' => $project->id, 'einundzwanzig_pleb_id' => $member->id, 'value' => true]);

    $component = Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project]);

    expect($component->get('boardVotes'))->toHaveCount(1)
        ->and($component->get('otherVotes'))->toHaveCount(1);
});

// recordPayout / revertPayout

function boardDecides(ProjectProposal $project, bool $inFavour, ?int $count = null): void
{
    $count ??= ProjectProposal::boardVoteThreshold();

    collect(ProjectProposal::boardPlebIds())->take($count)->each(
        fn (int $plebId) => Vote::create([
            'project_proposal_id' => $project->id,
            'einundzwanzig_pleb_id' => $plebId,
            'value' => $inFavour,
        ])
    );
}

it('lets a board member record a payout, which flips the status to supported', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create(['support_in_sats' => 100000, 'sats_paid' => 0]);
    boardDecides($project, inFavour: true);

    NostrAuth::login($board->pubkey);

    $component = Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->set('payoutSats', 100000)
        ->call('recordPayout')
        ->assertHasNoErrors();

    // Backed enum cases are invokable in this codebase (ArchTech\Enums\InvokableCases),
    // which makes `assertSet('status', SomeCase)` misfire — Livewire treats the enum
    // instance as a callable predicate instead of a value to compare against.
    expect($component->get('status'))->toBe(ProjectProposalStatus::Supported);
    expect($project->fresh()->sats_paid)->toBe(100000);
});

it('denies recordPayout to a non-board member', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['sats_paid' => 0]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->set('payoutSats', 21000)
        ->call('recordPayout')
        ->assertForbidden();

    expect($project->fresh()->sats_paid)->toBe(0);
});

it('denies recordPayout to a guest', function () {
    $project = ProjectProposal::factory()->create(['sats_paid' => 0]);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->set('payoutSats', 21000)
        ->call('recordPayout')
        ->assertForbidden();

    expect($project->fresh()->sats_paid)->toBe(0);
});

it('refuses a payout while the proposal is still being voted on', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create(['sats_paid' => 0]);
    // Eine Stimme zu wenig für die absolute Mehrheit.
    boardDecides($project, inFavour: true, count: ProjectProposal::boardVoteThreshold() - 1);

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->set('payoutSats', 50000)
        ->call('recordPayout')
        ->assertForbidden();

    expect($project->fresh()->sats_paid)->toBe(0);
});

it('refuses a payout for a rejected proposal', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create(['sats_paid' => 0]);
    boardDecides($project, inFavour: false);

    NostrAuth::login($board->pubkey);

    // Geld darf einem abgelehnten Antrag unter keinen Umständen folgen.
    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->set('payoutSats', 50000)
        ->call('recordPayout')
        ->assertForbidden();

    expect($project->fresh()->sats_paid)->toBe(0);
});

it('hides the payout form and names the missing approvals', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create(['sats_paid' => 0]);

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->assertSet('canPayout', false)
        ->assertSee('Auszahlung erst nach Beschluss')
        ->assertDontSee('Noch nichts ausgezahlt.');
});

it('rejects a zero payout amount', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create(['sats_paid' => 0]);
    boardDecides($project, inFavour: true);

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->set('payoutSats', 0)
        ->call('recordPayout')
        ->assertHasErrors(['payoutSats']);

    expect($project->fresh()->sats_paid)->toBe(0);
});

it('lets a board member revert a recorded payout', function () {
    // sats_paid is deliberately not fillable (see MassAssignmentProtectionTest)
    // — a payout must be set via direct attribute assignment, not update().
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();
    $project->sats_paid = 50000;
    $project->save();

    NostrAuth::login($board->pubkey);

    $component = Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->call('revertPayout');

    expect($component->get('status'))->toBe(ProjectProposalStatus::InVoting)
        ->and($project->fresh()->sats_paid)->toBe(0);
});

it('denies revertPayout to a non-board member and leaves the payout untouched', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();
    $project->sats_paid = 50000;
    $project->save();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->call('revertPayout')
        ->assertForbidden();

    expect($project->fresh()->sats_paid)->toBe(50000);
});

// canSeeContact / Kontaktangabe auf der Detailseite

/*
 * The panel on its own: a child component is callable without the page, so
 * its writes ask their policies themselves, and the page follows a vote or a
 * payout through the `project-proposal-changed` event.
 */
it('hands a vote to the page, whose status badge follows', function () {
    $project = ProjectProposal::factory()->create(['support_in_sats' => 21000]);
    $page = Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSee(ProjectProposalStatus::InVoting->label());

    $panel = null;

    foreach (EinundzwanzigPleb::query()->whereIn('id', ProjectProposal::boardPlebIds())->take(ProjectProposal::boardVoteThreshold())->get() as $board) {
        NostrAuth::login($board->pubkey);
        $panel = Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project->fresh()])
            ->call('castVote', true)
            ->assertDispatched('project-proposal-changed');
    }

    $page->assertSee(ProjectProposalStatus::InVoting->label())
        ->dispatch('project-proposal-changed')
        ->assertSee(ProjectProposalStatus::Accepted->label());

    // A payout hands over the same way.
    $panel->set('payoutSats', 21000)->call('recordPayout')->assertHasNoErrors()->assertDispatched('project-proposal-changed');

    $page->dispatch('project-proposal-changed')->assertSee(ProjectProposalStatus::Supported->label());
});

it('refuses the payout actions of the panel to a member who is not on the board, and dispatches nothing', function (string $action) {
    $project = ProjectProposal::factory()->create(['sats_paid' => $action === 'revertPayout' ? 21000 : 0]);
    NostrAuth::login(EinundzwanzigPleb::factory()->create()->pubkey);

    Livewire::test('association.project-support.show-vote-panel', ['projectProposal' => $project])
        ->set('payoutSats', 21000)
        ->call($action)
        ->assertForbidden()
        ->assertNotDispatched('project-proposal-changed');

    expect($project->fresh()->sats_paid)->toBe($action === 'revertPayout' ? 21000 : 0);
})->with(['recordPayout', 'revertPayout']);
