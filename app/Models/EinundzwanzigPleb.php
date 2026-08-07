<?php

namespace App\Models;

use App\Enums\AssociationStatus;
use App\Support\Board;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

class EinundzwanzigPleb extends Authenticatable implements CipherSweetEncrypted
{
    use HasFactory;
    use UsesCipherSweet;

    /**
     * What an erased record's `pubkey` and `npub` start with.
     *
     * `MembershipService::erasePersonalData()` replaces both with a random
     * value behind this prefix. It lives on the model rather than in the
     * service because "what does an erased row look like?" is a question every
     * QUERY has to be able to ask — and the first place it had to be asked was
     * a controller nobody thought of while writing the erasure.
     *
     * The prefix is outside the lowercase-hex alphabet, so it can never
     * collide with a real pubkey.
     */
    public const TOMBSTONE_PREFIX = 'deleted-';

    /**
     * What a NIP-05 handle may be — THE definition, shared by all three write
     * paths (`ProfileForm`, `benefits.blade.php`, the API's
     * `StoreApplicationRequest`). Three copies of the same regex is how they
     * drift apart, and the API's copy was already the loosest of the three.
     *
     * Two subtleties, both measured rather than assumed:
     *
     *  - `/D` is load-bearing. Without it `$` also matches BEFORE a trailing
     *    newline: `preg_match('/^[a-z0-9_-]+$/', "root\n")` returns 1, with
     *    `/D` it returns 0. That `"root\n"` still arrived as `"root"` was the
     *    work of Laravel's TrimStrings middleware — the guarantee rested on a
     *    component in a different file, and on HTTP being the only way in.
     *  - `not_in:_` keeps the name `_` out. NIP-05 gives it a special meaning:
     *    clients render `_@einundzwanzig.space` as the bare domain
     *    `einundzwanzig.space`, so whoever holds that handle appears AS THE
     *    ASSOCIATION. The underscore stays legal inside a handle — `alice_bob`
     *    is fine and existing handles must keep saving.
     *
     * Split on `|` where an array of rules is needed; no rule in it contains
     * that character, which is exactly why the regex may live in a string at
     * all.
     */
    public const NIP05_HANDLE_RULES = 'string|max:255|regex:/^[a-z0-9_-]+$/D|not_in:_';

    /**
     * `association_status` is deliberately absent: the payment constitutes the
     * membership, so the status is only ever assigned explicitly and never
     * through mass assignment from request data. `application_for` is absent
     * for the same reason — it only serves the board path PASSIVE → ACTIVE.
     *
     * @var list<string>
     */
    protected $fillable = [
        'npub',
        'pubkey',
        'email',
        'no_email',
        'nip05_handle',
        'application_text',
        'archived_application_text',
        'statutes_accepted_at',
        'applied_at',
    ];

    /**
     * Defence in depth for the three fields that must never travel in a
     * serialised model — NOT a replacement for the explicit field lists in the
     * API resources and in `GetPaidMembers`.
     *
     * CipherSweet encrypts `email` AT REST only: the accessor decrypts, so
     * `toArray()`/`toJson()` on a model that was loaded without a `select()`
     * hands out the plaintext address. Until now the only thing standing
     * between that and a public response was one `select()` line in
     * `GetPaidMembers` — a single forgotten column list away from publishing
     * every paying member's e-mail address.
     *
     * `application_text` and `archived_application_text` are free-form prose
     * people wrote about themselves and are just as unfit for a response body.
     *
     * Note what this does NOT cover, so nobody mistakes it for complete: it
     * only affects array/JSON serialisation. Direct attribute access, Blade
     * output and `DB::table()` reads are untouched, which is why the resources
     * name their fields explicitly instead of relying on this list.
     *
     * @var list<string>
     */
    protected $hidden = [
        'email',
        'application_text',
        'archived_application_text',
    ];

    protected function casts(): array
    {
        return [
            'association_status' => AssociationStatus::class,
            'statutes_accepted_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addOptionalTextField('email')
            ->addBlindIndex('email', new BlindIndex('email_index'));
    }

    public function profile()
    {
        return $this->hasOne(Profile::class, 'pubkey', 'pubkey');
    }

    public function paymentEvents()
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /**
     * Audit trail of every membership granted by a paid annual fee — it names
     * the payment event that caused each promotion.
     */
    public function membershipGrants(): HasMany
    {
        return $this->hasMany(MembershipGrant::class);
    }

    /**
     * Has this record been erased on its owner's request?
     */
    public function isErased(): bool
    {
        return str_starts_with((string) $this->pubkey, self::TOMBSTONE_PREFIX);
    }

    /**
     * Every record except the erased ones.
     *
     * Needed wherever member rows are shown, exported or carried outside the
     * application: an erased row keeps its settled fees, so it keeps matching
     * "has paid for year X" and would otherwise go on appearing in any list
     * built on that condition — with its tombstone where the npub used to be,
     * and with the same primary key as before. Two snapshots of such a list,
     * taken before and after an erasure, re-identify it by that key alone.
     *
     * The erasure even CREATES the condition for one of those queries: it
     * deletes the cached kind-0 profile, so `whereDoesntHave('profile')` — the
     * selection `sync:profiles` runs on — picks the row up on every single run
     * from then on and carries the tombstone to a relay.
     *
     * WRAPPED IN A CLOSURE, not appended bare. A caller that combines this
     * with `orWhere` (the member admin's search block does exactly that) would
     * otherwise get `… AND pubkey NOT LIKE … OR name LIKE …`, and operator
     * precedence turns the guarantee into a suggestion: any row matching the
     * or-branch comes back, erased or not. The nesting costs one closure and
     * makes the scope safe to combine with anything.
     */
    public function scopeNotErased(Builder $query): void
    {
        /*
         * The column is QUALIFIED because callers join: the member admin joins
         * `profiles` to sort by name, and `profiles` has a `pubkey` column
         * too — an unqualified reference there is an ambiguous-column error,
         * i.e. the screen would not have been filtered but broken.
         */
        $column = $query->qualifyColumn('pubkey');

        $query->where(
            fn (Builder $query) => $query->where($column, 'not like', self::TOMBSTONE_PREFIX.'%')
        );
    }

    public function isBoardMember(): bool
    {
        return Board::containsNpub($this->npub) || Board::containsPubkey($this->pubkey);
    }

    /**
     * A membership category AND the fee for the year actually settled.
     *
     * The default year comes from Carbon rather than date() so it follows a
     * travelled test clock — the same reason as in
     * `MembershipService::currentYear()`, and the two have to agree about which
     * year "current" means. Identical value in production.
     */
    public function hasPaidMembership(?int $year = null): bool
    {
        return $this->association_status->value > 1
            && $this->paymentEvents()
                ->where('year', $year ?? (int) now()->year)
                ->where('paid', true)
                ->exists();
    }
}
