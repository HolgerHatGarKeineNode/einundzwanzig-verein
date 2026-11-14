<?php

namespace App\Enums;

/**
 * The kinds of direct message the association sends.
 *
 * There is no template editor: the types, their standard texts and the
 * placeholders each text may use live here, and the board changes the text of
 * a single sending (App\Services\Dm\ComposeDirectMessage checks it).
 *
 * Plain text only. A Nostr client shows the content as it is, so there is no
 * Markdown and no HTML to render — and the portal inbox has to print it
 * escaped, as text.
 *
 * Two rules every standard text keeps, because a DM is easy to imitate:
 * it never asks for a key or for payment details, and it points to the
 * portal for anything that needs a login. Election results are never in the
 * text; they are not to be published on Nostr at all, only a pointer to the
 * portal is.
 *
 * `{name}` is filled per recipient; every other placeholder is a value of the
 * message (DmMessage::$variables). The footer naming a contact is appended to
 * every text by App\Services\Dm\DirectMessageText, not written here.
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

    /** Filled from the recipient's record, never supplied by the author. */
    public const array RECIPIENT_VARIABLES = ['name'];

    public function label(): string
    {
        return match ($this) {
            self::Individual => __('Freie Nachricht'),
            self::ElectionAnnouncement => __('Ankündigung der Wahl'),
            self::NominationDeadline => __('Frist für Kandidaturen'),
            self::FeeReminder => __('Erinnerung an den Mitgliedsbeitrag'),
            self::RoundOpened => __('Wahlgang geöffnet'),
            self::RoundClosingSoon => __('Wahlgang schließt bald'),
            self::ElectionResult => __('Ergebnis liegt vor'),
        };
    }

    /**
     * The placeholders the message itself must give a value for.
     *
     * @return list<string>
     */
    public function messageVariables(): array
    {
        return match ($this) {
            self::Individual => [],
            self::ElectionAnnouncement => ['assembly_date', 'link'],
            self::NominationDeadline => ['deadline', 'link'],
            self::FeeReminder => ['year', 'link'],
            self::RoundOpened, self::RoundClosingSoon => ['round', 'closes_at', 'link'],
            self::ElectionResult => ['round', 'link'],
        };
    }

    /**
     * Every placeholder a text of this type may contain.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return [...self::RECIPIENT_VARIABLES, ...$this->messageVariables()];
    }

    public function defaultText(): string
    {
        return match ($this) {
            self::Individual => "Hallo {name},\n\n",
            self::ElectionAnnouncement => "Hallo {name},\n\n"
                .'am {assembly_date} wählt der Verein EINUNDZWANZIG einen neuen Vorstand. '
                ."Gewählt wird im Vereinsportal, angemeldet wird mit Nostr wie bisher.\n\n"
                .'Prüfe jetzt, ob du wahlberechtigt bist: {link}',
            self::NominationDeadline => "Hallo {name},\n\n"
                .'Kandidaturen für den Vorstand nimmt das Vereinsportal bis {deadline} entgegen. '
                .'Wer kandidieren möchte, reicht dort einen kurzen Vorstellungstext ein: {link}',
            self::FeeReminder => "Hallo {name},\n\n"
                .'laut Vereinsportal ist dein Mitgliedsbeitrag für {year} noch offen. '
                .'Ohne bezahlten Beitrag kannst du bei der Vorstandswahl nicht abstimmen. '
                ."Bezahlen kannst du nach der Anmeldung im Portal: {link}\n\n"
                .'Per Nachricht fragen wir nie nach Schlüsseln oder Zahlungsdaten.',
            self::RoundOpened => "Hallo {name},\n\n"
                .'der Wahlgang „{round}“ ist geöffnet und läuft bis {closes_at}. '
                .'Abstimmen kannst du nach der Anmeldung im Vereinsportal: {link}',
            self::RoundClosingSoon => "Hallo {name},\n\n"
                .'der Wahlgang „{round}“ schließt am {closes_at}. '
                .'Falls du noch abstimmen möchtest: {link}',
            self::ElectionResult => "Hallo {name},\n\n"
                .'das Ergebnis des Wahlgangs „{round}“ steht fest. '
                .'Du findest es nach der Anmeldung im Vereinsportal: {link}',
        };
    }
}
