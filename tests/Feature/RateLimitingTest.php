<?php

use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Models\Vote;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    RateLimiter::clear('api');
    RateLimiter::clear('voting');
    RateLimiter::clear('nostr-login');
});

test('api routes return 429 after exceeding rate limit', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/members/2024')->assertSuccessful();
    }

    $this->getJson('/api/members/2024')->assertStatus(429);
});

test('api routes include rate limit headers', function () {
    $response = $this->getJson('/api/members/2024');

    $response->assertSuccessful();
    $response->assertHeader('X-RateLimit-Limit', 60);
    $response->assertHeader('X-RateLimit-Remaining');
});

/*
 * Die Schluessel muessen echte NIP-01-Pubkeys sein (64 Zeichen Kleinbuchstaben-Hex):
 * seit der Route-Constraint in `routes/api.php` faellt alles andere schon im Router
 * als 404 heraus — und ein Router-404 laeuft an der Throttle-Middleware vorbei, der
 * Zaehler wuerde also nie steigen. Die frueheren Platzhalter ("testkey0" …) haetten
 * den Test in ein gruenes Nichts verwandelt.
 */
test('nostr profile api route is rate limited', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/nostr/profile/'.hash('sha256', 'testkey'.$i));
    }

    $this->getJson('/api/nostr/profile/'.hash('sha256', 'testkey'))->assertStatus(429);
});

test('voting actions are rate limited after 10 attempts', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    for ($i = 0; $i < 10; $i++) {
        RateLimiter::attempt('voting:127.0.0.1', 10, function () {});
    }

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->call('handleApprove')
        ->assertStatus(429);
});

test('nostr login is rate limited after 10 attempts', function () {
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::attempt('nostr-login:127.0.0.1', 10, function () {});
    }

    // Rate limit is checked before signature verification, so an empty
    // payload is enough to trigger the 429.
    Livewire::test('association.project-support.index')
        ->call('handleNostrLogin', [])
        ->assertStatus(429);
});

test('project proposal creation is rate limited after 5 attempts', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    NostrAuth::login($pleb->pubkey);

    for ($i = 0; $i < 5; $i++) {
        RateLimiter::attempt('project-proposal-create:127.0.0.1', 5, function () {});
    }

    Livewire::test('association.project-support.form.create')
        ->set('form.name', 'Test Project')
        ->set('form.description', 'Test Description')
        ->set('form.support_in_sats', 21000)
        ->set('form.website', 'https://example.com')
        ->call('save')
        ->assertStatus(429);
});

test('project proposal update is rate limited after 5 attempts', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
    ]);

    NostrAuth::login($pleb->pubkey);

    for ($i = 0; $i < 5; $i++) {
        RateLimiter::attempt('project-proposal-update:127.0.0.1', 5, function () {});
    }

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $project])
        ->set('form.name', 'Updated Name')
        ->call('update')
        ->assertStatus(429);
});

test('voting works within rate limit', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.show', ['projectProposal' => $project])
        ->call('handleApprove')
        ->assertHasNoErrors();

    $vote = Vote::query()
        ->where('project_proposal_id', $project->id)
        ->where('einundzwanzig_pleb_id', $pleb->id)
        ->first();

    expect($vote)->not->toBeNull()
        ->and($vote->value)->toBeTrue();
});
