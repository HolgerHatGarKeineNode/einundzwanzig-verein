<?php

use App\Auth\NostrUser;
use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use Illuminate\Support\Facades\Gate;

// viewAny
it('allows anyone to view any elections', function () {
    expect(Gate::forUser(null)->allows('viewAny', Election::class))->toBeTrue();
});

// view
it('allows anyone to view an election', function () {
    $election = Election::factory()->create();

    expect(Gate::forUser(null)->allows('view', $election))->toBeTrue();
});

// create
it('allows board member to create elections', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('create', Election::class))->toBeTrue();
});

it('denies non-board member from creating elections', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('create', Election::class))->toBeFalse();
});

// update
it('allows board member to update an election', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->create();
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('update', $election))->toBeTrue();
});

it('denies non-board member from updating an election', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('update', $election))->toBeFalse();
});

// delete
it('allows board member to delete an election', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->create();
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('delete', $election))->toBeTrue();
});

it('denies non-board member from deleting an election', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('delete', $election))->toBeFalse();
});

// vote
it('allows active member to vote in an election', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('vote', $election))->toBeTrue();
});

it('allows honorary member to vote in an election', function () {
    $pleb = EinundzwanzigPleb::factory()->withPaidCurrentYear()->create([
        'association_status' => AssociationStatus::HONORARY,
    ]);
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('vote', $election))->toBeTrue();
});

it('denies an active member whose current year is unpaid', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect($pleb->hasPaidMembership())->toBeFalse()
        ->and(Gate::forUser($nostrUser)->allows('vote', $election))->toBeFalse();
});

it('denies an honorary member whose current year is unpaid', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::HONORARY,
    ]);
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('vote', $election))->toBeFalse();
});

it('denies an active member who only paid a previous year', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();
    $pleb->paymentEvents()->create([
        'year' => (int) date('Y') - 1,
        'amount' => 21000,
        'paid' => true,
        'event_id' => 'test_event_previous_year',
    ]);
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('vote', $election))->toBeFalse();
});

it('denies passive member from voting in an election', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::PASSIVE,
    ]);
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('vote', $election))->toBeFalse();
});

it('denies default (non-member) from voting in an election', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $election = Election::factory()->create();
    $nostrUser = new NostrUser($pleb->pubkey);

    expect(Gate::forUser($nostrUser)->allows('vote', $election))->toBeFalse();
});

it('denies unauthenticated users from voting in an election', function () {
    $election = Election::factory()->create();

    expect(Gate::forUser(null)->allows('vote', $election))->toBeFalse();
});
