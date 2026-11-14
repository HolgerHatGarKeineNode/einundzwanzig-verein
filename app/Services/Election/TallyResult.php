<?php

namespace App\Services\Election;

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;

/**
 * The count of one round: numbers per option, and who is elected.
 *
 * Numbers only. Nothing in here can be traced to a single ballot — there is
 * no list of ballots, no order and no id.
 */
final readonly class TallyResult
{
    /**
     * @param  array<int, array{for: int, yes: int, no: int}>  $counts  per candidate id, ascending
     * @param  list<int>  $elected  candidate ids, ascending
     * @param  list<int>  $tied  candidate ids level on votes for the last `$tiedSeats` seats, ascending
     */
    public function __construct(
        public ElectionCountingRule $rule,
        public int $seats,
        public ElectionOutcome $outcome,
        public array $counts,
        public int $blank,
        public array $elected,
        public array $tied,
        public int $tiedSeats,
    ) {}

    /**
     * @return array{rule: string, seats: int, outcome: string, counts: array<int, array{for: int, yes: int, no: int}>, blank: int, elected: list<int>, tied: list<int>, tied_seats: int}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule->value,
            'seats' => $this->seats,
            'outcome' => $this->outcome->value,
            'counts' => $this->counts,
            'blank' => $this->blank,
            'elected' => $this->elected,
            'tied' => $this->tied,
            'tied_seats' => $this->tiedSeats,
        ];
    }
}
