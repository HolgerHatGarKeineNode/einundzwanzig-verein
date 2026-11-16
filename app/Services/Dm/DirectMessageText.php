<?php

namespace App\Services\Dm;

use App\Enums\DmMessageType;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;

/**
 * Fills a message in for one recipient: placeholders replaced, reply footer
 * appended. The result is plain text and is sent and stored as it is.
 *
 * Values go in literally. Nothing is escaped here because nothing here is
 * markup; whoever prints the text in HTML escapes it there.
 */
final class DirectMessageText
{
    /**
     * Any brace pair, not only well-formed names: `{Name}` or `{link2}` would
     * otherwise go out literally instead of being refused.
     */
    private const string PLACEHOLDER = '/\{([^{}]*)\}/u';

    /** A name from a kind 0 profile is foreign input; it gets one line and a bound. */
    private const int MAX_NAME_LENGTH = 64;

    /** Used when a member has no profile name, and for every erased member. */
    public const string FALLBACK_NAME = 'Mitglied';

    /**
     * Everything that keeps this text from being sent as a message of this
     * type; empty when it can go out.
     *
     * @param  array<string, mixed>  $values  the message-wide placeholder values
     * @return list<string>
     */
    public function problems(DmMessageType $type, string $text, array $values): array
    {
        $problems = [];

        if (trim($text) === '') {
            $problems[] = 'the text is empty';
        }

        foreach ($this->placeholdersIn($text) as $placeholder) {
            if (! in_array($placeholder, $type->variables(), true)) {
                $problems[] = "unknown placeholder {{$placeholder}}";
            }
        }

        foreach ($type->messageVariables() as $variable) {
            $value = $values[$variable] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $problems[] = "no value for {{$variable}}";
            }
        }

        foreach (array_keys($values) as $key) {
            if (! in_array($key, $type->messageVariables(), true)) {
                $problems[] = "value for unknown placeholder {{$key}}";
            }
        }

        if ($this->replyContact() === '') {
            $problems[] = 'no reply contact is configured (DM_REPLY_CONTACT)';
        }

        return $problems;
    }

    /**
     * The text this recipient receives.
     *
     * @throws DirectMessageTextInvalid when the stored message no longer passes problems()
     */
    public function render(DmMessage $message, EinundzwanzigPleb $recipient): string
    {
        $values = $message->variables ?? [];
        $problems = $this->problems($message->type, $message->plaintext, $values);

        if ($problems !== []) {
            throw DirectMessageTextInvalid::because($problems);
        }

        $values['name'] = $this->nameOf($recipient);

        $body = preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $match): string => (string) $values[$match[1]],
            $message->plaintext,
        );

        return rtrim((string) $body)."\n\n".$this->footer();
    }

    /**
     * E21: replies to this key are read by nobody, so every message says so
     * and names where to write instead.
     */
    public function footer(): string
    {
        return "--\nDiese Nachricht kommt vom Verein EINUNDZWANZIG. Antworten an dieses Konto liest niemand. "
            .'Fragen bitte an: '.$this->replyContact();
    }

    private function replyContact(): string
    {
        $contact = config('einundzwanzig.config.dm_reply_contact');

        if (! is_string($contact) || trim($contact) === '') {
            $contact = config('app.url');
        }

        return is_string($contact) ? trim($contact) : '';
    }

    /**
     * @return list<string>
     */
    private function placeholdersIn(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function nameOf(EinundzwanzigPleb $recipient): string
    {
        return $this->profileNameOf($recipient) ?? self::FALLBACK_NAME;
    }

    /**
     * The name `{name}` is filled with: the profile's display name, else its
     * name, cleaned and bounded. Null for an erased member or one without a
     * usable profile name. Whoever shows a recipient next to the text (the
     * preview header) uses this, so both say the same name.
     */
    public function profileNameOf(EinundzwanzigPleb $recipient): ?string
    {
        if ($recipient->isErased()) {
            return null;
        }

        $profile = $recipient->profile;
        $name = (string) ($profile?->display_name ?: $profile?->name ?: '');
        // Format characters (bidi overrides, zero-width) would let a name
        // reorder or hide the text around it.
        $name = (string) preg_replace('/\p{Cf}+/u', '', $name);
        $name = trim((string) preg_replace('/[\s\p{Cc}]+/u', ' ', $name));

        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
