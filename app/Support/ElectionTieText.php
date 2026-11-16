<?php

namespace App\Support;

use App\Enums\ElectionTieKind;

/**
 * The sentences about a tie and the chair's casting vote (statutes Art. 6),
 * one wording for the member page, the share view, the board's live control
 * and the minutes.
 */
final class ElectionTieText
{
    /**
     * What happens with a tie nobody has decided yet.
     */
    public static function pending(): string
    {
        return __('Die/der Vorsitzende fällt den Stichentscheid.');
    }

    /**
     * The tie itself, for a yes = no tie. A tie between candidates each page
     * words with its own seat count.
     *
     * @param  list<string>  $tied
     */
    public static function yesEqualsNo(array $tied): string
    {
        return trans_choice('{1} Gleich viele Ja- wie Nein-Stimmen für :names.|[2,*] Gleich viele Ja- wie Nein-Stimmen für :names.', count($tied), ['names' => self::join($tied)]);
    }

    /**
     * The chair's decision, naming the chair.
     *
     * @param  list<string>  $elected  whom the chair decided for
     * @param  list<string>  $tied  everybody who was level
     */
    public static function decided(ElectionTieKind $kind, string $chair, array $elected, array $tied): string
    {
        if ($kind === ElectionTieKind::Candidates) {
            return trans_choice('{1} Stichentscheid der/des Vorsitzenden :chair: gewählt ist :names.|[2,*] Stichentscheid der/des Vorsitzenden :chair: gewählt sind :names.', count($elected), [
                'chair' => $chair,
                'names' => self::join($elected),
            ]);
        }

        $rejected = array_values(array_diff($tied, $elected));
        $parts = array_filter([
            $elected === [] ? null : __('Ja für :names', ['names' => self::join($elected)]),
            $rejected === [] ? null : __('Nein für :names', ['names' => self::join($rejected)]),
        ]);

        return __('Stichentscheid der/des Vorsitzenden :chair: :decision.', ['chair' => $chair, 'decision' => implode(', ', $parts)]);
    }

    /**
     * The assembly resolved a run-off instead of the casting vote.
     */
    public static function runOffResolved(): string
    {
        return __('Die Versammlung hat statt des Stichentscheids eine Stichwahl beschlossen.');
    }

    /**
     * @param  list<string>  $names
     */
    protected static function join(array $names): string
    {
        return collect($names)->join(', ', ' und ');
    }
}
