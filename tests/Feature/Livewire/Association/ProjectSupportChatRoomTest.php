<?php

use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Support\NostrAuth;
use Livewire\Livewire;
use swentel\nostr\Key\Key;

/*
 * The private chat room of a project proposal, a child component of its page,
 * mounted for board and submitter only. Moved here from ProjectSupportTest with
 * the component.
 */

/**
 * Der Vorstand wird je Test frisch angelegt, weil ProjectProposal::boardPlebIds()
 * die npubs aus der Konfiguration bei jedem Aufruf gegen die Datenbank auflöst.
 * Ohne passende Pleb-Zeilen zählt keine Stimme als Vorstandsstimme.
 *
 * Bewusst KEINE Annahme über Aufrufreihenfolge oder Memoisierung: die Methode
 * cacht absichtlich nicht (ein prozessweiter Cache überlebt bei langlebigen
 * Workern den Request und würde Stimmen verschlucken).
 */
beforeEach(function () {
    collect(config('einundzwanzig.config.current_board'))->each(
        fn (string $npub) => EinundzwanzigPleb::factory()->create(['npub' => $npub, 'pubkey' => (new Key)->convertToHex($npub)])
    );
});

it('lets a board member create the chat room and persists the computed room id', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->call('storeChatRoom', $project->nostrGroupId())
        ->assertHasNoErrors();

    $fresh = $project->fresh();
    expect($fresh->nostr_group_h)->toBe($project->nostrGroupId())
        ->and($fresh->nostr_group_created_at)->not->toBeNull();
});

it('rejects storeChatRoom for the submitter, even with the correctly computed room id — the endpoint itself must refuse, not just the button', function () {
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);

    NostrAuth::login($submitter->pubkey);

    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->call('storeChatRoom', $project->nostrGroupId())
        ->assertForbidden();

    $fresh = $project->fresh();
    expect($fresh->nostr_group_h)->toBeNull()
        ->and($fresh->nostr_group_created_at)->toBeNull();
});

it('rejects storeChatRoom for an unrelated member', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    // The section is not theirs: the component refuses to mount, so no action is reachable.
    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->assertForbidden();

    expect($project->fresh()->nostr_group_h)->toBeNull();
});

it('rejects storeChatRoom for a guest', function () {
    $project = ProjectProposal::factory()->create();

    // The section is not theirs: the component refuses to mount, so no action is reachable.
    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->assertForbidden();

    expect($project->fresh()->nostr_group_h)->toBeNull();
});

it('refuses a second storeChatRoom call once the room already exists, without changing the stored value', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($board->pubkey);

    $component = Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->call('storeChatRoom', $project->nostrGroupId())
        ->assertHasNoErrors();

    $storedAfterFirstCall = $project->fresh()->nostr_group_h;
    $createdAtAfterFirstCall = $project->fresh()->nostr_group_created_at;

    // Der zweite Aufruf trifft auf eine bereits vorhandene Raum-ID: die
    // createChatRoom-Policy verlangt ! hasNostrGroup() und verweigert daher —
    // das ist die Idempotenz-Grenze, nicht ein stiller no-op im Handler selbst.
    $component->call('storeChatRoom', $project->nostrGroupId())
        ->assertForbidden();

    $fresh = $project->fresh();
    expect($fresh->nostr_group_h)->toBe($storedAfterFirstCall)
        ->and($fresh->nostr_group_created_at->equalTo($createdAtAfterFirstCall))->toBeTrue();
});

it('rejects storeChatRoom when the reported room id does not match the server-computed one, and writes nothing', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($board->pubkey);

    // Der Aufrufer ist berechtigt (Vorstand, kein Raum vorhanden) — die
    // Ablehnung muss also aus dem Abgleich im Handler kommen, nicht aus der
    // Policy.
    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->call('storeChatRoom', 'p'.str_repeat('f', 12))
        ->assertHasNoErrors();

    $fresh = $project->fresh();
    expect($fresh->nostr_group_h)->toBeNull()
        ->and($fresh->nostr_group_created_at)->toBeNull()
        ->and($fresh->hasNostrGroup())->toBeFalse();
});

it('delegates chatRoomMemberPubkeys to the model, including submitter and board', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);

    NostrAuth::login($board->pubkey);

    $component = Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project]);

    expect($component->get('chatRoomMemberPubkeys'))->toBe($project->nostrGroupMemberPubkeys())
        ->and($component->get('chatRoomMemberPubkeys'))->toContain($submitter->pubkey);
});

// Eingebettete Chat-Insel auf der Detailseite.
//
// Entscheidend ist die SERVER-Seite: Wer den Raum nicht sehen darf, bekommt das
// Insel-Markup gar nicht erst ausgeliefert. Ein rein visuelles Verstecken waere
// wertlos — der Raumname und die Mitglieder-Pubkeys staenden trotzdem im HTML.

it('lets a board member reset the chat room reference and unlocks the create action again', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create([
        'nostr_group_h' => 'p'.str_repeat('a', 12),
        'nostr_group_created_at' => now()->subDay(),
    ]);

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->assertSet('canResetChatRoom', true)
        ->assertSet('canCreateChatRoom', false)
        ->call('resetChatRoom')
        ->assertHasNoErrors()
        // Das ist der eigentliche Zweck: der Antrag ist wieder frei.
        ->assertSet('canCreateChatRoom', true)
        ->assertSet('canResetChatRoom', false)
        ->assertSee('Chatraum anlegen');

    $fresh = $project->fresh();
    expect($fresh->nostr_group_h)->toBeNull()
        ->and($fresh->nostr_group_created_at)->toBeNull()
        ->and($fresh->hasNostrGroup())->toBeFalse();
});

it('rejects resetChatRoom when no room is on file, even for a board member', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($board->pubkey);

    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->assertSet('canResetChatRoom', false)
        ->call('resetChatRoom')
        ->assertForbidden();
});

it('rejects resetChatRoom for the submitter — the endpoint itself must refuse, not just the hidden button', function () {
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $submitter->id,
        'nostr_group_h' => 'p'.str_repeat('a', 12),
        'nostr_group_created_at' => now()->subDay(),
    ]);

    NostrAuth::login($submitter->pubkey);

    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->call('resetChatRoom')
        ->assertForbidden();

    $fresh = $project->fresh();
    expect($fresh->nostr_group_h)->toBe('p'.str_repeat('a', 12))
        ->and($fresh->nostr_group_created_at)->not->toBeNull();
});

it('rejects resetChatRoom for an unrelated member', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'nostr_group_h' => 'p'.str_repeat('a', 12),
    ]);

    NostrAuth::login($pleb->pubkey);

    // The section is not theirs: the component refuses to mount, so no action is reachable.
    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->assertForbidden();

    expect($project->fresh()->nostr_group_h)->toBe('p'.str_repeat('a', 12));
});

it('rejects resetChatRoom for a guest', function () {
    $project = ProjectProposal::factory()->create([
        'nostr_group_h' => 'p'.str_repeat('a', 12),
    ]);

    // The section is not theirs: the component refuses to mount, so no action is reachable.
    Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])
        ->assertForbidden();

    expect($project->fresh()->nostr_group_h)->toBe('p'.str_repeat('a', 12));
});

/*
 * The room on its own: a child component is callable without the page, so it
 * asks `viewContact` on mount and on every later request.
 */
it('stops answering when the session leaves the board after the room was rendered', function () {
    $board = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $project = ProjectProposal::factory()->create();
    $project->nostr_group_h = $project->nostrGroupId();
    $project->save();

    NostrAuth::login($board->pubkey);
    $room = Livewire::test('association.project-support.show-chat-room', ['projectProposal' => $project])->assertSee('Chat öffnen');

    // Even a plain re-render, which would show the room id, is refused.
    NostrAuth::login(EinundzwanzigPleb::factory()->create()->pubkey);
    $room->call('$refresh')->assertForbidden();
});

it('mounts the room on the page for board and submitter only', function (string $who, bool $mounted) {
    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);
    $pubkey = match ($who) {
        'board' => EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail()->pubkey,
        'submitter' => $submitter->pubkey,
        'member' => EinundzwanzigPleb::factory()->create()->pubkey,
        'guest' => null,
    };

    if ($pubkey !== null) {
        NostrAuth::login($pubkey);
    }

    $page = Livewire::test('association.project-support.show', ['projectProposal' => $project]);

    $mounted
        ? $page->assertSeeLivewire('association.project-support.show-chat-room')
        : $page->assertDontSeeLivewire('association.project-support.show-chat-room');
})->with([
    'board' => ['board', true],
    'submitter' => ['submitter', true],
    'member' => ['member', false],
    'guest' => ['guest', false],
]);
