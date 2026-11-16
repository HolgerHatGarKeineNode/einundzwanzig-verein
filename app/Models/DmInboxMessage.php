<?php

namespace App\Models;

use Database\Factories\DmInboxMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message of the board inbox: a member's reply to the notification key
 * (`in`) or the board's answer (`out`). See the migration for what is stored.
 */
class DmInboxMessage extends Model
{
    /** @use HasFactory<DmInboxMessageFactory> */
    use HasFactory;

    public const string IN = 'in';

    public const string OUT = 'out';

    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_NO_INBOX = 'no_inbox';

    public const string STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'einundzwanzig_pleb_id',
        'direction',
        'rumor_id',
        'body',
        'sent_at',
        'read_at',
        'author_pleb_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'einundzwanzig_pleb_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'author_pleb_id');
    }
}
