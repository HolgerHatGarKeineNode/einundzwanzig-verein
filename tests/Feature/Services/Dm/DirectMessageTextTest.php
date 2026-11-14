<?php

use App\Enums\DmMessageType;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Services\Dm\ComposeDirectMessage;
use App\Services\Dm\DirectMessageText;
use App\Services\Dm\DirectMessageTextInvalid;
use App\Services\MembershipService;

beforeEach(function () {
    config()->set('einundzwanzig.config.dm_reply_contact', 'vorstand@example.org');
});

/**
 * @return array<string, string>
 */
function valuesFor(DmMessageType $type): array
{
    return collect($type->messageVariables())
        ->mapWithKeys(fn (string $variable): array => [$variable => "<{$variable}-value>"])
        ->all();
}

it('composes and renders the standard text of every type with nothing left unfilled', function (DmMessageType $type) {
    $recipient = EinundzwanzigPleb::factory()->create();
    Profile::factory()->create(['pubkey' => $recipient->pubkey, 'display_name' => 'Satoshi']);

    $message = app(ComposeDirectMessage::class)->handle($type, null, valuesFor($type));
    $text = app(DirectMessageText::class)->render($message, $recipient);

    expect($message->plaintext)->toBe($type->defaultText())
        ->and($text)->toStartWith('Hallo Satoshi,')
        ->and($text)->not->toMatch('/\{[a-z_]+\}/')
        ->and($text)->toEndWith('Fragen bitte an: vorstand@example.org');

    foreach ($type->messageVariables() as $variable) {
        expect($text)->toContain("<{$variable}-value>");
    }
})->with(DmMessageType::cases());

it('keeps every standard text to the placeholders its type declares', function (DmMessageType $type) {
    expect(app(DirectMessageText::class)->problems($type, $type->defaultText(), valuesFor($type)))->toBe([]);
})->with(DmMessageType::cases());

it('refuses a placeholder the type does not know', function () {
    app(ComposeDirectMessage::class)->handle(DmMessageType::Individual, null, [], 'Hallo {npub}');
})->throws(DirectMessageTextInvalid::class, 'unknown placeholder {npub}');

it('refuses every brace token that is not a declared placeholder, whatever its shape', function (string $token) {
    app(ComposeDirectMessage::class)->handle(DmMessageType::Individual, null, [], "Hallo {$token}");
})->throws(DirectMessageTextInvalid::class, 'unknown placeholder')->with([
    'capitalised' => '{Name}',
    'with a digit' => '{link2}',
    'with a hyphen' => '{assembly-date}',
    'padded' => '{ name }',
    'empty' => '{}',
]);

it('refuses a message whose placeholder has no value', function () {
    app(ComposeDirectMessage::class)->handle(DmMessageType::FeeReminder, null, ['year' => '2027']);
})->throws(DirectMessageTextInvalid::class, 'no value for {link}');

it('refuses a value for a placeholder the type does not have', function () {
    app(ComposeDirectMessage::class)->handle(DmMessageType::Individual, null, ['link' => 'x'], 'Hallo');
})->throws(DirectMessageTextInvalid::class, 'value for unknown placeholder {link}');

it('refuses an empty text', function () {
    app(ComposeDirectMessage::class)->handle(DmMessageType::Individual, null, [], "  \n ");
})->throws(DirectMessageTextInvalid::class, 'the text is empty');

it('refuses to compose and to render when there is no contact to name', function () {
    config()->set('einundzwanzig.config.dm_reply_contact', '');
    config()->set('app.url', '');

    expect(fn () => app(ComposeDirectMessage::class)->handle(DmMessageType::Individual, null, [], 'Hallo'))
        ->toThrow(DirectMessageTextInvalid::class, 'no reply contact');

    $message = DmMessage::factory()->create(['plaintext' => 'Hallo']);

    expect(fn () => app(DirectMessageText::class)->render($message, EinundzwanzigPleb::factory()->create()))
        ->toThrow(DirectMessageTextInvalid::class, 'no reply contact');
});

it('falls back to the application url as the contact', function () {
    config()->set('einundzwanzig.config.dm_reply_contact', '');
    config()->set('app.url', 'https://verein.example.org');

    expect(app(DirectMessageText::class)->footer())->toEndWith('Fragen bitte an: https://verein.example.org');
});

it('inserts values literally and only once', function () {
    $message = DmMessage::factory()->create([
        'type' => DmMessageType::FeeReminder,
        'plaintext' => 'Beitrag {year}: {link}',
        'variables' => ['year' => '<b>2027</b> {link}', 'link' => '{name}'],
    ]);

    $text = app(DirectMessageText::class)->render($message, EinundzwanzigPleb::factory()->create());

    expect($text)->toStartWith("Beitrag <b>2027</b> {link}: {name}\n\n--\n");
});

it('puts a profile name on one bounded line and falls back without one', function () {
    $named = EinundzwanzigPleb::factory()->create();
    Profile::factory()->create(['pubkey' => $named->pubkey, 'display_name' => '', 'name' => "Ha\nl\tlo ".str_repeat('x', 100)]);
    $nameless = EinundzwanzigPleb::factory()->create();
    $message = DmMessage::factory()->create(['plaintext' => '[{name}]']);
    $renderer = app(DirectMessageText::class);

    expect($renderer->render($message, $named))->toStartWith('[Ha l lo '.str_repeat('x', 64 - 8).']')
        ->and($renderer->render($message, $nameless))->toStartWith('[Mitglied]');
});

it('strips bidi overrides and zero-width characters from a profile name', function () {
    $member = EinundzwanzigPleb::factory()->create();
    Profile::factory()->create(['pubkey' => $member->pubkey, 'display_name' => "Sa\u{202E}to\u{200B}shi\u{2066}"]);

    $text = app(DirectMessageText::class)->render(DmMessage::factory()->create(['plaintext' => '[{name}]']), $member);

    expect($text)->toStartWith('[Satoshi]')
        ->and($text)->not->toMatch('/\p{Cf}/u');
});

it('names an erased member by the fallback only', function () {
    $member = EinundzwanzigPleb::factory()->create();
    Profile::factory()->create(['pubkey' => $member->pubkey, 'display_name' => 'Satoshi']);
    app(MembershipService::class)->erasePersonalData($member);

    $text = app(DirectMessageText::class)->render(
        DmMessage::factory()->create(['plaintext' => '[{name}]']),
        $member->fresh(),
    );

    expect($text)->toStartWith('[Mitglied]');
});

it('labels every type', function (DmMessageType $type) {
    expect($type->label())->not->toBe('');
})->with(DmMessageType::cases());
