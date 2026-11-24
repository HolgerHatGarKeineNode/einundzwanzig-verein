<?php

use App\Enums\AssociationStatus;
use App\Exceptions\MemberStatusLockedException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\MembershipService;

/*
 * The board decisions on an application, without the members page around
 * them. The page tests in Members/AdminTest pin the same rules through the
 * UI; these pin that the rules live in the service.
 */

function applicantFor(AssociationStatus $current, ?int $applicationFor, ?string $text = 'Ich moechte aktiv mitarbeiten.'): EinundzwanzigPleb
{
    return EinundzwanzigPleb::factory()->create([
        'association_status' => $current,
        'application_for' => $applicationFor,
        'application_text' => $text,
    ]);
}

it('accepts a pending application, raises the status and archives the text', function () {
    $pleb = applicantFor(AssociationStatus::PASSIVE, AssociationStatus::ACTIVE->value);

    expect(app(MembershipService::class)->acceptApplication($pleb))->toBeTrue();

    $fresh = $pleb->fresh();

    expect($fresh->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and($fresh->application_for)->toBeNull()
        ->and($fresh->application_text)->toBeNull()
        ->and($fresh->archived_application_text)->toBe('Ich moechte aktiv mitarbeiten.');
});

it('never demotes when accepting', function () {
    $pleb = applicantFor(AssociationStatus::HONORARY, AssociationStatus::PASSIVE->value);

    expect(app(MembershipService::class)->acceptApplication($pleb))->toBeTrue()
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::HONORARY);
});

it('does nothing without a valid pending application', function (?int $applicationFor) {
    $pleb = applicantFor(AssociationStatus::PASSIVE, $applicationFor, null);

    expect(app(MembershipService::class)->acceptApplication($pleb))->toBeFalse();

    $fresh = $pleb->fresh();

    expect($fresh->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and($fresh->application_for)->toBe($applicationFor)
        ->and($fresh->archived_application_text)->toBeNull();
})->with(['none pending' => null, 'unknown status' => 99]);

it('refuses to accept while a real election locks member status', function () {
    $election = Election::factory()->announced()->create(['name' => 'Vorstandswahl 2026']);
    ElectionRound::factory()->create(['election_event_id' => $election->id]);
    $pleb = applicantFor(AssociationStatus::PASSIVE, AssociationStatus::ACTIVE->value);

    try {
        app(MembershipService::class)->acceptApplication($pleb);
        $this->fail('Expected the election lock to refuse the change.');
    } catch (MemberStatusLockedException $locked) {
        expect($locked->election->is($election))->toBeTrue();
    }

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and($pleb->fresh()->application_for)->toBe(AssociationStatus::ACTIVE->value);
});

it('rejects an application and keeps the status', function () {
    $pleb = applicantFor(AssociationStatus::PASSIVE, AssociationStatus::ACTIVE->value);

    app(MembershipService::class)->rejectApplication($pleb);

    $fresh = $pleb->fresh();

    expect($fresh->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and($fresh->application_for)->toBeNull()
        ->and($fresh->application_text)->toBeNull()
        ->and($fresh->archived_application_text)->toBeNull();
});
