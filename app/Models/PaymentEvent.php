<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentEvent extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'year',
        'event_id',
        'amount',
        'paid',
        'btc_pay_invoice',
    ];

    public function pleb()
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'einundzwanzig_pleb_id');
    }

    /**
     * Payments against this fee that were refused and await a human.
     *
     * @return HasMany<PaymentReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(PaymentReview::class);
    }

    /**
     * Storno records. The fee itself stays on the books either way — see
     * MembershipService::reversePayment().
     *
     * @return HasMany<PaymentReversal, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(PaymentReversal::class);
    }

    /**
     * Memberships this fee brought about.
     *
     * @return HasMany<MembershipGrant, $this>
     */
    public function membershipGrants(): HasMany
    {
        return $this->hasMany(MembershipGrant::class);
    }

    /**
     * Has money ever been booked against this fee year?
     *
     * NOT the same question as `paid`, and the difference is what made a
     * measured data loss possible. `paid` is a flag and a flag has no memory:
     * a Storno sets it back to false, after which the row is indistinguishable
     * from a fee that was never paid — and the branch that frees an expired
     * checkout keyed its refusal on precisely that flag. Second call, same
     * dead invoice, row deleted, and the reversal and the grant went with it
     * through their cascades. A promoted member was left with no record of a
     * payment, of its reversal, or of why they had been promoted.
     *
     * So the question asked before any deletion is the one with a memory: is
     * there a reversal or a grant hanging off this row? Either means money was
     * once booked here, and the row stops being disposable from that moment
     * on — permanently, regardless of what the flag says today.
     */
    public function hasSettlementHistory(): bool
    {
        return (bool) $this->paid
            || $this->reversals()->exists()
            || $this->membershipGrants()->exists();
    }
}
