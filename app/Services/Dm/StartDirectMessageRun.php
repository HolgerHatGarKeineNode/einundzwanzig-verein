<?php

namespace App\Services\Dm;

use App\Enums\DmAudience;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\Election;

/**
 * Starts a sending: resolves the audience once, freezes the result on the
 * run and hands the run to the fan-out.
 *
 * The frozen list is the whole truth about who gets this run. The fan-out
 * reads `recipient_ids` and never the audience, so a member who joins or
 * pays after the start is not added, and nobody drops out.
 */
final class StartDirectMessageRun
{
    public function __construct(private readonly DirectMessageAudience $audience) {}

    public function handle(DmMessage $message, DmAudience $audience, ?Election $election = null): DmRun
    {
        $run = $message->freezeRun(
            $this->audience->query($audience, $election)->select('einundzwanzig_plebs.id')->get(),
        );

        DispatchBulkDirectMessages::dispatch($run->id);

        return $run;
    }
}
