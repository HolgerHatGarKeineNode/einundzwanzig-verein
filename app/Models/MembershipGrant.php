<?php

namespace App\Models;

use App\Enums\AssociationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record of a membership granted by a paid annual fee. Written only by
 * MembershipService::grantMembershipOnPayment().
 */
class MembershipGrant extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'einundzwanzig_pleb_id',
        'payment_event_id',
        'from_status',
        'to_status',
        'year',
        'granted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => AssociationStatus::class,
            'to_status' => AssociationStatus::class,
            'granted_at' => 'datetime',
        ];
    }

    public function pleb(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'einundzwanzig_pleb_id');
    }

    public function paymentEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentEvent::class);
    }
}
