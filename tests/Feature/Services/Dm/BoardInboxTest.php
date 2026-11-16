<?php

use App\Models\DmInboxMessage;
use App\Models\EinundzwanzigPleb;
use App\Services\Dm\DirectMessageText;
use App\Services\MembershipService;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\EventHash;
use App\Services\Nostr\EventSigning;
use App\Services\Nostr\EventVerification;
use App\Services\Nostr\NotificationKey;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use swentel\nostr\Encryption\Nip44;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/../Election/helpers.php';
require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';

/*
 * The board inbox: members' replies to the notification key are fetched,
 * opened and stored; the board reads them and answers as a NIP-17 message.
 */

const INBOX_RELAY_A = 'wss://inbox-a.verein.example/';
const INBOX_RELAY_B = 'wss://inbox-b.verein.example/';

/**
 * Arms the notification key and the inbox relays; returns the key's pubkey.
 */
function armBoardInbox(): string
{
    armDirectMessages();
    config()->set('einundzwanzig.config.dm_inbox_relays', [INBOX_RELAY_A, INBOX_RELAY_B]);

    return NotificationKey::fromConfig()->publicKey();
}

/**
 * A reply as a member's client builds it: sealed by the member, wrapped for the portal key.
 *
 * @return array<string, mixed>
 */
function memberReply(string $memberSecret, string $portalPubkey, string $text): array
{
    $recipient = new EinundzwanzigPleb(['pubkey' => $portalPubkey]);

    return (new DirectMessageSigner(new NotificationKey($memberSecret)))->wrapFor($recipient, $text)->wrap;
}

/**
 * A wrap whose seal is signed by `$sealer` but whose rumor claims `$claimedPubkey`.
 *
 * @return array<string, mixed>
 */
function forgedReply(string $sealer, string $claimedPubkey, string $portalPubkey, string $text): array
{
    $now = time();
    $rumor = ['pubkey' => $claimedPubkey, 'created_at' => $now, 'kind' => 14, 'tags' => [['p', $portalPubkey]], 'content' => $text];
    $rumor = ['id' => EventHash::of($claimedPubkey, $now, 14, $rumor['tags'], $text), ...$rumor];
    $seal = EventSigning::sign($sealer, 13, [], Nip44::encrypt(EventHash::json($rumor), Nip44::getConversationKey($sealer, $portalPubkey)), $now);
    $oneTime = EventSigning::freshSecret();

    return EventSigning::sign($oneTime, 1059, [['p', $portalPubkey]], Nip44::encrypt(EventHash::json($seal), Nip44::getConversationKey($oneTime, $portalPubkey)), $now);
}

it('stores a member reply once, even when both inbox relays return it, and drops strangers, forgeries and wraps for another key', function () {
    $portal = armBoardInbox();
    [$member, $memberSecret] = dmRecipient();
    $reply = memberReply($memberSecret, $portal, "Danke!\nIch komme zur Versammlung.");
    $stranger = memberReply(testPrivateKey(), $portal, 'Kauf Bitcoin bei mir');
    $forged = forgedReply(testPrivateKey(), (string) $member->pubkey, $portal, 'Ich trete aus');
    $otherKey = memberReply($memberSecret, (new Key)->getPublicKey(testPrivateKey()), 'Nicht für den Verein');

    $fake = FakeRelayTransport::bind()
        ->withStoredEvents(INBOX_RELAY_A, [$reply, $stranger, $forged])
        ->withStoredEvents(INBOX_RELAY_B, [$reply, $otherKey]);

    Artisan::call('dm:fetch-inbox');

    expect(Artisan::output())->toContain('wraps=4 stored=1 unreadable=2 not_members=1')
        ->and(DmInboxMessage::query()->sole()->only(['einundzwanzig_pleb_id', 'direction', 'body', 'read_at']))
        ->toBe(['einundzwanzig_pleb_id' => $member->id, 'direction' => 'in', 'body' => "Danke!\nIch komme zur Versammlung.", 'read_at' => null])
        ->and($fake->fetched()[0]['filter'])->toMatchArray(['kinds' => [1059], '#p' => [$portal]])
        ->and($fake->fetched()[0]['filter']['since'])->toBeLessThanOrEqual(now()->subDays(7)->getTimestamp() + 5);

    Artisan::call('dm:fetch-inbox');

    expect(DmInboxMessage::query()->count())->toBe(1);
});

it('fetches nothing while the inbox relays are empty', function () {
    armDirectMessages();
    config()->set('einundzwanzig.config.dm_inbox_relays', []);
    $fake = FakeRelayTransport::bind();

    Artisan::call('dm:fetch-inbox');

    expect($fake->fetched())->toBe([])
        ->and(Artisan::output())->toContain('Board inbox is off');
});

it('publishes the key\'s inbox relay list as a signed kind 10050 to the inbox and profile relays', function () {
    $portal = armBoardInbox();
    config()->set('einundzwanzig.config.profile_relays', ['wss://profiles.example/']);
    $fake = FakeRelayTransport::bind();

    expect(Artisan::call('dm:publish-inbox-relays'))->toBe(0);

    $published = $fake->published();
    $event = $published[0]['event'];

    expect(array_column($published, 'relay'))->toBe([INBOX_RELAY_A, INBOX_RELAY_B, 'wss://profiles.example/'])
        ->and($event['kind'])->toBe(10050)
        ->and($event['pubkey'])->toBe($portal)
        ->and($event['tags'])->toBe([['relay', INBOX_RELAY_A], ['relay', INBOX_RELAY_B]])
        ->and(EventVerification::isAuthentic($event))->toBeTrue();
});

it('shows the board a reply as unread, marks it read on opening, and sends the answer to the member\'s inbox relays', function () {
    armBoardInbox();
    [$board] = electionBoard(1);
    [$member, $memberSecret] = dmRecipient(['wss://member-inbox.example/']);
    DmInboxMessage::factory()->create(['einundzwanzig_pleb_id' => $member->id, 'body' => 'Wann ist die Versammlung?']);
    $fake = FakeRelayTransport::bind();
    NostrAuth::login($board->pubkey);

    Livewire::test('association.messages.inbox')
        ->assertSeeHtml('data-test="unread-'.$member->id.'"')
        ->assertSee('Wann ist die Versammlung?')
        ->call('open', $member->id)
        ->assertDontSeeHtml('data-test="unread-'.$member->id.'"')
        ->call('send')
        ->assertHasErrors(['reply' => 'required'])
        ->set('reply', 'Am 21. März, Einladung folgt.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('Am 21. März, Einladung folgt.')
        ->assertSee('zugestellt');

    $answer = DmInboxMessage::query()->where('direction', 'out')->sole();
    $published = $fake->published();

    expect(DmInboxMessage::query()->where('direction', 'in')->whereNull('read_at')->exists())->toBeFalse()
        ->and($answer->only(['author_pleb_id', 'status', 'body']))->toBe(['author_pleb_id' => $board->id, 'status' => 'sent', 'body' => 'Am 21. März, Einladung folgt.'])
        ->and(array_column($published, 'relay'))->toBe(['wss://member-inbox.example/'])
        ->and(openWrapWith($published[0]['event'], $memberSecret)['content'])->toBe('Am 21. März, Einladung folgt.')
        ->and($answer->rumor_id)->toBe(openWrapWith($published[0]['event'], $memberSecret)['id']);
});

it('marks an answer to a member without inbox relays as not delivered and dials no relay', function () {
    armBoardInbox();
    [$board] = electionBoard(1);
    [$member] = dmRecipient(null);
    DmInboxMessage::factory()->create(['einundzwanzig_pleb_id' => $member->id]);
    $fake = FakeRelayTransport::bind();
    NostrAuth::login($board->pubkey);

    Livewire::test('association.messages.inbox')
        ->call('open', $member->id)
        ->assertSeeHtml('data-test="no-member-inbox"')
        ->set('reply', 'Hallo')
        ->call('send')
        ->assertSee('nicht zugestellt: keine Nostr-Postfach-Relays');

    expect(DmInboxMessage::query()->where('direction', 'out')->sole()->status)->toBe('no_inbox');
    $fake->assertNothingPublished();
});

it('keeps the inbox from members who are not on the board and from guests', function () {
    armBoardInbox();
    $reply = DmInboxMessage::factory()->create(['body' => 'Vertraulich']);
    $member = EinundzwanzigPleb::factory()->active()->create();

    Livewire::test('association.messages.inbox')
        ->assertDontSee('Vertraulich')
        ->call('open', $reply->einundzwanzig_pleb_id)
        ->assertForbidden();

    NostrAuth::login($member->pubkey);

    Livewire::test('association.messages.inbox')->assertForbidden();

    expect($reply->fresh()->read_at)->toBeNull();
});

it('tells members in every DM that the board reads replies once the inbox is on', function () {
    armDirectMessages();
    config()->set('einundzwanzig.config.dm_inbox_relays', []);

    expect(app(DirectMessageText::class)->footer())->toContain('Antworten an dieses Konto liest niemand.');

    config()->set('einundzwanzig.config.dm_inbox_relays', [INBOX_RELAY_A]);

    expect(app(DirectMessageText::class)->footer())
        ->toContain('Du kannst direkt antworten: Der Vorstand liest Antworten im Vereinsportal.')
        ->not->toContain('liest niemand');
});

it('deletes a member\'s inbox messages when their personal data is erased', function () {
    [$board] = electionBoard(1);
    $member = EinundzwanzigPleb::factory()->active()->create();
    DmInboxMessage::factory()->create(['einundzwanzig_pleb_id' => $member->id]);
    DmInboxMessage::factory()->answer($board)->create(['einundzwanzig_pleb_id' => $member->id]);
    $other = DmInboxMessage::factory()->create();

    app(MembershipService::class)->erasePersonalData($member);

    expect(DmInboxMessage::query()->pluck('id')->all())->toBe([$other->id]);
});
