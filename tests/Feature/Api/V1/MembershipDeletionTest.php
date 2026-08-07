<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use App\Models\Profile;
use App\Support\NostrAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

/*
 * THE STRICTEST COVERAGE ON THIS SURFACE (audit item 8): DELETE /me was the
 * most valuable target of the two authentication holes P3 closed, and it is
 * the one endpoint whose mistakes are irreversible in both directions —
 * deleting too much destroys the association's books, deleting too little
 * leaves the person on file after they asked to be gone.
 */

const DEL_CLIENT_KEY = 'del111111111111111111111111111111111111111111111111111111del11';

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => DEL_CLIENT_KEY],
        'einundzwanzig.config.currency' => 'SATS',
        'app.debug' => false,
    ]);
});

/**
 * A member with everything a deletion has to reason about: contact data, both
 * application texts, two settled annual fees, the grant those fees caused and
 * a cached Nostr profile.
 *
 * @return array{pleb: EinundzwanzigPleb, grant: MembershipGrant, privkey: string, pubkey: string}
 */
function deletionSubject(): array
{
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    $pleb = EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
        'association_status' => AssociationStatus::PASSIVE,
        'email' => 'erase-me@example.test',
        'no_email' => false,
        'nip05_handle' => 'erase-me@einundzwanzig.space',
        'application_text' => 'the prose I want erased',
        'archived_application_text' => 'the archived prose I want erased',
        'statutes_accepted_at' => Carbon::parse('2026-03-01 12:00:00'),
        'applied_at' => Carbon::parse('2026-03-01 12:00:00'),
    ]);

    $current = PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'btc_pay_invoice' => 'inv-current',
    ]);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year - 1,
        'amount' => 21000,
        'btc_pay_invoice' => 'inv-previous',
    ]);

    $grant = MembershipGrant::create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'payment_event_id' => $current->id,
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

    return ['pleb' => $pleb, 'grant' => $grant, 'privkey' => $privkey, 'pubkey' => $pubkey];
}

/**
 * @return array{response: TestResponse, pubkey: string, privkey: string}
 */
function deleteCall(?string $privkey = null): array
{
    return apiV1SignedRequest('DELETE', '/api/v1/membership/me', DEL_CLIENT_KEY, $privkey);
}

it('requires a NIP-98 signature', function () {
    $subject = deletionSubject();

    $this->withHeaders([
        'X-Api-Key' => DEL_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->delete('/api/v1/membership/me')->assertUnauthorized();

    expect($subject['pleb']->fresh()->email)->toBe('erase-me@example.test');
});

it('requires a client key', function () {
    $subject = deletionSubject();
    $signed = makeNip98Event(url('/api/v1/membership/me'), 'DELETE');

    $this->withHeaders([
        'Authorization' => $signed['header'],
        'Accept' => 'application/json',
    ])->delete('/api/v1/membership/me')->assertUnauthorized();

    expect($subject['pleb']->fresh()->email)->toBe('erase-me@example.test');
});

it('erases the person and keeps the books', function () {
    $subject = deletionSubject();
    $sumBefore = (int) PaymentEvent::query()->where('year', (int) now()->year)->sum('amount');

    $call = deleteCall($subject['privkey']);

    $call['response']->assertOk()
        ->assertJsonPath('data.erased', true)
        ->assertJsonPath('data.retained_payments', 2);

    $row = EinundzwanzigPleb::query()->find($subject['pleb']->id);

    // Nothing personal is left …
    expect($row)->not->toBeNull()
        ->and($row->email)->toBeNull()
        ->and($row->application_text)->toBeNull()
        ->and($row->archived_application_text)->toBeNull()
        ->and($row->nip05_handle)->toBeNull()
        ->and((bool) $row->no_email)->toBeTrue()
        // … and the identity itself is gone, replaced rather than emptied
        // because the columns are not nullable and the pubkey is unique. The
        // tombstone can never match a pubkey, so no signature reaches this row
        // again.
        ->and($row->pubkey)->not->toBe($subject['pubkey'])
        ->and($row->pubkey)->toStartWith('deleted-')
        ->and($row->pubkey)->not->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->npub)->toStartWith('deleted-');

    // … while the booking is untouched, down to the last satoshi.
    expect((int) PaymentEvent::query()->where('year', (int) now()->year)->sum('amount'))->toBe($sumBefore)
        ->and(PaymentEvent::query()->where('einundzwanzig_pleb_id', $row->id)->count())->toBe(2)
        ->and((bool) PaymentEvent::query()->where('year', (int) now()->year)->value('paid'))->toBeTrue();
});

it('leaves no trace of the erased person anywhere in the database', function () {
    $subject = deletionSubject();

    deleteCall($subject['privkey'])['response']->assertOk();

    /*
     * Read raw, past the model: the e-mail is encrypted at rest, so an ORM
     * read could report "null" while the ciphertext sat in the column. And the
     * blind index lives in a table of its own, where a searchable hash of the
     * address would survive unnoticed — it is a personal reference like any
     * other and has to go with the rest.
     */
    $raw = DB::table('einundzwanzig_plebs')->where('id', $subject['pleb']->id)->first();

    expect($raw->email)->toBeNull()
        ->and($raw->application_text)->toBeNull()
        ->and($raw->archived_application_text)->toBeNull()
        ->and($raw->nip05_handle)->toBeNull()
        ->and(DB::table('blind_indexes')
            ->where('indexable_id', $subject['pleb']->id)
            ->where('indexable_type', EinundzwanzigPleb::class)
            ->count())->toBe(0)
        // The cached kind-0 profile is a copy of what the person published
        // themselves and nothing in the books depends on it.
        ->and(Profile::query()->where('pubkey', $subject['pubkey'])->exists())->toBeFalse();
});

it('keeps the membership grant consistent with the payment it names', function () {
    $subject = deletionSubject();

    deleteCall($subject['privkey'])['response']->assertOk();

    $grant = MembershipGrant::query()->find($subject['grant']->id);

    /*
     * The grant answers "which payment constituted this membership?" — a
     * bookkeeping question, and the reason the row exists at all. It holds no
     * personal data, so it stays, and both ends of it must still resolve:
     * deleting the member row would have taken it and the fees with it
     * (cascadeOnDelete), which is precisely why erasure is not a deletion.
     */
    expect($grant)->not->toBeNull()
        ->and($grant->einundzwanzig_pleb_id)->toBe($subject['pleb']->id)
        ->and($grant->paymentEvent)->not->toBeNull()
        ->and((int) $grant->paymentEvent->einundzwanzig_pleb_id)->toBe($subject['pleb']->id)
        ->and($grant->pleb)->not->toBeNull()
        ->and($grant->to_status)->toBe(AssociationStatus::PASSIVE);

    // The category stays too: lowering it is a board decision, never a side
    // effect of a data-protection request — and the anonymised row can
    // exercise nothing anyway, because no signature reaches it.
    expect(EinundzwanzigPleb::query()->find($subject['pleb']->id)->association_status)
        ->toBe(AssociationStatus::PASSIVE);
});

it('drops the erased member out of the public paid-members list', function () {
    /*
     * THE RE-IDENTIFICATION NOBODY SAW COMING, and it needed no privileged
     * access at all. `GET /api/members/{year}` is unauthenticated and publishes
     * the internal `id`. The erased row keeps its settled fee — that is the
     * whole point of anonymising instead of deleting — so it went on matching
     * "has paid for year X" and stayed in the list with the SAME id and a
     * tombstone where the npub had been. Two snapshots, taken before and after,
     * line up on that id and undo the erasure retroactively.
     */
    $subject = deletionSubject();
    $year = (int) now()->year;

    $before = $this->getJson('/api/members/'.$year);
    $before->assertOk();

    expect(collect($before->json())->pluck('id'))->toContain($subject['pleb']->id);

    deleteCall($subject['privkey'])['response']->assertOk();

    $after = $this->getJson('/api/members/'.$year);
    $after->assertOk();

    $idsBefore = collect($before->json())->pluck('id')->all();
    $idsAfter = collect($after->json())->pluck('id')->all();

    // The id appears in NEITHER answer any more — not in the second one, and
    // therefore in no pair of snapshots that could be joined on it.
    expect($idsAfter)->not->toContain($subject['pleb']->id)
        ->and(array_intersect($idsBefore, $idsAfter))->toBe([]);

    expect($after->getContent())
        ->not->toContain('deleted-')
        ->not->toContain($subject['pleb']->npub)
        ->not->toContain('erase-me');
});

it('keeps everyone else in the public paid-members list', function () {
    /*
     * The discriminating half: an exclusion that simply emptied the list would
     * satisfy the test above and break an endpoint with external consumers.
     */
    $subject = deletionSubject();
    $year = (int) now()->year;

    $keeper = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::PASSIVE,
        'nip05_handle' => 'keeper',
    ]);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $keeper->id,
        'year' => $year,
        'amount' => 21000,
    ]);

    deleteCall($subject['privkey'])['response']->assertOk();

    $after = $this->getJson('/api/members/'.$year);

    $after->assertOk();

    expect(collect($after->json())->pluck('id'))->toContain($keeper->id)
        ->and($after->json())->toHaveCount(1);
});

it('strips the pointers that lead back out of the house', function () {
    /*
     * The booking has to survive — year, amount, settled. Its two REFERENCES
     * must not:
     *
     *  - `event_id` names a public kind-32121 event whose `d` tag is
     *    "<pubkey>,<year>" in the clear (PublishPaymentEventToNostr:70), so
     *    the id alone reads the pubkey off any relay.
     *  - `btc_pay_invoice` names an invoice carrying posData.pubkey,
     *    posData.npub and an itemDesc naming the npub — in the association's
     *    OWN system, and therefore within reach of an erasure request.
     */
    $subject = deletionSubject();

    PaymentEvent::query()
        ->where('einundzwanzig_pleb_id', $subject['pleb']->id)
        ->update(['event_id' => 'nostr-event-naming-the-pubkey']);

    $sumBefore = (int) PaymentEvent::query()->sum('amount');

    deleteCall($subject['privkey'])['response']->assertOk();

    $payments = PaymentEvent::query()->where('einundzwanzig_pleb_id', $subject['pleb']->id)->get();

    expect($payments)->toHaveCount(2)
        ->and($payments->pluck('event_id')->filter()->all())->toBe([])
        ->and($payments->pluck('btc_pay_invoice')->filter()->all())->toBe([])
        // …and the entry itself is untouched, which is the point of keeping it.
        ->and((int) PaymentEvent::query()->sum('amount'))->toBe($sumBefore)
        ->and($payments->every(fn (PaymentEvent $event): bool => (bool) $event->paid))->toBeTrue()
        ->and($payments->pluck('year')->sort()->values()->all())
        ->toBe([(int) now()->year - 1, (int) now()->year]);
});

it('answers a repeated erasure the same way and changes nothing more', function () {
    $subject = deletionSubject();

    $first = deleteCall($subject['privkey']);
    $first['response']->assertOk()->assertJsonPath('data.erased', true);

    $tombstone = EinundzwanzigPleb::query()->find($subject['pleb']->id)->pubkey;
    $rowsBefore = EinundzwanzigPleb::query()->count();

    $second = deleteCall($subject['privkey']);

    /*
     * Idempotent by status as well as by outcome. What was asked for is a
     * postcondition — "nothing personal of this pubkey is stored" — and after
     * the first call it is true and stays true. A 404 would turn a fulfilled
     * request into an error and leave a client retrying after a timeout unable
     * to tell "already done" from "failed".
     */
    $second['response']->assertOk()
        ->assertJsonPath('data.erased', true)
        /*
         * NULL, NOT ZERO. The two annual fees do survive, so zero was a false
         * data-protection statement to a client that merely retried after a
         * timeout. Null says "not determinable any more" — and it cannot say
         * anything else: reaching that row again would need the recomputable
         * link the erasure destroyed on purpose.
         */
        ->assertJsonPath('data.retained_payments', null);

    expect($first['response']->json('data.retained_payments'))->toBe(2);

    expect(EinundzwanzigPleb::query()->count())->toBe($rowsBefore)
        // The second call did not mint a new tombstone over the old one.
        ->and(EinundzwanzigPleb::query()->find($subject['pleb']->id)->pubkey)->toBe($tombstone)
        ->and(PaymentEvent::query()->count())->toBe(2)
        ->and(MembershipGrant::query()->count())->toBe(1);
});

it('erases the caller and never a bystander', function () {
    $subject = deletionSubject();

    $bystanderPrivkey = (new Key)->generatePrivateKey();
    $bystanderPubkey = (new Key)->getPublicKey($bystanderPrivkey);

    $bystander = EinundzwanzigPleb::factory()->create([
        'pubkey' => $bystanderPubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($bystanderPubkey),
        'email' => 'bystander@example.test',
        'application_text' => 'the bystander’s prose',
    ]);

    deleteCall($subject['privkey'])['response']->assertOk();

    $untouched = $bystander->fresh();

    expect($untouched->pubkey)->toBe($bystanderPubkey)
        ->and($untouched->email)->toBe('bystander@example.test')
        ->and($untouched->application_text)->toBe('the bystander’s prose');
});

it('refuses to erase on behalf of somebody else', function () {
    /*
     * The subject is not a parameter, so there is no "somebody else" to name —
     * but a client that tries anyway must be refused rather than quietly
     * served its own record, and nothing may be erased on the way.
     */
    $subject = deletionSubject();
    $victim = EinundzwanzigPleb::factory()->create(['email' => 'victim@example.test']);

    $signed = makeNip98Event(
        url('/api/v1/membership/me?pubkey='.$victim->pubkey),
        'DELETE',
        createdAt: now()->timestamp,
        privkey: $subject['privkey'],
    );

    $this->withHeaders([
        'X-Api-Key' => DEL_CLIENT_KEY,
        'Authorization' => $signed['header'],
        'Accept' => 'application/json',
    ])->delete('/api/v1/membership/me?pubkey='.$victim->pubkey)
        ->assertForbidden();

    expect($victim->fresh()->email)->toBe('victim@example.test')
        ->and($subject['pleb']->fresh()->email)->toBe('erase-me@example.test');
});

it('lets the same person start over as a stranger', function () {
    /*
     * THE CHOSEN BEHAVIOUR FOR A LOGIN AFTER ERASURE. `NostrAuth::ensurePleb()`
     * creates a record for any verified pubkey, so the question is not whether
     * one appears but what it contains — and the answer is: nothing. Erasure
     * is not a ban, and the association has no lawful basis for keeping a list
     * of pubkeys it refuses to serve. The old booking stays behind, anonymised
     * and unreachable; the random tombstone means nothing can ever link the
     * two again.
     */
    $subject = deletionSubject();

    deleteCall($subject['privkey'])['response']->assertOk();

    $challenge = NostrAuth::issueChallenge();
    ['event' => $event] = makeSignedLoginEvent($challenge, privkey: $subject['privkey']);

    expect(NostrAuth::loginWithSignedEvent($event))->toBe($subject['pubkey']);

    $fresh = EinundzwanzigPleb::query()->where('pubkey', $subject['pubkey'])->firstOrFail();

    expect($fresh->id)->not->toBe($subject['pleb']->id)
        ->and($fresh->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and($fresh->email)->toBeNull()
        ->and($fresh->application_text)->toBeNull()
        ->and($fresh->applied_at)->toBeNull()
        ->and($fresh->statutes_accepted_at)->toBeNull()
        ->and($fresh->paymentEvents()->count())->toBe(0);

    // And the endpoints agree: the returning person is a stranger.
    apiV1SignedGet('/api/v1/membership/me', DEL_CLIENT_KEY, $subject['privkey'])['response']
        ->assertOk()
        ->assertJsonPath('data.membership_status', 'none')
        ->assertJsonPath('data.association_status', 'DEFAULT');
});

it('leaves nothing of the erased record reachable through the API', function () {
    $subject = deletionSubject();

    deleteCall($subject['privkey'])['response']->assertOk();

    $export = apiV1SignedGet('/api/v1/membership/export', DEL_CLIENT_KEY, $subject['privkey'])['response'];
    $payments = apiV1SignedGet('/api/v1/membership/payments', DEL_CLIENT_KEY, $subject['privkey'])['response'];

    $export->assertOk()
        ->assertJsonPath('data.member', null)
        ->assertJsonPath('data.subject.npub', null)
        ->assertJsonPath('data.payments', [])
        ->assertJsonPath('data.nostr_profile', null);

    $payments->assertOk()->assertJsonPath('data', []);

    foreach ([$export, $payments] as $response) {
        expect($response->getContent())
            ->not->toContain('erase-me@example.test')
            ->not->toContain('the prose I want erased')
            ->not->toContain('the archived prose I want erased')
            ->not->toContain('erase-me@einundzwanzig.space')
            ->not->toContain('my-nostr-name')
            ->not->toContain($subject['pleb']->npub);
    }
});

it('returns only the allowed fields and never personal data', function () {
    $subject = deletionSubject();

    $call = deleteCall($subject['privkey']);

    $call['response']->assertOk();

    expect($call['response']->json('data'))
        ->toHaveKeys(['erased', 'retained_payments'])
        ->not->toHaveKeys([
            'id',
            'pubkey',
            'npub',
            'email',
            'application_text',
            'archived_application_text',
        ]);

    expect($call['response']->getContent())
        ->not->toContain('erase-me@example.test')
        ->not->toContain('the prose I want erased')
        ->not->toContain('the archived prose I want erased')
        ->not->toContain($subject['pubkey']);
});
