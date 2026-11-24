<?php

use App\Enums\AssociationStatus;
use App\Enums\NewsCategory;
use App\Models\EinundzwanzigPleb;
use App\Models\Notification;
use App\Models\ProjectProposal;
use App\Models\Vote;
use App\Support\NostrAuth;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * S3 of the page optimisation plan: a Livewire snapshot carries the member it
 * was rendered for (`currentPleb`). A write action must act for whoever the
 * session says is logged in NOW, and refuse when that is nobody or somebody
 * else — never write with the identity frozen into the snapshot.
 *
 * Measured at e43fe90: board member A rendered the proposal page, the session
 * was switched to an outsider, and `handleNotApprove` flipped A's vote.
 */
beforeEach(function () {
    Http::fake(['einundzwanzig.space/.well-known/*' => Http::response(['names' => []], 200)]);
    Storage::fake('public');
    Storage::fake('private');
});

it('refuses a vote written for the rendered board member after the session switched', function () {
    collect(config('einundzwanzig.config.current_board'))->each(
        fn (string $npub) => EinundzwanzigPleb::factory()->create(['npub' => $npub])
    );
    $boardMember = EinundzwanzigPleb::query()->where('npub', config('einundzwanzig.config.current_board')[0])->firstOrFail();
    $outsider = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();
    Vote::create([
        'project_proposal_id' => $project->id,
        'einundzwanzig_pleb_id' => $boardMember->id,
        'value' => true,
    ]);

    NostrAuth::login($boardMember->pubkey);
    $page = Livewire::test('association.project-support.show', ['projectProposal' => $project]);

    NostrAuth::login($outsider->pubkey);
    $page->call('castVote', false)->assertForbidden();

    expect(Vote::query()->where('einundzwanzig_pleb_id', $boardMember->id)->value('value'))->toBeTrue()
        ->and(Vote::query()->where('einundzwanzig_pleb_id', $outsider->id)->exists())->toBeFalse();
});

it('refuses an approval written for the rendered member after the session switched', function () {
    $renderedFor = EinundzwanzigPleb::factory()->create();
    $outsider = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($renderedFor->pubkey);
    $page = Livewire::test('association.project-support.show', ['projectProposal' => $project]);

    NostrAuth::login($outsider->pubkey);
    $page->call('castVote', true)->assertForbidden();

    expect(Vote::query()->where('project_proposal_id', $project->id)->exists())->toBeFalse();
});

it('refuses the profile email opt-out for the rendered member after logout', function () {
    $member = EinundzwanzigPleb::factory()->create(['no_email' => false]);

    NostrAuth::login($member->pubkey);
    $page = Livewire::test('association.profile');

    NostrAuth::logout();
    $page->set('no', true)->assertForbidden();

    expect((bool) $member->fresh()->no_email)->toBeFalse();
});

it('refuses profile writes for the rendered member after the session switched', function (string $action, array $set) {
    $member = EinundzwanzigPleb::factory()->create([
        'email' => 'member@example.com',
        'nip05_handle' => 'memberhandle',
        'association_status' => AssociationStatus::DEFAULT,
    ]);
    $other = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($member->pubkey);
    $page = Livewire::test('association.profile');

    foreach ($set as $property => $value) {
        $page->set($property, $value);
    }

    NostrAuth::login($other->pubkey);
    $page->call($action)->assertForbidden();

    $fresh = $member->fresh();

    expect($fresh->email)->toBe('member@example.com')
        ->and($fresh->nip05_handle)->toBe('memberhandle')
        ->and($fresh->applied_at)->toBeNull()
        ->and($other->fresh()->applied_at)->toBeNull();
})->with([
    'saveEmail' => ['saveEmail', ['profileForm.email' => 'attacker@example.com']],
    'saveNip05Handle' => ['saveNip05Handle', ['profileForm.nip05Handle' => 'attackerhandle']],
    'save (application)' => ['save', ['form.check' => true]],
]);

it('refuses a payment event or invoice for the rendered member after the session switched', function (string $action, array $arguments) {
    $member = EinundzwanzigPleb::factory()->create();
    $other = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($member->pubkey);
    $page = Livewire::test('association.profile');
    $eventsBefore = $member->paymentEvents()->count();

    NostrAuth::login($other->pubkey);
    $page->call($action, ...$arguments)->assertForbidden();

    expect($member->paymentEvents()->count())->toBe($eventsBefore)
        ->and($other->paymentEvents()->count())->toBe(0);
})->with([
    'createPaymentEvent' => ['createPaymentEvent', []],
    'pay' => ['pay', ['comment']],
]);

it('refuses the benefits handle for the rendered member after the session switched', function () {
    $member = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create(['nip05_handle' => 'memberhandle']);
    $other = EinundzwanzigPleb::factory()->create();

    NostrAuth::login($member->pubkey);
    $page = Livewire::test('association.benefits');

    NostrAuth::login($other->pubkey);
    $page->set('nip05Handle', 'attackerhandle')
        ->call('saveNip05Handle')
        ->assertForbidden();

    expect($member->fresh()->nip05_handle)->toBe('memberhandle');
});

it('refuses news attributed to the rendered board member after the session switched', function () {
    $board = config('einundzwanzig.config.current_board');
    $renderedFor = EinundzwanzigPleb::factory()->create(['npub' => $board[0], 'association_status' => AssociationStatus::HONORARY]);
    EinundzwanzigPleb::factory()->create(['npub' => $board[1], 'association_status' => AssociationStatus::HONORARY]);
    $otherBoardMember = EinundzwanzigPleb::query()->where('npub', $board[1])->firstOrFail();

    NostrAuth::login($renderedFor->pubkey);

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    file_put_contents($file->getPathname(), '%PDF-1.4 fake pdf content for testing');

    $page = Livewire::test('association.news')
        ->set('file', $file)
        ->set('form.category', (string) NewsCategory::Organisation->value)
        ->set('form.name', 'Switched session news')
        ->set('form.description', 'Test');

    NostrAuth::login($otherBoardMember->pubkey);
    $page->call('save')->assertForbidden();

    expect(Notification::query()->where('name', 'Switched session news')->exists())->toBeFalse();
});
