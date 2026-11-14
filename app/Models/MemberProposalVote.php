<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberProposalVote extends Model
{
    use HasFactory;

    public const UP = 'up';

    public const DOWN = 'down';

    public const VALUES = [self::UP, self::DOWN];

    /**
     * Nothing is mass assignable: a vote is written in exactly one place,
     * `MemberProposal::castVote()`, attribute by attribute.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_proposal_id' => 'integer',
            'einundzwanzig_pleb_id' => 'integer',
        ];
    }

    public function memberProposal(): BelongsTo
    {
        return $this->belongsTo(MemberProposal::class);
    }

    public function einundzwanzigPleb(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class);
    }
}
