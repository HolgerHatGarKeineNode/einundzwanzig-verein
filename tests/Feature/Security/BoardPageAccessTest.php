<?php

use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Models\Notification;
use App\Services\MembershipService;
use App\Support\Board;
use App\Support\NostrAuth;
use Livewire\Livewire;

require_once __DIR__.'/../Services/Election/helpers.php';

/*
 * One board guard for every board page (`WithViewer::boardActor()`): logged
 * in, not erased, on the board list. Before it, six pages carried their own
 * copy and the members page asked only the session's pubkey against the
 * board list.
 *
 * The erased board member is built so that ONLY the erasure denies them:
 * erased through the service, then their tombstone npub put on the board
 * list and their tombstone pubkey logged in. Without the not-erased clause
 * they would pass every check here.
 */

dataset('viewers', [
    'guest' => ['guest'],
    'member, not on the board' => ['member'],
    'erased board member' => ['erased board member'],
    'board member' => ['board member'],
]);

function logInAs(string $viewer): void
{
    if ($viewer === 'guest') {
        return;
    }

    if ($viewer === 'member') {
        electionBoard(1);
        NostrAuth::login(electionVoter()->pubkey);

        return;
    }

    [$board] = electionBoard(1);

    if ($viewer === 'erased board member') {
        app(MembershipService::class)->erasePersonalData($board);
        $board = $board->fresh();

        expect($board->isErased())->toBeTrue();

        config(['einundzwanzig.config.current_board' => [$board->npub]]);
        Board::flush();

        expect($board->isBoardMember())->toBeTrue();
    }

    NostrAuth::login($board->pubkey);
}

/**
 * The status per viewer, in dataset order: guest, member, erased, board.
 *
 * @param  array{0: int, 1: int, 2: int, 3: int}  $statuses
 */
function expectedFor(string $viewer, array $statuses): int
{
    return $statuses[array_search($viewer, ['guest', 'member', 'erased board member', 'board member'], true)];
}

it('serves the board pages to the board only', function (string $viewer) {
    $election = Election::factory()->create();
    logInAs($viewer);

    $pages = [
        // A guest gets the login prompt on these, not an error.
        '/association/election/admin' => [200, 403, 403, 200],
        '/association/messages/admin' => [200, 403, 403, 200],
        '/association/messages/inbox' => [200, 403, 403, 200],
        "/association/election/admin/{$election->id}/screen" => [403, 403, 403, 200],
        "/association/election/admin/{$election->id}/protocol" => [403, 403, 403, 200],
    ];

    foreach ($pages as $url => $statuses) {
        expect($this->get($url)->status())->toBe(expectedFor($viewer, $statuses), "{$viewer} on {$url}");
    }
})->with('viewers');

it('mounts the turnout counter for the board only', function (string $viewer) {
    $round = ElectionRound::factory()->create();
    logInAs($viewer);

    $page = Livewire::test('association.election.turnout', ['roundId' => $round->id]);

    $viewer === 'board member' ? $page->assertOk() : $page->assertForbidden();
})->with('viewers');

it('lets only the board manage members', function (string $viewer) {
    logInAs($viewer);
    $isBoard = $viewer === 'board member';

    $page = Livewire::test('association.members.admin')->assertSet('isAllowed', $isBoard);

    $isBoard ? $page->call('exportCsv')->assertOk() : $page->call('exportCsv')->assertForbidden();
})->with('viewers');

it('lets only the board moderate member proposals', function (string $viewer) {
    logInAs($viewer);

    Livewire::test('association.proposals.board')->assertSet('isAllowed', $viewer === 'board member');
})->with('viewers');

it('lets only the board edit news', function (string $viewer) {
    $news = Notification::factory()->create();
    logInAs($viewer);

    $page = Livewire::test('association.news')
        ->assertSet('canEdit', $viewer === 'board member')
        ->call('confirmDelete', $news->id);

    $viewer === 'board member' ? $page->assertOk()->assertSet('confirmDeleteId', $news->id) : $page->assertForbidden();
})->with('viewers');

it('types the session member', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    NostrAuth::login($pleb->pubkey);

    expect((new ReflectionMethod(NostrAuth::user(), 'getPleb'))->getReturnType()?->__toString())->toBe('?'.EinundzwanzigPleb::class)
        ->and(NostrAuth::user()->getPleb()->is($pleb))->toBeTrue();
});
