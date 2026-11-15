<?php

namespace App\Models;

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use Database\Factories\DmMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A direct message as written, before it is filled in for anyone.
 */
class DmMessage extends Model
{
    /** @use HasFactory<DmMessageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'plaintext',
        'variables',
        'author_pleb_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DmMessageType::class,
            'variables' => 'array',
        ];
    }

    /**
     * @return BelongsTo<EinundzwanzigPleb, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'author_pleb_id');
    }

    /**
     * @return HasMany<DmRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(DmRun::class);
    }

    /**
     * Start a run with its recipient list frozen now.
     *
     * Ids only, unique and sorted: the list must not depend on the order the
     * audience query happened to return, and it must not name anyone once a
     * member has been erased.
     *
     * The audience and election are stored with the run so the history can
     * say who it was meant for; both stay null for a test to one member.
     *
     * @param  iterable<int, EinundzwanzigPleb>  $recipients
     */
    public function freezeRun(iterable $recipients, ?DmAudience $audience = null, ?Election $election = null): DmRun
    {
        $ids = [];

        foreach ($recipients as $recipient) {
            $ids[] = (int) $recipient->getKey();
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $this->runs()->create([
            'audience' => $audience,
            'election_event_id' => $election?->getKey(),
            'recipient_ids' => $ids,
            'recipient_count' => count($ids),
        ]);
    }
}
