<?php

namespace App\Enums;

/**
 * What a line of the election audit log records. One case per privileged
 * action; the value is what is stored and hashed, so it never changes.
 */
enum ElectionAuditAction: string
{
    case LeadAppointed = 'lead_appointed';

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

    /** Written by the payment flow, not by a person: the actor is empty. */
    case VoterEnrolledAfterStatusChange = 'voter_enrolled_after_status_change';

    case PaperVoteRecorded = 'paper_vote_recorded';

    case MemberStatusChanged = 'member_status_changed';
}
