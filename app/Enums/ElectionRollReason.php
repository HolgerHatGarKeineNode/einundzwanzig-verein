<?php

namespace App\Enums;

/**
 * Why a member is on the electoral roll.
 */
enum ElectionRollReason: string
{
    case ActiveFeePaid = 'active_fee_paid';

    case HonoraryFeePaid = 'honorary_fee_paid';

    /** Honorary member, and this election exempts honorary members from the fee. */
    case HonoraryExempt = 'honorary_exempt';

    /**
     * Entered by the Wahlleitung with a reason in the audit log — the roll
     * does not say more than that, the audit line does.
     */
    case ConfirmedByBoard = 'confirmed_by_board';
}
