<?php

namespace App\Enums;

/**
 * Which paid fee entitles a member to vote. Each election names exactly one
 * rule; which one applies is laid down in the election regulations.
 */
enum ElectionFeeRule: string
{
    /** The fee of the election's fee year. */
    case Year = 'year';

    /** The fee of the election's fee year or of the year before it. */
    case YearOrPrevious = 'year_or_previous';

    /** Any fee settled within the twelve months before the roll is written. */
    case LastTwelveMonths = 'last_twelve_months';

    public function needsFeeYear(): bool
    {
        return $this !== self::LastTwelveMonths;
    }

    /**
     * The rule in a few words; `$year` defaults to the current year.
     */
    public function label(?int $year = null): string
    {
        $year ??= (int) now()->year;

        return match ($this) {
            self::Year => __('Beitrag :year', ['year' => $year]),
            self::YearOrPrevious => __('Beitrag :year oder :previous', ['year' => $year, 'previous' => $year - 1]),
            self::LastTwelveMonths => __('Beitrag in den letzten 12 Monaten'),
        };
    }

    /**
     * Who may vote under the rule, as one sentence; `$year` defaults to the
     * current year.
     */
    public function hint(?int $year = null): string
    {
        $year ??= (int) now()->year;

        return match ($this) {
            self::Year => __('Wählen darf, wer als Aktiv- oder Ehrenmitglied den Beitrag für :year bezahlt hat.', ['year' => $year]),
            self::YearOrPrevious => __('Wählen darf, wer als Aktiv- oder Ehrenmitglied den Beitrag für :year oder für :previous bezahlt hat.', ['year' => $year, 'previous' => $year - 1]),
            self::LastTwelveMonths => __('Wählen darf, wer als Aktiv- oder Ehrenmitglied in den zwölf Monaten vor dem Öffnen des Wahlgangs einen Beitrag bezahlt hat.'),
        };
    }

    /**
     * What a member who is not entitled under the rule lacks.
     */
    public function missingText(?int $year): string
    {
        return match ($this) {
            self::Year => __('Beitrag :year fehlt', ['year' => $year]),
            self::YearOrPrevious => __('Beitrag :year oder :previous fehlt', ['year' => $year, 'previous' => (int) $year - 1]),
            self::LastTwelveMonths => __('Kein Beitrag in den letzten 12 Monaten'),
        };
    }
}
