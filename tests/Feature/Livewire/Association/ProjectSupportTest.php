<?php

use App\Enums\ProjectProposalStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Models\ProjectProposal;
use App\Models\Vote;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use swentel\nostr\Key\Key;

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

it('loads projects on mount', function () {
    $project1 = ProjectProposal::factory()->create();
    $project2 = ProjectProposal::factory()->create();

    Livewire::test('association.project-support.index')
        ->assertSet('projects', function ($projects) {
            return $projects->count() >= 2;
        });
});

it('shows paid sats on the overview card, not the original request, when a payout was recorded', function () {
    // Regression: die Karte las immer support_in_sats. Eine korrigierte
    // Auszahlung (sats_paid) blieb nur auf der Detailseite sichtbar.
    ProjectProposal::factory()->create([
        'name' => 'Shirts fuer der Weg',
        'support_in_sats' => 1_250_000,
        'sats_paid' => 392_613,
    ]);

    Livewire::test('association.project-support.index')
        ->assertSee('392.613')
        ->assertDontSee('1.250.000');
});

it('shows the requested sats on the overview card when nothing is paid yet', function () {
    ProjectProposal::factory()->create([
        'support_in_sats' => 1_250_000,
        'sats_paid' => null,
    ]);

    Livewire::test('association.project-support.index')
        ->assertSee('1.250.000');
});

it('sorts by paid sats when a payout exists, otherwise by the requested amount', function () {
    $smallPaid = ProjectProposal::factory()->create([
        'name' => 'Small Paid',
        'support_in_sats' => 9_000_000,
        'sats_paid' => 100_000,
    ]);
    $largeUnpaid = ProjectProposal::factory()->create([
        'name' => 'Large Unpaid',
        'support_in_sats' => 500_000,
        'sats_paid' => null,
    ]);
    $midPaid = ProjectProposal::factory()->create([
        'name' => 'Mid Paid',
        'support_in_sats' => 50_000,
        'sats_paid' => 300_000,
    ]);

    Livewire::test('association.project-support.index')
        ->call('setSort', 'sats')
        ->assertSet('projects', function ($projects) use ($smallPaid, $largeUnpaid, $midPaid) {
            $ids = $projects->pluck('id')->all();

            // 500k (unpaid) > 300k (paid) > 100k (paid) — nicht 9M beantragt.
            return $ids === [$largeUnpaid->id, $midPaid->id, $smallPaid->id];
        });
});

it('can search projects', function () {
    $project = ProjectProposal::factory()->create(['name' => 'Unique Project Name']);

    Livewire::test('association.project-support.index')
        ->set('search', 'Unique')
        ->assertSet('projects', function ($projects) use ($project) {
            return $projects->contains('id', $project->id);
        });
});

it('can filter projects', function () {
    Livewire::test('association.project-support.index')
        ->call('setFilter', 'new')
        ->assertSet('activeFilter', 'new');
});

it('counts every status regardless of which filter is active', function () {
    ProjectProposal::factory()->count(2)->create();
    ProjectProposal::factory()->create(['sats_paid' => 21000]);

    $component = Livewire::test('association.project-support.index')
        ->call('setFilter', ProjectProposalStatus::Supported->value);

    expect($component->get('statusCounts'))->toBe([
        'all' => 3,
        ProjectProposalStatus::InVoting->value => 2,
        ProjectProposalStatus::Accepted->value => 0,
        ProjectProposalStatus::Rejected->value => 0,
        ProjectProposalStatus::Supported->value => 1,
    ]);
});

it('counts every status and the board member\'s own work list in one query, as each filter alone would', function () {
    $board = EinundzwanzigPleb::query()->whereIn('id', ProjectProposal::boardPlebIds())->orderBy('id')->get();
    $threshold = ProjectProposal::boardVoteThreshold();
    $vote = fn (ProjectProposal $project, EinundzwanzigPleb $pleb, bool $value) => Vote::create(['project_proposal_id' => $project->id, 'einundzwanzig_pleb_id' => $pleb->id, 'value' => $value]);

    ProjectProposal::factory()->create(['name' => 'Grant ohne meine Stimme']);
    $vote(ProjectProposal::factory()->create(['name' => 'Grant mit meiner Stimme']), $board[0], true);
    $accepted = ProjectProposal::factory()->create(['name' => 'Grant angenommen']);
    $board->take($threshold)->each(fn (EinundzwanzigPleb $pleb) => $vote($accepted, $pleb, true));
    $rejected = ProjectProposal::factory()->create(['name' => 'Grant abgelehnt']);
    $board->take($threshold)->each(fn (EinundzwanzigPleb $pleb) => $vote($rejected, $pleb, false));
    ProjectProposal::factory()->create(['name' => 'Grant ausgezahlt', 'sats_paid' => 21000]);
    ProjectProposal::factory()->create(['name' => 'Anderes Projekt']);
    NostrAuth::login($board[0]->pubkey);
    $component = Livewire::test('association.project-support.index')->set('search', 'Grant');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $counts = $component->instance()->statusCounts();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $expected = ['all' => ProjectProposal::query()->search('Grant')->count()];

    foreach (ProjectProposalStatus::cases() as $status) {
        $expected[$status->value] = ProjectProposal::query()->search('Grant')->withStatus($status->value)->count();
    }

    $expected['awaiting_my_vote'] = ProjectProposal::query()->search('Grant')->awaitingVoteFrom($board[0]->id)->count();

    expect($counts)->toBe($expected)
        ->and($expected)->toBe(['all' => 5, 'new' => 2, 'accepted' => 1, 'rejected' => 1, 'supported' => 1, 'awaiting_my_vote' => 1])
        // The board's ids, then the counts.
        ->and($queries)->toBeLessThanOrEqual(2);
});

it('combines search and status filter', function () {
    $matching = ProjectProposal::factory()->create(['name' => 'Lightning Node Grant']);
    ProjectProposal::factory()->create(['name' => 'Lightning Watchtower Grant', 'sats_paid' => 21000]);
    ProjectProposal::factory()->create(['name' => 'Unrelated Project']);

    Livewire::test('association.project-support.index')
        ->set('search', 'Lightning')
        ->call('setFilter', ProjectProposalStatus::InVoting->value)
        ->assertSet('projects', function ($projects) use ($matching) {
            return $projects->count() === 1 && $projects->contains('id', $matching->id);
        });
});

it('resets search, filter and sort in one call', function () {
    Livewire::test('association.project-support.index')
        ->set('search', 'something')
        ->call('setFilter', ProjectProposalStatus::Supported->value)
        ->call('setSort', 'oldest')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('activeFilter', 'all')
        ->assertSet('sortBy', 'newest');
});

it('paginates beyond the first 12 projects', function () {
    ProjectProposal::factory()->count(15)->create();

    $component = Livewire::test('association.project-support.index');

    expect($component->get('projects')->total())->toBe(15)
        ->and($component->get('projects')->count())->toBe(12)
        ->and($component->get('projects')->lastPage())->toBe(2);
});

it('can confirm delete', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.index')
        ->call('confirmDeleteProject', $project->id)
        ->assertSet('projectToDelete.id', $project->id);
});

it('refuses to open the delete dialog for an unauthorised caller', function () {
    $project = ProjectProposal::factory()->create();

    // confirmDeleteProject ist als Livewire-Endpunkt direkt aufrufbar und darf
    // deshalb nicht bloss über die UI verborgen sein.
    Livewire::test('association.project-support.index')
        ->call('confirmDeleteProject', $project->id)
        ->assertForbidden();
});

it('can delete project', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.index')
        ->call('confirmDeleteProject', $project->id)
        ->call('delete');

    expect(ProjectProposal::find($project->id))->toBeNull();
});

it('reflects an authenticated nostr session on mount', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);

    expect(Livewire::test('association.project-support.index')->instance()->currentPleb?->is($pleb))->toBeTrue();
});

it('asks the session again after a logout instead of remembering the member', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($pleb->pubkey);
    $page = Livewire::test('association.project-support.index');

    NostrAuth::logout();
    $page->call('$refresh');

    expect($page->instance()->currentPleb)->toBeNull();
});

it('denies access to create when not authenticated', function () {
    Livewire::test('association.project-support.form.create')
        ->assertSet('isAllowed', false)
        ->assertSee('Projektförderung kann nicht angelegt werden');
});

it('denies access to create when pleb has not paid', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->assertSet('isAllowed', false);
});

it('grants access to create when pleb is active and paid', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->assertSet('isAllowed', true);
});

it('can create project proposal', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->set('form.name', 'Test Project')
        ->set('form.description', 'Test Description')
        ->set('form.support_in_sats', 21000)
        ->set('form.website', 'https://example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProjectProposal::where('name', 'Test Project')->exists())->toBeTrue();
});

it('validates project proposal creation', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.description']);
});

// Project Support Edit Tests
it('renders project support edit component', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
    ]);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $project])
        ->assertStatus(200);
});

it('denies access to edit when not owner', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $project])
        ->assertSet('isAllowed', false);
});

it('grants access to edit when owner', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $project])
        ->assertSet('isAllowed', true);
});

it('can update project proposal', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'name' => 'Old Name',
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $project])
        ->set('form.name', 'New Name')
        ->set('form.description', 'Updated Description')
        ->set('form.support_in_sats', 21000)
        ->set('form.website', 'https://example.com')
        ->call('update')
        ->assertHasNoErrors();

    expect($project->fresh()->name)->toBe('New Name');
});

it('validates project proposal update', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $project])
        ->set('form.name', '')
        ->call('update')
        ->assertHasErrors(['form.name']);
});

// Project Support Show Tests
it('renders project support show component', function () {
    $project = ProjectProposal::factory()->create();

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertStatus(200);
});

it('grants access to show when authenticated', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('currentPubkey', $pleb->pubkey);
});

it('displays project details', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'name' => 'Test Project Name',
        'description' => 'Test Project Description',
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('projectProposal.name', 'Test Project Name')
        ->assertSee('Test Project Name')
        ->assertSee('Test Project Description');
});

it('shows the own rejection instead of an approval', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();
    Vote::create([
        'project_proposal_id' => $project->id,
        'einundzwanzig_pleb_id' => $pleb->id,
        'value' => false,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSee('Du hast abgelehnt.')
        ->assertDontSee('Du hast zugestimmt.');
});

it('requires a confirmation modal before a vote is cast', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    // Die Knöpfe lösen nicht direkt aus, sondern öffnen einen Bestätigungsdialog —
    // eine endgültige Stimme darf kein Fehlklick sein.
    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSee('Deine Stimme ist endgültig.')
        ->assertSee('confirm-approve', escape: false)
        ->assertSee('confirm-reject', escape: false);
});

it('hides voting buttons from unauthenticated users', function () {
    $project = ProjectProposal::factory()->create();

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertDontSee('Zustimmen')
        ->assertDontSee('Ablehnen');
});

// Ersatzzustand der Abstimmungskarte
//
// Die Detailseite ist oeffentlich erreichbar, die Karte „Deine Stimme" hing aber
// allein am Login. Ein ausgeloggter Besucher — auf dem Telefon der Normalfall,
// dort gibt es keine Signer-Erweiterung — sah die ganze Seite, nur die
// Abstimmung fehlte ersatzlos und kommentarlos. Die drei Zustaende des Slots
// werden hier zusammen geprueft, damit keiner davon still verschwindet.

it('shows the members sentiment panel even when nobody has voted yet', function () {
    $project = ProjectProposal::factory()->create();

    // Das Panel hing früher an otherVotes->isNotEmpty() und verschwand ganz —
    // das las sich, als könnten Mitglieder nicht abstimmen.
    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSee('Stimmungsbild der Mitglieder')
        ->assertSee('Zählt nicht zur Mehrheit')
        ->assertSee('Noch keine Stimme abgegeben.');
});

/**
 * Lässt den Vorstand mit der geforderten Mehrheit zustimmen bzw. ablehnen.
 * Die Anzahl kommt aus boardVoteThreshold(), nie als feste Zahl.
 */
it('shows the nostr-dm contact info to the submitter', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'contact_via_nostr_dm' => true,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeContact', true)
        ->assertSee('Nostr-DM erwünscht');
});

it('hides the contact info from an unrelated logged-in member', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['contact_via_nostr_dm' => true]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeContact', false)
        ->assertDontSee('Kontakt zum Einreicher');
});

it('shows "no contact channel" instead of an error when both dm and alternative are unset', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'contact_via_nostr_dm' => false,
        'contact_alternative' => null,
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSee('Kein Kontaktweg hinterlegt.');
});

// Privater Chatraum: canCreateChatRoom / canSeeChatRoom / storeChatRoom
//
// Modell-Ebene (nostrGroupId(), nostrGroupMemberPubkeys()) und reine
// Policy-Aufrufe leben in tests/Feature/Models/ProjectProposalNostrGroupTest.php
// und tests/Feature/Policies/ProjectProposalChatRoomPolicyTest.php. Hier geht es
// um den Livewire-Endpunkt selbst: storeChatRoom() ist eine öffentliche Methode
// und direkt aufrufbar, unabhängig davon, was die View anzeigt — der Schutz
// muss also am Aufruf greifen, nicht nur am sichtbaren Knopf.

it('allows a board member to see the create-chat-room action when no room exists', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canCreateChatRoom', true)
        ->assertSee('Chatraum anlegen');
});

it('hands the database id of the proposal to the create-chat-room island', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create(['name' => 'Ein Antrag mit beweglichem Slug']);

    NostrAuth::login($board->pubkey);

    // Die Insel baut daraus das Bindungs-Tag ["i","proposal:<id>"] des 39000.
    // Es muss die DATENBANK-ID sein, nicht der Slug: Der Slug hängt am Namen
    // (HasSlug) und wandert bei einer Umbenennung mit — das Tag steht danach
    // unwiderruflich im Nostr-Event und zeigte ins Leere.
    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSee('proposalId: '.$project->id, false)
        ->assertDontSee('proposalId: "'.$project->slug.'"', false);
});

it('hides the create-chat-room action from the submitter', function () {
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);

    NostrAuth::login($submitter->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canCreateChatRoom', false)
        ->assertDontSee('Chatraum anlegen');
});

it('hides the create-chat-room action from an unrelated member', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canCreateChatRoom', false);
});

it('hides the create-chat-room action from a guest', function () {
    $project = ProjectProposal::factory()->create();

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canCreateChatRoom', false);
});

it('hides the chat room panel entirely before it exists for a non-board, non-submitter viewer', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatRoom', false)
        ->assertSet('canCreateChatRoom', false)
        ->assertDontSee('Chat zum Antrag');
});

/**
 * Der Einreicher faellt durch beide Einzelrechte, solange kein Raum existiert:
 * `viewChatRoom` verlangt einen Raum, `createChatRoom` den Vorstand. Vor der
 * Einfuehrung von `canSeeChatSection` war der Hinweis fuer genau diesen Fall
 * damit toter Code — der Einreicher erfuhr nichts von dem privaten Kanal.
 */
it('tells the submitter about the coming chat room before it exists', function () {
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);

    NostrAuth::login($submitter->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatSection', true)
        ->assertSet('canSeeChatRoom', false)
        ->assertSet('canCreateChatRoom', false)
        ->assertSee('Chat zum Antrag')
        ->assertSee('Der Vorstand legt den Raum bei Nachfragen an');
});

it('keeps the chat section closed for a non-board, non-submitter viewer', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatSection', false)
        ->assertDontSee('Chat zum Antrag')
        ->assertDontSee('Der Vorstand legt den Raum bei Nachfragen an');
});

it('shows the existing chat room to a board member', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create(['nostr_group_h' => 'p'.str_repeat('a', 12)]);

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatRoom', true)
        ->assertSee('Chat öffnen');
});

it('shows the existing chat room to the submitter', function () {
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $submitter->id,
        'nostr_group_h' => 'p'.str_repeat('a', 12),
    ]);

    NostrAuth::login($submitter->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatRoom', true)
        ->assertSee('Chat öffnen');
});

it('hides an existing chat room from an unrelated member', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['nostr_group_h' => 'p'.str_repeat('a', 12)]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatRoom', false)
        ->assertDontSee('Chat zum Antrag');
});

it('hides an existing chat room from a guest', function () {
    $project = ProjectProposal::factory()->create(['nostr_group_h' => 'p'.str_repeat('a', 12)]);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatRoom', false)
        ->assertDontSee('Chat zum Antrag');
});

it('embeds the chat island for a board member once the room exists', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();
    $project->nostr_group_h = $project->nostrGroupId();
    $project->nostr_group_created_at = now();
    $project->save();

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatRoom', true)
        ->assertSee('projectChatFeed(', false)
        ->assertSee('projectChatRoomFeed(', false);
});

it('embeds the chat island for the submitter once the room exists', function () {
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);
    $project->nostr_group_h = $project->nostrGroupId();
    $project->nostr_group_created_at = now();
    $project->save();

    NostrAuth::login($submitter->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatRoom', true)
        ->assertSee('projectChatFeed(', false);
});

it('leaves no trace of the chat island for an unrelated member', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();
    $project->nostr_group_h = $project->nostrGroupId();
    $project->nostr_group_created_at = now();
    $project->save();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatRoom', false)
        ->assertDontSee('projectChatFeed(', false)
        ->assertDontSee('projectChatRoomFeed(', false)
        ->assertDontSee($project->nostrGroupId());
});

it('leaves no trace of the chat island for a guest', function () {
    $project = ProjectProposal::factory()->create();
    $project->nostr_group_h = $project->nostrGroupId();
    $project->nostr_group_created_at = now();
    $project->save();

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSet('canSeeChatRoom', false)
        ->assertDontSee('projectChatFeed(', false)
        ->assertDontSee($project->nostrGroupId());
});

it('keeps the fallback link to the full chat client next to the island', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();
    $project->nostr_group_h = $project->nostrGroupId();
    $project->nostr_group_created_at = now();
    $project->save();

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSee('Chat öffnen')
        ->assertSee('/rooms/'.$project->nostrGroupId(), false);
});

// Raumverweis zuruecksetzen: canResetChatRoom / resetChatRoom
//
// Der Zweck ist die Entsperrung: Ein auf dem Relay geloeschter Raum bleibt am
// Antrag stehen, „Chat oeffnen" zeigt ins Leere und „Chatraum anlegen" ist
// gesperrt. Reine Policy-Aufrufe liegen in
// tests/Feature/Policies/ProjectProposalChatRoomPolicyTest.php — hier geht es um
// den Livewire-Endpunkt, der oeffentlich und damit direkt aufrufbar ist.

it('shows the reset action to a board member and keeps it out of the markup for everyone else', function () {
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $submitter->id,
        'nostr_group_h' => 'p'.str_repeat('a', 12),
        'nostr_group_created_at' => now()->subDay(),
    ]);

    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSee('Raumverweis zurücksetzen')
        // Der Reparaturknopf darf den Alltagsweg nicht verdraengen.
        ->assertSee('Chat öffnen');

    // Der Einreicher sieht denselben Raum, aber nicht den Reparaturknopf.
    NostrAuth::login($submitter->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertSee('Chat öffnen')
        ->assertDontSee('Raumverweis zurücksetzen');

    NostrAuth::logout();

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertDontSee('Raumverweis zurücksetzen');
});

it('keeps voters anonymous and shows only the tally', function () {
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $submitter->id,
    ]);

    // Ein Vorstandsmitglied (bindende Stimme) und ein Mitglied (Stimmungsbild),
    // beide mit vollem Profil — nichts davon darf auf der Seite landen.
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    Profile::factory()->create([
        'pubkey' => $board->pubkey,
        'name' => 'GeheimerVorstand',
        'display_name' => 'Geheimer Vorstand',
        'picture' => 'https://example.test/vorstand-avatar.jpg',
    ]);
    Vote::factory()->approve()->create([
        'project_proposal_id' => $project->id,
        'einundzwanzig_pleb_id' => $board->id,
    ]);

    // npub explizit: die Factory setzt sonst ein Lorem-Wort, das im Markup
    // zufaellig vorkommt und die Anonymitaets-Pruefung wertlos macht.
    $member = EinundzwanzigPleb::factory()->create(['npub' => 'npub1geheimesmitglied']);
    Profile::factory()->create([
        'pubkey' => $member->pubkey,
        'name' => 'GeheimesMitglied',
        'display_name' => 'Geheimes Mitglied',
        'picture' => 'https://example.test/mitglied-avatar.jpg',
    ]);
    Vote::factory()->reject()->create([
        'project_proposal_id' => $project->id,
        'einundzwanzig_pleb_id' => $member->id,
    ]);

    NostrAuth::login($submitter->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->assertDontSee('GeheimerVorstand')
        ->assertDontSee('Geheimer Vorstand')
        ->assertDontSee('vorstand-avatar.jpg')
        ->assertDontSee('GeheimesMitglied')
        ->assertDontSee('Geheimes Mitglied')
        ->assertDontSee('mitglied-avatar.jpg')
        ->assertDontSee($board->npub)
        ->assertDontSee($member->npub)
        // Die Zahlen bleiben sichtbar.
        ->assertSee('Zustimmung')
        ->assertSee('Ablehnung');
});

// Filter "Fehlende Stimme" — die Arbeitsliste des Vorstands
it('offers the missing-vote filter only to board members', function () {
    ProjectProposal::factory()->create();

    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.index')
        ->assertSee('Fehlende Stimme');

    $member = EinundzwanzigPleb::factory()->create();
    NostrAuth::login($member->pubkey);

    Livewire::test('association.project-support.index')
        ->assertDontSee('Fehlende Stimme');

    NostrAuth::logout();

    Livewire::test('association.project-support.index')
        ->assertDontSee('Fehlende Stimme');
});

it('lists only proposals still in voting where the board member has not voted yet', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();

    $offen = ProjectProposal::factory()->create(['name' => 'Antrag ohne meine Stimme']);

    $schonGestimmt = ProjectProposal::factory()->create(['name' => 'Antrag mit meiner Stimme']);
    Vote::factory()->approve()->create([
        'project_proposal_id' => $schonGestimmt->id,
        'einundzwanzig_pleb_id' => $board->id,
    ]);

    // Beschlossen: die absolute Mehrheit steht, eine weitere Stimme aendert nichts.
    $beschlossen = ProjectProposal::factory()->create(['name' => 'Antrag bereits beschlossen']);
    EinundzwanzigPleb::query()
        ->whereIn('npub', config('einundzwanzig.config.current_board'))
        ->where('id', '!=', $board->id)
        ->take(ProjectProposal::boardVoteThreshold())
        ->get()
        ->each(fn ($pleb) => Vote::factory()->approve()->create([
            'project_proposal_id' => $beschlossen->id,
            'einundzwanzig_pleb_id' => $pleb->id,
        ]));

    // Ausgezahlt: ebenfalls erledigt.
    $ausgezahlt = ProjectProposal::factory()->create(['name' => 'Antrag ausgezahlt', 'sats_paid' => 21000]);

    NostrAuth::login($board->pubkey);

    $component = Livewire::test('association.project-support.index')
        ->call('setFilter', 'awaiting_my_vote')
        ->assertSet('activeFilter', 'awaiting_my_vote');

    $namen = $component->get('projects')->pluck('name');

    expect($namen)->toContain('Antrag ohne meine Stimme')
        ->and($namen)->not->toContain('Antrag mit meiner Stimme')
        ->and($namen)->not->toContain('Antrag bereits beschlossen')
        ->and($namen)->not->toContain('Antrag ausgezahlt');
});

it('refuses the missing-vote filter for a non board member on every path', function () {
    ProjectProposal::factory()->create(['name' => 'Sichtbarer Antrag']);

    $member = EinundzwanzigPleb::factory()->create();
    NostrAuth::login($member->pubkey);

    // Direkter Livewire-Aufruf: der Filter bleibt, wo er war.
    Livewire::test('association.project-support.index')
        ->call('setFilter', 'awaiting_my_vote')
        ->assertSet('activeFilter', 'all');

    // Ueber die URL vorbelegt: faellt beim Mount auf 'all' zurueck.
    Livewire::withQueryParams(['status' => 'awaiting_my_vote'])
        ->test('association.project-support.index')
        ->assertSet('activeFilter', 'all')
        ->assertSee('Sichtbarer Antrag');

    // Ueber den mobilen Select direkt gesetzt.
    Livewire::test('association.project-support.index')
        ->set('activeFilter', 'awaiting_my_vote')
        ->assertSet('activeFilter', 'all');
});
