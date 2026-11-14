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

    case InvalidReceipt = 'invalid_receipt';

    public function label(): string
    {
        return match ($this) {
            self::RoundNotOpen => __('Dieser Wahlgang ist nicht geöffnet.'),
            self::NotOnRoll => __('Du stehst für diesen Wahlgang nicht im Wählerverzeichnis.'),
            self::AlreadyVoted => __('Du hast in diesem Wahlgang bereits gewählt.'),
            self::InvalidChoices => __('Dieser Stimmzettel ist nicht gültig ausgefüllt.'),
            self::InvalidReceipt => __('Der Quittungscode ist ungültig. Bitte lade die Seite neu und versuche es noch einmal.'),
        };
    }
}
