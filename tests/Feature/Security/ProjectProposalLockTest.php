<?php

use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Models\Vote;
use App\Support\NostrAuth;
use Livewire\Livewire;
use swentel\nostr\Key\Key;

/**
 * S1 of the page optimisation plan: once the board has started deciding on a
 * proposal, the submitter must no longer be able to change what is being
 * decided on, nor wipe the votes by deleting it. The amount the board approved
 * is frozen at the deciding vote and caps the payout.
 *
 * Measured at e43fe90: 100k requested, board accepted, submitter raised it to
 * 5M, the payout form was prefilled with 5M, and deleting the proposal took
 * the votes with it (cascade).
 */
beforeEach(function () {
    collect(config('einundzwanzig.config.current_board'))->each(
        fn (string $npub) => EinundzwanzigPleb::factory()->create(['npub' => $npub, 'pubkey' => (new Key)->convertToHex($npub)])
    );
});

/**
 * Casts the board's approvals through the real page, one member at a time,
 * until the absolute majority is reached.
 */
function acceptThroughBoard(ProjectProposal $project): void
{
    EinundzwanzigPleb::query()
        ->whereIn('id', ProjectProposal::boardPlebIds())
        ->take(ProjectProposal::boardVoteThreshold())
        ->get()
        ->each(function (EinundzwanzigPleb $board) use ($project) {
            NostrAuth::login($board->pubkey);

            Livewire::test('association.project-support.show', ['projectProposal' => $project])
                ->call('castVote', true)
                ->assertHasNoErrors();
        });
}

function firstBoardMember(): EinundzwanzigPleb
{
    return EinundzwanzigPleb::query()
        ->where('npub', config('einundzwanzig.config.current_board')[0])
        ->firstOrFail();
}

it('refuses the submitter raising the amount after the board accepted', function () {
    $owner = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $owner->id,
        'support_in_sats' => 100000,
    ]);
    acceptThroughBoard($project);

    NostrAuth::login($owner->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $project])
        ->assertSet('isAllowed', false)
        ->set('form.name', $project->name)
        ->set('form.description', 'Now much more expensive')
        ->set('form.support_in_sats', 5000000)
        ->set('form.website', 'https://example.com')
        ->call('update')
        ->assertForbidden();

    expect($project->fresh()->support_in_sats)->toBe(100000);
});

it('refuses the submitter editing once a single board vote exists', function () {
    $owner = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $owner->id]);
    Vote::create([
        'project_proposal_id' => $project->id,
        'einundzwanzig_pleb_id' => firstBoardMember()->id,
        'value' => false,
    ]);

    NostrAuth::login($owner->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $project])
        ->call('update')
        ->assertForbidden();
});

it('still lets the submitter edit and delete while only members, not the board, voted', function () {
    $owner = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $owner->id]);
    Vote::create([
        'project_proposal_id' => $project->id,
        'einundzwanzig_pleb_id' => EinundzwanzigPleb::factory()->create()->id,
        'value' => true,
    ]);

    NostrAuth::login($owner->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $project])
        ->assertSet('isAllowed', true);

    Livewire::test('association.project-support.index')
        ->call('confirmDeleteProject', $project->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(ProjectProposal::query()->find($project->id))->toBeNull();
});

it('refuses the submitter deleting a proposal the board has voted on, keeping the votes', function () {
    $owner = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $owner->id]);
    acceptThroughBoard($project);

    NostrAuth::login($owner->pubkey);

    Livewire::test('association.project-support.index')
        ->call('confirmDeleteProject', $project->id)
        ->assertForbidden();

    expect(ProjectProposal::query()->find($project->id))->not->toBeNull()
        ->and(Vote::query()->where('project_proposal_id', $project->id)->count())
        ->toBe(ProjectProposal::boardVoteThreshold());
});

it('refuses the submitter deleting a proposal that was paid out', function () {
    $owner = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $owner->id]);
    $project->sats_paid = 21000;
    $project->save();

    NostrAuth::login($owner->pubkey);

    Livewire::test('association.project-support.index')
        ->call('confirmDeleteProject', $project->id)
        ->assertForbidden();

    expect(ProjectProposal::query()->find($project->id))->not->toBeNull();
});

it('freezes the approved amount at the deciding board vote', function () {
    $project = ProjectProposal::factory()->create(['support_in_sats' => 100000]);

    acceptThroughBoard($project);

    expect($project->fresh()->approved_sats)->toBe(100000);
});

it('prefills the payout with the approved amount, not a later raise', function () {
    $project = ProjectProposal::factory()->create(['support_in_sats' => 100000]);
    acceptThroughBoard($project);

    // A board member may still edit the proposal; the approved amount must not follow.
    $project->fresh()->update(['support_in_sats' => 5000000]);

    NostrAuth::login(firstBoardMember()->pubkey);

    // fresh(): mount() reads the model it is handed, as the route would load it.
    Livewire::test('association.project-support.show', ['projectProposal' => $project->fresh()])
        ->assertSet('payoutSats', 100000);
});

it('refuses a payout above the approved amount', function () {
    $project = ProjectProposal::factory()->create(['support_in_sats' => 100000]);
    acceptThroughBoard($project);
    $project->fresh()->update(['support_in_sats' => 5000000]);

    NostrAuth::login(firstBoardMember()->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project->fresh()])
        ->set('payoutSats', 5000000)
        ->call('recordPayout')
        ->assertHasErrors(['payoutSats']);

    expect((int) $project->fresh()->sats_paid)->toBe(0);
});

it('accepts a payout up to the approved amount', function () {
    $project = ProjectProposal::factory()->create(['support_in_sats' => 100000]);
    acceptThroughBoard($project);

    NostrAuth::login(firstBoardMember()->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->set('payoutSats', 100000)
        ->call('recordPayout')
        ->assertHasNoErrors();

    expect($project->fresh()->sats_paid)->toBe(100000);
});
