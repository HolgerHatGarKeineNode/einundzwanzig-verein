<?php

namespace App\Services\Dm;

use App\Enums\DmAudience;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionFeeRule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRollEntry;
use App\Services\Election\ElectoralRoll;
use App\Support\Board;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Turns a named audience into the members it means right now, and into the
 * sentence the board reads before sending.
 *
 * Erased members are never part of an audience. Who is entitled to vote is
 * not decided here but asked of the electoral roll, so the reminder and the
 * vote cannot disagree about it.
 */
final class DirectMessageAudience
{
    public function __construct(private readonly ElectoralRoll $roll) {}

    /**
     * @return Builder<EinundzwanzigPleb>
     *
     * @throws InvalidArgumentException when the audience needs an election and none is given
     */
    public function query(DmAudience $audience, ?Election $election = null): Builder
    {
        $election = $this->electionFor($audience, $election);

        $query = EinundzwanzigPleb::query()
            ->notErased()
            ->whereNotNull('pubkey')
            ->where('pubkey', '!=', '')
            ->orderBy('id');

        return match ($audience) {
            DmAudience::Members => $query->whereIn('association_status', $this->votingStatuses()),
            DmAudience::EntitledVoters => $query->whereIn('id', $this->entitledIds($election)),
            DmAudience::FeeOutstanding => $query
                ->whereIn('association_status', $this->votingStatuses())
                ->whereNotIn('id', $this->entitledIds($election)),
            DmAudience::Candidates => $query->whereIn('id', ElectionCandidate::query()
                ->select('election_candidates.einundzwanzig_pleb_id')
                ->join('election_rounds', 'election_rounds.id', '=', 'election_candidates.election_round_id')
                ->where('election_rounds.election_event_id', $election->getKey())
                ->whereIn('election_candidates.status', [
                    ElectionCandidateStatus::Pending->value,
                    ElectionCandidateStatus::Approved->value,
                ])),
            DmAudience::Board => $query->where(fn (Builder $board) => $board
                ->whereIn('npub', Board::npubs())
                ->orWhereIn('pubkey', Board::pubkeys())),
        };
    }

    /**
     * The audience as one German sentence, e.g. „42 Mitglieder, die für die
     * Wahl „Vorstandswahl 2027“ stimmberechtigt sind“.
     *
     * @param  int|null  $count  null counts the audience now
     */
    public function describe(DmAudience $audience, ?Election $election = null, ?int $count = null): string
    {
        $count ??= $this->query($audience, $election)->count();
        $name = (string) $election?->name;

        return match ($audience) {
            DmAudience::Members => trans_choice(
                ':count Aktiv- oder Ehrenmitglied, Beitrag bezahlt oder nicht|:count Aktiv- und Ehrenmitglieder, Beitrag bezahlt oder nicht',
                $count,
            ),
            DmAudience::EntitledVoters => trans_choice(
                ':count Mitglied, das für die Wahl „:election“ stimmberechtigt ist|:count Mitglieder, die für die Wahl „:election“ stimmberechtigt sind',
                $count,
                ['election' => $name],
            ),
            DmAudience::FeeOutstanding => trans_choice(
                ':count Mitglied ohne bezahlten Beitrag :period (Wahl „:election“)|:count Mitglieder ohne bezahlten Beitrag :period (Wahl „:election“)',
                $count,
                ['election' => $name, 'period' => $this->feePeriod($election)],
            ),
            DmAudience::Candidates => trans_choice(
                ':count Kandidatur für die Wahl „:election“|:count Kandidierende der Wahl „:election“',
                $count,
                ['election' => $name],
            ),
            DmAudience::Board => trans_choice(
                ':count Vorstandsmitglied|:count Vorstandsmitglieder',
                $count,
            ),
        };
    }

    /**
     * Members entitled under the election's fee rule now, and anyone already
     * on the roll of one of its rounds (enrolled by hand or as a late payer).
     *
     * @return Builder<EinundzwanzigPleb>
     */
    private function entitledIds(Election $election): Builder
    {
        return $this->roll->entitled($election, now())
            ->select('einundzwanzig_plebs.id')
            ->union(EinundzwanzigPleb::query()
                ->select('einundzwanzig_plebs.id')
                ->whereIn('id', ElectionRollEntry::query()
                    ->select('election_roll_entries.einundzwanzig_pleb_id')
                    ->join('election_rounds', 'election_rounds.id', '=', 'election_roll_entries.election_round_id')
                    ->where('election_rounds.election_event_id', $election->getKey())));
    }

    private function electionFor(DmAudience $audience, ?Election $election): ?Election
    {
        if ($audience->needsElection() && $election === null) {
            throw new InvalidArgumentException("The audience {$audience->value} needs an election.");
        }

        return $election;
    }

    /**
     * @return list<int>
     */
    private function votingStatuses(): array
    {
        return array_column(ElectoralRoll::VOTING_STATUSES, 'value');
    }

    private function feePeriod(?Election $election): string
    {
        $year = $election?->fee_year;

        return match (true) {
            $election?->fee_rule === ElectionFeeRule::LastTwelveMonths => __('in den letzten zwölf Monaten'),
            $year === null => __('(Beitragsjahr der Wahl fehlt)'),
            $election->fee_rule === ElectionFeeRule::YearOrPrevious => __('für :previous oder :year', ['previous' => $year - 1, 'year' => $year]),
            default => __('für :year', ['year' => $year]),
        };
    }
}
