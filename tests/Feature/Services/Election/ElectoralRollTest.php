<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionRollReason;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRollEntry;
use App\Models\ElectionRound;
use App\Models\PaymentEvent;
use App\Services\Election\CloseRound;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\OpenRound;
use App\Services\MembershipService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

require_once __DIR__.'/helpers.php';

/*
 * Who is on the electoral roll, and when the roll is written: a snapshot at
 * the opening, late payers added by their payment, nobody ever removed.
 */

/**
 * The roll of a round as `member id => [reason, source]`.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function electionRollOf(ElectionRound $round): array
{
    return ElectionRollEntry::query()
        ->where('election_round_id', $round->id)
        ->orderBy('einundzwanzig_pleb_id')
        ->get()
        ->mapWithKeys(fn (ElectionRollEntry $entry): array => [
            $entry->einundzwanzig_pleb_id => [$entry->reason->value, $entry->source->value],
        ])
        ->all();
}

function memberWithStatus(AssociationStatus $status, ?int $paidYear = null): EinundzwanzigPleb
{
    $factory = EinundzwanzigPleb::factory();

    return ($paidYear === null ? $factory : $factory->withPaidYear($paidYear))
        ->create(['association_status' => $status]);
}

/**
 * Settle an open fee the way the webhook does.
 */
function settleElectionFee(EinundzwanzigPleb $pleb, int $year): PaymentEvent
{
    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => $year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => 'invoice-'.fake()->uuid(),
    ]);

    $result = app(MembershipService::class)->markPaid($paymentEvent, [
        'id' => $paymentEvent->btc_pay_invoice,
        'status' => 'Settled',
        'amount' => '21000',
        'currency' => 'SATS',
    ]);

    expect($result['settled'])->toBeTrue();

    return $paymentEvent;
}

it('puts active and honorary members with a paid fee on the roll, and nobody else', function () {
    $this->travelTo(Carbon::parse('2027-03-20 14:00:00'));

    $active = memberWithStatus(AssociationStatus::ACTIVE, 2027);
    $honorary = memberWithStatus(AssociationStatus::HONORARY, 2027);

    memberWithStatus(AssociationStatus::PASSIVE, 2027);
    memberWithStatus(AssociationStatus::DEFAULT, 2027);
    memberWithStatus(AssociationStatus::ACTIVE);
    memberWithStatus(AssociationStatus::ACTIVE, 2026);
    memberWithStatus(AssociationStatus::HONORARY);

    $round = openElectionRound();

    // The candidates the factory made are active members without a fee.
    expect(electionRollOf($round))->toBe([
        $active->id => ['active_fee_paid', 'opening'],
        $honorary->id => ['honorary_fee_paid', 'opening'],
    ]);
});

it('does not count a fee that is on record but not paid', function () {
    $member = memberWithStatus(AssociationStatus::ACTIVE);
    $member->paymentEvents()->create(['year' => (int) now()->year, 'amount' => 21000, 'paid' => false]);

    expect(electionRollOf(openElectionRound()))->toBe([]);
});

it('leaves an erased member off the roll', function () {
    $member = electionVoter();
    app(MembershipService::class)->erasePersonalData($member);

    expect(electionRollOf(openElectionRound()))->toBe([]);
});

it('exempts honorary members from the fee only where the election says so', function () {
    $honorary = memberWithStatus(AssociationStatus::HONORARY);
    $paidHonorary = memberWithStatus(AssociationStatus::HONORARY, (int) now()->year);
    memberWithStatus(AssociationStatus::PASSIVE);
    memberWithStatus(AssociationStatus::ACTIVE);

    $round = openElectionRound(election: Election::factory()->honoraryWithoutFee()->create());

    expect(electionRollOf($round))->toBe([
        $honorary->id => ['honorary_exempt', 'opening'],
        $paidHonorary->id => ['honorary_fee_paid', 'opening'],
    ]);
});

it('applies the fee rule the election names', function (ElectionFeeRule $rule, array $expected) {
    $this->travelTo(Carbon::parse('2027-03-20 14:00:00'));

    $members = [
        'paid 2027' => memberWithStatus(AssociationStatus::ACTIVE, 2027),
        'paid 2026' => memberWithStatus(AssociationStatus::ACTIVE, 2026),
        'paid 2025' => memberWithStatus(AssociationStatus::ACTIVE, 2025),
    ];

    // When each fee was settled: 2 months, 11 months and 13 months ago.
    foreach (['paid 2027' => 2, 'paid 2026' => 11, 'paid 2025' => 13] as $name => $monthsAgo) {
        DB::table('payment_events')
            ->where('einundzwanzig_pleb_id', $members[$name]->id)
            ->update(['updated_at' => now()->subMonths($monthsAgo)]);
    }

    $election = Election::factory()->feeRule($rule, $rule->needsFeeYear() ? 2027 : null)->create();
    $roll = electionRollOf(openElectionRound(election: $election));

    expect(array_keys($roll))->toBe(array_map(fn (string $name): int => $members[$name]->id, $expected));
})->with([
    'year' => [ElectionFeeRule::Year, ['paid 2027']],
    'year or previous' => [ElectionFeeRule::YearOrPrevious, ['paid 2027', 'paid 2026']],
    'last twelve months' => [ElectionFeeRule::LastTwelveMonths, ['paid 2027', 'paid 2026']],
]);

it('takes the roll when the round is opened, not when it is drafted', function () {
    $round = draftElectionRound();
    $voter = electionVoter();

    expect(electionRollOf($round))->toBe([]);

    $round = app(OpenRound::class)->handle($round);

    expect(array_keys(electionRollOf($round)))->toBe([$voter->id]);
});

it('enrols a member whose fee is settled while the round is open', function () {
    $late = memberWithStatus(AssociationStatus::ACTIVE);
    $round = openElectionRound();

    expect(electionRollOf($round))->toBe([]);

    settleElectionFee($late, (int) now()->year);

    expect(electionRollOf($round))->toBe([$late->id => ['active_fee_paid', 'late_payment']]);

    castElectionBallot($round, $late);

    expect(electionRowCounts($round)['marks'])->toBe(1);
});

it('enrols a late payer on a window round that has not begun yet', function () {
    $late = memberWithStatus(AssociationStatus::ACTIVE);
    $round = app(OpenRound::class)->handle(
        ElectionRound::factory()->withCandidates()->window(now()->addDay(), now()->addDays(2))->create()
    );

    settleElectionFee($late, (int) now()->year);

    expect(array_keys(electionRollOf($round)))->toBe([$late->id]);
});

it('does not enrol a late payer who is not entitled', function (string $kind) {
    $year = (int) now()->year;

    [$member, $feeYear] = match ($kind) {
        'a passive member' => [memberWithStatus(AssociationStatus::PASSIVE), $year],
        'a new member the payment makes passive' => [
            EinundzwanzigPleb::factory()->create(['statutes_accepted_at' => now()]),
            $year,
        ],
        'an active member paying for another year' => [memberWithStatus(AssociationStatus::ACTIVE), $year - 1],
    };
    $round = openElectionRound();

    settleElectionFee($member, $feeYear);

    expect(electionRollOf($round))->toBe([]);
})->with([
    'a passive member',
    'a new member the payment makes passive',
    'an active member paying for another year',
]);

it('does not enrol a late payer on a round that is not running', function (string $kind) {
    $late = memberWithStatus(AssociationStatus::ACTIVE);

    $round = match ($kind) {
        'a draft' => draftElectionRound(),
        'a sealed round' => app(CloseRound::class)->handle(openElectionRound()),
        'a round whose window has run out' => tap(openElectionRound(), fn () => test()->travelTo(now()->addHours(2))),
        'an annulled round' => tap(openElectionRound(), fn (ElectionRound $round) => $round->forceFill(['annulled_at' => now()])->save()),
    };

    settleElectionFee($late, (int) now()->year);

    expect(electionRollOf($round))->toBe([]);
})->with(['a draft', 'a sealed round', 'a round whose window has run out', 'an annulled round']);

it('leaves an entry as it is when its member pays again', function () {
    $voter = electionVoter((int) now()->year - 1);
    $round = openElectionRound(election: Election::factory()->feeRule(ElectionFeeRule::YearOrPrevious, (int) now()->year)->create());

    settleElectionFee($voter, (int) now()->year);

    expect(electionRollOf($round))->toBe([$voter->id => ['active_fee_paid', 'opening']]);
});

it('books the payment even when the enrolment fails, and reports the failure', function () {
    Exceptions::fake();

    $late = memberWithStatus(AssociationStatus::ACTIVE);
    $round = openElectionRound();

    $this->mock(ElectoralRoll::class)
        ->shouldReceive('enrolLatePayer')
        ->once()
        ->andThrow(new RuntimeException('roll unavailable'));

    $paymentEvent = settleElectionFee($late, (int) now()->year);

    expect((bool) $paymentEvent->fresh()->paid)->toBeTrue()
        ->and(electionRollOf($round))->toBe([]);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'roll unavailable');
});

it('removes nobody from the roll of an open round', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    $before = electionRollOf($round);

    app(MembershipService::class)->reversePayment($voter->paymentEvents()->sole(), 'invalid', 'test');
    $voter->association_status = AssociationStatus::PASSIVE;
    $voter->save();
    $this->travelTo(now()->addYear()->startOfYear());
    app(ElectoralRoll::class)->enrolLatePayer($voter, now());

    expect(electionRollOf($round))->toBe($before)->toHaveCount(1);
});

it('gives a run-off the roll of the round it belongs to', function () {
    [$first, $second] = [electionVoter(), electionVoter()];
    $election = Election::factory()->create();

    $parent = app(CloseRound::class)->handle(openElectionRound(election: $election));

    // Entitled by now, but was not when the first round opened.
    electionVoter();
    // On the roll of the first round, no longer entitled today.
    $second->forceFill(['association_status' => AssociationStatus::PASSIVE])->save();

    $runOff = openElectionRound(['parent_round_id' => $parent->id], election: $election);

    expect(electionRollOf($runOff))->toBe([
        $first->id => ['active_fee_paid', 'inherited'],
        $second->id => ['active_fee_paid', 'inherited'],
    ]);
});

it('answers the question of entitlement from the stored member, not from the instance', function () {
    $member = electionVoter();
    $election = Election::factory()->create();

    DB::table('einundzwanzig_plebs')->where('id', $member->id)->update(['association_status' => AssociationStatus::PASSIVE->value]);

    expect($member->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and(app(ElectoralRoll::class)->reasonFor($election, $member, now()))->toBeNull();
});

it('names the reason a member is entitled', function () {
    $election = Election::factory()->honoraryWithoutFee()->create();
    $roll = app(ElectoralRoll::class);

    expect($roll->reasonFor($election, electionVoter(), now()))->toBe(ElectionRollReason::ActiveFeePaid)
        ->and($roll->reasonFor($election, memberWithStatus(AssociationStatus::HONORARY), now()))->toBe(ElectionRollReason::HonoraryExempt)
        ->and($roll->reasonFor($election, memberWithStatus(AssociationStatus::PASSIVE, (int) now()->year), now()))->toBeNull();
});
