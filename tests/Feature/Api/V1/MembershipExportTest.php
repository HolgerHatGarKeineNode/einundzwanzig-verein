<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use App\Models\Profile;
use Illuminate\Support\Carbon;
use swentel\nostr\Key\Key;

const EXP_CLIENT_KEY = 'exp111111111111111111111111111111111111111111111111111111exp11';

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => EXP_CLIENT_KEY],
        'einundzwanzig.config.currency' => 'SATS',
        'app.debug' => false,
    ]);
});

/**
 * A member with the full spread: contact data, both application texts, two
 * annual fees, a grant and a cached Nostr profile.
 *
 * @return array{pleb: EinundzwanzigPleb, privkey: string}
 */
function exportSubject(): array
{
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    $pleb = EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
        'association_status' => AssociationStatus::PASSIVE,
        'email' => 'mine@example.test',
        'no_email' => false,
        'nip05_handle' => 'mine@einundzwanzig.space',
        'application_text' => 'my own application prose',
        'archived_application_text' => 'my archived application prose',
        'statutes_accepted_at' => Carbon::parse('2026-03-01 12:00:00'),
        'applied_at' => Carbon::parse('2026-03-01 12:00:00'),
    ]);

    // Years relative to the clock, not literals: a fixture pinned to 2026
    // would quietly turn this member into a lapsed one on New Year's Eve and
    // fail for a reason that has nothing to do with the export.
    $paid = PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'btc_pay_invoice' => 'inv-current',
        'event_id' => 'nostr-event-of-my-fee',
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year - 1,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => null,
    ]);

    MembershipGrant::create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'payment_event_id' => $paid->id,
        'from_status' => AssociationStatus::DEFAULT,
        'to_status' => AssociationStatus::PASSIVE,
        'year' => (int) now()->year,
        'granted_at' => Carbon::parse('2026-03-02 08:00:00'),
    ]);

    Profile::create([
        'pubkey' => $pubkey,
        'name' => 'my-nostr-name',
        'about' => 'my own about text',
    ]);

    return ['pleb' => $pleb, 'privkey' => $privkey];
}

it('requires a NIP-98 signature', function () {
    $this->withHeaders([
        'X-Api-Key' => EXP_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->get('/api/v1/membership/export')->assertUnauthorized();
});

it('hands out everything stored about the caller, including the fields every other endpoint hides', function () {
    $subject = exportSubject();

    $call = apiV1SignedGet('/api/v1/membership/export', EXP_CLIENT_KEY, $subject['privkey']);

    $call['response']->assertOk()
        ->assertJsonPath('data.subject.pubkey', $subject['pleb']->pubkey)
        ->assertJsonPath('data.subject.npub', $subject['pleb']->npub)
        ->assertJsonPath('data.membership_status', 'member')
        /*
         * THE ONE PLACE THESE THREE MAY LEAVE THE SYSTEM. An access response
         * that quietly dropped the prose somebody wrote about themselves would
         * be incomplete in the one way a data subject cannot detect.
         */
        ->assertJsonPath('data.member.email', 'mine@example.test')
        ->assertJsonPath('data.member.application_text', 'my own application prose')
        ->assertJsonPath('data.member.archived_application_text', 'my archived application prose')
        ->assertJsonPath('data.member.nip05_handle', 'mine@einundzwanzig.space')
        ->assertJsonPath('data.member.association_status', 'PASSIVE')
        ->assertJsonPath('data.member.statutes_accepted_at', Carbon::parse('2026-03-01 12:00:00')->toIso8601String())
        ->assertJsonPath('data.nostr_profile.name', 'my-nostr-name')
        ->assertJsonPath('data.nostr_profile.about', 'my own about text');

    expect($call['response']->json('data.payments'))->toHaveCount(2)
        ->and($call['response']->json('data.payments.0.year'))->toBe((int) now()->year)
        ->and($call['response']->json('data.payments.0.paid'))->toBeTrue()
        ->and($call['response']->json('data.payments.0.amount'))->toBe(21000)
        // Withheld from ordinary responses because no client needs them — but
        // to their own subject they are simply part of the record.
        ->and($call['response']->json('data.payments.0.btc_pay_invoice'))->toBe('inv-current')
        ->and($call['response']->json('data.payments.0.nostr_event_id'))->toBe('nostr-event-of-my-fee')
        ->and($call['response']->json('data.membership_grants'))->toHaveCount(1)
        ->and($call['response']->json('data.membership_grants.0.to_status'))->toBe('PASSIVE')
        ->and($call['response']->json('data.membership_grants.0.year'))->toBe((int) now()->year);
});

it('exports the data of the signing pubkey and of nobody else', function () {
    $subject = exportSubject();

    $strangerPubkey = (new Key)->getPublicKey((new Key)->generatePrivateKey());

    $stranger = EinundzwanzigPleb::factory()->create([
        // A real npub, not the factory's faker word: a three-letter word makes
        // the "must not contain" assertion below trip over any substring.
        'pubkey' => $strangerPubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($strangerPubkey),
        'email' => 'stranger@example.test',
        'application_text' => 'the stranger’s application prose',
        'archived_application_text' => 'the stranger’s archived prose',
        'nip05_handle' => 'stranger@einundzwanzig.space',
    ]);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $stranger->id,
        'year' => 2026,
        'amount' => 999999,
        'btc_pay_invoice' => 'stranger-invoice',
        'event_id' => 'stranger-nostr-event',
    ]);

    Profile::create(['pubkey' => $stranger->pubkey, 'name' => 'stranger-nostr-name']);

    $call = apiV1SignedGet('/api/v1/membership/export', EXP_CLIENT_KEY, $subject['privkey']);

    $call['response']->assertOk();

    expect($call['response']->json('data.payments'))->toHaveCount(2);

    expect($call['response']->getContent())
        ->not->toContain('stranger@example.test')
        ->not->toContain('the stranger’s application prose')
        ->not->toContain('the stranger’s archived prose')
        ->not->toContain('stranger@einundzwanzig.space')
        ->not->toContain('stranger-invoice')
        ->not->toContain('stranger-nostr-event')
        ->not->toContain('stranger-nostr-name')
        ->not->toContain('999999')
        ->not->toContain($stranger->pubkey)
        ->not->toContain($stranger->npub);
});

it('answers a pubkey with nothing on file truthfully instead of refusing', function () {
    /*
     * "Nothing is stored about you" is a complete answer to an access request.
     * A 404 would leave a data subject unable to tell an empty file from a
     * broken endpoint — and it must not create one either.
     */
    $call = apiV1SignedGet('/api/v1/membership/export', EXP_CLIENT_KEY);

    $call['response']->assertOk()
        ->assertJsonPath('data.subject.pubkey', $call['pubkey'])
        ->assertJsonPath('data.subject.npub', null)
        ->assertJsonPath('data.member', null)
        ->assertJsonPath('data.membership_status', 'none')
        ->assertJsonPath('data.payments', [])
        ->assertJsonPath('data.membership_grants', [])
        ->assertJsonPath('data.nostr_profile', null);

    expect(EinundzwanzigPleb::query()->where('pubkey', $call['pubkey'])->exists())->toBeFalse();
});

it('buys its completeness without weakening $hidden or any other response', function () {
    /*
     * The export reads the attributes off the model directly; `$hidden` only
     * ever affected toArray()/toJson(). So the two do not fight, and this test
     * pins that the price of the export was not paid somewhere else: the model
     * still hides the three fields, and /me still does not carry them.
     */
    $subject = exportSubject();

    expect($subject['pleb']->toArray())
        ->not->toHaveKeys(['email', 'application_text', 'archived_application_text']);

    $me = apiV1SignedGet('/api/v1/membership/me', EXP_CLIENT_KEY, $subject['privkey'])['response'];

    $me->assertOk();

    expect($me->getContent())
        ->not->toContain('mine@example.test')
        ->not->toContain('my own application prose')
        ->not->toContain('my archived application prose');
});
