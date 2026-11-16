<?php

namespace App\Enums;

/**
 * The named selections a direct message can be sent to.
 *
 * Each one is resolved to a member query by App\Services\Dm\DirectMessageAudience,
 * which also writes the sentence the board reads before sending. There is no
 * "has not voted yet" audience (E20): the selection never reads a vote.
 */
enum DmAudience: string
{
    /** Every active and honorary member, paid or not. Not the right to vote: that is `EntitledVoters`. */
    case Members = 'members';

    /** Members entitled to vote in the election. */
    case EntitledVoters = 'entitled_voters';

    /** Active and honorary members who are not entitled to vote in the election (fee rule not met). */
    case FeeOutstanding = 'fee_outstanding';

    /** Members with a pending or approved candidacy in the election. */
    case Candidates = 'candidates';

    /** The board as configured in `einundzwanzig.config.current_board`. */
    case Board = 'board';

    public function label(): string
    {
        return match ($this) {
            self::Members => __('Alle Aktiv- und Ehrenmitglieder'),
            self::EntitledVoters => __('Stimmberechtigte der Wahl'),
            self::FeeOutstanding => __('Mitglieder mit offenem Beitrag'),
            self::Candidates => __('Kandidierende der Wahl'),
            self::Board => __('Vorstand'),
        };
    }

    /**
     * Does resolving this audience need an election?
     */
    public function needsElection(): bool
    {
        return match ($this) {
            self::EntitledVoters, self::FeeOutstanding, self::Candidates => true,
            self::Members, self::Board => false,
        };
    }
}
