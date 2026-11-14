<?php

namespace App\Services\Election;

use App\Enums\AssociationStatus;
use App\Enums\ElectionAuditAction;
use App\Exceptions\ElectionActionRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The status lock (E5): from the announcement of an election until all of
 * its rounds are sealed or annulled, a membership category that touches the
 * vote changes only through its Wahlleitung — and every such change leaves an
 * audit line in each election that holds the lock.
 *
 * "Touches the vote" means the change starts or ends at ACTIVE or HONORARY.
 * DEFAULT → PASSIVE, the step a paid fee makes on its own
 * (`MembershipService::grantMembershipOnPayment()`), stays free: it gives no
 * vote and is nobody's decision.
 *
 * A status change alone puts nobody on a roll. A member promoted after an
 * opening is enrolled only when they then pay inside the window
 * (`ElectoralRoll::enrolLatePayer()`), and that entry carries the source
 * `status_change`, not `late_payment`.
 *
 * Outside a lock this class checks no rights: the caller's own check
 * governs, as it did before the lock existed (the board screen).
 *
 * `EinundzwanzigPleb` refuses every other writer of such a change while a
 * lock holds; `applying()` is how it tells this class apart.
 */
class ChangeMemberStatus
{
    use AuthorizesElectionActor;

    private static bool $applying = false;

    public function __construct(protected ElectionAuditLog $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ElectionActionRefusedException
     */
    public function handle(EinundzwanzigPleb $member, AssociationStatus $to, ?EinundzwanzigPleb $actor): EinundzwanzigPleb
    {
        return DB::transaction(function () use ($member, $to, $actor): EinundzwanzigPleb {
            $member = EinundzwanzigPleb::query()->lockForUpdate()->findOrFail($member->getKey());
            $from = $member->association_status;

            if ($from === $to) {
                return $member;
            }

            $locking = self::touchesTheVote($from, $to) ? self::lockingElections() : new Collection;

            foreach ($locking as $election) {
                if ($actor === null) {
                    throw new AuthorizationException('An election holds the status lock; only its Wahlleitung changes a category now.');
                }

                $this->authorizeActor($actor, 'changeMemberStatus', $election);
            }

            if ($locking->isNotEmpty() && $actor?->is($member)) {
                throw new ElectionActionRefusedException('The Wahlleitung does not change its own category during the election.');
            }

            $member->association_status = $to;

            self::$applying = true;

            try {
                $member->save();
            } finally {
                self::$applying = false;
            }

            foreach ($locking as $election) {
                $this->audit->append($election, ElectionAuditAction::MemberStatusChanged, $actor, subject: $member, details: [
                    'from' => $from->name,
                    'to' => $to->name,
                ]);
            }

            return $member;
        });
    }

    /**
     * Whether `ChangeMemberStatus` is the writer of the save in progress.
     */
    public static function applying(): bool
    {
        return self::$applying;
    }

    /**
     * Refuse a change of category that the lock covers and that does not
     * come through `handle()`. Called by `EinundzwanzigPleb` before an update.
     *
     * @throws LogicException
     */
    public static function guard(EinundzwanzigPleb $member): void
    {
        if (self::$applying || ! $member->isDirty('association_status')) {
            return;
        }

        $from = AssociationStatus::tryFrom((int) $member->getRawOriginal('association_status'));
        $to = $member->association_status;

        if (($from === null || self::touchesTheVote($from, $to)) && self::lockingElections()->isNotEmpty()) {
            throw new LogicException('An election holds the status lock; a category changes only through ChangeMemberStatus.');
        }
    }

    /**
     * Announced real elections that are not over: a round is still unsealed
     * and not annulled, or no round exists yet.
     *
     * @return Collection<int, Election>
     */
    public static function lockingElections(): Collection
    {
        return Election::query()
            ->whereNotNull('announced_at')
            ->where('is_test', false)
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('rounds')
                    ->orWhereHas('rounds', fn (Builder $rounds) => $rounds->whereNull('closed_at')->whereNull('annulled_at'));
            })
            ->get();
    }

    protected static function touchesTheVote(AssociationStatus $from, AssociationStatus $to): bool
    {
        return in_array($from, ElectoralRoll::VOTING_STATUSES, true)
            || in_array($to, ElectoralRoll::VOTING_STATUSES, true);
    }
}
