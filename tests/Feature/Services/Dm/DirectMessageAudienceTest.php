<?php

use App\Enums\AssociationStatus;
use App\Enums\DmAudience;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionFeeRule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Services\Dm\DirectMessageAudience;
use App\Services\Election\ElectoralRoll;
use App\Services\MembershipService;

/**
 * @return list<int>
 */
function audienceIds(DmAudience $audience, ?Election $election = null): array
{
    return app(DirectMessageAudience::class)->query($audience, $election)->pluck('id')->all();
}

function audienceMember(AssociationStatus $status, ?int $paidYear = null): EinundzwanzigPleb
{
    $factory = EinundzwanzigPleb::factory()->state(['association_status' => $status]);

    return ($paidYear === null ? $factory : $factory->withPaidYear($paidYear))->create();
}

it('resolves "all members" to active and honorary members, never passive, non-members or erased ones', function () {
    $active = audienceMember(AssociationStatus::ACTIVE);
    $honorary = audienceMember(AssociationStatus::HONORARY);
    $passive = audienceMember(AssociationStatus::PASSIVE, 2027);
    $nonMember = audienceMember(AssociationStatus::DEFAULT);
    $erased = audienceMember(AssociationStatus::ACTIVE, 2027);
    app(MembershipService::class)->erasePersonalData($erased);

    expect(audienceIds(DmAudience::Members))->toBe([$active->id, $honorary->id])
        ->and(app(DirectMessageAudience::class)->describe(DmAudience::Members))
        ->toBe('2 Aktiv- und Ehrenmitglieder, Beitrag bezahlt oder nicht');
});

/*
 * Board jury L6: „Alle Mitglieder mit Stimmrecht“ counted an unpaid active
 * member the roll does not count. The audience is every active and honorary
 * member, paid or not; neither its label nor its sentence may promise a vote.
 * Who may vote in an election is `EntitledVoters`.
 */
it('names "all members" for what it is, without promising a vote', function () {
    audienceMember(AssociationStatus::ACTIVE);

    expect(DmAudience::Members->label())->toBe('Aktiv- und Ehrenmitglieder')
        ->and(app(DirectMessageAudience::class)->describe(DmAudience::Members))->toBe('1 Aktiv- oder Ehrenmitglied, Beitrag bezahlt oder nicht')
        ->and(DmAudience::Members->label().app(DirectMessageAudience::class)->describe(DmAudience::Members))->not->toContain('Stimmrecht');
});

it('resolves the entitled voters through the electoral roll, including members enrolled by hand', function () {
    $election = Election::factory()->feeRule(ElectionFeeRule::Year, 2027)->create(['name' => 'Vorstandswahl 2027']);
    $paid = audienceMember(AssociationStatus::ACTIVE, 2027);
    $paidLastYear = audienceMember(AssociationStatus::ACTIVE, 2026);
    $passivePaid = audienceMember(AssociationStatus::PASSIVE, 2027);
    $byHand = audienceMember(AssociationStatus::ACTIVE);
    $otherElectionsVoter = audienceMember(AssociationStatus::ACTIVE);

    app(ElectoralRoll::class)->enrolByHand(ElectionRound::factory()->for($election)->create(), $byHand, now());
    app(ElectoralRoll::class)->enrolByHand(ElectionRound::factory()->create(), $otherElectionsVoter, now());

    expect(audienceIds(DmAudience::EntitledVoters, $election))->toBe([$paid->id, $byHand->id])
        ->and(app(DirectMessageAudience::class)->describe(DmAudience::EntitledVoters, $election))
        ->toBe('2 Mitglieder, die für die Wahl „Vorstandswahl 2027“ stimmberechtigt sind');

    expect($paidLastYear->id)->not->toBeIn(audienceIds(DmAudience::EntitledVoters, $election))
        ->and($passivePaid->id)->not->toBeIn(audienceIds(DmAudience::EntitledVoters, $election));
});

it('resolves "fee outstanding" to active and honorary members the fee rule does not entitle', function () {
    $election = Election::factory()->feeRule(ElectionFeeRule::Year, 2027)->honoraryWithoutFee()->create(['name' => 'Vorstandswahl 2027']);
    $unpaid = audienceMember(AssociationStatus::ACTIVE, 2026);
    $paid = audienceMember(AssociationStatus::ACTIVE, 2027);
    $honoraryExempt = audienceMember(AssociationStatus::HONORARY);
    $passive = audienceMember(AssociationStatus::PASSIVE);

    expect(audienceIds(DmAudience::FeeOutstanding, $election))->toBe([$unpaid->id])
        ->and(app(DirectMessageAudience::class)->describe(DmAudience::FeeOutstanding, $election))
        ->toBe('1 Mitglied ohne bezahlten Beitrag für 2027 (Wahl „Vorstandswahl 2027“)');
});

it('names the period of the fee rule in the fee reminder sentence', function (ElectionFeeRule $rule, ?int $year, string $period) {
    $election = Election::factory()->feeRule($rule, $year)->create(['name' => 'W']);

    expect(app(DirectMessageAudience::class)->describe(DmAudience::FeeOutstanding, $election, 3))
        ->toBe("3 Mitglieder ohne bezahlten Beitrag {$period} (Wahl „W“)");
})->with([
    'year' => [ElectionFeeRule::Year, 2027, 'für 2027'],
    'year or previous' => [ElectionFeeRule::YearOrPrevious, 2027, 'für 2026 oder 2027'],
    'last twelve months' => [ElectionFeeRule::LastTwelveMonths, null, 'in den letzten zwölf Monaten'],
]);

it('resolves the candidates to pending and approved candidacies of this election only', function () {
    $election = Election::factory()->create(['name' => 'Vorstandswahl 2027']);
    $round = ElectionRound::factory()->for($election)->create();
    $approved = ElectionCandidate::factory()->for($round, 'round')->create();
    $pending = ElectionCandidate::factory()->for($round, 'round')->pending()->create();
    ElectionCandidate::factory()->for($round, 'round')->status(ElectionCandidateStatus::Withdrawn)->create();
    ElectionCandidate::factory()->for($round, 'round')->status(ElectionCandidateStatus::Rejected)->create();
    ElectionCandidate::factory()->create();

    expect(audienceIds(DmAudience::Candidates, $election))
        ->toBe([$approved->einundzwanzig_pleb_id, $pending->einundzwanzig_pleb_id])
        ->and(app(DirectMessageAudience::class)->describe(DmAudience::Candidates, $election))
        ->toBe('2 Kandidierende der Wahl „Vorstandswahl 2027“');
});

it('resolves the board from the configured board list', function () {
    $board = EinundzwanzigPleb::factory()->boardMember()->create();
    audienceMember(AssociationStatus::ACTIVE, 2027);

    expect(audienceIds(DmAudience::Board))->toBe([$board->id])
        ->and(app(DirectMessageAudience::class)->describe(DmAudience::Board))->toBe('1 Vorstandsmitglied');
});

it('refuses an election audience without an election instead of resolving it to everyone', function () {
    app(DirectMessageAudience::class)->query(DmAudience::EntitledVoters);
})->throws(InvalidArgumentException::class);
