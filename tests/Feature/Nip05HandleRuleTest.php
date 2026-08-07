<?php

use App\Models\EinundzwanzigPleb;
use Illuminate\Support\Facades\Validator;

/*
 * THE RULE ITSELF, WITHOUT ANY MIDDLEWARE IN FRONT OF IT.
 *
 * A trailing newline never reaches the rule over HTTP — Laravel's TrimStrings
 * removes it first, so an endpoint test would report "accepted as `root`" and
 * prove nothing about the pattern. That was the whole finding: the guarantee
 * appeared to hold while resting on a component in a different file, on a code
 * path that is not the only way into these forms (console commands, jobs and
 * seeders all validate without middleware).
 *
 * These cases therefore go straight at the constant.
 */

/**
 * @return Illuminate\Validation\Validator
 */
function nip05Validator(string $handle, string $rules)
{
    return Validator::make(['nip05_handle' => $handle], ['nip05_handle' => $rules]);
}

it('refuses a handle that ends in a newline', function () {
    expect(nip05Validator("root\n", EinundzwanzigPleb::NIP05_HANDLE_RULES)->fails())->toBeTrue();
});

it('would have let that newline through without the /D modifier', function () {
    /*
     * The discriminating evidence, kept because it is the only thing that
     * shows the modifier is doing work: `$` matches before a trailing newline
     * unless `/D` says otherwise. If this ever starts failing, PHP changed the
     * default and the test above stopped testing anything.
     */
    expect(nip05Validator("root\n", 'string|regex:/^[a-z0-9_-]+$/')->fails())->toBeFalse()
        ->and(preg_match('/^[a-z0-9_-]+$/', "root\n"))->toBe(1)
        ->and(preg_match('/^[a-z0-9_-]+$/D', "root\n"))->toBe(0);
});

it('refuses the reserved NIP-05 name', function () {
    // `_@einundzwanzig.space` is rendered by clients as the bare domain
    // `einundzwanzig.space` — the holder appears as the association itself.
    expect(nip05Validator('_', EinundzwanzigPleb::NIP05_HANDLE_RULES)->fails())->toBeTrue();
});

it('accepts the handles it is supposed to accept', function (string $handle) {
    expect(nip05Validator($handle, EinundzwanzigPleb::NIP05_HANDLE_RULES)->fails())->toBeFalse();
})->with([
    'plain' => 'alice',
    'underscore inside' => 'alice_bob',
    'hyphen inside' => 'alice-bob',
    'digits' => 'alice21',
]);

it('is one rule for all three write paths', function () {
    /*
     * The drift guard. Three literal copies of the same regex is how the API
     * ended up as the loosest of the three in the first place; each file must
     * read the constant rather than spell the pattern out again.
     */
    $writePaths = [
        'app/Http/Requests/Api/V1/StoreApplicationRequest.php',
        'app/Livewire/Forms/ProfileForm.php',
        'resources/views/livewire/association/benefits.blade.php',
    ];

    foreach ($writePaths as $path) {
        $source = file_get_contents(base_path($path));

        expect($source)->toContain('NIP05_HANDLE_RULES')
            ->and($source)->not->toContain('[a-z0-9_-]');
    }
});
