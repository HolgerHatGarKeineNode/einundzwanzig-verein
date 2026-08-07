<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;

/*
 * Defence in depth for the three fields that must never travel in a serialised
 * model.
 *
 * CipherSweet encrypts `email` at rest only. The accessor decrypts, so before
 * this list existed a single `EinundzwanzigPleb::all()` handed to
 * `response()->json()` published every member's plaintext address — and the
 * only thing preventing that on the one public endpoint was a `select()` line.
 */

it('keeps email and application prose out of the serialised model', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'email' => 'private@example.test',
        'application_text' => 'my private application prose',
        'archived_application_text' => 'my archived application prose',
    ]);

    expect(array_keys($pleb->fresh()->toArray()))
        ->not->toContain('email')
        ->not->toContain('application_text')
        ->not->toContain('archived_application_text');

    expect($pleb->fresh()->toJson())
        ->not->toContain('private@example.test')
        ->not->toContain('my private application prose')
        ->not->toContain('my archived application prose');
});

it('still lets the application read the fields directly', function () {
    /*
     * The point of $hidden is serialisation, not access. Blade output, the Volt
     * profile form and the board's member screen all read these attributes and
     * must keep working — a "protection" that broke them would simply be
     * reverted.
     */
    $pleb = EinundzwanzigPleb::factory()->create([
        'email' => 'private@example.test',
        'application_text' => 'my private application prose',
    ]);

    $fresh = $pleb->fresh();

    expect($fresh->email)->toBe('private@example.test')
        ->and($fresh->application_text)->toBe('my private application prose');
});

it('encrypts the email at rest', function () {
    /*
     * Tested at the model boundary, not the library: a raw read must not show
     * the plaintext. Without this, $hidden could pass while the column itself
     * held the address in the clear, and a `DB::table()` export would leak it.
     */
    $pleb = EinundzwanzigPleb::factory()->create(['email' => 'private@example.test']);

    $raw = DB::table('einundzwanzig_plebs')->where('id', $pleb->id)->value('email');

    expect($raw)->not->toBeNull()
        ->and($raw)->not->toContain('private@example.test')
        ->and($pleb->fresh()->email)->toBe('private@example.test');
});

it('leaves the public paid-members endpoint unchanged', function () {
    /*
     * The regression this guards: GetPaidMembers relies on a `select()` naming
     * four columns. $hidden must not remove one of them — npub, pubkey and
     * nip05_handle are the contract external consumers depend on, and a
     * breaking change there was ruled out (plan P1 step 8).
     */
    $member = EinundzwanzigPleb::factory()->create([
        'npub' => 'npub1hiddentest',
        'pubkey' => 'pubkey-hidden-test',
        'nip05_handle' => 'hidden@example.test',
        'email' => 'private@example.test',
        'association_status' => AssociationStatus::ACTIVE,
    ]);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $member->id,
        'year' => 2024,
    ]);

    $response = $this->getJson('/api/members/2024');

    $response->assertOk()->assertJsonFragment([
        'npub' => 'npub1hiddentest',
        'pubkey' => 'pubkey-hidden-test',
        'nip05_handle' => 'hidden@example.test',
    ]);

    expect($response->getContent())->not->toContain('private@example.test');
});
