<?php

use App\Enums\MemberProposalState;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Support\NostrAuth;
use Livewire\Livewire;

require_once __DIR__.'/Helpers.php';

it('lets a paying member submit a proposal under their own name', function () {
    $member = paidMember();
    NostrAuth::login($member->pubkey);

    Livewire::test('association.proposals.form')
        ->assertSet('isAllowed', true)
        ->set('form.title', '  Stammtisch in jedem Kanton ')
        ->set('form.description', 'Ein **Vorschlag**.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirectToRoute('association.proposals.show', MemberProposal::query()->sole());

    $proposal = MemberProposal::query()->sole();

    expect($proposal->einundzwanzig_pleb_id)->toBe($member->id)
        ->and($proposal->title)->toBe('Stammtisch in jedem Kanton')
        ->and($proposal->description)->toBe('Ein **Vorschlag**.')
        ->and($proposal->state)->toBe(MemberProposalState::Open)
        ->and($proposal->is_hidden)->toBeFalse()
        ->and($proposal->board_note)->toBeNull()
        ->and($proposal->last_activity_at)->not->toBeNull();
});

it('refuses a submission from anybody who is not a paying member', function (?EinundzwanzigPleb $outsider) {
    if ($outsider) {
        NostrAuth::login($outsider->pubkey);
    }

    Livewire::test('association.proposals.form')
        ->assertSet('isAllowed', false)
        ->assertSee('Vorschlag kann nicht eingereicht werden')
        ->set('form.title', 'Titel')
        ->set('form.description', 'Text')
        ->call('save')
        ->assertForbidden();

    expect(MemberProposal::query()->count())->toBe(0);
})->with('outsiders');

it('requires a title and a description', function () {
    NostrAuth::login(paidMember()->pubkey);

    Livewire::test('association.proposals.form')
        ->call('save')
        ->assertHasErrors(['form.title', 'form.description']);

    expect(MemberProposal::query()->count())->toBe(0);
});

it('previews the description through the sanitizing renderer', function () {
    NostrAuth::login(paidMember()->pubkey);

    $page = Livewire::test('association.proposals.form')
        ->set('form.description', "**fett** <script>alert('xss')</script> [x](javascript:alert(1))")
        ->assertSeeHtml('<strong>fett</strong>');

    expect($page->html())->not->toContain('<script>alert')
        ->and($page->html())->not->toContain('href="javascript:');
});

it('lets the author change an open proposal', function () {
    $author = paidMember();
    $proposal = MemberProposal::factory()->for($author)->create(['title' => 'Alt', 'description' => 'Alt']);
    NostrAuth::login($author->pubkey);

    Livewire::test('association.proposals.form', ['memberProposal' => $proposal])
        ->assertSet('isAllowed', true)
        ->assertSet('form.title', 'Alt')
        ->set('form.title', 'Neu')
        ->set('form.description', 'Neuer Text')
        ->call('save')
        ->assertRedirectToRoute('association.proposals.show', $proposal);

    expect($proposal->refresh()->title)->toBe('Neu')
        ->and($proposal->description)->toBe('Neuer Text')
        ->and(MemberProposal::query()->count())->toBe(1);
});

it('refuses a change by another member and does not show them the text to edit', function () {
    $proposal = MemberProposal::factory()->create(['title' => 'Fremder Titel', 'description' => 'Fremder Text']);
    NostrAuth::login(paidMember()->pubkey);

    Livewire::test('association.proposals.form', ['memberProposal' => $proposal])
        ->assertSet('isAllowed', false)
        ->assertSet('form.title', '')
        ->set('form.title', 'Gekapert')
        ->set('form.description', 'Gekapert')
        ->call('save')
        ->assertForbidden();

    expect($proposal->refresh()->title)->toBe('Fremder Titel');
});

it('refuses a change by the board', function () {
    $proposal = MemberProposal::factory()->create(['title' => 'Titel des Mitglieds']);
    NostrAuth::login(EinundzwanzigPleb::factory()->boardMember()->withPaidCurrentYear()->create()->pubkey);

    Livewire::test('association.proposals.form', ['memberProposal' => $proposal])
        ->set('form.title', 'Titel des Vorstands')
        ->set('form.description', 'Text')
        ->call('save')
        ->assertForbidden();

    expect($proposal->refresh()->title)->toBe('Titel des Mitglieds');
});

it('accepts no change once the proposal is archived', function (MemberProposalState $state) {
    $author = paidMember();
    $proposal = MemberProposal::factory()->for($author)->inState($state)->create(['title' => 'Beschlossen so']);
    NostrAuth::login($author->pubkey);

    Livewire::test('association.proposals.form', ['memberProposal' => $proposal])
        ->assertSet('isAllowed', false)
        ->assertSee('Dieser Vorschlag kann nicht mehr geändert werden')
        ->set('form.title', 'Nachträglich anders')
        ->set('form.description', 'Text')
        ->call('save')
        ->assertForbidden();

    expect($proposal->refresh()->title)->toBe('Beschlossen so');
})->with('archived proposal states');

it('refuses a change when the board scheduled the proposal while the form was open', function () {
    $author = paidMember();
    $proposal = MemberProposal::factory()->for($author)->create(['title' => 'Wie eingereicht']);
    NostrAuth::login($author->pubkey);

    $page = Livewire::test('association.proposals.form', ['memberProposal' => $proposal])
        ->assertSet('isAllowed', true);

    $proposal->state = MemberProposalState::Scheduled;
    $proposal->save();

    $page->set('form.title', 'Nach der Vormerkung geändert')->call('save')->assertForbidden();

    expect($proposal->refresh()->title)->toBe('Wie eingereicht');
});

it('serves the create and edit routes', function () {
    $author = paidMember();
    $proposal = MemberProposal::factory()->for($author)->create(['title' => 'Mein Vorschlag']);
    NostrAuth::login($author->pubkey);

    $this->get(route('association.proposals.create'))->assertOk()->assertSee('Vorschlag einreichen');
    $this->get(route('association.proposals.edit', $proposal))->assertOk()->assertSee('Vorschlag ändern');
    $this->get(route('association.proposals.show', $proposal))->assertOk()->assertSee('Mein Vorschlag');
    $this->get(route('association.proposals.edit', MemberProposal::factory()->withdrawn()->for($author)->create()))->assertNotFound();
});
