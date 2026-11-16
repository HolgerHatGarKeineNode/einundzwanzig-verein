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

    /** A run-off was set up; for a tie the line carries `resolution: assembly`, the assembly having resolved it. */
    case RunOffCreated = 'run_off_created';

    /** The election officer entered the chair's casting vote on a tie (statutes Art. 6). */
    case CastingVoteRecorded = 'casting_vote_recorded';

    /** An annulled round was set up again as a new draft; the line names the annulled round. */
    case RoundRepeated = 'round_repeated';
}
