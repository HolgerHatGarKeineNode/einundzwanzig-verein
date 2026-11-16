<?php

namespace App\Models;

use App\Enums\DmAudience;
use App\Services\Dm\DirectMessageAudience;
use Carbon\CarbonInterface;
use Database\Factories\DmScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message planned for a moment on an election's timeline. Started once by
 * `dm:send-due` when `send_at` has come; `dm_run_id` says it was.
 */
class DmSchedule extends Model
{
    /** @use HasFactory<DmScheduleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'election_event_id',
        'dm_message_id',
        'audience',
        'send_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => DmAudience::class,
            'send_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Election, $this>
     */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_event_id');
    }

    /**
     * @return BelongsTo<DmMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(DmMessage::class, 'dm_message_id');
    }

    /**
     * @return BelongsTo<DmRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DmRun::class, 'dm_run_id');
    }

    /**
     * Not started yet and `send_at` reached: a row is due at its moment, not
     * a second before.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDue(Builder $query, CarbonInterface $now): void
    {
        $query->whereNull('dm_run_id')->where('send_at', '<=', $now);
    }

    /**
     * How many members this row would go to if it were started now. Counts
     * the audience, writes nothing.
     */
    public function recipientCountToday(): int
    {
        return app(DirectMessageAudience::class)->query($this->audience, $this->election)->count();
    }
}
