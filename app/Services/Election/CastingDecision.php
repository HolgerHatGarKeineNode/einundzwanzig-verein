<?php

namespace App\Services\Election;

use App\Enums\ElectionOutcome;
use App\Enums\ElectionTieKind;
use Carbon\CarbonInterface;

/**
 * The chair's casting vote on the tie of a round (statutes Art. 6), as the
 * election officer entered it: whom the chair decided for, the chair's name
 * and an optional note.
 */
final readonly class CastingDecision
{
    /**
     * @param  list<int>  $candidates  candidate ids the chair decided for, ascending; empty when the chair said no to every yes = no tie
     * @param  list<int>  $tied  the candidate ids that were level, ascending
     */
    public function __construct(
        public array $candidates,
        public array $tied,
        public int $tiedSeats,
        public string $chair,
        public ?string $note,
        public CarbonInterface $recordedAt,
    ) {}

    /**
     * Does this decision answer the tie of `$result`?
     *
     *  - between candidates: exactly as many of the tied as there are tied seats;
     *  - yes = no: any of the tied, up to the tied seats, none included — the
     *    chair may decide „nein“.
     */
    public function fits(TallyResult $result): bool
    {
        if ($result->outcome !== ElectionOutcome::Tie || $result->tieKind === null) {
            return false;
        }

        $chosen = array_values(array_unique($this->candidates));

        if (count($chosen) !== count($this->candidates) || array_diff($chosen, $result->tied) !== []) {
            return false;
        }

        return match ($result->tieKind) {
            ElectionTieKind::Candidates => count($chosen) === $result->tiedSeats,
            ElectionTieKind::YesEqualsNo => count($chosen) <= $result->tiedSeats,
        };
    }
}
