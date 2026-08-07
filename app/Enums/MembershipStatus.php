<?php

namespace App\Enums;

/**
 * The one true answer to "is this person a member right now".
 *
 * It exists because `association_status` alone answers a DIFFERENT question.
 * Art. 4.1 of the statutes lets a membership lapse after a year without
 * payment, and active/honorary members lose their category with it — but the
 * association deliberately does NOT implement that as a hard cut (plan
 * `docs/plans/2026-08-06T1114-mitgliedschafts-api.md`, "Beitrittsmodell"
 * point 4): the enum keeps its value and only the payment state moves. A
 * record reading ACTIVE with an unpaid current year is therefore no longer a
 * member under the statutes while still reading ACTIVE in the database.
 *
 * Whoever evaluates the enum on its own gets that case wrong, and two clients
 * evaluating it differently would publish two contradicting answers about the
 * same person. Hence one derived value, computed in exactly one place
 * (`MembershipService::membershipStatus()`), served to every consumer.
 */
enum MembershipStatus: string
{
    /** No application on record, and no membership category. */
    case None = 'none';

    /** Applied for, current fee year still unpaid, not a member yet. */
    case AwaitingPayment = 'awaiting_payment';

    /** A membership category AND the current fee year paid. */
    case Member = 'member';

    /** A membership category, but the current fee year is unpaid. */
    case Lapsed = 'lapsed';
}
