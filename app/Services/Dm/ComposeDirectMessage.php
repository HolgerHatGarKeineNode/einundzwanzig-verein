<?php

namespace App\Services\Dm;

use App\Enums\DmMessageType;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;

/**
 * Stores a message once its text passes the rules of its type.
 *
 * Checked here and not only when sending: a placeholder the type does not
 * know would otherwise surface as a failed delivery for every recipient.
 */
final class ComposeDirectMessage
{
    public function __construct(private readonly DirectMessageText $text) {}

    /**
     * @param  array<string, string>  $variables  values of the type's message placeholders
     * @param  string|null  $plaintext  null takes the type's standard text
     *
     * @throws DirectMessageTextInvalid
     */
    public function handle(
        DmMessageType $type,
        ?EinundzwanzigPleb $author,
        array $variables = [],
        ?string $plaintext = null,
    ): DmMessage {
        $plaintext ??= $type->defaultText();
        $problems = $this->text->problems($type, $plaintext, $variables);

        if ($problems !== []) {
            throw DirectMessageTextInvalid::because($problems);
        }

        return DmMessage::query()->create([
            'type' => $type,
            'plaintext' => $plaintext,
            'variables' => $variables,
            'author_pleb_id' => $author?->getKey(),
        ]);
    }
}
