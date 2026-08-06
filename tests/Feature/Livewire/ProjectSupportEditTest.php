<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Support\NostrAuth;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->pleb = EinundzwanzigPleb::query()->create([
        'pubkey' => 'test_pubkey_'.Str::random(20),
        'npub' => 'test_npub_'.Str::random(20),
    ]);

    $this->pleb->association_status = AssociationStatus::ACTIVE;
    $this->pleb->save();

    // Create payment event for the current year
    $this->pleb->paymentEvents()->create([
        'year' => date('Y'),
        'amount' => 21000,
        'paid' => true,
        'event_id' => 'test_event_'.Str::random(40),
    ]);

    $this->project = new ProjectProposal;
    $this->project->einundzwanzig_pleb_id = $this->pleb->id;
    $this->project->name = 'Original Project';
    $this->project->description = 'Original Description';
    $this->project->support_in_sats = 21000;
    $this->project->website = 'https://original.example.com';
    $this->project->accepted = false;
    $this->project->sats_paid = 0;
    $this->project->save();

    // Get board member pubkeys from config
    $this->boardMember = EinundzwanzigPleb::query()->create([
        'pubkey' => 'board_pubkey_'.Str::random(20),
        'npub' => 'board_npub_'.Str::random(20),
    ]);

    $this->boardMember->association_status = AssociationStatus::HONORARY;
    $this->boardMember->save();

    // Simulate board member by temporarily updating config for testing
    config(['einundzwanzig.config.current_board' => [$this->boardMember->npub]]);
});

it('renders edit form for authorized project owners', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->assertStatus(200)
        ->assertSee('Projektförderungs-Antrag bearbeiten')
        ->assertSet('form.name', $this->project->name)
        ->assertSet('form.description', $this->project->description);
});

it('renders edit form for board members', function () {
    NostrAuth::login($this->boardMember->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->assertStatus(200)
        ->assertSee('Projektförderungs-Antrag bearbeiten');
});

it('does not render edit form for unauthorized users', function () {
    $unauthorizedPleb = EinundzwanzigPleb::query()->create([
        'pubkey' => 'test_pubkey_'.Str::random(20),
        'npub' => 'test_npub_'.Str::random(20),
    ]);

    $unauthorizedPleb->association_status = AssociationStatus::ACTIVE;
    $unauthorizedPleb->save();

    NostrAuth::login($unauthorizedPleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->assertSet('isAllowed', false);
});

it('validates required name field', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->set('form.name', '')
        ->set('form.description', 'Test description')
        ->call('update')
        ->assertHasErrors(['form.name']);
});

it('validates required description field', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->set('form.name', 'Test Project')
        ->set('form.description', '')
        ->call('update')
        ->assertHasErrors(['form.description']);
});

it('updates project proposal successfully', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->set('form.name', 'Updated Name')
        ->set('form.description', 'Updated Description')
        ->call('update')
        ->assertHasNoErrors();

    $this->project->refresh();
    expect($this->project->name)->toBe('Updated Name')
        ->and($this->project->description)->toBe('<p>Updated Description</p>');
});

it('ignores accepted and sats_paid form keys even for board members — those fields no longer exist on the edit form', function () {
    // accepted/sats_paid and the $isAdmin gate were removed from this
    // component; a payout is now only ever recorded through recordPayout()
    // on the detail page (see ProjectSupportTest). Setting these as raw
    // form-array keys just adds throwaway entries — update() only ever
    // writes the fixed set of keys below, so a board member editing the
    // form can no longer touch accepted/sats_paid this way either.
    NostrAuth::login($this->boardMember->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->set('form.accepted', true)
        ->set('form.sats_paid', 50000)
        ->set('form.name', 'Updated by board')
        ->set('form.description', 'Updated by board')
        ->call('update')
        ->assertHasNoErrors();

    $this->project->refresh();
    expect($this->project->accepted)->toBeFalse()
        ->and($this->project->sats_paid)->toBe(0)
        ->and($this->project->name)->toBe('Updated by board');
});

it('saves opting out of nostr dm with no alternative channel without validation errors', function () {
    // Both fields are optional — "no DM, no alternative" means "don't
    // contact me at all" and is a valid choice, not an incomplete form.
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->set('form.contact_via_nostr_dm', false)
        ->set('form.contact_alternative', '')
        ->call('update')
        ->assertHasNoErrors();

    $this->project->refresh();
    expect($this->project->contact_via_nostr_dm)->toBeFalse()
        ->and($this->project->contact_alternative)->toBeNull();
});

it('saves an alternative contact channel when dm is opted out', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->set('form.contact_via_nostr_dm', false)
        ->set('form.contact_alternative', 'email@example.com')
        ->call('update')
        ->assertHasNoErrors();

    $this->project->refresh();
    expect($this->project->contact_via_nostr_dm)->toBeFalse()
        ->and($this->project->contact_alternative)->toBe('email@example.com');
});

it('disables update button during save', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $this->project])
        ->set('form.name', 'Test')
        ->set('form.description', 'Test')
        ->call('update')
        ->assertSeeHtml('wire:loading');
});
