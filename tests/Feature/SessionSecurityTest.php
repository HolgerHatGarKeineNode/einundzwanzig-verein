<?php

it('has secure defaults in session config file', function () {
    $config = require base_path('config/session.php');

    // When no env vars are set, these should default to secure values
    expect($config['http_only'])->toBeTrue('http_only should default to true');
    expect($config['same_site'])->toBe('lax', 'same_site should default to lax');
});

it('defaults session encryption to true in config', function () {
    $configContent = file_get_contents(base_path('config/session.php'));

    expect($configContent)->toContain("env('SESSION_ENCRYPT', true)");
});

it('defaults secure cookie to true in config', function () {
    $configContent = file_get_contents(base_path('config/session.php'));

    expect($configContent)->toContain("env('SESSION_SECURE_COOKIE', true)");
});

it('has secure session defaults in env example', function () {
    $envExample = file_get_contents(base_path('.env.example'));

    expect($envExample)->toContain('SESSION_ENCRYPT=true')
        ->toContain('SESSION_SECURE_COOKIE=true');
});

it('sets httponly and samesite flags on session cookie', function () {
    $response = $this->get('/');

    $sessionCookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($sessionCookie)->not->toBeNull()
        ->and($sessionCookie->isHttpOnly())->toBeTrue('Session cookie should be HttpOnly')
        ->and($sessionCookie->getSameSite())->toBe('lax', 'Session cookie should have SameSite=lax');
});
