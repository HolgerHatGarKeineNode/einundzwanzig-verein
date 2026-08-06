<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('prints an env line whose key is 64 hex characters', function () {
    expect(Artisan::call('api:client-key', ['name' => 'einundzwanzig-group']))->toBe(0);

    expect(Artisan::output())
        ->toMatch('/API_CLIENT_KEYS="einundzwanzig-group:[0-9a-f]{64}"/');
});

it('mints a different key every time', function () {
    Artisan::call('api:client-key', ['name' => 'einundzwanzig-group']);
    $first = Artisan::output();

    Artisan::call('api:client-key', ['name' => 'einundzwanzig-group']);
    $second = Artisan::output();

    expect($first)->not->toBe($second);
});

it('writes nothing', function () {
    // The command hands the operator a value; setting it is a deploy step.
    // A command that edited .env on a production box would be
    // indistinguishable from an accident, so "writes nothing" is a property
    // worth pinning rather than a description of the current implementation.
    $envPath = base_path('.env');
    $before = File::exists($envPath) ? File::get($envPath) : null;
    $mtime = File::exists($envPath) ? File::lastModified($envPath) : null;

    Artisan::call('api:client-key', ['name' => 'einundzwanzig-group']);

    expect(File::exists($envPath) ? File::get($envPath) : null)->toBe($before)
        ->and(File::exists($envPath) ? File::lastModified($envPath) : null)->toBe($mtime)
        ->and(config('einundzwanzig.config.api_client_keys'))->toBe([]);
});

it('refuses a name that would break the env grammar', function (string $name) {
    // "," separates entries and ":" separates name from key. A name carrying
    // either would parse back into something other than what was minted, and
    // the operator would only find out through a 401 nobody can explain.
    expect(Artisan::call('api:client-key', ['name' => $name]))->toBe(1);

    expect(Artisan::output())->not->toContain('API_CLIENT_KEYS=');
})->with([
    'contains a comma' => ['group,other'],
    'contains a colon' => ['group:other'],
    'empty' => ['   '],
]);
