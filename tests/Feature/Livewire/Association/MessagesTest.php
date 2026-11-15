<?php

use App\Enums\DmDeliveryStatus;
use App\Enums\DmMessageType;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';

/*
 * The member inbox "Nachrichten vom Verein" at /association/messages.
 */

beforeEach(fn () => armDirectMessages());

function memberMessage(EinundzwanzigPleb $member, string $plaintext, DmDeliveryStatus $status = DmDeliveryStatus::Sent, DmMessageType $type = DmMessageType::Individual): DmDelivery
{
    $message = DmMessage::factory()->create(['type' => $type]);

    return DmDelivery::query()->create([
        'dm_run_id' => $message->freezeRun([$member])->id,
        'einundzwanzig_pleb_id' => $member->id,
        'status' => $status,
        'plaintext' => $plaintext,
    ]);
}

function inboxAs(EinundzwanzigPleb $member)
{
    NostrAuth::login($member->pubkey);

    return Livewire::test('association.messages');
}

it('serves the inbox under its name and answers a guest with the members-only notice', function () {
    expect(route('association.messages', absolute: false))->toBe('/association/messages');

    $this->get('/association/messages')
        ->assertOk()
        ->assertSee('Nachrichten sind nur für Mitglieder sichtbar')
        ->assertDontSee('Noch keine Nachrichten');
});

it('lists only the member\'s own messages, newest first, with label and Berlin time', function () {
    $this->travelTo(Carbon::parse('2027-02-01 09:30:00', 'UTC'));
    $member = EinundzwanzigPleb::factory()->active()->create();
    $other = EinundzwanzigPleb::factory()->active()->create();
    memberMessage($member, "Hallo Satoshi,\n\nerste Nachricht.");
    memberMessage($member, "Hallo Satoshi,\n\nzweite Nachricht.", type: DmMessageType::ElectionStartsNow);
    memberMessage($other, 'Fremde Nachricht nur für jemand anderen.');

    $html = inboxAs($member)
        ->assertSee('Die Wahl beginnt jetzt')
        ->assertSee('1. Februar 2027, 10:30')
        ->assertSee('2 ungelesen.')
        ->assertDontSee('Fremde Nachricht')
        ->html();

    expect(strpos($html, 'zweite Nachricht'))->toBeLessThan(strpos($html, 'erste Nachricht'))
        ->and($html)->not->toContain('<span class="col-span-2 truncate text-base text-text-secondary">Hallo Satoshi,');
});

it('opens a message, marks it seen and lowers the unread count', function () {
    $member = EinundzwanzigPleb::factory()->active()->create();
    $delivery = memberMessage($member, "Hallo Satoshi,\n\nder ganze Text.\nZweite Zeile.");
    memberMessage($member, 'Noch eine.');

    $page = inboxAs($member)->assertSee('2 ungelesen.');

    $page->call('open', $delivery->id)
        ->assertSet('openId', $delivery->id)
        ->assertSee("der ganze Text.\nZweite Zeile.", false)
        ->assertSee('1 ungelesen.');

    expect($delivery->fresh()->seen_at)->not->toBeNull()
        ->and($page->instance()->unreadCount)->toBe(1);
});

it('ignores another member\'s delivery id', function () {
    $member = EinundzwanzigPleb::factory()->active()->create();
    $foreign = memberMessage(EinundzwanzigPleb::factory()->active()->create(), 'Geheim für jemand anderen.');

    inboxAs($member)
        ->call('open', $foreign->id)
        ->assertSet('openId', null)
        ->assertDontSee('Geheim für jemand anderen.');

    expect($foreign->fresh()->seen_at)->toBeNull();
});

it('prints the text escaped and turns only http(s) URLs into safe links', function () {
    $member = EinundzwanzigPleb::factory()->active()->create();
    $delivery = memberMessage($member, "Hallo,\n<script>alert(1)</script> <b>fett</b> javascript:alert(2)\nHier abstimmen: https://portal.example/wahl?a=1&b=2.");

    $html = inboxAs($member)->call('open', $delivery->id)->html();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('&lt;b&gt;fett&lt;/b&gt;')
        ->and($html)->not->toContain('href="javascript:')
        ->and($html)->toContain('<a href="https://portal.example/wahl?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">https://portal.example/wahl?a=1&amp;b=2</a>.');
});

it('explains where messages come from when the inbox is empty', function () {
    inboxAs(EinundzwanzigPleb::factory()->active()->create())
        ->assertSee('Noch keine Nachrichten')
        ->assertSee('Relays für private Nachrichten')
        ->assertDontSee('ungelesen');
});

it('tells a member without DM relays why some messages are only here', function () {
    $member = EinundzwanzigPleb::factory()->active()->create();
    memberMessage($member, 'Ohne Postfach.', DmDeliveryStatus::NoInbox);

    inboxAs($member)
        ->assertSee('nur hier')
        ->assertSee('Dein Nostr-Konto gibt keine Relays für private Nachrichten an');

    $withRelays = EinundzwanzigPleb::factory()->active()->create();
    memberMessage($withRelays, 'Zugestellt.');

    inboxAs($withRelays)->assertDontSee('Dein Nostr-Konto gibt keine Relays');
});

it('shows the unread badge in the navigation only while something is unread', function () {
    $member = EinundzwanzigPleb::factory()->active()->create();
    NostrAuth::login($member->pubkey);

    $navEntry = function (): string {
        $html = $this->get('/association/profile')->assertOk()->getContent();
        preg_match('/<a\b[^>]*data-inbox-link[^>]*>.*?<\/a>/s', $html, $match);

        return $match[0] ?? '';
    };

    expect($navEntry())->toContain(route('association.messages'))
        ->toContain('Nachrichten')
        ->not->toContain('data-flux-navlist-badge');

    $delivery = memberMessage($member, 'Ungelesen.');
    memberMessage($member, 'Auch ungelesen.');

    expect($navEntry())->toMatch('/data-flux-navlist-badge>\s*2\s*</');

    $delivery->forceFill(['seen_at' => now()])->save();

    expect($navEntry())->toMatch('/data-flux-navlist-badge>\s*1\s*</');
});
