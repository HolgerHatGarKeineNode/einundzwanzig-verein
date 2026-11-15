<?php

namespace App\Services\Dm;

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Plans a message for a moment on an election's timeline.
 *
 * The text is composed and checked now (ComposeDirectMessage), so a broken
 * placeholder is refused while the board is still looking at it, not at
 * `send_at`. The audience is resolved only when the row is started.
 */
final class ScheduleDirectMessage
{
    public function __construct(private readonly ComposeDirectMessage $compose) {}

    /**
     * @param  array<string, string>  $variables  values of the type's message placeholders
     * @param  string|null  $plaintext  null takes the type's standard text
     *
     * @throws DirectMessageTextInvalid
     */
    public function handle(
        Election $election,
        DmMessageType $type,
        DmAudience $audience,
        CarbonInterface $sendAt,
        array $variables = [],
        ?string $plaintext = null,
        ?EinundzwanzigPleb $author = null,
    ): DmSchedule {
        return DB::transaction(fn (): DmSchedule => DmSchedule::query()->create([
            'election_event_id' => $election->getKey(),
            'dm_message_id' => $this->compose->handle($type, $author, $variables, $plaintext)->getKey(),
            'audience' => $audience,
            'send_at' => $sendAt,
        ]));
    }
}
