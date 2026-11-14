<?php

use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Models\MemberProposalComment;
use App\Models\MemberProposalVote;
use App\Models\Profile;
use App\Services\MembershipService;
use App\Support\NostrAuth;
use Livewire\Livewire;

require_once __DIR__.'/Helpers.php';

it('deletes an erased member\'s votes and leaves their proposals and comments without name or pubkey', function () {
    $member = paidMember(['npub' => 'npub1erasedmemberxyz']);
    $profile = Profile::factory()->create([
        'pubkey' => $member->pubkey,
        'name' => 'hodler-aus-zug',
        'display_name' => 'Hodler Aus Zug',
    ]);
    $pubkey = $member->pubkey;

    $ownProposal = MemberProposal::factory()->for($member)->create(['title' => 'Vorschlag des Gelöschten']);
    $foreignProposal = MemberProposal::factory()->create();
    $comment = MemberProposalComment::factory()->for($foreignProposal)->for($member)->create(['body' => 'Kommentar des Gelöschten']);

    MemberProposalVote::factory()->for($foreignProposal)->for($member)->up()->create();
    MemberProposalVote::factory()->for($ownProposal)->for($member)->up()->create();
    $otherVote = MemberProposalVote::factory()->for($foreignProposal)->down()->create();

    // Before: the name is on both pages. Without this the "not there" below
    // would hold for a page that never printed names at all.
    NostrAuth::login(paidMember()->pubkey);
    showProposal($ownProposal)->assertSee('hodler-aus-zug');
    showProposal($foreignProposal)->assertSee('hodler-aus-zug')->assertSeeHtml('data-test="up-count">1<');

    app(MembershipService::class)->erasePersonalData($member);

    expect(MemberProposalVote::query()->pluck('id')->all())->toBe([$otherVote->id])
        ->and($ownProposal->fresh())->not->toBeNull()
        ->and($comment->fresh()->body)->toBe('Kommentar des Gelöschten');

    $erased = EinundzwanzigPleb::query()->findOrFail($member->id);

    $pages = [
        showProposal($ownProposal)->assertSee('Vorschlag des Gelöschten'),
        showProposal($foreignProposal)->assertSee('Kommentar des Gelöschten')->assertSeeHtml('data-test="up-count">0<'),
        Livewire::test('association.proposals.index'),
    ];

    foreach ($pages as $page) {
        $exposed = $page->html().json_encode($page->snapshot);

        expect($exposed)
            ->toContain('Ehemaliges Mitglied')
            ->not->toContain($profile->name)
            ->not->toContain($profile->display_name)
            ->not->toContain($pubkey)
            ->not->toContain('npub1erasedmemberxyz')
            // The tombstones are not a name either.
            ->not->toContain($erased->pubkey)
            ->not->toContain($erased->npub)
            ->not->toContain(EinundzwanzigPleb::TOMBSTONE_PREFIX);
    }
});

it('does not touch the votes of other members when one is erased', function () {
    $proposal = MemberProposal::factory()->create();
    $erased = paidMember();
    MemberProposalVote::factory()->for($proposal)->for($erased)->up()->create();
    MemberProposalVote::factory()->for($proposal)->up()->count(2)->create();

    app(MembershipService::class)->erasePersonalData($erased);

    expect($proposal->votes()->count())->toBe(2)
        ->and($proposal->votes()->where('einundzwanzig_pleb_id', $erased->id)->exists())->toBeFalse();
});
