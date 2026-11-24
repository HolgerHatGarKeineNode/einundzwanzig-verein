<?php

use App\Enums\AssociationStatus;
use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Services\Dm\DirectMessagePause;
use App\Services\Dm\StartDirectMessageRun;
use App\Support\NostrAuth;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;

require_once __DIR__.'/../../../../Feature/Jobs/DirectMessageHelpers.php';
require_once __DIR__.'/helpers.php';

/*
 * "Neue Nachricht" of the board's message centre, a child component of its
 * own: a dry run that writes nothing, a test to the actor alone, a confirmed
 * sending with the count the board saw. A started sending is handed to the
 * page as `message-sent`.
 */

beforeEach(fn () => armDirectMessages());

it('answers 403 on every public method once the session belongs to a member who is not on the board', function () {
    Queue::fake();
    [$board] = electionBoard(1);
    $member = electionVoter();

    $methods = expectEveryMessageMethodBoardOnly(
        // A current dry run, so a send that skipped the guard would write before the render refuses.
        fn () => messageCompose()->set('type', DmMessageType::Individual->value)->set('text', 'Hallo {name}.')->call('runDryRun'),
        $board,
        $member,
        [],
        pure: ['dryRunIsCurrent', 'variableLabel', 'variableHint', 'longDate', 'shortDate'],
        updates: ['type' => 'fee_reminder', 'audience' => 'board', 'electionId' => null, 'text' => 'x', 'variables' => ['year' => '2027']],
    );

    expect($methods)->toBe(['audienceCount', 'audienceSentence', 'composeElection', 'confirmSend', 'draftProblems', 'elections', 'previews', 'restoreDefaultText', 'runDryRun', 'sendTestToSelf', 'senderKeyUsable'])
        ->and(DmMessage::query()->count())->toBe(0)
        ->and(DmRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    NostrAuth::login($member->pubkey);
    messageCompose()->assertForbidden();
});

it('runs a dry run that counts the audience and names who has no inbox, and writes nothing', function () {
    Queue::fake();
    [, $members] = messageCentreBoard(3);
    $members[0]->profile()->create(['pubkey' => $members[0]->pubkey, 'name' => 'Ohne Postfach']);
    [$reachable] = dmRecipient();

    $page = messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', "Hallo {name},\n\nTreffen am Freitag.")
        ->call('runDryRun')
        ->assertHasNoErrors();

    // board + 3 members + 1 reachable; only the last has a kind 10050 list
    expect($page->get('dryRun')['count'])->toBe(5)
        ->and($page->get('dryRun')['unreachable_count'])->toBe(4)
        ->and(DmMessage::query()->count())->toBe(0)
        ->and(DmRun::query()->count())->toBe(0)
        ->and(DmDelivery::query()->count())->toBe(0);
    $page->assertSee('5 Empfänger.')->assertSee('Ohne Postfach')->assertSee('An 5 Mitglieder senden');
    Queue::assertNothingPushed();
});

it('shows a placeholder the type does not know under the text and offers no dry run', function () {
    messageCentreBoard(1);

    messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('text', 'Hallo {vorname}')
        ->assertSee('Den Platzhalter {vorname} kennt diese Nachricht nicht. Möglich sind: {name}.')
        ->call('runDryRun')
        ->assertSet('dryRun', null);
});

it('sends a test to the acting board member and to nobody else', function () {
    Queue::fake();
    [$board] = messageCentreBoard(4);

    messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('text', 'Hallo {name}, ein Test.')
        ->call('sendTestToSelf');

    $run = DmRun::query()->sole();

    expect($run->recipient_ids)->toBe([$board->id])
        ->and($run->recipient_count)->toBe(1)
        ->and($run->audience)->toBeNull()
        ->and($run->message->author_pleb_id)->toBe($board->id);
    Queue::assertPushed(DispatchBulkDirectMessages::class, fn ($job) => $job->runId === $run->id);

    messageHistory()->assertSee('Test an');
});

it('starts the confirmed sending with the count of the dry run and stores the audience and the election', function () {
    Queue::fake();
    messageCentreBoard(2);
    $election = Election::factory()->create(['name' => 'Vorstandswahl 2027']);

    $page = messageCompose()
        ->set('type', DmMessageType::ElectionStartsNow->value)
        ->set('electionId', $election->id)
        ->set('audience', DmAudience::EntitledVoters->value)
        ->call('runDryRun');
    $shown = $page->get('dryRun')['count'];

    $page->call('confirmSend');

    $run = DmRun::query()->sole();
    $page->assertDispatched('message-sent', fn (string $event, array $params): bool => $params['runId'] === $run->id);

    // only the board member has paid this year
    expect($shown)->toBe(1)
        ->and($run->recipient_count)->toBe($shown)
        ->and($run->audience)->toBe(DmAudience::EntitledVoters)
        ->and($run->election_event_id)->toBe($election->id);
    messageHistory($run->id)->assertSee('1 Mitglied, das für die Wahl „Vorstandswahl 2027“ stimmberechtigt ist');
    Queue::assertPushed(DispatchBulkDirectMessages::class, 1);
});

it('says another sending is starting when the start lock stays taken, and writes nothing', function () {
    Queue::fake();
    messageCentreBoard(2);
    $taken = Mockery::mock(Lock::class);
    $taken->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with(StartDirectMessageRun::START_LOCK, Mockery::any())->andReturn($taken);

    messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('text', 'Hallo {name}, eins.')
        ->call('runDryRun')
        ->call('confirmSend')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && $params['slots']['text'] === 'Gerade startet ein anderer Versand. Es wurde nichts gesendet. Versuche es in einer Minute noch einmal.');

    expect(DmRun::query()->count())->toBe(0)->and(DmMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses to send when the draft changed after the dry run', function () {
    Queue::fake();
    messageCentreBoard(2);

    messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('text', 'Hallo {name}, eins.')
        ->call('runDryRun')
        ->set('text', 'Hallo {name}, zwei.')
        ->assertSee('Seit dem Probelauf hat sich etwas geändert.')
        ->call('confirmSend');

    expect(DmRun::query()->count())->toBe(0)->and(DmMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses to send when the audience changed after the dry run, and queues nothing', function () {
    Queue::fake();
    messageCentreBoard(3);

    $page = messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, eine Nachricht.')
        ->call('runDryRun');

    expect($page->get('dryRun')['count'])->toBe(4);

    // two members join between the dry run and the confirmation
    EinundzwanzigPleb::factory()->active()->count(2)->create();

    $page->call('confirmSend')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && $params['slots']['text'] === 'Die Zielgruppe hat sich seit dem Probelauf geändert: jetzt 6 Mitglieder statt 4. Es wurde nichts gesendet. Starte den Probelauf neu.')
        ->assertSet('dryRun', null)
        ->assertNotDispatched('message-sent');

    expect(DmRun::query()->count())->toBe(0)->and(DmMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    // a new dry run shows the new number, and that number is what is sent
    $page->call('runDryRun');
    expect($page->get('dryRun')['count'])->toBe(6);
    $page->call('confirmSend')->assertDispatched('message-sent');

    expect(DmRun::query()->sole()->recipient_count)->toBe(6);
    Queue::assertPushed(DispatchBulkDirectMessages::class, 1);
});

it('refuses to send when members were swapped after the dry run although the number is the same', function () {
    Queue::fake();
    [, $others] = messageCentreBoard(3);

    $page = messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, eine Nachricht.')
        ->call('runDryRun');

    expect($page->get('dryRun')['count'])->toBe(4);

    // one member leaves the voting statuses, another one joins: still four
    $others[0]->forceFill(['association_status' => AssociationStatus::DEFAULT])->save();
    EinundzwanzigPleb::factory()->active()->create();

    $page->call('confirmSend')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && $params['slots']['text'] === 'Die Zielgruppe hat sich seit dem Probelauf geändert: gleich viele, aber andere Mitglieder. Es wurde nichts gesendet. Starte den Probelauf neu.')
        ->assertSet('dryRun', null);

    expect(DmRun::query()->count())->toBe(0)->and(DmMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses the test to self when the sending was paused after the page checked, and starts nothing', function () {
    Queue::fake();
    messageCentreBoard(2);

    // the pause lands between the page's check and the service's check
    DmMessage::created(fn () => app(DirectMessagePause::class)->pause());

    messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('text', 'Hallo {name}, ein Test.')
        ->call('sendTestToSelf')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && $params['slots']['text'] === 'Der Versand ist pausiert. Setze ihn im Zeitplan fort, dann geht auch der Test raus.')
        ->assertNotDispatched('toast-show', fn (string $event, array $params): bool => str_starts_with($params['slots']['text'], 'Test an dich gestartet'));

    expect(DmRun::query()->count())->toBe(0)->and(DmMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

/*
 * Design Q23 on the page: the second sending of the same message to the same
 * audience within the lock counts the members as skipped, creates no
 * delivery, and the history says „übersprungen“.
 */
it('shows a repeated sending within the lock as skipped and creates no new delivery', function () {
    Queue::fake();
    messageCentreBoard(2);

    $send = fn (): Testable => messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, morgen ist Wahl.')
        ->call('runDryRun');

    $send()->call('confirmSend')->assertDispatched('message-sent');
    $first = DmRun::query()->sole();
    (new DispatchBulkDirectMessages($first->id))->handle();

    // Nobody is left, so nothing may claim that "all" have an inbox.
    $page = $send()
        ->assertSee('0 Empfänger.')
        ->assertSee('Alle haben diese Nachricht in den letzten 24 Stunden schon bekommen.')
        ->assertDontSee('Alle haben einen Nostr-Posteingang.')
        ->assertSee('Übersprungen: 3 Mitglieder, sie haben diese Nachricht für diese Zielgruppe in den letzten 24 Stunden schon bekommen.')
        ->assertSee('Alle schon erreicht, nichts zu senden');

    expect($page->get('dryRun')['count'])->toBe(0)
        ->and(DmDelivery::query()->count())->toBe(3);

    // A start that slips through anyway (an older page, a second board member) adds nobody.
    $again = app(StartDirectMessageRun::class)->handle(tap($first->message->replicate())->save(), DmAudience::Members);
    (new DispatchBulkDirectMessages($again->id))->handle();

    expect(DmDelivery::query()->count())->toBe(3);

    messageHistory()->assertSee('3 übersprungen');
});

/*
 * The preview header and {name} in the text name a recipient the same way:
 * display name before name. Mutation: the header back on
 * MemberProposal::authorNameOf (name first) → red.
 */
it('names a recipient in the preview header as the text does, display name first', function () {
    [, $members] = messageCentreBoard(1);
    $members[0]->profile()->create(['pubkey' => $members[0]->pubkey, 'name' => 'satoshi_n', 'display_name' => 'Satoshi Nakamoto']);

    messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, bis Freitag.')
        ->assertSee('An Satoshi Nakamoto, von EINUNDZWANZIG')
        ->assertSee('Hallo Satoshi Nakamoto, bis Freitag.')
        ->assertDontSee('An satoshi_n, von EINUNDZWANZIG')
        // The board member has no profile: the header keeps the npub, only the text says „Mitglied“.
        ->assertSee('Hallo Mitglied, bis Freitag.')
        ->assertDontSee('An Mitglied, von EINUNDZWANZIG');
});

/*
 * Two board pages dry-run the same message; page A confirms, then page B.
 * B's difference to its dry run is only the repeat lock: it says the members
 * were skipped because the message just went out, not that the audience
 * changed, and nobody gets the message twice. Mutation: drop the repeat-lock
 * exemption in StartDirectMessageRun::freeze() → B reads „Die Zielgruppe hat
 * sich … geändert“ → red.
 */
it('tells a second board page that confirms after the first that everyone was skipped, and sends nothing twice', function () {
    Queue::fake();
    messageCentreBoard(2);

    $draft = fn (): Testable => messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, morgen ist Wahl.')
        ->call('runDryRun');

    $pageA = $draft();
    $pageB = $draft();
    expect($pageA->get('dryRun')['count'])->toBe(3)->and($pageB->get('dryRun')['count'])->toBe(3);

    $pageA->call('confirmSend')->assertDispatched('message-sent');
    $first = DmRun::query()->sole();

    $pageB->call('confirmSend')
        ->assertNotDispatched('toast-show', fn (string $event, array $params): bool => str_contains($params['slots']['text'], 'Zielgruppe hat sich'))
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'warning'
            && $params['slots']['text'] === 'Nichts gesendet: Alle 3 Mitglieder haben diese Nachricht in den letzten 24 Stunden schon bekommen und sind übersprungen.')
        ->assertDispatched('message-sent');

    $second = DmRun::query()->whereKeyNot($first->id)->sole();

    expect($second->recipient_ids)->toBe([])
        ->and($second->recipient_count)->toBe(0)
        ->and($second->skipped_ids)->toBe($first->recipient_ids);

    foreach (DmRun::query()->pluck('id') as $runId) {
        (new DispatchBulkDirectMessages($runId))->handle();
    }

    expect(DmDelivery::query()->count())->toBe(3)
        ->and(DmDelivery::query()->distinct()->count('einundzwanzig_pleb_id'))->toBe(3)
        ->and(DmDelivery::query()->where('dm_run_id', $second->id)->count())->toBe(0);

    messageHistory()->assertSee('3 übersprungen');
});

/*
 * The repeat-lock exemption above covers members the lock skips, nobody
 * else. Mutation: accept any confirmed member missing now as a skip → a
 * member who left goes unnoticed → red.
 */
it('still refuses to send when a member left after the dry run and nobody was skipped', function () {
    Queue::fake();
    [, $others] = messageCentreBoard(3);

    $page = messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, eine Nachricht.')
        ->call('runDryRun');

    $others[0]->forceFill(['association_status' => AssociationStatus::DEFAULT])->save();

    $page->call('confirmSend')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && $params['slots']['text'] === 'Die Zielgruppe hat sich seit dem Probelauf geändert: jetzt 3 Mitglieder statt 4. Es wurde nichts gesendet. Starte den Probelauf neu.');

    expect(DmRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

/*
 * Board M3: without a usable sender key every Nostr delivery fails as
 * `no_sender_key`; only the portal copy arrives. The board is told before it
 * sends: on the page, in the dry run, in the confirm dialog and in the toast.
 * Sending stays possible (the portal copy is real, the failures can be
 * re-sent once the key is set). The key itself never reaches the page.
 * Mutation that must turn this red: senderKeyUsable() always true.
 */
it('warns on the page, in the dry run, in the confirm dialog and in the toast when the sender key is missing', function () {
    Queue::fake();
    messageCentreBoard(2);
    config()->set('services.nostr_notification_key', '');

    messageCentre()
        ->assertSeeHtml('data-test="sender-key-missing"')
        ->assertSee('Absenderschlüssel fehlt.');

    $page = messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, morgen ist Wahl.')
        ->call('runDryRun')
        ->assertSee('3 Empfänger.')
        ->assertSee('Aber der Absenderschlüssel fehlt: Es geht keine Nostr-Nachricht raus, alle 3 lesen sie nur im Portal.')
        ->assertSeeHtml('data-test="confirm-no-sender-key"')
        ->assertDontSee('und die Nachricht geht sofort raus');

    $page->call('confirmSend')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'warning'
            && $params['slots']['text'] === 'Gestartet: geht an 3 Mitglieder. Ohne Absenderschlüssel steht sie nur im Posteingang im Portal.');

    expect(DmRun::query()->sole()->recipient_count)->toBe(3);
});

it('shows no sender-key warning when the key is set', function () {
    Queue::fake();
    messageCentreBoard(1);

    messageCentre()->assertDontSeeHtml('data-test="sender-key-missing"');

    messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, morgen ist Wahl.')
        ->call('runDryRun')
        ->assertDontSeeHtml('data-test="dry-run-no-sender-key"')
        ->assertDontSeeHtml('data-test="confirm-no-sender-key"')
        ->assertSee('und die Nachricht geht sofort raus');
});

/*
 * Board low: a sending that skips some members says how many in its toast.
 * Mutation that must turn this red: drop the skipped sentence.
 */
it('names the skipped count in the toast of a sending that reaches only part of the audience', function () {
    Queue::fake();
    messageCentreBoard(2);

    $draft = fn (): Testable => messageCompose()
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, morgen ist Wahl.')
        ->call('runDryRun');

    $draft()->call('confirmSend');
    (new DispatchBulkDirectMessages(DmRun::query()->sole()->id))->handle();
    EinundzwanzigPleb::factory()->active()->create();

    $draft()->assertSee('Ein Empfänger.')
        ->call('confirmSend')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'success'
            && $params['slots']['text'] === 'Gestartet: geht an ein Mitglied. Übersprungen: 3 Mitglieder, sie haben sie in den letzten 24 Stunden schon bekommen.');
});
