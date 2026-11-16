<?php

use App\Enums\AssociationStatus;
use App\Enums\DmAudience;
use App\Enums\DmDeliveryStatus;
use App\Enums\DmMessageType;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Jobs\Dm\SendDirectMessage;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Services\Dm\DirectMessagePause;
use App\Services\Dm\StartDirectMessageRun;
use App\Support\NostrAuth;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';
require_once __DIR__.'/../../../../Feature/Jobs/DirectMessageHelpers.php';

/*
 * The board's message centre at /association/messages/admin: board only,
 * a dry run that writes nothing, a test to the actor alone, a confirmed
 * sending with the count the board saw, the schedule, the pause.
 */

beforeEach(fn () => armDirectMessages());

function messageCentre(): Testable
{
    return Livewire::test('association.messages.admin');
}

/**
 * A board of one, logged in, plus $members further active members.
 *
 * @return array{0: EinundzwanzigPleb, 1: list<EinundzwanzigPleb>}
 */
function messageCentreBoard(int $members = 0): array
{
    [$board] = electionBoard(1);
    $others = [];

    for ($i = 0; $i < $members; $i++) {
        $others[] = EinundzwanzigPleb::factory()->active()->create();
    }

    NostrAuth::login($board->pubkey);

    return [$board, $others];
}

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
    Queue::fake();
    [$board] = electionBoard(1);
    $member = electionVoter();
    $run = DmMessage::factory()->create()->freezeRun([$member]);
    $row = DmSchedule::factory()->create();

    NostrAuth::login($board->pubkey);
    $instance = messageCentre()->instance();
    $methods = collect((new ReflectionObject($instance))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isStatic())
        ->filter(fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() === $instance::class)
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->reject(fn (string $name) => str_starts_with($name, 'updated') || in_array($name, ['mount', 'dryRunIsCurrent', 'scheduleTypes', 'variableLabel', 'variableHint', 'recipientsOf', 'countsOf', 'countsSentence', 'relaySummary', 'deliveryFailed', 'deliveryLabel', 'nameOf', 'longDate', 'shortDate'], true))
        ->values();

    expect($methods->all())->toContain('confirmSend', 'sendTestToSelf', 'runDryRun', 'resendFailed', 'addSchedule', 'removeSchedule', 'runs');

    foreach ($methods as $method) {
        NostrAuth::login($board->pubkey);
        $page = messageCentre();
        NostrAuth::login($member->pubkey);

        $reflection = new ReflectionMethod($page->instance(), $method);

        if ($reflection->getAttributes(Computed::class) !== []) {
            unset($page->instance()->{$method});
            expect(fn () => $page->instance()->{$method})->toThrow(HttpException::class);

            continue;
        }

        $arguments = collect($reflection->getParameters())
            ->reject(fn (ReflectionParameter $parameter) => $parameter->getType() instanceof ReflectionNamedType && ! $parameter->getType()->isBuiltin())
            ->map(fn (ReflectionParameter $parameter) => match ($parameter->getName()) {
                'tab' => 'compose',
                'runId' => $run->id,
                'scheduleId' => $row->id,
                default => throw new LogicException("No test argument for \${$parameter->getName()} of {$method}()."),
            })
            ->values()
            ->all();

        $page->call($method, ...$arguments)->assertForbidden();
    }

    // The update hooks run when a property is set from the browser.
    foreach (['paused' => true, 'type' => 'fee_reminder', 'audience' => 'board', 'electionId' => null, 'text' => 'x', 'tab' => 'schedule', 'scheduleElectionId' => null, 'newType' => 'fee_reminder'] as $property => $value) {
        NostrAuth::login($board->pubkey);
        $page = messageCentre();
        NostrAuth::login($member->pubkey);

        $page->set($property, $value)->assertForbidden();
    }

    expect(DmRun::query()->count())->toBe(1)
        ->and(DmSchedule::query()->whereKey($row->id)->exists())->toBeTrue()
        ->and(app(DirectMessagePause::class)->isPaused())->toBeFalse();
    Queue::assertNothingPushed();
});

it('runs a dry run that counts the audience and names who has no inbox, and writes nothing', function () {
    Queue::fake();
    [, $members] = messageCentreBoard(3);
    $members[0]->profile()->create(['pubkey' => $members[0]->pubkey, 'name' => 'Ohne Postfach']);
    [$reachable] = dmRecipient();

    $page = messageCentre()
        ->call('showTab', 'compose')
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

    messageCentre()
        ->call('showTab', 'compose')
        ->set('type', DmMessageType::Individual->value)
        ->set('text', 'Hallo {vorname}')
        ->assertSee('Den Platzhalter {vorname} kennt diese Nachricht nicht. Möglich sind: {name}.')
        ->call('runDryRun')
        ->assertSet('dryRun', null);
});

it('sends a test to the acting board member and to nobody else', function () {
    Queue::fake();
    [$board] = messageCentreBoard(4);

    messageCentre()
        ->set('type', DmMessageType::Individual->value)
        ->set('text', 'Hallo {name}, ein Test.')
        ->call('sendTestToSelf');

    $run = DmRun::query()->sole();

    expect($run->recipient_ids)->toBe([$board->id])
        ->and($run->recipient_count)->toBe(1)
        ->and($run->audience)->toBeNull()
        ->and($run->message->author_pleb_id)->toBe($board->id);
    Queue::assertPushed(DispatchBulkDirectMessages::class, fn ($job) => $job->runId === $run->id);

    messageCentre()->assertSee('Test an');
});

it('starts the confirmed sending with the count of the dry run and stores the audience and the election', function () {
    Queue::fake();
    messageCentreBoard(2);
    $election = Election::factory()->create(['name' => 'Vorstandswahl 2027']);

    $page = messageCentre()
        ->set('type', DmMessageType::ElectionStartsNow->value)
        ->set('electionId', $election->id)
        ->set('audience', DmAudience::EntitledVoters->value)
        ->call('runDryRun');
    $shown = $page->get('dryRun')['count'];

    $page->call('confirmSend')->assertSet('tab', 'history');

    $run = DmRun::query()->sole();

    // only the board member has paid this year
    expect($shown)->toBe(1)
        ->and($run->recipient_count)->toBe($shown)
        ->and($run->audience)->toBe(DmAudience::EntitledVoters)
        ->and($run->election_event_id)->toBe($election->id);
    $page->assertSee('1 Mitglied, das für die Wahl „Vorstandswahl 2027“ stimmberechtigt ist');
    Queue::assertPushed(DispatchBulkDirectMessages::class, 1);
});

it('says another sending is starting when the start lock stays taken, and writes nothing', function () {
    Queue::fake();
    messageCentreBoard(2);
    $taken = Mockery::mock(Lock::class);
    $taken->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with(StartDirectMessageRun::START_LOCK, Mockery::any())->andReturn($taken);

    messageCentre()
        ->call('showTab', 'compose')
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

    messageCentre()
        ->call('showTab', 'compose')
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

    $page = messageCentre()
        ->call('showTab', 'compose')
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
        ->assertSet('tab', 'compose');

    expect(DmRun::query()->count())->toBe(0)->and(DmMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    // a new dry run shows the new number, and that number is what is sent
    $page->call('runDryRun');
    expect($page->get('dryRun')['count'])->toBe(6);
    $page->call('confirmSend')->assertSet('tab', 'history');

    expect(DmRun::query()->sole()->recipient_count)->toBe(6);
    Queue::assertPushed(DispatchBulkDirectMessages::class, 1);
});

it('refuses to send when members were swapped after the dry run although the number is the same', function () {
    Queue::fake();
    [, $others] = messageCentreBoard(3);

    $page = messageCentre()
        ->call('showTab', 'compose')
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

    messageCentre()
        ->set('type', DmMessageType::Individual->value)
        ->set('text', 'Hallo {name}, ein Test.')
        ->call('sendTestToSelf')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && $params['slots']['text'] === 'Der Versand ist pausiert. Setze ihn im Zeitplan fort, dann geht auch der Test raus.')
        ->assertNotDispatched('toast-show', fn (string $event, array $params): bool => str_starts_with($params['slots']['text'], 'Test an dich gestartet'));

    expect(DmRun::query()->count())->toBe(0)->and(DmMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('shows an expired claim as failed everywhere: label, summary and re-send count agree', function () {
    messageCentreBoard();
    $recipients = EinundzwanzigPleb::factory()->active()->count(3)->create();
    $run = DmMessage::factory()->create()->freezeRun($recipients, DmAudience::Members);
    $now = now();
    $make = fn (int $index, DmDeliveryStatus $status, ?Carbon $claimedAt = null) => DmDelivery::query()->create([
        'dm_run_id' => $run->id,
        'einundzwanzig_pleb_id' => $recipients[$index]->id,
        'status' => $status,
        'claimed_at' => $claimedAt,
    ]);
    $failed = $make(0, DmDeliveryStatus::Failed);
    $expired = $make(1, DmDeliveryStatus::Claimed, $now->copy()->subSeconds(SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS + 5));
    $living = $make(2, DmDeliveryStatus::Claimed, $now->copy()->subSeconds(5));
    $run->forceFill(['fanned_out_at' => $now])->save();

    Queue::fake();
    $page = messageCentre()
        ->assertSee('2 fehlgeschlagen, 1 warten')
        ->call('toggleRun', $run->id)
        ->assertSee('Fehlgeschlagene erneut senden (2)')
        ->assertSeeHtml('data-test="delivery-'.$expired->id.'" data-status="claimed" data-stuck="true"')
        ->assertDontSeeHtml('data-test="delivery-'.$living->id.'" data-status="claimed" data-stuck="true"');

    $html = $page->html();
    $expiredRow = str($html)->after('data-test="delivery-'.$expired->id.'"')->before('</li>')->toString();
    $livingRow = str($html)->after('data-test="delivery-'.$living->id.'"')->before('</li>')->toString();

    expect($expiredRow)->toContain('Hängen geblieben, gilt als fehlgeschlagen')->toContain('text-danger')->not->toContain('Wird gerade gesendet')
        ->and($livingRow)->toContain('Wird gerade gesendet')->not->toContain('text-danger')
        ->and($page->instance()->countsOf($page->instance()->runs->firstWhere('id', $run->id)))->toMatchArray(['failed' => 2, 'waiting' => 1, 'retry' => 2]);

    $page->call('resendFailed', $run->id)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === '2 fehlgeschlagene Zustellungen werden erneut gesendet.');
    Queue::assertPushed(DispatchBulkDirectMessages::class, fn ($job) => $job->runId === $run->id);
    expect($failed->fresh()->status)->toBe(DmDeliveryStatus::Failed);
});

it('keeps one primary button: the header turns secondary while an open run offers its re-send', function () {
    messageCentreBoard();
    $recipient = EinundzwanzigPleb::factory()->active()->create();
    $run = DmMessage::factory()->create()->freezeRun([$recipient], DmAudience::Members);
    DmDelivery::query()->create(['dm_run_id' => $run->id, 'einundzwanzig_pleb_id' => $recipient->id, 'status' => DmDeliveryStatus::Failed]);

    // The project's primary button is the one filled with bg-orange-primary.
    $isPrimary = fn (string $html, string $test): bool => preg_match('/<button(?=[^>]*data-test="'.$test.'")[^>]*>/', $html, $tag) === 1
        && str_contains($tag[0], 'bg-orange-primary');

    $page = messageCentre();
    expect($isPrimary($page->html(), 'start-compose'))->toBeTrue();

    $page->call('toggleRun', $run->id)->assertSee('Fehlgeschlagene erneut senden (1)');
    expect($isPrimary($page->html(), 'start-compose'))->toBeFalse()
        ->and($isPrimary($page->html(), 'resend-failed'))->toBeTrue();
});

it('re-sends only the failed work of a run: failed and expired claims, never sent, no inbox or a living claim', function () {
    messageCentreBoard();
    $recipients = EinundzwanzigPleb::factory()->active()->count(5)->create();
    $run = DmMessage::factory()->create()->freezeRun($recipients);
    $now = now();
    $make = fn (int $index, DmDeliveryStatus $status, ?Carbon $claimedAt = null) => DmDelivery::query()->create([
        'dm_run_id' => $run->id,
        'einundzwanzig_pleb_id' => $recipients[$index]->id,
        'status' => $status,
        'claimed_at' => $claimedAt,
    ]);
    $sent = $make(0, DmDeliveryStatus::Sent);
    $noInbox = $make(1, DmDeliveryStatus::NoInbox);
    $failed = $make(2, DmDeliveryStatus::Failed);
    $expired = $make(3, DmDeliveryStatus::Claimed, $now->copy()->subSeconds(SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS + 5));
    $living = $make(4, DmDeliveryStatus::Claimed, $now->copy()->subSeconds(5));
    $run->forceFill(['fanned_out_at' => $now])->save();

    Queue::fake();
    messageCentre()
        ->call('toggleRun', $run->id)
        ->assertSee('Fehlgeschlagene erneut senden (2)')
        ->call('resendFailed', $run->id);

    Queue::assertPushed(DispatchBulkDirectMessages::class, fn ($job) => $job->runId === $run->id);

    // What the re-dispatched fan-out queues:
    Queue::fake();
    (new DispatchBulkDirectMessages($run->id))->handle();

    $queued = Queue::pushed(SendDirectMessage::class)->map(fn ($job) => $job->deliveryId)->sort()->values()->all();
    expect($queued)->toBe([$failed->id, $expired->id])
        ->and($queued)->not->toContain($sent->id, $noInbox->id, $living->id);
});

it('shows the state per recipient of a run, with the relays that took it', function () {
    messageCentreBoard();
    $anna = EinundzwanzigPleb::factory()->active()->create();
    $anna->profile()->create(['pubkey' => $anna->pubkey, 'name' => 'Anna']);
    $run = DmMessage::factory()->create()->freezeRun([$anna], DmAudience::Members);
    $delivery = DmDelivery::query()->create(['dm_run_id' => $run->id, 'einundzwanzig_pleb_id' => $anna->id, 'status' => DmDeliveryStatus::Failed]);
    $delivery->relays()->create(['relay_url' => 'wss://a.example/', 'status' => 'rejected', 'message' => '', 'attempts' => 1]);

    messageCentre()
        ->assertSee('1 fehlgeschlagen')
        ->call('toggleRun', $run->id)
        ->assertSee('Anna')
        ->assertSee('Kein Relay hat angenommen')
        ->assertSee('0 von 1 Relays haben angenommen');
});

/*
 * Jury run 1, L1 / board F9: a delivery no relay was tried for reads as what
 * it is, in red, counts as failed and is offered for the re-send.
 */
it('shows a delivery that was not sent for want of a key as such, not as refused by relays', function () {
    messageCentreBoard();
    $anna = EinundzwanzigPleb::factory()->active()->create();
    $anna->profile()->create(['pubkey' => $anna->pubkey, 'name' => 'Anna']);
    $run = DmMessage::factory()->create()->freezeRun([$anna], DmAudience::Members);
    $delivery = DmDelivery::query()->create(['dm_run_id' => $run->id, 'einundzwanzig_pleb_id' => $anna->id, 'status' => DmDeliveryStatus::NoSenderKey]);

    $page = messageCentre()
        ->assertSee('1 fehlgeschlagen')
        ->call('toggleRun', $run->id)
        ->assertSee('Nicht gesendet: Absenderschlüssel fehlt oder ist ungültig')
        ->assertDontSee('Kein Relay hat angenommen')
        ->assertSee('Fehlgeschlagene erneut senden (1)');

    preg_match('/<li[^>]*data-test="delivery-'.$delivery->id.'".*?<\/li>/s', $page->html(), $row);

    expect($row[0] ?? '')->toContain('text-danger');
});

it('adds a planned row in German time, says to how many it would go today, and removes it again', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2027-02-01 08:00:00'));
    messageCentreBoard(2);
    $election = Election::factory()->create();

    $page = messageCentre()
        ->call('showTab', 'schedule')
        ->set('scheduleElectionId', $election->id)
        ->set('newType', DmMessageType::ElectionAnnouncement->value)
        ->set('newDate', '2027-02-10')
        ->set('newTime', '09:00')
        ->set('newVariables.assembly_date', '20. März 2027, 19:30 Uhr')
        ->call('addSchedule')
        ->assertHasNoErrors();

    $row = DmSchedule::query()->sole();

    expect($row->send_at->toDateTimeString())->toBe('2027-02-10 08:00:00')
        ->and($row->audience)->toBe(DmAudience::Members)
        ->and($row->message->type)->toBe(DmMessageType::ElectionAnnouncement);
    $page->assertSee('würde heute an 3 gehen');

    $page->call('removeSchedule', $row->id);

    expect(DmSchedule::query()->count())->toBe(0)->and(DmMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a planned row in the past and keeps a started row', function () {
    $this->travelTo(Carbon::parse('2027-02-01 08:00:00'));
    messageCentreBoard();
    $election = Election::factory()->create();
    $started = DmSchedule::factory()->create(['election_event_id' => $election->id]);
    $started->forceFill(['dm_run_id' => DmMessage::factory()->create()->freezeRun([])->id])->save();

    messageCentre()
        ->set('scheduleElectionId', $election->id)
        ->set('newDate', '2027-01-31')
        ->set('newTime', '09:00')
        ->set('newVariables.assembly_date', 'morgen')
        ->call('addSchedule')
        ->assertHasErrors(['newDate'])
        ->call('removeSchedule', $started->id);

    expect(DmSchedule::query()->count())->toBe(1);
});

it('pauses and resumes the sending with the switch and says so', function () {
    Queue::fake();
    messageCentreBoard();

    $page = messageCentre()
        ->call('showTab', 'schedule')
        ->assertSee('Versand läuft')
        ->set('paused', true);

    expect(app(DirectMessagePause::class)->isPaused())->toBeTrue();
    $page->assertSee('Versand pausiert')->assertSee('Bis zum Fortsetzen geht keine Nachricht raus.');

    $page->set('paused', false);

    expect(app(DirectMessagePause::class)->isPaused())->toBeFalse();
    $page->assertSee('Versand läuft');
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
 * Design Q23 on the page: the second sending of the same message to the same
 * audience within the lock counts the members as skipped, creates no
 * delivery, and the history says „übersprungen“.
 */
it('shows a repeated sending within the lock as skipped and creates no new delivery', function () {
    Queue::fake();
    messageCentreBoard(2);

    $send = fn (): Testable => messageCentre()
        ->call('showTab', 'compose')
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, morgen ist Wahl.')
        ->call('runDryRun');

    $send()->call('confirmSend')->assertSet('tab', 'history');
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

    messageCentre()->call('showTab', 'history')->assertSee('3 übersprungen');
});

/*
 * The preview header and {name} in the text name a recipient the same way:
 * display name before name. Mutation: the header back on
 * MemberProposal::authorNameOf (name first) → red.
 */
it('names a recipient in the preview header as the text does, display name first', function () {
    [, $members] = messageCentreBoard(1);
    $members[0]->profile()->create(['pubkey' => $members[0]->pubkey, 'name' => 'satoshi_n', 'display_name' => 'Satoshi Nakamoto']);

    messageCentre()
        ->call('showTab', 'compose')
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

    $draft = fn (): Testable => messageCentre()
        ->call('showTab', 'compose')
        ->set('type', DmMessageType::Individual->value)
        ->set('audience', DmAudience::Members->value)
        ->set('text', 'Hallo {name}, morgen ist Wahl.')
        ->call('runDryRun');

    $pageA = $draft();
    $pageB = $draft();
    expect($pageA->get('dryRun')['count'])->toBe(3)->and($pageB->get('dryRun')['count'])->toBe(3);

    $pageA->call('confirmSend')->assertSet('tab', 'history');
    $first = DmRun::query()->sole();

    $pageB->call('confirmSend')
        ->assertNotDispatched('toast-show', fn (string $event, array $params): bool => str_contains($params['slots']['text'], 'Zielgruppe hat sich'))
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'warning'
            && $params['slots']['text'] === 'Nichts gesendet: Alle 3 Mitglieder haben diese Nachricht in den letzten 24 Stunden schon bekommen und sind übersprungen.')
        ->assertSet('tab', 'history');

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

    messageCentre()->call('showTab', 'history')->assertSee('3 übersprungen');
});

/*
 * The repeat-lock exemption above covers members the lock skips, nobody
 * else. Mutation: accept any confirmed member missing now as a skip → a
 * member who left goes unnoticed → red.
 */
it('still refuses to send when a member left after the dry run and nobody was skipped', function () {
    Queue::fake();
    [, $others] = messageCentreBoard(3);

    $page = messageCentre()
        ->call('showTab', 'compose')
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

    $page = messageCentre()
        ->assertSeeHtml('data-test="sender-key-missing"')
        ->assertSee('Absenderschlüssel fehlt.')
        ->call('showTab', 'compose')
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

    messageCentre()
        ->assertDontSeeHtml('data-test="sender-key-missing"')
        ->call('showTab', 'compose')
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

    $draft = fn (): Testable => messageCentre()
        ->call('showTab', 'compose')
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
