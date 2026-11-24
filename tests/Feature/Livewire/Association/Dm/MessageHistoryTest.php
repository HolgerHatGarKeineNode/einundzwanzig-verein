<?php

use App\Enums\DmAudience;
use App\Enums\DmDeliveryStatus;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Jobs\Dm\SendDirectMessage;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../../../Feature/Jobs/DirectMessageHelpers.php';
require_once __DIR__.'/helpers.php';

/*
 * "Verlauf" of the board's message centre, a child component of its own:
 * every run with its counts, one opened with its state per recipient, and
 * the re-send of its failed work. Opening a run is handed to the page as
 * `message-run-toggled`.
 */

beforeEach(fn () => armDirectMessages());

it('answers 403 on every public method once the session belongs to a member who is not on the board', function () {
    Queue::fake();
    [$board] = electionBoard(1);
    $member = electionVoter();
    $run = DmMessage::factory()->create()->freezeRun([$member]);
    // Failed work, so a re-send that skipped the guard would queue it.
    DmDelivery::query()->create(['dm_run_id' => $run->id, 'einundzwanzig_pleb_id' => $member->id, 'status' => DmDeliveryStatus::Failed]);

    $methods = expectEveryMessageMethodBoardOnly(
        fn () => messageHistory(),
        $board,
        $member,
        ['runId' => $run->id],
        pure: ['countsSentence', 'longDate', 'shortDate'],
    );

    expect($methods)->toBe(['openRun', 'resendFailed', 'runs', 'toggleRun', 'upcoming']);
    Queue::assertNothingPushed();

    NostrAuth::login($member->pubkey);
    messageHistory()->assertForbidden();
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
    $page = messageHistory()
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
        ->and((fn () => $this->countsOf($this->runs->firstWhere('id', $run->id)))->call($page->instance()))->toMatchArray(['failed' => 2, 'waiting' => 1, 'retry' => 2]);

    $page->call('resendFailed', $run->id)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === '2 fehlgeschlagene Zustellungen werden erneut gesendet.');
    Queue::assertPushed(DispatchBulkDirectMessages::class, fn ($job) => $job->runId === $run->id);
    expect($failed->fresh()->status)->toBe(DmDeliveryStatus::Failed);
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
    messageHistory()
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

    messageHistory()
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

    $page = messageHistory()
        ->assertSee('1 fehlgeschlagen')
        ->call('toggleRun', $run->id)
        ->assertSee('Nicht gesendet: Absenderschlüssel fehlt oder ist ungültig')
        ->assertDontSee('Kein Relay hat angenommen')
        ->assertSee('Fehlgeschlagene erneut senden (1)');

    preg_match('/<li[^>]*data-test="delivery-'.$delivery->id.'".*?<\/li>/s', $page->html(), $row);

    expect($row[0] ?? '')->toContain('text-danger');
});

it('opens the run the page hands in, and hands an opened or closed run back to the page', function () {
    messageCentreBoard();
    $recipient = EinundzwanzigPleb::factory()->active()->create();
    $run = DmMessage::factory()->create()->freezeRun([$recipient], DmAudience::Members);

    messageHistory($run->id)->assertSeeHtml('data-test="run-detail"');

    messageHistory()
        ->assertDontSeeHtml('data-test="run-detail"')
        ->call('toggleRun', $run->id)
        ->assertSeeHtml('data-test="run-detail"')
        ->assertDispatched('message-run-toggled', fn (string $event, array $params): bool => $params['runId'] === $run->id)
        ->call('toggleRun', $run->id)
        ->assertDontSeeHtml('data-test="run-detail"')
        ->assertDispatched('message-run-toggled', fn (string $event, array $params): bool => $params['runId'] === null);
});
