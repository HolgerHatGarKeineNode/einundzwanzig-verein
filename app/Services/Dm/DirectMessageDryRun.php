<?php

namespace App\Services\Dm;

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;

/**
 * What a sending would do, without doing any of it: how many members the
 * audience has now, how the text reads for a few of them, and who cannot be
 * reached over Nostr. Writes nothing — the message used for the preview is
 * never saved.
 */
final class DirectMessageDryRun
{
    public function __construct(
        private readonly DirectMessageAudience $audience,
        private readonly DirectMessageText $text,
    ) {}

    /**
     * @param  array<string, string>  $variables  values of the type's message placeholders
     * @param  string|null  $plaintext  null takes the type's standard text
     * @param  int  $sampleSize  how many recipients get a rendered preview
     */
    public function handle(
        DmMessageType $type,
        DmAudience $audience,
        ?Election $election = null,
        array $variables = [],
        ?string $plaintext = null,
        int $sampleSize = 3,
    ): DirectMessageDryRunResult {
        $plaintext ??= $type->defaultText();
        $recipients = $this->audience->query($audience, $election)->with(['profile', 'dmRelays'])->get();
        $problems = $this->text->problems($type, $plaintext, $variables);
        $message = new DmMessage(['type' => $type, 'plaintext' => $plaintext, 'variables' => $variables]);

        $previews = $problems !== [] ? [] : $recipients->take(max(0, $sampleSize))
            ->map(fn (EinundzwanzigPleb $recipient): array => [
                'recipient_id' => (int) $recipient->getKey(),
                'text' => $this->text->render($message, $recipient),
            ])
            ->values()
            ->all();

        return new DirectMessageDryRunResult(
            count: $recipients->count(),
            description: $this->audience->describe($audience, $election, $recipients->count()),
            problems: $problems,
            previews: $previews,
            unreachableIds: $recipients
                ->reject(fn (EinundzwanzigPleb $recipient): bool => $this->hasInbox($recipient))
                ->map(fn (EinundzwanzigPleb $recipient): int => (int) $recipient->getKey())
                ->values()
                ->all(),
        );
    }

    /**
     * The same test SendDirectMessage makes before it builds a wrap: without
     * a kind 10050 list with at least one relay the member gets the message in
     * the portal inbox only.
     */
    private function hasInbox(EinundzwanzigPleb $recipient): bool
    {
        $relays = $recipient->dmRelays?->relays;

        return is_array($relays) && array_filter($relays, fn ($relay): bool => is_string($relay) && $relay !== '') !== [];
    }
}
