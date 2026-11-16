<?php

namespace App\Services\Election;

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionTieKind;

/**
 * The count of one round: numbers per option, and who is elected.
 *
 * Numbers only. Nothing in here can be traced to a single ballot — there is
 * no list of ballots, no order and no id.
 *
 * As `Tally` returns it, this is the raw count. `TallyRound::effective()`
 * hands out the effective result instead: the count with the chair's casting
 * vote applied (`decided()`), which is what every "who is elected" reads. The
 * counts themselves are the same in both.
 */
final readonly class TallyResult
{
    /**
     * @param  array<int, array{for: int, yes: int, no: int}>  $counts  per candidate id, ascending
     * @param  list<int>  $elected  candidate ids, ascending
     * @param  list<int>  $tied  candidate ids level on votes for the last `$tiedSeats` seats, ascending
     * @param  ElectionTieKind|null  $tieKind  what the tie is level on; null without a tie
     * @param  CastingDecision|null  $castingVote  the chair's decision of the tie, applied; null on a raw count
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
        public ?ElectionTieKind $tieKind = null,
        public ?CastingDecision $castingVote = null,
    ) {}

    /**
     * This count with `$decision` applied: the candidates the chair decided
     * for are elected, the tie is gone. The counts stay as they are.
     *
     * Refuses a decision that does not fit the tie — the caller keeps the raw
     * count then, so a broken row never elects anybody.
     *
     * @throws \InvalidArgumentException
     */
    public function decided(CastingDecision $decision): self
    {
        if (! $decision->fits($this)) {
            throw new \InvalidArgumentException('The casting vote does not fit the tie of this count.');
        }

        $elected = [...$this->elected, ...$decision->candidates];
        sort($elected);

        return new self(
            $this->rule,
            $this->seats,
            count($elected) >= $this->seats ? ElectionOutcome::Complete : ElectionOutcome::Understaffed,
            $this->counts,
            $this->blank,
            $elected,
            [],
            0,
            $this->tieKind,
            $decision,
        );
    }

    /**
     * The raw count only; the casting vote is not part of it.
     *
     * @return array{rule: string, seats: int, outcome: string, counts: array<int, array{for: int, yes: int, no: int}>, blank: int, elected: list<int>, tied: list<int>, tied_seats: int, tie_kind: string|null}
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
            'tie_kind' => $this->tieKind?->value,
        ];
    }
}
