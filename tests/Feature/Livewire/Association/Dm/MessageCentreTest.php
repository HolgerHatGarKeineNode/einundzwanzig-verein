<?php

use App\Enums\DmAudience;
use App\Enums\DmDeliveryStatus;
use App\Enums\DmMessageType;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Support\NostrAuth;
use Livewire\Livewire;

require_once __DIR__.'/../../../../Feature/Jobs/DirectMessageHelpers.php';
require_once __DIR__.'/helpers.php';

/*
 * The board's message centre at /association/messages/admin: board only,
 * a dry run that writes nothing, a test to the actor alone, a confirmed
 * sending with the count the board saw, the schedule, the pause.
 *
 * The page keeps the header, the sender-key and pause lines and the tabs;
 * each tab is a child component with a test file of its own. Here: what the
 * page does with what a tab hands back.
 */

beforeEach(fn () => armDirectMessages());
it('is a board page: a guest sees a sign-in hint, a member gets 403, the board the page and its navigation entry', function () {
    [$board] = electionBoard(1);
    $member = electionVoter();

    $this->get(route('association.messages.admin'))->assertOk()->assertSee('Melde dich mit Nostr an');

    NostrAuth::login($member->pubkey);
    $this->get(route('association.messages.admin'))->assertForbidden();
    $this->get(route('association.profile'))->assertDontSee(route('association.messages.admin'));

    NostrAuth::login($board->pubkey);
    $this->get(route('association.messages.admin'))->assertOk()->assertSee('Nachrichten an Mitglieder');
    $this->get(route('association.profile'))->assertSee(route('association.messages.admin'));

    // The sidebar entry carries the page's own name, so the board finds the
    // page by the word the guide and the page heading use. Measured on another
    // page: the message centre itself already says it in its heading.
    $this->get(route('association.profile'))
        ->assertSee('Nachrichten an Mitglieder')
        ->assertDontSee('Nachrichten senden');
});

it('answers 403 on every public method once the session belongs to a member who is not on the board', function () {
    [$board] = electionBoard(1);
    $member = electionVoter();
    $run = DmMessage::factory()->create()->freezeRun([$member]);

    $methods = expectEveryMessageMethodBoardOnly(
        fn () => messageCentre(),
        $board,
        $member,
        ['tab' => 'compose', 'runId' => $run->id],
        pure: ['longDate', 'shortDate'],
        updates: ['tab' => 'schedule'],
    );

    expect($methods)->toBe(['failedRunIds', 'hasElection', 'messageSent', 'messagesChanged', 'pausedAt', 'runToggled', 'senderKeyUsable', 'showTab', 'upcomingCount']);

    // Each tab is its own endpoint and asks again: MessageComposeTest,
    // MessageHistoryTest and MessageScheduleTest.
    NostrAuth::login($member->pubkey);
    messageCentre()->assertForbidden();
});

it('keeps one primary button: the header turns secondary while the history tab has a run open that offers its re-send', function () {
    messageCentreBoard();
    $recipient = EinundzwanzigPleb::factory()->active()->create();
    $run = DmMessage::factory()->create()->freezeRun([$recipient], DmAudience::Members);
    DmDelivery::query()->create(['dm_run_id' => $run->id, 'einundzwanzig_pleb_id' => $recipient->id, 'status' => DmDeliveryStatus::Failed]);
    $quiet = DmMessage::factory()->create()->freezeRun([$recipient], DmAudience::Members);

    // The project's primary button is the one filled with bg-orange-primary.
    $isPrimary = fn (string $html, string $test): bool => preg_match('/<button(?=[^>]*data-test="'.$test.'")[^>]*>/', $html, $tag) === 1
        && str_contains($tag[0], 'bg-orange-primary');

    $page = messageCentre()->assertSee('1 mit Fehlern');
    expect($isPrimary($page->html(), 'start-compose'))->toBeTrue();

    $history = messageHistory()->call('toggleRun', $run->id)
        ->assertSee('Fehlgeschlagene erneut senden (1)')
        ->assertDispatched('message-run-toggled', fn (string $event, array $params): bool => $params['runId'] === $run->id);
    expect($isPrimary($history->html(), 'resend-failed'))->toBeTrue();

    $page->dispatch('message-run-toggled', runId: $run->id)->assertSet('openRunId', $run->id);
    expect($isPrimary($page->html(), 'start-compose'))->toBeFalse();

    // A run without failures, or none, leaves the header primary.
    $page->dispatch('message-run-toggled', runId: $quiet->id);
    expect($isPrimary($page->html(), 'start-compose'))->toBeTrue();

    $page->dispatch('message-run-toggled', runId: null)->assertSet('openRunId', null);
    expect($isPrimary($page->html(), 'start-compose'))->toBeTrue();
});

it('opens a sending the compose tab started in the history tab', function () {
    messageCentreBoard();
    $recipient = EinundzwanzigPleb::factory()->active()->create();
    $run = DmMessage::factory()->create()->freezeRun([$recipient], DmAudience::Members);

    messageCentre()
        ->call('showTab', 'compose')
        ->assertSeeHtml('data-test="compose-text"')
        ->dispatch('message-sent', runId: $run->id)
        ->assertSet('tab', 'history')
        ->assertSet('openRunId', $run->id)
        ->assertSeeLivewire('association.messages.admin-history')
        ->assertSeeHtml('data-test="run-detail"');

    // A run that does not exist opens nothing.
    messageCentre()->call('showTab', 'compose')->dispatch('message-sent', runId: $run->id + 1)->assertNotFound();
});

it('shows the pause line once the schedule tab says the sending was paused', function () {
    messageCentreBoard();

    $page = messageCentre()->call('showTab', 'schedule')->assertDontSeeHtml('data-test="paused-line"');

    messageSchedule()->set('paused', true)->assertDispatched('messages-changed');

    $page->dispatch('messages-changed')
        ->assertSeeHtml('data-test="paused-line"')
        ->assertSee('Bis zum Fortsetzen geht keine Nachricht raus.');
});

it('lists the member admin messages of the chosen member only', function () {
    [$board] = electionBoard(1);
    NostrAuth::login($board->pubkey);
    $anna = EinundzwanzigPleb::factory()->active()->create();
    $bert = EinundzwanzigPleb::factory()->active()->create();
    $toAnna = DmMessage::factory()->create(['type' => DmMessageType::FeeReminder, 'variables' => ['year' => '2027', 'link' => 'https://x.example']]);
    $toBert = DmMessage::factory()->create(['type' => DmMessageType::ElectionResult, 'variables' => ['round' => 'Vorstand', 'link' => 'https://x.example']]);
    $annaDelivery = DmDelivery::query()->create(['dm_run_id' => $toAnna->freezeRun([$anna])->id, 'einundzwanzig_pleb_id' => $anna->id, 'status' => DmDeliveryStatus::Sent]);
    DmDelivery::query()->create(['dm_run_id' => $toBert->freezeRun([$bert])->id, 'einundzwanzig_pleb_id' => $bert->id, 'status' => DmDeliveryStatus::Failed]);

    $page = Livewire::test('association.members.admin')->call('openMessages', $anna->id);

    expect($page->instance()->memberDeliveries->pluck('id')->all())->toBe([$annaDelivery->id]);
    $page->assertSee('Erinnerung an den Mitgliedsbeitrag')
        ->assertSee('An Relays übergeben')
        ->assertDontSee('Ergebnis liegt vor')
        ->assertSee('Alles Weitere steht unter „Nachrichten an Mitglieder“.');

    NostrAuth::login(electionVoter()->pubkey);
    Livewire::test('association.members.admin')->call('openMessages', $anna->id)->assertForbidden();
});

it('never claims more than the relays told: no state label says read or delivered', function () {
    foreach (DmDeliveryStatus::cases() as $status) {
        expect(mb_strtolower($status->label()))->not->toContain('gelesen')
            ->not->toContain('zugestellt')
            ->not->toContain('read')
            ->not->toContain('delivered');
    }
});

/*
 * Q2: one primary per view. On the schedule tab „Einplanen“ is primary, so
 * the header's „Neue Nachricht“ is not. Mutation: drop $scheduleHasPrimary → red.
 */
it('keeps one primary button on the schedule tab: „Einplanen“, not the header', function () {
    messageCentreBoard();
    Election::factory()->create();

    $isPrimary = fn (string $html, string $test): bool => preg_match('/<button(?=[^>]*data-test="'.$test.'")[^>]*>/', $html, $tag) === 1
        && str_contains($tag[0], 'bg-orange-primary');

    $html = messageCentre()->call('showTab', 'schedule')->html();

    expect($isPrimary($html, 'add-schedule-submit'))->toBeTrue()
        ->and($isPrimary($html, 'start-compose'))->toBeFalse()
        ->and(substr_count($html, 'bg-orange-primary'))->toBeGreaterThan(0);
});

/*
 * Member M4: on a phone the signer is an app, not a browser extension.
 */
it('names the browser extension and the phone app as the signer in the election messages', function (DmMessageType $type) {
    expect($type->defaultText())->toContain('Nostr-Signer (Browser-Erweiterung oder Handy-App)')
        ->not->toContain('Nostr-Signer (Browser-Erweiterung).');
})->with([DmMessageType::ElectionAnnouncement, DmMessageType::ElectionStartsNow]);

/*
 * Q13: the modal close button speaks German like the rest of the site.
 */
it('labels the modal close button in German', function () {
    expect(__('Close modal'))->toBe('Schließen');
});
