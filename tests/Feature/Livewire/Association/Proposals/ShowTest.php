<?php

use App\Enums\MemberProposalState;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Models\MemberProposalComment;
use App\Models\MemberProposalVote;
use App\Models\Profile;
use App\Support\NostrAuth;
use Illuminate\Database\UniqueConstraintViolationException;

require_once __DIR__.'/Helpers.php';

it('refuses a vote from anybody who is not a paying member', function (?EinundzwanzigPleb $outsider) {
    $proposal = MemberProposal::factory()->create();

    if ($outsider) {
        NostrAuth::login($outsider->pubkey);
    }

    showProposal($proposal)->call('vote', 'up')->assertForbidden();

    expect(MemberProposalVote::query()->count())->toBe(0);
})->with('outsiders');

it('refuses a comment from anybody who is not a paying member', function (?EinundzwanzigPleb $outsider) {
    $proposal = MemberProposal::factory()->create();

    if ($outsider) {
        NostrAuth::login($outsider->pubkey);
    }

    showProposal($proposal)->set('commentBody', 'Hello')->call('addComment')->assertForbidden();

    expect(MemberProposalComment::query()->count())->toBe(0);
})->with('outsiders');

it('shows outsiders the members-only notice instead of the proposal', function (?EinundzwanzigPleb $outsider) {
    $proposal = MemberProposal::factory()->create(['title' => 'Stammtisch in jedem Kanton']);

    if ($outsider) {
        NostrAuth::login($outsider->pubkey);
    }

    showProposal($proposal)
        ->assertSee('Vorschläge sind nur für Mitglieder sichtbar')
        ->assertDontSee('Stammtisch in jedem Kanton');
})->with('outsiders');

it('keeps one row per member: a second vote replaces the first, the same vote again withdraws it', function () {
    $member = paidMember();
    $proposal = MemberProposal::factory()->create();
    NostrAuth::login($member->pubkey);

    $page = showProposal($proposal)->call('vote', 'up');

    expect($proposal->votes()->pluck('value')->all())->toBe(['up']);
    $page->assertSeeHtml('data-test="up-count">1<')->assertSeeHtml('data-test="down-count">0<');

    $page->call('vote', 'down');

    expect($proposal->votes()->pluck('value')->all())->toBe(['down']);
    $page->assertSeeHtml('data-test="up-count">0<')->assertSeeHtml('data-test="down-count">1<');

    $page->call('vote', 'down');

    expect($proposal->votes()->count())->toBe(0);
    $page->assertSeeHtml('data-test="down-count">0<');
});

it('cannot store two votes of one member on one proposal', function () {
    $vote = MemberProposalVote::factory()->up()->create();

    expect(fn () => MemberProposalVote::factory()->down()->create([
        'member_proposal_id' => $vote->member_proposal_id,
        'einundzwanzig_pleb_id' => $vote->einundzwanzig_pleb_id,
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(MemberProposalVote::query()->count())->toBe(1);
});

it('counts the votes of different members separately', function () {
    $proposal = MemberProposal::factory()->create();
    MemberProposalVote::factory()->for($proposal)->up()->count(3)->create();
    MemberProposalVote::factory()->for($proposal)->down()->count(2)->create();
    NostrAuth::login(paidMember()->pubkey);

    showProposal($proposal)
        ->assertSeeHtml('data-test="up-count">3<')
        ->assertSeeHtml('data-test="down-count">2<');
});

it('rejects a vote value that is neither up nor down', function () {
    $proposal = MemberProposal::factory()->create();
    NostrAuth::login(paidMember()->pubkey);

    showProposal($proposal)->call('vote', 'sideways')->assertStatus(422);

    expect(MemberProposalVote::query()->count())->toBe(0);
});

it('accepts neither vote nor comment on an archived proposal', function (MemberProposalState $state) {
    $member = paidMember();
    $proposal = MemberProposal::factory()->inState($state)->create();
    MemberProposalVote::factory()->for($proposal)->for($member)->up()->create();
    NostrAuth::login($member->pubkey);

    showProposal($proposal)->call('vote', 'down')->assertForbidden();
    showProposal($proposal)->call('vote', 'up')->assertForbidden();
    showProposal($proposal)->set('commentBody', 'Too late')->call('addComment')->assertForbidden();

    expect($proposal->votes()->pluck('value')->all())->toBe(['up'])
        ->and($proposal->comments()->count())->toBe(0);

    showProposal($proposal)
        ->assertSee('Dieser Vorschlag liegt im Archiv')
        ->assertDontSeeHtml('wire:click="vote(')
        ->assertDontSeeHtml('wire:submit="addComment"');
})->with('archived proposal states');

it('closes a proposal that the board archived after the page was loaded', function () {
    $proposal = MemberProposal::factory()->create();
    NostrAuth::login(paidMember()->pubkey);

    $page = showProposal($proposal);

    $proposal->state = MemberProposalState::Rejected;
    $proposal->save();

    $page->call('vote', 'up')->assertForbidden();

    expect($proposal->votes()->count())->toBe(0);
});

it('stores a comment under the member\'s name and renders it', function () {
    $member = paidMember();
    Profile::factory()->create(['pubkey' => $member->pubkey, 'name' => 'satoshi-aus-bern']);
    $proposal = MemberProposal::factory()->create();
    NostrAuth::login($member->pubkey);

    showProposal($proposal)
        ->set('commentBody', 'Gute **Idee**')
        ->call('addComment')
        ->assertHasNoErrors()
        ->assertSet('commentBody', '')
        ->assertSee('satoshi-aus-bern')
        ->assertSeeHtml('<strong>Idee</strong>');

    $comment = $proposal->comments()->sole();

    expect($comment->einundzwanzig_pleb_id)->toBe($member->id)
        ->and($comment->body)->toBe('Gute **Idee**');
});

it('requires a comment to have text', function () {
    $proposal = MemberProposal::factory()->create();
    NostrAuth::login(paidMember()->pubkey);

    showProposal($proposal)->set('commentBody', '   ')->call('addComment')->assertHasErrors('commentBody');

    expect($proposal->comments()->count())->toBe(0);
});

it('lets a member delete their own comment and nobody else\'s', function () {
    $member = paidMember();
    $proposal = MemberProposal::factory()->create();
    $own = MemberProposalComment::factory()->for($proposal)->for($member)->create();
    $foreign = MemberProposalComment::factory()->for($proposal)->create();
    NostrAuth::login($member->pubkey);

    showProposal($proposal)->call('deleteComment', $foreign->id)->assertForbidden();
    showProposal($proposal)->call('deleteComment', $own->id)->assertOk();

    expect($proposal->comments()->pluck('id')->all())->toBe([$foreign->id]);
});

it('keeps the text of a hidden comment away from members and shows it to the board', function () {
    $proposal = MemberProposal::factory()->create();
    MemberProposalComment::factory()->for($proposal)->hidden()->create(['body' => 'Beleidigung-xyz']);

    NostrAuth::login(paidMember()->pubkey);

    showProposal($proposal)
        ->assertSee('Dieser Kommentar wurde vom Vorstand ausgeblendet.')
        ->assertDontSee('Beleidigung-xyz');

    NostrAuth::login(boardMember()->pubkey);

    showProposal($proposal)->assertSee('Beleidigung-xyz');
});

it('lets the author withdraw the proposal, after which nobody can open it', function () {
    $author = paidMember();
    $proposal = MemberProposal::factory()->for($author)->create();
    NostrAuth::login($author->pubkey);

    showProposal($proposal)->call('withdraw')->assertRedirectToRoute('association.proposals.index');

    expect($proposal->refresh()->isWithdrawn())->toBeTrue();

    $this->get(route('association.proposals.show', $proposal))->assertNotFound();
});

it('refuses a withdrawal by anybody but the author', function () {
    $proposal = MemberProposal::factory()->create();
    NostrAuth::login(paidMember()->pubkey);

    showProposal($proposal)->call('withdraw')->assertForbidden();

    expect($proposal->refresh()->isWithdrawn())->toBeFalse();
});

it('answers 404 for a hidden proposal to other members', function () {
    $proposal = MemberProposal::factory()->hidden()->create();
    NostrAuth::login(paidMember()->pubkey);

    $this->get(route('association.proposals.show', $proposal))->assertNotFound();
});

it('exposes two counts and the viewer\'s own vote, never who else voted', function () {
    $proposal = MemberProposal::factory()->create();

    $voters = collect(['up', 'up', 'down'])->map(function (string $value) use ($proposal) {
        $voter = paidMember(['npub' => 'npub1voter'.fake()->unique()->lexify('??????????')]);
        $profile = Profile::factory()->create(['pubkey' => $voter->pubkey]);
        MemberProposalVote::factory()->for($proposal)->for($voter)->create(['value' => $value]);

        return [$voter, $profile];
    });

    $viewer = paidMember();
    NostrAuth::login($viewer->pubkey);

    $page = showProposal($proposal)->call('vote', 'up');

    $exposed = $page->html().json_encode($page->snapshot).json_encode($page->effects);

    foreach ($voters as [$voter, $profile]) {
        expect($exposed)
            ->not->toContain($voter->pubkey)
            ->not->toContain($voter->npub)
            ->not->toContain($profile->name)
            ->not->toContain($profile->display_name);
    }

    $page->assertSeeHtml('data-test="up-count">3<')
        ->assertSeeHtml('data-test="down-count">1<')
        ->assertSee('Du hast dafür gestimmt.');
});

it('exposes no voter to the board either', function () {
    $proposal = MemberProposal::factory()->create();
    $voter = paidMember(['npub' => 'npub1voterboardcase']);
    $profile = Profile::factory()->create(['pubkey' => $voter->pubkey]);
    MemberProposalVote::factory()->for($proposal)->for($voter)->up()->create();

    NostrAuth::login(boardMember()->pubkey);

    $page = showProposal($proposal);
    $exposed = $page->html().json_encode($page->snapshot);

    expect($exposed)
        ->not->toContain($voter->pubkey)
        ->not->toContain($voter->npub)
        ->not->toContain($profile->name)
        ->not->toContain($profile->display_name);
});

it('renders script and javascript: payloads in title, description and comment harmless', function () {
    $payload = "<script>alert('xss')</script> [klick](javascript:alert(1)) <img src=x onerror=alert(2)> <a href=\"javascript:alert(3)\">x</a>";

    $proposal = MemberProposal::factory()->create([
        'title' => $payload,
        'description' => $payload,
    ]);
    MemberProposalComment::factory()->for($proposal)->create(['body' => $payload]);

    $member = paidMember();
    NostrAuth::login($member->pubkey);

    $page = showProposal($proposal)->set('commentBody', $payload)->call('addComment')->assertHasNoErrors();

    expect($proposal->comments()->count())->toBe(2);

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8"><body>'.$page->html().'</body>');
    $xpath = new DOMXPath($dom);

    // Read as structure, not as text: the payload may appear escaped, as
    // visible text. What must not exist is an element or attribute made of it.
    expect($xpath->query('//script')->length)->toBe(0)
        ->and($xpath->query('//img')->length)->toBe(0)
        ->and($xpath->query('//@*[starts-with(translate(normalize-space(.), "JAVSCRIPT", "javscript"), "javascript:")]')->length)->toBe(0)
        ->and($xpath->query('//@*[starts-with(name(), "on")]')->length)->toBe(0);

    // And the payload did arrive: escaped, three times (title, description
    // and two comments make four; the title is plain text, not Markdown).
    expect(substr_count($page->html(), '&lt;script&gt;'))->toBeGreaterThanOrEqual(4);
});
