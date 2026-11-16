<?php

namespace App\Models;

use App\Enums\MemberProposalState;
use App\Support\MarkdownRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MemberProposal extends Model
{
    use HasFactory;

    /**
     * Only what the author types. State, note, visibility and the author are
     * set explicitly by the code that is allowed to.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
    ];

    /**
     * The flag is `is_hidden` and not `hidden`: inside a model `$this->hidden`
     * is Eloquent's own serialisation list, which is always falsy here — the
     * check would read "not hidden" for every row and say nothing.
     *
     * The column defaults, so a freshly built model answers `isArchived()`
     * and friends before it has been read back from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => 'open',
        'is_hidden' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'einundzwanzig_pleb_id' => 'integer',
            'state' => MemberProposalState::class,
            'is_hidden' => 'boolean',
            'state_changed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * The description as it may be output. The column holds Markdown; this
     * is the only form meant for `{!! !!}`.
     */
    public function safeDescription(): string
    {
        return (new MarkdownRenderer)->toSafeHtml($this->description);
    }

    public function einundzwanzigPleb(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class);
    }

    /**
     * What to print for an author. An erased member has no profile and a
     * tombstone where the npub was — neither is a name, and neither is shown.
     *
     * Without a profile name the whole npub stands in for it, never a cut
     * one: on a ballot or in a result a name is not shortened (checklist
     * Q11), and 24 characters of an npub identify nobody. Every place that
     * prints it lets a long word wrap.
     */
    public static function authorNameOf(?EinundzwanzigPleb $pleb): string
    {
        if ($pleb === null || $pleb->isErased()) {
            return __('Ehemaliges Mitglied');
        }

        return $pleb->profile?->name
            ?: ($pleb->profile?->display_name ?: (string) $pleb->npub);
    }

    public function authorName(): string
    {
        return self::authorNameOf($this->einundzwanzigPleb);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(MemberProposalVote::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(MemberProposalComment::class);
    }

    public function isArchived(): bool
    {
        return $this->state->isArchived();
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    /**
     * May votes and comments still be added? Not in the archive, not on a
     * withdrawn proposal and not while the board has hidden it.
     */
    public function acceptsParticipation(): bool
    {
        return ! $this->isArchived() && ! $this->isWithdrawn() && ! $this->is_hidden;
    }

    public function isAuthoredBy(?EinundzwanzigPleb $pleb): bool
    {
        return $pleb !== null && $pleb->id === $this->einundzwanzig_pleb_id;
    }

    public function scopeNotWithdrawn(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('withdrawn_at'));
    }

    /**
     * What a viewer may see in a list: nothing withdrawn, and hidden
     * proposals only for the board and their own author.
     */
    public function scopeVisibleTo(Builder $query, ?EinundzwanzigPleb $viewer): void
    {
        $query->notWithdrawn();

        if ($viewer?->isBoardMember()) {
            return;
        }

        $hidden = $query->qualifyColumn('is_hidden');
        $author = $query->qualifyColumn('einundzwanzig_pleb_id');

        $query->where(fn (Builder $query) => $query
            ->where($hidden, false)
            ->when($viewer !== null, fn (Builder $query) => $query->orWhere($author, $viewer->id)));
    }

    /**
     * @param  list<MemberProposalState>  $states
     */
    public function scopeInStates(Builder $query, array $states): void
    {
        $query->whereIn(
            $query->qualifyColumn('state'),
            array_map(fn (MemberProposalState $state): string => $state->value, $states),
        );
    }

    /**
     * The two numbers the pages show, as subqueries — no vote row is loaded,
     * so no voter can end up in a page or a Livewire snapshot by accident.
     */
    public function scopeWithVoteCounts(Builder $query): void
    {
        $query->withCount([
            'votes as up_votes_count' => fn (Builder $query) => $query->where('value', MemberProposalVote::UP),
            'votes as down_votes_count' => fn (Builder $query) => $query->where('value', MemberProposalVote::DOWN),
        ]);
    }

    /**
     * Record a member's vote: a new value replaces the old one, the same
     * value again takes the vote back.
     *
     * @return string|null the member's vote afterwards
     */
    public function castVote(EinundzwanzigPleb $pleb, string $value): ?string
    {
        if (! in_array($value, MemberProposalVote::VALUES, true)) {
            throw new InvalidArgumentException('Unknown vote value.');
        }

        try {
            return DB::transaction(function () use ($pleb, $value): ?string {
                $vote = $this->votes()->where('einundzwanzig_pleb_id', $pleb->id)->lockForUpdate()->first();

                if ($vote === null) {
                    $vote = new MemberProposalVote;
                    $vote->member_proposal_id = $this->id;
                    $vote->einundzwanzig_pleb_id = $pleb->id;
                    $vote->value = $value;
                    $vote->save();

                    return $value;
                }

                if ($vote->value === $value) {
                    $vote->delete();

                    return null;
                }

                $vote->value = $value;
                $vote->save();

                return $value;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request of the same member inserted first. The
            // index kept it to one row; that row now takes this value.
            $this->votes()->where('einundzwanzig_pleb_id', $pleb->id)->update(['value' => $value]);

            return $value;
        }
    }

    /**
     * The given member's own vote, or null.
     */
    public function voteOf(?EinundzwanzigPleb $pleb): ?string
    {
        if ($pleb === null) {
            return null;
        }

        return $this->votes()->where('einundzwanzig_pleb_id', $pleb->id)->value('value');
    }
}
