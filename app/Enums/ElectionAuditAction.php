<?php

namespace App\Enums;

/**
 * What a line of the election audit log records. One case per privileged
 * action; the value is what is stored and hashed, so it never changes.
 */
enum ElectionAuditAction: string
{
    case ElectionCreated = 'election_created';

    case ElectionUpdated = 'election_updated';

    case ElectionAnnounced = 'election_announced';

    case ElectionDeleted = 'election_deleted';

    case CandidacyApproved = 'candidacy_approved';

    case CandidacyRejected = 'candidacy_rejected';

    case CandidacyWithdrawn = 'candidacy_withdrawn';

    case RoundOpened = 'round_opened';

    case RoundClosed = 'round_closed';

    case RoundClosedEarly = 'round_closed_early';

    case RoundAnnulled = 'round_annulled';

    case VoterEnrolledByHand = 'voter_enrolled_by_hand';

    /** The meeting time or the call link changed after a round was opened. */
    case ElectionMeetingUpdated = 'election_meeting_updated';

    case RunOffCreated = 'run_off_created';
}
