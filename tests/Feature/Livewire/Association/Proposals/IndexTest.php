<?php

use App\Enums\MemberProposalState;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Models\MemberProposalComment;
use App\Models\MemberProposalVote;
use App\Support\NostrAuth;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/Helpers.php';

/**
 * The ids of the listed proposals, in the order the page shows them.
 *
 * @return list<int>
 */
function listedProposalIds(Testable $page): array
{
    return collect($page->instance()->proposals->items())->map->id->all();
}

it('shows outsiders the members-only notice and no proposal', function (?EinundzwanzigPleb $outsider) {
    MemberProposal::factory()->create(['title' => 'Stammtisch in jedem Kanton']);

    if ($outsider) {
        NostrAuth::login($outsider->pubkey);
    }

    $page = Livewire::test('association.proposals.index')
        ->assertSee('Vorschläge sind nur für Mitglieder sichtbar')
        ->assertDontSee('Stammtisch in jedem Kanton');

    expect(fn () => $page->instance()->proposals)->toThrow(AuthorizationException::class);
})->with('outsiders');

it('tells a logged-in outsider the whole participation rule, both fee years included', function () {
    $this->travelTo('2026-03-01');
    NostrAuth::login(EinundzwanzigPleb::factory()->active()->withPaidYear(2024)->create()->pubkey);

    Livewire::test('association.proposals.index')
        ->assertSee('Ehrenmitglieder')
        ->assertSee('aktive und passive Mitglieder')
        ->assertSee('für 2026 oder 2025 bezahlt');
});

it('lists current proposals and keeps archived, hidden and withdrawn ones out', function () {
    $open = MemberProposal::factory()->create(['title' => 'Offener Vorschlag']);
    $scheduled = MemberProposal::factory()->inState(MemberProposalState::Scheduled)->create();
    MemberProposal::factory()->inState(MemberProposalState::Adopted)->create(['title' => 'Beschlossener Vorschlag']);
    MemberProposal::factory()->hidden()->create(['title' => 'Ausgeblendeter Vorschlag']);
    MemberProposal::factory()->withdrawn()->create(['title' => 'Zurückgezogener Vorschlag']);

    NostrAuth::login(paidMember()->pubkey);

    $page = Livewire::test('association.proposals.index')
        ->assertSee('Offener Vorschlag')
        ->assertDontSee('Beschlossener Vorschlag')
        ->assertDontSee('Ausgeblendeter Vorschlag')
        ->assertDontSee('Zurückgezogener Vorschlag');

    expect(listedProposalIds($page))->toEqualCanonicalizing([$open->id, $scheduled->id]);
});

it('shows a hidden proposal to the board and to its author, marked as hidden', function () {
    $author = paidMember();
    $hidden = MemberProposal::factory()->for($author)->hidden()->create();

    NostrAuth::login($author->pubkey);
    expect(listedProposalIds(Livewire::test('association.proposals.index')->assertSee('Ausgeblendet')))->toBe([$hidden->id]);

    NostrAuth::login(boardMember()->pubkey);
    expect(listedProposalIds(Livewire::test('association.proposals.index')->assertSee('Ausgeblendet')))->toBe([$hidden->id]);
});

it('sorts by support, by newest and by latest activity', function () {
    $this->travelTo(now()->subDays(3));
    $oldest = MemberProposal::factory()->create(['last_activity_at' => now()]);
    $this->travelTo(now()->addDay());
    $middle = MemberProposal::factory()->create(['last_activity_at' => now()]);
    $this->travelTo(now()->addDay());
    $newest = MemberProposal::factory()->create(['last_activity_at' => now()]);
    $this->travelBack();

    // Support: the middle one leads, the oldest follows, the newest has none.
    MemberProposalVote::factory()->for($middle)->up()->count(3)->create();
    MemberProposalVote::factory()->for($oldest)->up()->count(1)->create();
    MemberProposalVote::factory()->for($newest)->down()->count(5)->create();

    // Activity: a fresh comment makes the oldest proposal the latest active.
    $oldest->last_activity_at = now();
    $oldest->save();

    NostrAuth::login(paidMember()->pubkey);

    $page = Livewire::test('association.proposals.index');

    expect(listedProposalIds($page))->toBe([$middle->id, $oldest->id, $newest->id]);
    expect(listedProposalIds($page->set('sortBy', 'newest')))->toBe([$newest->id, $middle->id, $oldest->id]);
    expect(listedProposalIds($page->set('sortBy', 'activity')))->toBe([$oldest->id, $newest->id, $middle->id]);
});

it('falls back to the default sort for a value it does not offer', function () {
    NostrAuth::login(paidMember()->pubkey);

    Livewire::withQueryParams(['sort' => 'title; drop table'])
        ->test('association.proposals.index')
        ->assertSet('sortBy', 'support')
        ->set('sortBy', 'einundzwanzig_pleb_id')
        ->assertSet('sortBy', 'support')
        ->assertOk();
});

it('filters by state', function () {
    $open = MemberProposal::factory()->create();
    $scheduled = MemberProposal::factory()->inState(MemberProposalState::Scheduled)->create();
    NostrAuth::login(paidMember()->pubkey);

    $page = Livewire::test('association.proposals.index');

    expect(listedProposalIds($page->set('stateFilter', 'scheduled')))->toBe([$scheduled->id]);
    expect(listedProposalIds($page->set('stateFilter', 'open')))->toBe([$open->id]);

    // An archived state is not a filter of the current list.
    $page->set('stateFilter', 'adopted')->assertSet('stateFilter', 'all');
    expect(listedProposalIds($page))->toEqualCanonicalizing([$open->id, $scheduled->id]);
});

it('shows the archive with adopted, rejected and done proposals only', function () {
    MemberProposal::factory()->create(['title' => 'Offener Vorschlag']);
    $adopted = MemberProposal::factory()->inState(MemberProposalState::Adopted)->create();
    $rejected = MemberProposal::factory()->inState(MemberProposalState::Rejected)->create();
    $done = MemberProposal::factory()->inState(MemberProposalState::Done)->create();
    NostrAuth::login(paidMember()->pubkey);

    $page = Livewire::test('association.proposals.index', ['archive' => true])
        ->assertSee('Archiv der Vorschläge')
        ->assertDontSee('Offener Vorschlag')
        ->assertDontSee('Vorschlag einreichen');

    expect(listedProposalIds($page))->toEqualCanonicalizing([$adopted->id, $rejected->id, $done->id]);
    expect(listedProposalIds($page->set('stateFilter', 'rejected')))->toBe([$rejected->id]);
});

it('serves the archive under its own route', function () {
    MemberProposal::factory()->create(['title' => 'Offener Vorschlag']);
    MemberProposal::factory()->inState(MemberProposalState::Done)->create(['title' => 'Erledigter Vorschlag']);
    NostrAuth::login(paidMember()->pubkey);

    $this->get(route('association.proposals.archive'))
        ->assertOk()
        ->assertSee('Erledigter Vorschlag')
        ->assertDontSee('Offener Vorschlag');

    $this->get(route('association.proposals.index'))
        ->assertOk()
        ->assertSee('Offener Vorschlag')
        ->assertDontSee('Erledigter Vorschlag');
});

it('shows the two counts and the number of visible comments per proposal', function () {
    $proposal = MemberProposal::factory()->create();
    MemberProposalVote::factory()->for($proposal)->up()->count(4)->create();
    MemberProposalVote::factory()->for($proposal)->down()->count(1)->create();
    MemberProposalComment::factory()->for($proposal)->count(2)->create();
    MemberProposalComment::factory()->for($proposal)->hidden()->create();
    NostrAuth::login(paidMember()->pubkey);

    $page = Livewire::test('association.proposals.index')
        ->assertSeeHtml('data-test="up-count">4<')
        ->assertSeeHtml('data-test="down-count">1<');

    expect($page->instance()->proposals->first()->visible_comments_count)->toBe(2);
});

it('offers submitting to paying members and the meeting view to the board only', function () {
    NostrAuth::login(paidMember()->pubkey);

    Livewire::test('association.proposals.index')
        ->assertSeeHtml(route('association.proposals.create'))
        ->assertDontSeeHtml(route('association.proposals.board'));

    // An active board member without a fee: the board role alone opens the
    // meeting view, not submitting.
    NostrAuth::login(EinundzwanzigPleb::factory()->boardMember()->active()->create()->pubkey);

    Livewire::test('association.proposals.index')
        ->assertSeeHtml(route('association.proposals.board'))
        ->assertDontSeeHtml(route('association.proposals.create'));
});

it('has a navigation entry that leads to the proposals', function () {
    $this->get(route('association.proposals.index'))
        ->assertOk()
        ->assertSee('href="'.route('association.proposals.index').'"', false)
        ->assertSee('Vorschläge');
});
