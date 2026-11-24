<?php

namespace App\Services\Election;

use App\Enums\AssociationStatus;
use App\Enums\ElectionAuditAction;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
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
     * Those of `$roundIds` whose roll holds `$pleb`: `contains()` for many
     * rounds in one query, for a page that asks on every poll.
     *
     * @param  list<int>  $roundIds
     * @return list<int>
     */
    public function roundIdsContaining(EinundzwanzigPleb $pleb, array $roundIds): array
    {
        if ($roundIds === []) {
            return [];
        }

        return ElectionRollEntry::query()
            ->whereIn('election_round_id', $roundIds)
            ->where('einundzwanzig_pleb_id', $pleb->getKey())
            ->pluck('election_round_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Write the roll of a round that is being opened. Returns the number of
     * entries on it afterwards.
     *
     * A run-off does not ask the question again at its opening: it copies
     * the roll of the round it belongs to, and a status change since that
     * opening adds nobody and removes nobody. Its roll is not closed for
     * good, though — whoever settles a qualifying fee while the run-off is
     * open is added to it by `enrolLatePayer()`, so a run-off can have
     * voters its parent round did not have.
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
     * Late means late: the qualifying payment must have been settled
     * (`paid_at`) at or after the round's opening and before its end. A
     * member whose fee was already settled at the opening and who was not
     * entitled then — a PASSIVE member promoted to ACTIVE afterwards, say —
     * is not enrolled by a later status change or by a redelivery of that
     * old settlement; the snapshot at the opening decided about them. A
     * run-off is a round of its own here: whoever pays while it is open is
     * enrolled in it.
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
            $feePaid = $this->feePaid($round->election, $at);

            $entitled = $this->entitled($round->election, $at)
                ->whereKey($pleb->getKey())
                ->whereHas('paymentEvents', function (Builder $payments) use ($feePaid, $round): void {
                    $feePaid($payments);

                    $payments->where('paid_at', '>=', $round->opened_at)
                        ->where('paid_at', '<', $round->closes_at);
                })
                ->first();

            if ($entitled === null) {
                continue;
            }

            $reason = $this->reasonOf($entitled);

            $this->insert($round, [[
                'einundzwanzig_pleb_id' => $pleb->getKey(),
                'reason' => $reason->value,
                'source' => ElectionRollSource::LatePayment->value,
            ]], $at);
        }
    }

    /**
     * Put `$pleb` on the roll of `$round` by hand. Returns false if they are
     * on it already — their entry stays exactly as it is.
     *
     * Asks nothing about the fee: this is the Wahlleitung's way for what the
     * payment flow cannot see (a fee settled outside BTCPay, a payment lost
     * between opening and settlement). `EnrolByHand` checks the rights and
     * writes the reason into the audit log.
     */
    public function enrolByHand(ElectionRound $round, EinundzwanzigPleb $pleb, CarbonInterface $at): bool
    {
        $before = $round->rollEntries()->where('einundzwanzig_pleb_id', $pleb->getKey())->exists();

        $this->insert($round, [[
            'einundzwanzig_pleb_id' => (int) $pleb->getKey(),
            'reason' => ElectionRollReason::ConfirmedByBoard->value,
            'source' => ElectionRollSource::ByHand->value,
        ]], $at);

        return ! $before;
    }

    /**
     * Every member entitled to vote in `$election` at `$at`, each carrying a
     * `fee_paid` flag that says whether the fee rule is met and a
     * `confirmed_by_board` flag for an entitlement the board entered by hand
     * (`EnrolByHand::entitle()`).
     *
     * ONE definition for the snapshot, the single-member check and the
     * direct message audiences (App\Services\Dm\DirectMessageAudience), so
     * they cannot drift apart.
     *
     * @return Builder<EinundzwanzigPleb>
     */
    public function entitled(Election $election, CarbonInterface $at): Builder
    {
        $feePaid = $this->feePaid($election, $at);
        $confirmed = $this->confirmedByBoard($election);

        return EinundzwanzigPleb::query()
            ->notErased()
            ->withExists(['paymentEvents as fee_paid' => $feePaid])
            ->addSelect(['confirmed_by_board' => (clone $confirmed)->whereColumn('election_audit_entries.subject_pleb_id', 'einundzwanzig_plebs.id')->limit(1)])
            ->whereIn('association_status', array_column(self::VOTING_STATUSES, 'value'))
            ->where(function (Builder $query) use ($election, $feePaid, $confirmed): void {
                $query->whereHas('paymentEvents', $feePaid)
                    ->orWhereIn('einundzwanzig_plebs.id', $confirmed);

                if ($election->honorary_without_fee) {
                    $query->orWhere('association_status', AssociationStatus::HONORARY->value);
                }
            });
    }

    /**
     * The members the board entered as entitled in `$election` by hand: the
     * audit lines of `EnrolByHand::entitle()`, which carry no round.
     *
     * @return Builder<ElectionAuditEntry>
     */
    protected function confirmedByBoard(Election $election): Builder
    {
        return ElectionAuditEntry::query()
            ->select('subject_pleb_id')
            ->where('election_event_id', $election->getKey())
            ->where('action', ElectionAuditAction::VoterEnrolledByHand->value)
            ->whereNull('election_round_id')
            ->whereNotNull('subject_pleb_id');
    }

    /**
     * The election's fee rule as a constraint on `payment_events`.
     *
     * A year rule without a year matches nothing: an unreadable rule entitles
     * nobody rather than everybody.
     *
     * `LastTwelveMonths` reads `paid_at`, the moment of settlement written
     * once by `MembershipService::markPaid()`. A paid row without it (written
     * by hand, never through `markPaid()`) does not count: an unknown moment
     * of payment entitles nobody.
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
                ElectionFeeRule::LastTwelveMonths => $payments->where('paid_at', '>=', $at->copy()->subMonths(12)),
            };
        };
    }

    /**
     * Expects a row from `entitled()`, i.e. one that is entitled.
     */
    public function reasonOf(EinundzwanzigPleb $pleb): ElectionRollReason
    {
        if ($pleb->fee_paid) {
            return $pleb->association_status === AssociationStatus::ACTIVE ? ElectionRollReason::ActiveFeePaid : ElectionRollReason::HonoraryFeePaid;
        }

        return $pleb->confirmed_by_board ? ElectionRollReason::ConfirmedByBoard : ElectionRollReason::HonoraryExempt;
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
