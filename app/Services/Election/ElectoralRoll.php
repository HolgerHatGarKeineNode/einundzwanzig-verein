<?php

namespace App\Services\Election;

use App\Enums\AssociationStatus;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRollEntry;
use App\Models\ElectionRound;
use App\Models\PaymentEvent;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may vote — decided here, written down per round, and read back from
 * what was written.
 *
 * Two questions that must not be confused:
 *
 *  - "Is this member entitled right now?" (`reasonFor()`) depends on the live
 *    membership category and payment state. It is asked exactly twice: when a
 *    round opens and when a fee is settled while a round is open.
 *  - "May this member vote in this round?" (`contains()`) is a lookup of the
 *    roll row and nothing else. `CastBallot` asks only this one, so a year
 *    change or a reversed payment during an open round takes nobody's vote
 *    away.
 *
 * Deliberately NOT built on `EinundzwanzigPleb::hasPaidMembership()`: that
 * accepts PASSIVE members (`association_status > 1`) and asks for whatever
 * year the clock shows at the moment of the call.
 */
class ElectoralRoll
{
    /**
     * The only categories with a vote. PASSIVE is never among them, whatever
     * was paid.
     *
     * @var list<AssociationStatus>
     */
    public const VOTING_STATUSES = [AssociationStatus::ACTIVE, AssociationStatus::HONORARY];

    /**
     * Why `$pleb` is entitled to vote in `$election` at `$at` — or null if
     * they are not.
     *
     * Reads the member fresh from the database; the category on the instance
     * handed in may be stale.
     */
    public function reasonFor(Election $election, EinundzwanzigPleb $pleb, CarbonInterface $at): ?ElectionRollReason
    {
        $entitled = $this->entitled($election, $at)->whereKey($pleb->getKey())->first();

        return $entitled === null ? null : $this->reasonOf($entitled);
    }

    /**
     * Is `$pleb` on the roll of `$round`? The whole right to vote, as far as
     * the cast is concerned.
     */
    public function contains(ElectionRound $round, EinundzwanzigPleb $pleb): bool
    {
        return ElectionRollEntry::query()
            ->where('election_round_id', $round->getKey())
            ->where('einundzwanzig_pleb_id', $pleb->getKey())
            ->exists();
    }

    /**
     * Write the roll of a round that is being opened. Returns the number of
     * entries on it afterwards.
     *
     * A run-off does not ask the question again: it copies the roll of the
     * round it belongs to, so the same people decide both.
     */
    public function snapshot(ElectionRound $round, CarbonInterface $at): int
    {
        $rows = $round->parent_round_id !== null
            ? $this->inheritedRows($round)
            : $this->entitled($round->election, $at)->get()->map(fn (EinundzwanzigPleb $pleb): array => [
                'einundzwanzig_pleb_id' => $pleb->getKey(),
                'reason' => $this->reasonOf($pleb)->value,
                'source' => ElectionRollSource::Opening->value,
            ])->all();

        $this->insert($round, $rows, $at);

        return $round->rollEntries()->count();
    }

    /**
     * A fee was settled: put the member on the roll of every round that has
     * been opened and has not ended, provided the payment makes them
     * entitled under that election's rule.
     *
     * Only ever adds. Called again for the same payment it changes nothing.
     */
    public function enrolLatePayer(EinundzwanzigPleb $pleb, CarbonInterface $at): void
    {
        $rounds = ElectionRound::query()
            ->with('election')
            ->whereNotNull('opened_at')
            ->whereNull('closed_at')
            ->whereNull('annulled_at')
            ->where('closes_at', '>', $at)
            ->get();

        foreach ($rounds as $round) {
            $reason = $this->reasonFor($round->election, $pleb, $at);

            if ($reason === null) {
                continue;
            }

            $this->insert($round, [[
                'einundzwanzig_pleb_id' => $pleb->getKey(),
                'reason' => $reason->value,
                'source' => ElectionRollSource::LatePayment->value,
            ]], $at);
        }
    }

    /**
     * Every member entitled to vote in `$election` at `$at`, each carrying a
     * `fee_paid` flag that says whether the fee rule is met.
     *
     * ONE definition for the snapshot and for the single-member check, so the
     * two cannot drift apart.
     *
     * @return Builder<EinundzwanzigPleb>
     */
    protected function entitled(Election $election, CarbonInterface $at): Builder
    {
        $feePaid = $this->feePaid($election, $at);

        return EinundzwanzigPleb::query()
            ->notErased()
            ->withExists(['paymentEvents as fee_paid' => $feePaid])
            ->whereIn('association_status', array_column(self::VOTING_STATUSES, 'value'))
            ->where(function (Builder $query) use ($election, $feePaid): void {
                $query->whereHas('paymentEvents', $feePaid);

                if ($election->honorary_without_fee) {
                    $query->orWhere('association_status', AssociationStatus::HONORARY->value);
                }
            });
    }

    /**
     * The election's fee rule as a constraint on `payment_events`.
     *
     * A year rule without a year matches nothing: an unreadable rule entitles
     * nobody rather than everybody.
     *
     * `LastTwelveMonths` reads `updated_at` of the paid row, because
     * `payment_events` has no column for the moment of settlement and
     * `MembershipService::markPaid()` is what last writes a paid row.
     *
     * @return Closure(Builder<PaymentEvent>): void
     */
    protected function feePaid(Election $election, CarbonInterface $at): Closure
    {
        $rule = $election->fee_rule;
        $year = $election->fee_year;

        return function (Builder $payments) use ($rule, $year, $at): void {
            $payments->where('paid', true);

            if ($rule->needsFeeYear() && $year === null) {
                $payments->whereRaw('1 = 0');

                return;
            }

            match ($rule) {
                ElectionFeeRule::Year => $payments->where('year', $year),
                ElectionFeeRule::YearOrPrevious => $payments->whereIn('year', [$year, $year - 1]),
                ElectionFeeRule::LastTwelveMonths => $payments->where('updated_at', '>=', $at->copy()->subMonths(12)),
            };
        };
    }

    /**
     * Expects a row from `entitled()`, i.e. one that is entitled.
     */
    protected function reasonOf(EinundzwanzigPleb $pleb): ElectionRollReason
    {
        if ($pleb->association_status === AssociationStatus::ACTIVE) {
            return ElectionRollReason::ActiveFeePaid;
        }

        return $pleb->fee_paid ? ElectionRollReason::HonoraryFeePaid : ElectionRollReason::HonoraryExempt;
    }

    /**
     * @return list<array{einundzwanzig_pleb_id: int, reason: string, source: string}>
     */
    protected function inheritedRows(ElectionRound $round): array
    {
        return ElectionRollEntry::query()
            ->where('election_round_id', $round->parent_round_id)
            ->get()
            ->map(fn (ElectionRollEntry $entry): array => [
                'einundzwanzig_pleb_id' => $entry->einundzwanzig_pleb_id,
                'reason' => $entry->reason->value,
                'source' => ElectionRollSource::Inherited->value,
            ])
            ->all();
    }

    /**
     * `insertOrIgnore`, so a member already on the roll stays exactly as
     * entered — the unique index on (round, member) decides, not a check
     * made beforehand.
     *
     * @param  list<array{einundzwanzig_pleb_id: int, reason: string, source: string}>  $rows
     */
    protected function insert(ElectionRound $round, array $rows, CarbonInterface $at): void
    {
        if ($rows === []) {
            return;
        }

        ElectionRollEntry::query()->insertOrIgnore(array_map(fn (array $row): array => [
            ...$row,
            'election_round_id' => $round->getKey(),
            'created_at' => $at,
            'updated_at' => $at,
        ], $rows));
    }
}
