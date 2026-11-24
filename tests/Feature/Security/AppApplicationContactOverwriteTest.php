<?php

use App\Models\EinundzwanzigPleb;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

/**
 * S2 of the page optimisation plan: the app branch of the membership API has
 * no signature, the subject is the body pubkey and the client key vouches for
 * it. Such a caller must not be able to rewrite a foreign member's contact
 * data — only fill what is still empty.
 *
 * Measured at e43fe90: `{pubkey: victim, email: attacker@…, nip05_handle: null}`
 * answered 200, replaced the email and nulled the handle; the attacker then
 * took the freed handle with a 201 for their own pubkey.
 */
const SECURITY_APP_CLIENT_KEY = 'sec111111111111111111111111111111111111111111111111111111sec1';

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-app' => SECURITY_APP_CLIENT_KEY],
        'app.debug' => false,
    ]);
});

/**
 * @param  array<string, mixed>  $body
 */
function appApplication(array $body): TestResponse
{
    return test()->withHeaders([
        'X-Api-Key' => SECURITY_APP_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/app/membership/applications', $body);
}

function existingMember(string $pubkey, array $attributes = []): EinundzwanzigPleb
{
    return EinundzwanzigPleb::factory()->create(array_merge([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
        'statutes_accepted_at' => now()->subDays(3),
    ], $attributes));
}

it('does not overwrite or clear a foreign member\'s email and handle', function () {
    $victimPubkey = (new Key)->getPublicKey(testPrivateKey());
    $victim = existingMember($victimPubkey, [
        'email' => 'victim@example.com',
        'nip05_handle' => 'victimhandle',
        'application_text' => 'Original application',
    ]);

    appApplication([
        'pubkey' => $victimPubkey,
        'email' => 'attacker@example.com',
        'nip05_handle' => null,
        'application_text' => 'Rewritten',
    ])->assertOk();

    $victim->refresh();

    expect($victim->email)->toBe('victim@example.com')
        ->and($victim->nip05_handle)->toBe('victimhandle')
        ->and($victim->application_text)->toBe('Original application');
});

it('keeps the handle with its owner, so another pubkey cannot take it', function () {
    $victimPubkey = (new Key)->getPublicKey(testPrivateKey());
    existingMember($victimPubkey, ['nip05_handle' => 'victimhandle']);

    appApplication(['pubkey' => $victimPubkey, 'nip05_handle' => null])->assertOk();

    $attackerPubkey = (new Key)->getPublicKey(str_repeat('2', 64));

    appApplication([
        'pubkey' => $attackerPubkey,
        'statutes_accepted' => true,
        'nip05_handle' => 'victimhandle',
    ])->assertUnprocessable()->assertJsonValidationErrors('nip05_handle');

    expect(EinundzwanzigPleb::query()->where('nip05_handle', 'victimhandle')->value('pubkey'))
        ->toBe($victimPubkey);
});

it('fills contact fields that are still empty on an existing record', function () {
    $pubkey = (new Key)->getPublicKey(testPrivateKey());
    $pleb = existingMember($pubkey, ['email' => null, 'nip05_handle' => null]);

    appApplication([
        'pubkey' => $pubkey,
        'email' => 'first@example.com',
        'nip05_handle' => 'firsthandle',
    ])->assertOk();

    $pleb->refresh();

    expect($pleb->email)->toBe('first@example.com')
        ->and($pleb->nip05_handle)->toBe('firsthandle');
});

it('writes every contact field on a newly created record', function () {
    $pubkey = (new Key)->getPublicKey(testPrivateKey());

    appApplication([
        'pubkey' => $pubkey,
        'statutes_accepted' => true,
        'email' => 'new@example.com',
        'no_email' => true,
        'nip05_handle' => 'newhandle',
        'application_text' => 'From the app',
    ])->assertCreated();

    $pleb = EinundzwanzigPleb::query()->where('pubkey', $pubkey)->firstOrFail();

    expect($pleb->email)->toBe('new@example.com')
        ->and((bool) $pleb->no_email)->toBeTrue()
        ->and($pleb->nip05_handle)->toBe('newhandle')
        ->and($pleb->application_text)->toBe('From the app');
});
