<?php

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;

/*
 * The words the board's pages and the minutes use for the election rules.
 * They used to be spelled out per page; the enums carry them now.
 */

it('names every counting rule and explains it', function (ElectionCountingRule $rule, string $label) {
    expect($rule->label())->toBe($label)
        ->and($rule->hint())->not->toBe('');
})->with([
    [ElectionCountingRule::SingleMajority, 'Einzelwahl'],
    [ElectionCountingRule::YesNoPerCandidate, 'Bestätigungswahl'],
    [ElectionCountingRule::MostVotes, 'Mehrfachwahl'],
]);

it('names every fee rule with its year', function () {
    expect(ElectionFeeRule::Year->label(2026))->toBe('Beitrag 2026')
        ->and(ElectionFeeRule::YearOrPrevious->label(2026))->toBe('Beitrag 2026 oder 2025')
        ->and(ElectionFeeRule::LastTwelveMonths->label(2026))->toBe('Beitrag in den letzten 12 Monaten')
        ->and(ElectionFeeRule::YearOrPrevious->hint(2026))->toContain('für 2026 oder für 2025')
        ->and(ElectionFeeRule::Year->missingText(2026))->toBe('Beitrag 2026 fehlt')
        ->and(ElectionFeeRule::YearOrPrevious->missingText(2026))->toBe('Beitrag 2026 oder 2025 fehlt')
        ->and(ElectionFeeRule::LastTwelveMonths->missingText(null))->toBe('Kein Beitrag in den letzten 12 Monaten');
});

it('takes the current year when a fee rule has none', function () {
    $this->travelTo(now()->setDate(2031, 1, 1));

    expect(ElectionFeeRule::Year->label())->toBe('Beitrag 2031')
        ->and(ElectionFeeRule::Year->hint())->toContain('für 2031');
});

it('names every roll reason and source', function () {
    expect(array_map(fn (ElectionRollReason $reason): string => $reason->label(), ElectionRollReason::cases()))
        ->toBe(['Aktivmitglied, Beitrag bezahlt', 'Ehrenmitglied, Beitrag bezahlt', 'Ehrenmitglied, ohne Beitrag', 'Vom Vorstand bestätigt'])
        ->and(array_map(fn (ElectionRollSource $source): string => $source->label(), ElectionRollSource::cases()))
        ->toBe(['beim Öffnen', 'durch Nachzahlung', 'aus dem Wahlgang übernommen', 'von Hand nachgetragen']);
});
