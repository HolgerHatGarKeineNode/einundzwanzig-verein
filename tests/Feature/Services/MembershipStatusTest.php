<?php

use App\Enums\AssociationStatus;
use App\Enums\MembershipStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use App\Services\MembershipService;
use Illuminate\Support\Carbon;

/*
 * The derived membership state at its source.
 *
 * The HTTP tests in tests/Feature/Api/V1/MembershipMeTest.php prove the same
 * four values reach a client. These prove they are computed in ONE place, so a
 * second consumer (run 2, P5, the Volt UI) cannot arrive at a different answer
 * about the same person by combining the enum and the payment history itself.
 */

function membershipStatusOf(?EinundzwanzigPleb $pleb, ?int $year = null): MembershipStatus
{
    return app(MembershipService::class)->membershipStatus($pleb, $year);
}

it('reports none for a subject that does not exist', function () {
    expect(membershipStatusOf(null))->toBe(MembershipStatus::None);
});

it('reports none for a record without an application', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'applied_at' => null,
    ]);

    expect(membershipStatusOf($pleb))->toBe(MembershipStatus::None);
});

it('reports awaiting_payment once an application is on record and the year is unpaid', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'applied_at' => now(),
        'statutes_accepted_at' => now(),
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'paid' => false,
    ]);

    expect(membershipStatusOf($pleb))->toBe(MembershipStatus::AwaitingPayment);
});

it('reports member for every category with the year settled', function (AssociationStatus $status) {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => $status,
        'applied_at' => now(),
        'statutes_accepted_at' => now(),
    ]);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
    ]);

    expect(membershipStatusOf($pleb))->toBe(MembershipStatus::Member);
})->with([
    'passive' => AssociationStatus::PASSIVE,
    'active' => AssociationStatus::ACTIVE,
    'honorary' => AssociationStatus::HONORARY,
]);

/*
 * The case the whole derived value exists for.
 *
 * Art. 4.1 of the statutes lets the membership lapse after an unpaid year and
 * strips active and honorary members of their category with it. The software
 * deliberately does NOT do that: the enum keeps its value. So the record still
 * reads ACTIVE while the person is, under the statutes, no longer a member —
 * and a consumer reading the enum alone would grant them an active member's
 * rights.
 */
it('reports lapsed in the following year while the category is untouched', function () {
    $paidYear = (int) now()->year;

    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::ACTIVE,
        'applied_at' => now(),
        'statutes_accepted_at' => now(),
    ]);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => $paidYear,
    ]);

    expect(membershipStatusOf($pleb))->toBe(MembershipStatus::Member);

    $this->travelTo(Carbon::create($paidYear + 1, 6, 1, 12, 0, 0));

    $fresh = $pleb->fresh();

    expect(app(MembershipService::class)->currentYear())->toBe($paidYear + 1)
        ->and(membershipStatusOf($fresh))->toBe(MembershipStatus::Lapsed)
        // No automatic demotion — the category is a board decision, and a
        // missed transfer is more often forgetfulness than resignation.
        ->and($fresh->association_status)->toBe(AssociationStatus::ACTIVE)
        // The two answers differ. That is the whole point.
        ->and($fresh->association_status->name)->not->toBe(MembershipStatus::Lapsed->name);
});

it('exposes the derived value through status() so every consumer reads the same one', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::ACTIVE,
        'applied_at' => now(),
    ]);

    $status = app(MembershipService::class)->status($pleb);

    expect($status['membership_status'])->toBe(MembershipStatus::Lapsed)
        ->and($status['association_status'])->toBe(AssociationStatus::ACTIVE)
        ->and($status['is_member'])->toBeFalse();
});

it('answers status() for a subject that has no record at all', function () {
    $status = app(MembershipService::class)->status(null);

    expect($status['membership_status'])->toBe(MembershipStatus::None)
        ->and($status['association_status'])->toBe(AssociationStatus::DEFAULT)
        ->and($status['paid'])->toBeFalse()
        ->and($status['is_member'])->toBeFalse()
        ->and($status['applied_at'])->toBeNull()
        ->and($status['year'])->toBe((int) now()->year);
});
