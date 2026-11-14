<?php

namespace App\Models;

use App\Enums\ElectionAuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of an election's audit log. Written by `ElectionAuditLog` only,
 * never changed and never deleted through the model.
 *
 * The chain makes a change after the fact visible — `ElectionAuditLog::verify()`
 * — it does not prevent one: whoever can write to the database can rewrite
 * the whole chain from the changed line on.
 */
class ElectionAuditEntry extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'election_event_id',
        'sequence',
        'action',
        'election_round_id',
        'actor_pleb_id',
        'subject_pleb_id',
        'details',
        'previous_hash',
        'hash',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ElectionAuditAction::class,
            'sequence' => 'integer',
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('An audit line cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('An audit line cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<EinundzwanzigPleb, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'actor_pleb_id');
    }
}
