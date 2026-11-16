<?php

namespace App\Models;

use App\Support\MarkdownRenderer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberProposalComment extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'body',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_hidden' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_proposal_id' => 'integer',
            'einundzwanzig_pleb_id' => 'integer',
            'is_hidden' => 'boolean',
        ];
    }

    /**
     * The comment as it may be output — Markdown through the same renderer
     * and sanitizer as the proposal itself.
     */
    public function safeBody(): string
    {
        return (new MarkdownRenderer)->toSafeHtml($this->body);
    }

    public function memberProposal(): BelongsTo
    {
        return $this->belongsTo(MemberProposal::class);
    }

    public function einundzwanzigPleb(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class);
    }

    public function authorName(): string
    {
        return MemberProposal::authorNameOf($this->einundzwanzigPleb);
    }

    public function isAuthoredBy(?EinundzwanzigPleb $pleb): bool
    {
        return $pleb !== null && $pleb->id === $this->einundzwanzig_pleb_id;
    }
}
