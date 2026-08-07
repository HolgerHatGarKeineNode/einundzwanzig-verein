<?php

use App\Models\EinundzwanzigPleb;

/*
 * GET /api/v1/membership/config is the one endpoint on this surface that a
 * caller reaches without a NIP-98 signature. The exception is defensible only
 * as long as the response says nothing about any person, so that is what these
 * tests pin — not merely that the endpoint answers.
 */

const CONFIG_CLIENT_KEY = 'cfg111111111111111111111111111111111111111111111111111111111cfg';

beforeEach(function () {
    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => CONFIG_CLIENT_KEY,
    ]]);
});

it('answers 200 without any NIP-98 signature', function () {
    $response = $this->withHeaders([
        'X-Api-Key' => CONFIG_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/membership/config');

    $response->assertOk();

    // The decisive half: no Authorization header was sent at all. Had the
    // endpoint been wired into the api.v1 group by accident, this would be 401.
    expect($response->json('data.fee'))->toBe((int) config('einundzwanzig.config.membership_fee'))
        ->and($response->json('data.currency'))->toBe(config('einundzwanzig.config.currency'))
        ->and($response->json('data.year'))->toBe((int) date('Y'));
});

it('rejects a request without a client key', function () {
    $this->withHeaders(['Accept' => 'application/json'])
        ->getJson('/api/v1/membership/config')
        ->assertUnauthorized();
});

it('rejects a request with an unknown client key', function () {
    $this->withHeaders([
        'X-Api-Key' => 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        'Accept' => 'application/json',
    ])->getJson('/api/v1/membership/config')->assertUnauthorized();
});

it('carries the statutes url and version from the configuration', function () {
    config([
        'einundzwanzig.config.statutes.url' => 'https://example.test/Statuten_v9.9.pdf',
        'einundzwanzig.config.statutes.version' => '9.9',
        'einundzwanzig.config.statutes.adopted_at' => '2099-01-01',
    ]);

    $response = $this->withHeaders([
        'X-Api-Key' => CONFIG_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/membership/config');

    // Configurable, not cast in code: a new general meeting adopts a new
    // version, and until it is served here every applicant consents to the
    // wrong document.
    $response->assertOk()
        ->assertJsonPath('data.statutes.url', 'https://example.test/Statuten_v9.9.pdf')
        ->assertJsonPath('data.statutes.version', '9.9')
        ->assertJsonPath('data.statutes.adopted_at', '2099-01-01');
});

it('returns no subject-related field whatsoever', function () {
    /*
     * Members exist, carry an e-mail address and an application text, and none
     * of it may show up. Without them in the database the assertion below would
     * pass for the trivial reason that there was nothing to leak.
     */
    EinundzwanzigPleb::factory()->count(3)->create([
        'email' => 'leaked@example.test',
        'application_text' => 'leaked application prose',
    ]);

    $response = $this->withHeaders([
        'X-Api-Key' => CONFIG_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/membership/config');

    $response->assertOk();

    expect($response->json('data'))
        ->toHaveKeys(['fee', 'currency', 'year', 'statutes', 'application'])
        ->not->toHaveKeys([
            'id',
            'email',
            'application_text',
            'archived_application_text',
            'pubkey',
            'npub',
            'nip05_handle',
            'association_status',
            'membership_status',
            'members',
        ]);

    expect($response->getContent())
        ->not->toContain('leaked@example.test')
        ->not->toContain('leaked application prose');
});

it('names the consent as the only mandatory application field', function () {
    $response = $this->withHeaders([
        'X-Api-Key' => CONFIG_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->getJson('/api/v1/membership/config');

    // The statutes name no mandatory personal detail (Art. 4/6), so anything
    // beyond the consent would be an invention of this API.
    $response->assertOk()
        ->assertJsonPath('data.application.required_fields', ['statutes_accepted']);
});
