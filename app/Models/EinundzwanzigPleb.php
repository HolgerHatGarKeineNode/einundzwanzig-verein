<?php

namespace App\Models;

use App\Enums\AssociationStatus;
use App\Support\Board;
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

    public function isBoardMember(): bool
    {
        return Board::containsNpub($this->npub) || Board::containsPubkey($this->pubkey);
    }

    public function hasPaidMembership(?int $year = null): bool
    {
        return $this->association_status->value > 1
            && $this->paymentEvents()
                ->where('year', $year ?? (int) date('Y'))
                ->where('paid', true)
                ->exists();
    }
}
