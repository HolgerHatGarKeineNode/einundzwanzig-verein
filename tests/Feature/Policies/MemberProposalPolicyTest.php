<?php

use App\Auth\NostrUser;
use App\Enums\AssociationStatus;
use App\Enums\MemberProposalState;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Models\MemberProposalComment;
use Illuminate\Support\Facades\Gate;

function proposalGate(?EinundzwanzigPleb $pleb): Illuminate\Contracts\Auth\Access\Gate
{
    return Gate::forUser($pleb === null ? null : new NostrUser($pleb->pubkey));
}

dataset('participation abilities', ['create', 'vote', 'comment']);

dataset('archived states', [
    'adopted' => MemberProposalState::Adopted,
    'rejected' => MemberProposalState::Rejected,
    'done' => MemberProposalState::Done,
]);

/**
 * Arguments for a participation ability: `create` is asked of the class, the
 * other two of a proposal.
 */
function participationArguments(string $ability, MemberProposal $proposal): mixed
{
    return $ability === 'create' ? MemberProposal::class : $proposal;
}

it('lets a member with a paid fee take part', function (string $ability) {
    $member = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $proposal = MemberProposal::factory()->create();

    expect(proposalGate($member)->allows($ability, participationArguments($ability, $proposal)))->toBeTrue();
})->with('participation abilities');

it('includes passive members with a paid fee', function (string $ability) {
    $member = EinundzwanzigPleb::factory()->withPaidCurrentYear()->create([
        'association_status' => AssociationStatus::PASSIVE,
    ]);
    $proposal = MemberProposal::factory()->create();

    expect(proposalGate($member)->allows($ability, participationArguments($ability, $proposal)))->toBeTrue();
})->with('participation abilities');

it('denies a guest', function (string $ability) {
    $proposal = MemberProposal::factory()->create();

    expect(proposalGate(null)->allows($ability, participationArguments($ability, $proposal)))->toBeFalse();
})->with('participation abilities');

it('denies a logged-in non-member even with a settled payment', function (string $ability) {
    $nonMember = EinundzwanzigPleb::factory()->withPaidCurrentYear()->create();
    $proposal = MemberProposal::factory()->create();

    expect(proposalGate($nonMember)->allows($ability, participationArguments($ability, $proposal)))->toBeFalse();
})->with('participation abilities');

it('denies a member whose fee for the current year is not paid', function (string $ability) {
    $member = EinundzwanzigPleb::factory()->active()->create();
    $member->paymentEvents()->create(['year' => now()->year - 1, 'amount' => 21000, 'paid' => true]);
    $member->paymentEvents()->create(['year' => now()->year, 'amount' => 21000, 'paid' => false]);
    $proposal = MemberProposal::factory()->create();

    expect(proposalGate($member)->allows($ability, participationArguments($ability, $proposal)))->toBeFalse();
})->with('participation abilities');

it('does not exempt a board member from the fee for taking part', function (string $ability) {
    $board = EinundzwanzigPleb::factory()->boardMember()->create();
    $proposal = MemberProposal::factory()->create();

    expect(proposalGate($board)->allows($ability, participationArguments($ability, $proposal)))->toBeFalse();
})->with('participation abilities');

it('closes votes and comments on archived proposals', function (MemberProposalState $state) {
    $member = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $proposal = MemberProposal::factory()->inState($state)->create();

    expect(proposalGate($member)->allows('vote', $proposal))->toBeFalse()
        ->and(proposalGate($member)->allows('comment', $proposal))->toBeFalse();
})->with('archived states');

it('keeps votes and comments open on a scheduled proposal', function () {
    $member = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $proposal = MemberProposal::factory()->inState(MemberProposalState::Scheduled)->create();

    expect(proposalGate($member)->allows('vote', $proposal))->toBeTrue()
        ->and(proposalGate($member)->allows('comment', $proposal))->toBeTrue();
});

it('closes votes and comments on hidden and withdrawn proposals', function () {
    $member = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    foreach ([MemberProposal::factory()->hidden()->create(), MemberProposal::factory()->withdrawn()->create()] as $proposal) {
        expect(proposalGate($member)->allows('vote', $proposal))->toBeFalse()
            ->and(proposalGate($member)->allows('comment', $proposal))->toBeFalse();
    }
});

it('lets the author edit only while the proposal is open', function () {
    $author = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $other = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $board = EinundzwanzigPleb::factory()->boardMember()->withPaidCurrentYear()->create();
    $open = MemberProposal::factory()->for($author)->create();

    expect(proposalGate($author)->allows('update', $open))->toBeTrue()
        ->and(proposalGate($other)->allows('update', $open))->toBeFalse()
        ->and(proposalGate($board)->allows('update', $open))->toBeFalse()
        ->and(proposalGate(null)->allows('update', $open))->toBeFalse();

    foreach (MemberProposalState::cases() as $state) {
        if ($state === MemberProposalState::Open) {
            continue;
        }

        $proposal = MemberProposal::factory()->for($author)->inState($state)->create();

        expect(proposalGate($author)->allows('update', $proposal))->toBeFalse();
    }
});

it('lets the author withdraw until the proposal is archived', function () {
    $author = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $other = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    foreach (MemberProposalState::cases() as $state) {
        $proposal = MemberProposal::factory()->for($author)->inState($state)->create();

        expect(proposalGate($author)->allows('withdraw', $proposal))->toBe(! $state->isArchived())
            ->and(proposalGate($other)->allows('withdraw', $proposal))->toBeFalse();
    }

    expect(proposalGate($author)->allows('withdraw', MemberProposal::factory()->for($author)->withdrawn()->create()))->toBeFalse();
});

it('lets a member delete their own comments, but not in the archive', function () {
    $commenter = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $other = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $proposal = MemberProposal::factory()->create();
    $comment = MemberProposalComment::factory()->for($proposal)->for($commenter)->create();

    expect(proposalGate($commenter)->allows('deleteComment', [$proposal, $comment]))->toBeTrue()
        ->and(proposalGate($other)->allows('deleteComment', [$proposal, $comment]))->toBeFalse()
        ->and(proposalGate(null)->allows('deleteComment', [$proposal, $comment]))->toBeFalse();

    $archived = MemberProposal::factory()->inState(MemberProposalState::Done)->create();
    $archivedComment = MemberProposalComment::factory()->for($archived)->for($commenter)->create();

    expect(proposalGate($commenter)->allows('deleteComment', [$archived, $archivedComment]))->toBeFalse()
        // A comment is only ever judged against the proposal it belongs to.
        ->and(proposalGate($commenter)->allows('deleteComment', [$proposal, $archivedComment]))->toBeFalse();
});

it('reserves moderation for the board', function () {
    $board = EinundzwanzigPleb::factory()->boardMember()->create();
    $member = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $nonMember = EinundzwanzigPleb::factory()->create();

    expect(proposalGate($board)->allows('moderate', MemberProposal::class))->toBeTrue()
        ->and(proposalGate($member)->allows('moderate', MemberProposal::class))->toBeFalse()
        ->and(proposalGate($nonMember)->allows('moderate', MemberProposal::class))->toBeFalse()
        ->and(proposalGate(null)->allows('moderate', MemberProposal::class))->toBeFalse();
});

it('stops answering yes once the member is no longer on the configured board', function () {
    $board = EinundzwanzigPleb::factory()->boardMember()->create();

    expect(proposalGate($board)->allows('moderate', MemberProposal::class))->toBeTrue();

    config()->set('einundzwanzig.config.current_board', []);

    expect(proposalGate($board)->allows('moderate', MemberProposal::class))->toBeFalse();
});

it('shows proposals to paying members and the board only', function () {
    $proposal = MemberProposal::factory()->create();
    $member = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $board = EinundzwanzigPleb::factory()->boardMember()->create();
    $nonMember = EinundzwanzigPleb::factory()->create();

    expect(proposalGate($member)->allows('view', $proposal))->toBeTrue()
        ->and(proposalGate($board)->allows('view', $proposal))->toBeTrue()
        ->and(proposalGate($nonMember)->allows('view', $proposal))->toBeFalse()
        ->and(proposalGate(null)->allows('view', $proposal))->toBeFalse()
        ->and(proposalGate(null)->allows('viewAny', MemberProposal::class))->toBeFalse();
});

it('shows a hidden proposal to the board and its author only, and a withdrawn one to nobody', function () {
    $author = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $member = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $board = EinundzwanzigPleb::factory()->boardMember()->create();
    $hidden = MemberProposal::factory()->for($author)->hidden()->create();
    $withdrawn = MemberProposal::factory()->for($author)->withdrawn()->create();

    expect(proposalGate($author)->allows('view', $hidden))->toBeTrue()
        ->and(proposalGate($board)->allows('view', $hidden))->toBeTrue()
        ->and(proposalGate($member)->allows('view', $hidden))->toBeFalse()
        ->and(proposalGate($author)->allows('view', $withdrawn))->toBeFalse()
        ->and(proposalGate($board)->allows('view', $withdrawn))->toBeFalse();
});
