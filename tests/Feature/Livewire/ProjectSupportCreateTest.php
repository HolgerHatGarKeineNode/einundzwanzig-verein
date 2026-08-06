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
});

it('renders create form for authorized users', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->assertStatus(200)
        ->assertSee('Projektförderungs-Antrag anlegen');
});

it('does not render create form for unauthorized users', function () {
    $unauthorizedPleb = EinundzwanzigPleb::query()->create([
        'pubkey' => 'test_pubkey_'.Str::random(20),
        'npub' => 'test_npub_'.Str::random(20),
    ]);

    $unauthorizedPleb->association_status = AssociationStatus::DEFAULT;
    $unauthorizedPleb->save();

    NostrAuth::login($unauthorizedPleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->assertSet('isAllowed', false)
        ->assertDontSee('Projektförderung anlegen');
});

it('validates required name field', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->set('form.name', '')
        ->set('form.description', 'Test description')
        ->call('save')
        ->assertHasErrors(['form.name']);
});

it('validates name max length', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->set('form.name', Str::random(300))
        ->set('form.description', 'Test description')
        ->call('save')
        ->assertHasErrors(['form.name']);
});

it('validates required description field', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->set('form.name', 'Test Project')
        ->set('form.description', '')
        ->call('save')
        ->assertHasErrors(['form.description']);
});

it('creates project proposal successfully', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->set('form.name', 'Test Project')
        ->set('form.description', 'This is a test project for unit testing purposes.')
        ->set('form.support_in_sats', 21000)
        ->set('form.website', 'https://example.com')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('association.projectSupport'));

    expect(ProjectProposal::count())->toBe(1);
    $project = ProjectProposal::first();
    expect($project->name)->toBe('Test Project')
        ->and($project->description)->toBe('<p>This is a test project for unit testing purposes.</p>');
});

it('defaults to nostr dm contact and stores it that way when unchanged', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->assertSet('form.contact_via_nostr_dm', true)
        ->set('form.name', 'Test Project')
        ->set('form.description', 'Test description')
        ->set('form.support_in_sats', 21000)
        ->set('form.website', 'https://example.com')
        ->call('save')
        ->assertHasNoErrors();

    $project = ProjectProposal::first();
    expect($project->contact_via_nostr_dm)->toBeTrue()
        ->and($project->contact_alternative)->toBeNull();
});

it('saves opting out of nostr dm with no alternative channel without validation errors', function () {
    // Both fields are optional — "no DM, no alternative" means "don't
    // contact me at all" and is a valid choice, not an incomplete form.
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->set('form.name', 'Test Project')
        ->set('form.description', 'Test description')
        ->set('form.support_in_sats', 21000)
        ->set('form.website', 'https://example.com')
        ->set('form.contact_via_nostr_dm', false)
        ->set('form.contact_alternative', '')
        ->call('save')
        ->assertHasNoErrors();

    $project = ProjectProposal::first();
    expect($project->contact_via_nostr_dm)->toBeFalse()
        ->and($project->contact_alternative)->toBeNull();
});

it('saves an alternative contact channel when dm is opted out', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->set('form.name', 'Test Project')
        ->set('form.description', 'Test description')
        ->set('form.support_in_sats', 21000)
        ->set('form.website', 'https://example.com')
        ->set('form.contact_via_nostr_dm', false)
        ->set('form.contact_alternative', 'telegram:@example')
        ->call('save')
        ->assertHasNoErrors();

    $project = ProjectProposal::first();
    expect($project->contact_via_nostr_dm)->toBeFalse()
        ->and($project->contact_alternative)->toBe('telegram:@example');
});

it('associates project proposal with current pleb', function () {
    NostrAuth::login($this->pleb->pubkey);

    Livewire::test('association.project-support.form.create')
        ->set('form.name', 'Test Project')
        ->set('form.description', 'Test description')
        ->set('form.support_in_sats', 21000)
        ->set('form.website', 'https://example.com')
        ->call('save')
        ->assertHasNoErrors();

    $project = ProjectProposal::first();
    expect($project->einundzwanzig_pleb_id)->toBe($this->pleb->id);
});
