<?php

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;

it('logs out, invalidates the session and regenerates the CSRF token', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    NostrAuth::login($pleb->pubkey);
    session()->put('marker', 'signed-in data');

    $sessionIdBefore = session()->getId();
    $tokenBefore = session()->token();

    $this->post(route('logout'))->assertRedirect('/');

    expect(NostrAuth::check())->toBeFalse()
        ->and(session()->has('marker'))->toBeFalse()
        ->and(session()->getId())->not->toBe($sessionIdBefore)
        ->and(session()->token())->not->toBe($tokenBefore)
        ->and(session()->token())->toBeString()->not->toBeEmpty();
});

it('lets a guest log out without an error', function () {
    $tokenBefore = session()->token();

    $this->post(route('logout'))->assertRedirect('/');

    expect(session()->token())->not->toBe($tokenBefore);
});
