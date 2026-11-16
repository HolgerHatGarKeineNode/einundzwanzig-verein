<?php

use App\Models\EinundzwanzigPleb;

/*
 * withPaidYear() exists so that a test which moves the clock still decides
 * the fee year itself. Mutation: take the year from date('Y') or now() instead
 * of the argument → both cases go red.
 */

it('records a paid fee for the named year and not for the year the clock shows', function () {
    $this->travelTo('2031-03-15 12:00:00');

    $pleb = EinundzwanzigPleb::factory()->active()->withPaidYear(2027)->create();

    expect($pleb->paymentEvents()->where('paid', true)->pluck('year')->all())->toEqual([2027])
        ->and($pleb->hasPaidMembership(2027))->toBeTrue()
        ->and($pleb->hasPaidMembership())->toBeFalse();
});

it('records one paid fee per year when chained', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidYear(2026)->withPaidYear(2027)->create();

    expect($pleb->paymentEvents()->where('paid', true)->orderBy('year')->pluck('year')->all())
        ->toEqual([2026, 2027]);
});
