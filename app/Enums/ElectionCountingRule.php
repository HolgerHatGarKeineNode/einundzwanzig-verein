<?php

namespace App\Enums;

use App\Exceptions\ElectionBallotRefusedException;

/**
 * How the ballots of a round are filled in and counted. Chosen per round and
 * frozen when the round opens.
 */
enum ElectionCountingRule: string
{
    /** One vote for one candidate; elected with more than half of the valid votes. */
    case SingleMajority = 'single_majority';

    /** Yes or no for each candidate; elected with more yes than no. */
    case YesNoPerCandidate = 'yes_no_per_candidate';

    /** As many votes as there are seats; the candidates named most often are elected. */
    case MostVotes = 'most_votes';

    /**
     * Turn what a member selected into the rows of their ballot, or refuse.
     *
     * `$selection` maps a candidate id to a choice value: `for` in the two
     * rules where candidates are picked, `yes` or `no` where each candidate
     * is voted on. Array keys make a second vote for the same candidate
     * impossible by construction. An empty selection is a valid ballot and
     * becomes one `blank` row, so abstentions stay countable.
     *
     * Everything that is not exactly an allowed selection is refused — there
     * is no "best effort" reading of a ballot.
     *
     * @param  array<array-key, mixed>  $selection
     * @param  list<int>  $candidateIds  the candidates of the round
     * @return list<array{candidate: int|null, choice: ElectionChoice}>
     *
     * @throws ElectionBallotRefusedException
     */
    public function ballotRows(array $selection, array $candidateIds, int $seats): array
    {
        if ($selection === []) {
            return [['candidate' => null, 'choice' => ElectionChoice::Blank]];
        }

        if (count($selection) > $this->maxSelections($candidateIds, $seats)) {
            throw new ElectionBallotRefusedException(ElectionBallotRefusal::InvalidChoices);
        }

        $rows = [];

        foreach ($selection as $candidateId => $value) {
            $choice = is_string($value) ? ElectionChoice::tryFrom($value) : null;

            if (
                ! is_int($candidateId)
                || ! in_array($candidateId, $candidateIds, true)
                || $choice === null
                || ! in_array($choice, $this->allowedChoices(), true)
            ) {
                throw new ElectionBallotRefusedException(ElectionBallotRefusal::InvalidChoices);
            }

            $rows[] = ['candidate' => $candidateId, 'choice' => $choice];
        }

        return $rows;
    }

    /**
     * @return list<ElectionChoice>
     */
    public function allowedChoices(): array
    {
        return match ($this) {
            self::SingleMajority, self::MostVotes => [ElectionChoice::For],
            self::YesNoPerCandidate => [ElectionChoice::Yes, ElectionChoice::No],
        };
    }

    /**
     * @param  list<int>  $candidateIds
     */
    public function maxSelections(array $candidateIds, int $seats): int
    {
        return match ($this) {
            self::SingleMajority => 1,
            self::MostVotes => max(0, $seats),
            self::YesNoPerCandidate => count($candidateIds),
        };
    }

    /**
     * The name of the rule as the board and the minutes call it.
     */
    public function label(): string
    {
        return match ($this) {
            self::SingleMajority => __('Einzelwahl'),
            self::YesNoPerCandidate => __('Bestätigungswahl'),
            self::MostVotes => __('Mehrfachwahl'),
        };
    }

    /**
     * One sentence on how the rule counts.
     */
    public function hint(): string
    {
        return match ($this) {
            self::SingleMajority => __('Eine Stimme. Gewählt ist, wer mehr als die Hälfte der gültigen Stimmen hat.'),
            self::YesNoPerCandidate => __('Ja oder Nein je Person. Gewählt ist, wer mehr Ja als Nein hat.'),
            self::MostVotes => __('So viele Stimmen wie Sitze. Gewählt sind die am häufigsten Genannten.'),
        };
    }
}
