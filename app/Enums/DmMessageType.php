<?php

namespace App\Enums;

/**
 * The kinds of direct message the association sends.
 *
 * There is no template editor: the types and their standard texts live in
 * code, and the board changes the text of a single sending.
 */
enum DmMessageType: string
{
    case Individual = 'individual';

    case ElectionAnnouncement = 'election_announcement';

    case NominationDeadline = 'nomination_deadline';

    case FeeReminder = 'fee_reminder';

    case RoundOpened = 'round_opened';

    case RoundClosingSoon = 'round_closing_soon';

    case ElectionResult = 'election_result';
}
