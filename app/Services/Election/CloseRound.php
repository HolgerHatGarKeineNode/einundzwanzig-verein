<?php

namespace App\Services\Election;

use App\Enums\ElectionRoundMode;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionRound;
use Illuminate\Support\Facades\DB;

/**
 * Seal a round: close it for good and write its manifest.
 *
 * One-way. There is no reopen and no extension, here or anywhere else.
 *
 * A live round is sealed by hand whenever the assembly is done voting. A
 * window round is sealed once its window has run out; ending a window EARLY
 * is a different act that needs a second person and is not offered here.
 *
 * A round whose window is over already refuses votes by the clock alone.
 * What sealing adds is the manifest, and results may only be read after it. Calling this on a sealed round changes nothing.
 */
class CloseRound
{
    public function __construct(protected RoundManifest $manifest, protected ElectionAuditLog $audit) {}

    /**
     * @throws ElectionRoundStateException
     */
    public function handle(ElectionRound $round): ElectionRound
    {
        return DB::transaction(function () use ($round): ElectionRound {
            /*
             * The same row lock `CastBallot` takes. A cast that got in first
             * commits before this continues, and one that comes after sees
             * `closed_at` — so no vote can land behind the manifest.
             */
            $round = ElectionRound::query()->lockForUpdate()->findOrFail($round->getKey());
            $now = now();

            if ($round->closed_at !== null) {
                return $round;
            }

            $state = $round->stateAt($now);

            if (! in_array($state, [ElectionRoundState::Open, ElectionRoundState::Closed], true)) {
                throw new ElectionRoundStateException('Only a round that has been opened and not annulled can be sealed.');
            }

            if ($state === ElectionRoundState::Open && $round->mode === ElectionRoundMode::Window) {
                throw new ElectionRoundStateException('A window round cannot be sealed before its window has run out.');
            }

            $round->closed_at = $now;
            $round->save();

            /*
             * In the same transaction as the close: a round is never
             * sealed without its manifest. The manifest names the head of
             * the election's audit log at this moment, so the chain up to
             * the seal is part of what its hash covers. The line that
             * records the seal itself comes after (`ElectionStewardship`).
             */
            $this->manifest->record($round, auditHeadHash: $this->audit->headHash($round->election));

            return $round;
        });
    }
}
