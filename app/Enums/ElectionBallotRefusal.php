<?php

namespace App\Enums;

/**
 * Why a ballot was not taken in.
 *
 * The label is the only text about a refusal that may reach a member. It
 * names the reason and nothing else — no member, no round, no choice.
 */
enum ElectionBallotRefusal: string
{
    case RoundNotOpen = 'round_not_open';

    case NotOnRoll = 'not_on_roll';

    case AlreadyVoted = 'already_voted';

    case InvalidChoices = 'invalid_choices';

    case TooManyAttempts = 'too_many_attempts';

    /** The event's id or signature does not hold. */
    case InvalidSignature = 'invalid_signature';

    /** Signed by another key than the member's. */
    case ForeignSigner = 'foreign_signer';

    /** Not a vote event (`VoteEvent::KIND`). */
    case WrongKind = 'wrong_kind';

    /** Signed for another election or round. */
    case WrongRound = 'wrong_round';

    /** Signed outside the round's open period. */
    case OutsideOpenPeriod = 'outside_open_period';

    public function label(): string
    {
        return match ($this) {
            self::RoundNotOpen => __('Dieser Wahlgang ist nicht geöffnet.'),
            self::NotOnRoll => __('Du stehst für diesen Wahlgang nicht im Wählerverzeichnis.'),
            self::AlreadyVoted => __('Du hast in diesem Wahlgang bereits gewählt.'),
            self::InvalidChoices => __('Dieser Stimmzettel ist nicht gültig ausgefüllt.'),
            self::TooManyAttempts => __('Zu viele Versuche. Bitte warte eine Minute und versuche es dann noch einmal.'),
            self::InvalidSignature => __('Die Signatur deiner Stimme ist ungültig. Bitte signiere sie noch einmal.'),
            self::ForeignSigner => __('Deine Stimme wurde mit einem anderen Nostr-Schlüssel signiert als dem, mit dem du angemeldet bist.'),
            self::WrongKind => __('Das signierte Ereignis ist keine Stimme.'),
            self::WrongRound => __('Diese Stimme wurde für einen anderen Wahlgang signiert.'),
            self::OutsideOpenPeriod => __('Diese Stimme wurde außerhalb der Öffnungszeit des Wahlgangs signiert. Bitte prüfe die Uhrzeit deines Geräts und signiere noch einmal.'),
        };
    }
}
