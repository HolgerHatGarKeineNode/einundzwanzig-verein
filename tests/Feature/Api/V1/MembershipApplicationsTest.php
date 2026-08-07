<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

const APPLY_CLIENT_KEY = 'apply111111111111111111111111111111111111111111111111111apply11';

beforeEach(function () {
    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => APPLY_CLIENT_KEY,
    ]]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function applyMemberFor(string $privkey, array $attributes = []): EinundzwanzigPleb
{
    $pubkey = (new Key)->getPublicKey($privkey);

    return EinundzwanzigPleb::factory()->create($attributes + [
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
    ]);
}

/**
 * @param  array<string, mixed>  $body
 * @return array{response: TestResponse, pubkey: string, privkey: string}
 */
function applyCall(array $body, ?string $privkey = null): array
{
    return apiV1SignedRequest('POST', '/api/v1/membership/applications', APPLY_CLIENT_KEY, $privkey, $body);
}

it('requires a NIP-98 signature', function () {
    $this->withHeaders([
        'X-Api-Key' => APPLY_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->postJson('/api/v1/membership/applications', ['statutes_accepted' => true])
        ->assertUnauthorized();
});

it('requires a client key', function () {
    $body = ['statutes_accepted' => true];
    $signed = makeNip98Event(
        url('/api/v1/membership/applications'),
        'POST',
        json_encode($body),
    );

    $this->withHeaders([
        'Authorization' => $signed['header'],
        'Accept' => 'application/json',
    ])->postJson('/api/v1/membership/applications', $body)
        ->assertUnauthorized();
});

it('records a first application and leaves the membership category alone', function () {
    $call = applyCall([
        'statutes_accepted' => true,
        'application_text' => 'I have been running the local meetup for two years.',
        'email' => 'applicant@example.test',
        'nip05_handle' => 'applicant',
    ]);

    $call['response']->assertCreated()
        ->assertJsonPath('data.membership_status', 'awaiting_payment')
        ->assertJsonPath('data.pubkey', $call['pubkey'])
        // The category is a consequence of a paid fee, never of an
        // application. DEFAULT(1) is what a fresh applicant is.
        ->assertJsonPath('data.association_status', 'DEFAULT')
        ->assertJsonPath('data.association_status_value', 1);

    $pleb = EinundzwanzigPleb::query()->where('pubkey', $call['pubkey'])->firstOrFail();

    expect($pleb->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and($pleb->applied_at)->not->toBeNull()
        ->and($pleb->statutes_accepted_at)->not->toBeNull()
        ->and($pleb->application_text)->toBe('I have been running the local meetup for two years.')
        ->and($pleb->email)->toBe('applicant@example.test')
        ->and($pleb->nip05_handle)->toBe('applicant')
        // The npub is derived from the signed pubkey, never sent by the client.
        ->and($pleb->npub)->toBe((new Key)->convertPublicKeyToBech32($call['pubkey']));
});

it('refuses an application without consent to the statutes and writes nothing', function () {
    $call = applyCall([
        'application_text' => 'let me in without agreeing to anything',
        'email' => 'sneaky@example.test',
    ]);

    $call['response']->assertStatus(422)
        ->assertJsonValidationErrors(['statutes_accepted']);

    // Nothing at all: not the record, not the contact data, not a timestamp.
    // The form request refuses before the controller runs, which is the only
    // arrangement in which "nothing was written" is structural rather than
    // lucky.
    expect(EinundzwanzigPleb::query()->where('pubkey', $call['pubkey'])->exists())->toBeFalse()
        ->and(EinundzwanzigPleb::query()->count())->toBe(0);
});

it('refuses an explicit refusal of the statutes', function () {
    /*
     * `accepted` rather than `boolean`: a client sending false has refused,
     * and refusing must fail rather than quietly record a non-consent that
     * later looks like consent because the timestamp is set.
     */
    $call = applyCall(['statutes_accepted' => false]);

    $call['response']->assertStatus(422)
        ->assertJsonValidationErrors(['statutes_accepted']);

    expect(EinundzwanzigPleb::query()->count())->toBe(0);
});

it('answers a second application with 200 and never moves the consent timestamp', function () {
    $privkey = (new Key)->generatePrivateKey();
    $consentedAt = Carbon::parse('2026-03-01 12:00:00');

    applyMemberFor($privkey, [
        'statutes_accepted_at' => $consentedAt,
        'applied_at' => $consentedAt,
        'application_text' => 'the first version of my text',
    ]);

    $this->travelTo(Carbon::parse('2026-08-07 09:00:00'));

    $call = applyCall(['application_text' => 'the corrected version of my text'], $privkey);

    $call['response']->assertOk()
        ->assertJsonPath('data.statutes_accepted_at', $consentedAt->toIso8601String());

    $pleb = EinundzwanzigPleb::query()->where('pubkey', $call['pubkey'])->firstOrFail();

    expect($pleb->statutes_accepted_at->toIso8601String())->toBe($consentedAt->toIso8601String())
        // The application text may be corrected …
        ->and($pleb->application_text)->toBe('the corrected version of my text')
        // … and `applied_at` does move: it records the latest application,
        // while the consent records the one that carries the membership.
        ->and($pleb->applied_at->toIso8601String())->toBe(Carbon::parse('2026-08-07 09:00:00')->toIso8601String());
});

it('needs no renewed consent for a second application', function () {
    /*
     * Consent is given once and is not an annual ritual (plan step 26). A
     * client that omits the field on a repeat application must not be refused —
     * the stored timestamp is the document, and demanding it again would
     * pretend the answer mattered when it would be discarded.
     */
    $privkey = (new Key)->generatePrivateKey();
    applyMemberFor($privkey, ['statutes_accepted_at' => Carbon::parse('2026-03-01 12:00:00')]);

    applyCall(['application_text' => 'no consent field in this body'], $privkey)['response']
        ->assertOk();
});

it('does not read a missing field as an instruction to delete', function () {
    /*
     * AUDIT ITEM 6. The body is signed as a whole, so a client operator can
     * drop a field without breaking the signature. If absence meant "clear
     * it", that operator could erase a member's e-mail address while the
     * member's own signature vouched for the request.
     */
    $privkey = (new Key)->generatePrivateKey();
    applyMemberFor($privkey, [
        'statutes_accepted_at' => Carbon::parse('2026-03-01 12:00:00'),
        'email' => 'keep-me@example.test',
        'application_text' => 'keep this prose',
        'nip05_handle' => 'keep',
    ]);

    $call = applyCall(['application_text' => 'only this one changes'], $privkey);

    $call['response']->assertOk();

    $pleb = EinundzwanzigPleb::query()->where('pubkey', $call['pubkey'])->firstOrFail();

    expect($pleb->email)->toBe('keep-me@example.test')
        ->and($pleb->nip05_handle)->toBe('keep')
        ->and($pleb->application_text)->toBe('only this one changes');
});

it('clears a field the client explicitly sends as null', function () {
    /*
     * The discriminating half of the test above. Without it, a controller that
     * simply never cleared anything would satisfy "absence does not delete" —
     * and a member would have no way to withdraw their e-mail address short of
     * erasing the whole record.
     */
    $privkey = (new Key)->generatePrivateKey();
    applyMemberFor($privkey, [
        'statutes_accepted_at' => Carbon::parse('2026-03-01 12:00:00'),
        'email' => 'remove-me@example.test',
    ]);

    $call = applyCall(['email' => null, 'no_email' => true], $privkey);

    $call['response']->assertOk();

    $pleb = EinundzwanzigPleb::query()->where('pubkey', $call['pubkey'])->firstOrFail();

    // Cast because the column carries no boolean cast on the model and SQLite
    // hands back the integer 1 — the value is right, the type is the storage's.
    expect($pleb->email)->toBeNull()
        ->and((bool) $pleb->no_email)->toBeTrue();
});

it('refuses an application text beyond the published limit', function () {
    $call = applyCall([
        'statutes_accepted' => true,
        'application_text' => str_repeat('a', 2001),
    ]);

    $call['response']->assertStatus(422)
        ->assertJsonValidationErrors(['application_text']);

    expect(EinundzwanzigPleb::query()->count())->toBe(0);
});

it('refuses a NIP-05 handle that already belongs to somebody else', function () {
    /*
     * The column is uniquely indexed. Without the rule this is a database
     * error and therefore a 500 for what is plainly a client-side mistake.
     */
    EinundzwanzigPleb::factory()->create(['nip05_handle' => 'taken']);

    /*
     * A WELL-FORMED handle on purpose: with `taken@einundzwanzig.space` this
     * test would fail on the format rule in front of the unique rule and prove
     * nothing about uniqueness at all.
     */
    $call = applyCall([
        'statutes_accepted' => true,
        'nip05_handle' => 'taken',
    ]);

    $call['response']->assertStatus(422)
        ->assertJsonValidationErrors(['nip05_handle']);
});

it('refuses a NIP-05 handle that is not one', function (string $handle) {
    /*
     * The eight values an audit fed through this endpoint before the format
     * rule existed. All eight were stored, answered 200, and came back out of
     * the UNAUTHENTICATED `GET /api/members/{year}` — including the script tag
     * and the traversal string. The rule is the same one the two existing
     * write paths have always applied (`ProfileForm.php:15`,
     * `benefits.blade.php:87`); an API that accepts what the UI refuses just
     * creates a second definition of a NIP-05 handle.
     *
     * `@` and `/` additionally break the `<handle>@einundzwanzig.space`
     * construction that NIP-05 rests on.
     */
    $call = applyCall([
        'statutes_accepted' => true,
        'nip05_handle' => $handle,
    ]);

    $call['response']->assertStatus(422)
        ->assertJsonValidationErrors(['nip05_handle']);

    expect(EinundzwanzigPleb::query()->count())->toBe(0);
})->with([
    'script tag' => '<script>alert(1)</script>',
    'path traversal' => '../../etc/passwd',
    'foreign domain' => 'alice@evil.example',
    'whitespace' => 'alice bob',
    'quotes' => 'a"b\'c',
    'non-ascii' => 'älice',
    'uppercase' => 'ALICE',
    /*
     * NIP-05 reserves the name `_`: clients render `_@einundzwanzig.space` as
     * the bare domain `einundzwanzig.space`, so whoever holds it appears AS
     * THE ASSOCIATION.
     */
    'the reserved NIP-05 name' => '_',
]);

it('accepts a well-formed NIP-05 handle', function () {
    // The discriminating counterpart to the seven refusals above: a rule that
    // rejected everything would satisfy them and lock out every legitimate
    // handle.
    $call = applyCall([
        'statutes_accepted' => true,
        'nip05_handle' => 'alice',
    ]);

    $call['response']->assertCreated();

    expect(EinundzwanzigPleb::query()->where('pubkey', $call['pubkey'])->value('nip05_handle'))
        ->toBe('alice');
});

it('lets a member keep their own NIP-05 handle on a repeat application', function () {
    $privkey = (new Key)->generatePrivateKey();
    applyMemberFor($privkey, [
        'statutes_accepted_at' => Carbon::parse('2026-03-01 12:00:00'),
        'nip05_handle' => 'mine',
    ]);

    applyCall(['nip05_handle' => 'mine'], $privkey)['response']
        ->assertOk();
});

it('returns only the allowed fields and never personal data', function () {
    $call = applyCall([
        'statutes_accepted' => true,
        'application_text' => 'my private application prose',
        'email' => 'private@example.test',
    ]);

    $call['response']->assertCreated();

    expect($call['response']->json('data'))
        ->toHaveKeys([
            'pubkey',
            'association_status',
            'association_status_value',
            'membership_status',
            'statutes_accepted_at',
            'applied_at',
            'current_year',
        ])
        ->not->toHaveKeys([
            'id',
            'email',
            'no_email',
            'nip05_handle',
            'application_text',
            'archived_application_text',
            'application_for',
        ]);

    expect($call['response']->getContent())
        ->not->toContain('private@example.test')
        ->not->toContain('my private application prose');
});
