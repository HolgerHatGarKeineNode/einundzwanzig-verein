<?php

use App\Enums\MemberProposalState;
use App\Livewire\Traits\ModeratesMemberProposals;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Models\MemberProposalComment;
use App\Models\MemberProposalVote;
use App\Support\NostrAuth;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/Helpers.php';

/**
 * Every public method the board page declares, read off the component itself.
 *
 * Reflection rather than a list: a board action added later is covered the
 * day it is written, without anybody remembering to extend a test. `mount`
 * is the page load, not an action; everything Livewire's base class brings
 * is declared there and therefore not in this set.
 *
 * @return list<string>
 */
function boardPageMethods(): array
{
    $instance = Livewire::test('association.proposals.board')->instance();

    return collect((new ReflectionObject($instance))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isStatic())
        ->filter(fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() === $instance::class)
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->reject(fn (string $name) => $name === 'mount')
        ->sort()
        ->values()
        ->all();
}

/**
 * The board actions as the trait declares them — the set the detail page
 * shares with the board page.
 *
 * @return list<string>
 */
function moderationMethods(): array
{
    return collect((new ReflectionClass(ModeratesMemberProposals::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->sort()
        ->values()
        ->all();
}

/**
 * Valid arguments for a board method, by parameter name. A parameter this
 * does not know fails the test: a new action has to be given a real argument
 * here, or the 403 it gets would prove nothing about its authorisation.
 *
 * @return list<mixed>
 */
function boardMethodArguments(object|string $component, string $method, MemberProposal $proposal, MemberProposalComment $comment): array
{
    return collect((new ReflectionMethod($component, $method))->getParameters())
        ->map(fn (ReflectionParameter $parameter) => match ($parameter->getName()) {
            'proposalId' => $proposal->id,
            'commentId' => $comment->id,
            'state' => MemberProposalState::Adopted->value,
            default => throw new LogicException("No test argument for parameter \${$parameter->getName()} of {$method}()."),
        })
        ->all();
}

/**
 * Call one public method the way a client can reach it and expect a 403.
 *
 * An action is a Livewire endpoint and is called as one. A computed property
 * is not — Livewire refuses to call it directly — so the only way to it is
 * reading it, and that has to be refused just the same.
 */
function expectForbidden(Testable $page, string $method, MemberProposal $proposal, MemberProposalComment $comment): void
{
    $reflection = new ReflectionMethod($page->instance(), $method);

    if ($reflection->getAttributes(Computed::class) !== []) {
        // Dropped first: the test keeps the instance of the last render, and a
        // value memoised while the viewer was still allowed would answer for
        // the method. A real request starts with nothing memoised.
        unset($page->instance()->{$method});

        expect(fn () => $page->instance()->{$method})->toThrow(AuthorizationException::class);

        return;
    }

    $page->call($method, ...boardMethodArguments($page->instance(), $method, $proposal, $comment))
        ->assertForbidden();
}

/**
 * What a board action could have changed, as one comparable value.
 *
 * @return array<string, mixed>
 */
function moderationFingerprint(MemberProposal $proposal, MemberProposalComment $comment): array
{
    return [
        'proposal' => $proposal->fresh()->only(['state', 'board_note', 'is_hidden', 'state_changed_at']),
        'comment' => $comment->fresh()->only(['is_hidden']),
    ];
}

it('finds the board actions it is about to test', function () {
    expect(moderationMethods())->toBe([
        'hideComment', 'hideProposal', 'saveBoardNote', 'setProposalState', 'unhideComment', 'unhideProposal',
    ]);

    expect(boardPageMethods())
        ->toContain(...moderationMethods())
        ->toContain('scheduled', 'topSupported');
});

it('answers 403 on every public method of the board page to anybody outside the board', function (?EinundzwanzigPleb $outsider) {
    $proposal = MemberProposal::factory()->create();
    $comment = MemberProposalComment::factory()->for($proposal)->create();
    $before = moderationFingerprint($proposal, $comment);

    if ($outsider) {
        NostrAuth::login($outsider->pubkey);
    }

    foreach (boardPageMethods() as $method) {
        $page = Livewire::test('association.proposals.board')->assertSet('isAllowed', false);

        expectForbidden($page->set('boardNotes.'.$proposal->id, 'Sitzung vom 1. Dezember'), $method, $proposal, $comment);
    }

    expect(moderationFingerprint($proposal, $comment))->toBe($before);
})->with('non-board');

it('answers 403 on every board action of the detail page to anybody outside the board', function (?EinundzwanzigPleb $outsider) {
    $proposal = MemberProposal::factory()->create();
    $comment = MemberProposalComment::factory()->for($proposal)->create();
    $before = moderationFingerprint($proposal, $comment);

    if ($outsider) {
        NostrAuth::login($outsider->pubkey);
    }

    foreach (moderationMethods() as $method) {
        $page = showProposal($proposal);

        expectForbidden($page->set('boardNotes.'.$proposal->id, 'Sitzung vom 1. Dezember'), $method, $proposal, $comment);
    }

    expect(moderationFingerprint($proposal, $comment))->toBe($before);
})->with('non-board');

it('answers 403 to a member removed from the board after the page was loaded', function (string $component) {
    $board = config('einundzwanzig.config.current_board');
    $proposal = MemberProposal::factory()->create();
    $comment = MemberProposalComment::factory()->for($proposal)->create();
    $before = moderationFingerprint($proposal, $comment);

    NostrAuth::login(boardMember()->pubkey);

    $methods = $component === 'association.proposals.board' ? boardPageMethods() : moderationMethods();

    foreach ($methods as $method) {
        config()->set('einundzwanzig.config.current_board', $board);

        // Loaded as a board member: the page is allowed and offers the actions.
        // A note is typed first: with an empty field saveBoardNote() would
        // write null over null, and a missing check would leave no trace.
        $page = Livewire::test($component, ['memberProposal' => $proposal])
            ->assertSeeHtml('setProposalState('.$proposal->id)
            ->set('boardNotes.'.$proposal->id, 'Typed before the board changed');

        config()->set('einundzwanzig.config.current_board', array_slice($board, 1));

        expectForbidden($page, $method, $proposal, $comment);
    }

    // The page itself goes too: its locked `isAllowed` still says yes, and the
    // next render is refused by the lists it would have to read.
    if ($component === 'association.proposals.board') {
        config()->set('einundzwanzig.config.current_board', $board);
        $page = Livewire::test($component);
        config()->set('einundzwanzig.config.current_board', array_slice($board, 1));

        $page->call('$refresh')->assertForbidden();
    }

    expect(moderationFingerprint($proposal, $comment))->toBe($before);
})->with(['association.proposals.board', 'association.proposals.show']);

it('lets the board set every state, stamping the change', function (MemberProposalState $state) {
    $proposal = MemberProposal::factory()->inState(
        $state === MemberProposalState::Open ? MemberProposalState::Scheduled : MemberProposalState::Open
    )->create(['state_changed_at' => null]);
    NostrAuth::login(boardMember()->pubkey);

    $this->travelTo(now()->addHour()->startOfSecond());

    Livewire::test('association.proposals.board')
        ->call('setProposalState', $proposal->id, $state->value)
        ->assertOk();

    $proposal->refresh();

    expect($proposal->state)->toBe($state)
        ->and($proposal->state_changed_at->equalTo(now()))->toBeTrue()
        ->and($proposal->last_activity_at->equalTo(now()))->toBeTrue();
})->with(MemberProposalState::cases());

it('rejects a state that does not exist', function () {
    $proposal = MemberProposal::factory()->create();
    NostrAuth::login(boardMember()->pubkey);

    Livewire::test('association.proposals.board')
        ->call('setProposalState', $proposal->id, 'approved-by-me')
        ->assertStatus(422);

    expect($proposal->refresh()->state)->toBe(MemberProposalState::Open);
});

it('saves a note typed alongside a state change and leaves the stored note alone when the field is empty', function () {
    $proposal = MemberProposal::factory()->create(['board_note' => 'Note of a colleague']);
    NostrAuth::login(boardMember()->pubkey);

    Livewire::test('association.proposals.board')
        ->call('setProposalState', $proposal->id, 'scheduled')
        ->assertOk();

    expect($proposal->refresh()->board_note)->toBe('Note of a colleague');

    Livewire::test('association.proposals.board')
        ->set('boardNotes.'.$proposal->id, ' Sitzung vom 1. Dezember ')
        ->call('setProposalState', $proposal->id, 'adopted')
        ->assertOk();

    expect($proposal->refresh()->board_note)->toBe('Sitzung vom 1. Dezember')
        ->and($proposal->state)->toBe(MemberProposalState::Adopted);
});

it('saves and clears the note on its own', function () {
    $proposal = MemberProposal::factory()->create();
    NostrAuth::login(boardMember()->pubkey);

    Livewire::test('association.proposals.board')
        ->set('boardNotes.'.$proposal->id, 'Sitzung vom 1. Dezember')
        ->call('saveBoardNote', $proposal->id)
        ->assertOk()
        ->assertSee('Sitzung vom 1. Dezember');

    expect($proposal->refresh()->board_note)->toBe('Sitzung vom 1. Dezember')
        ->and($proposal->state)->toBe(MemberProposalState::Open);

    Livewire::test('association.proposals.board')
        ->set('boardNotes.'.$proposal->id, '')
        ->call('saveBoardNote', $proposal->id);

    expect($proposal->refresh()->board_note)->toBeNull();
});

it('refuses a note longer than a thousand characters', function () {
    $proposal = MemberProposal::factory()->create();
    NostrAuth::login(boardMember()->pubkey);

    Livewire::test('association.proposals.board')
        ->set('boardNotes.'.$proposal->id, str_repeat('a', 1001))
        ->call('saveBoardNote', $proposal->id)
        ->assertHasErrors('boardNotes.'.$proposal->id);

    expect($proposal->refresh()->board_note)->toBeNull();
});

it('lets the board hide and unhide proposals and comments', function () {
    $proposal = MemberProposal::factory()->create();
    $comment = MemberProposalComment::factory()->for($proposal)->create();
    NostrAuth::login(boardMember()->pubkey);

    showProposal($proposal)->call('hideProposal', $proposal->id)->call('hideComment', $comment->id)->assertOk();

    expect($proposal->refresh()->is_hidden)->toBeTrue()
        ->and($comment->refresh()->is_hidden)->toBeTrue();

    showProposal($proposal)->call('unhideProposal', $proposal->id)->call('unhideComment', $comment->id)->assertOk();

    expect($proposal->refresh()->is_hidden)->toBeFalse()
        ->and($comment->refresh()->is_hidden)->toBeFalse();
});

it('lists scheduled proposals and the most supported open ones, and nothing archived or withdrawn', function () {
    $scheduled = MemberProposal::factory()->inState(MemberProposalState::Scheduled)->create(['title' => 'Vorgemerkter Vorschlag']);
    $popular = MemberProposal::factory()->create(['title' => 'Beliebter Vorschlag']);
    $quiet = MemberProposal::factory()->create(['title' => 'Stiller Vorschlag']);
    MemberProposal::factory()->inState(MemberProposalState::Done)->create(['title' => 'Erledigter Vorschlag']);
    MemberProposal::factory()->withdrawn()->create(['title' => 'Zurückgezogener Vorschlag']);
    MemberProposalVote::factory()->for($popular)->up()->count(2)->create();

    NostrAuth::login(boardMember()->pubkey);

    $page = Livewire::test('association.proposals.board')
        ->assertSet('isAllowed', true)
        ->assertSeeInOrder(['Vorgemerkter Vorschlag', 'Beliebter Vorschlag', 'Stiller Vorschlag'])
        ->assertDontSee('Erledigter Vorschlag')
        ->assertDontSee('Zurückgezogener Vorschlag');

    expect($page->instance()->scheduled->modelKeys())->toBe([$scheduled->id])
        ->and($page->instance()->topSupported->modelKeys())->toBe([$popular->id, $quiet->id]);
});

it('shows outsiders that the board view is not theirs, without any proposal', function () {
    MemberProposal::factory()->inState(MemberProposalState::Scheduled)->create(['title' => 'Vorgemerkter Vorschlag']);
    NostrAuth::login(paidMember()->pubkey);

    Livewire::test('association.proposals.board')
        ->assertSet('isAllowed', false)
        ->assertSee('Diese Ansicht ist dem Vorstand vorbehalten')
        ->assertDontSee('Vorgemerkter Vorschlag');
});
