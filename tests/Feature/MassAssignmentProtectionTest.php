<?php

declare(strict_types=1);

use App\Enums\AssociationStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\CourseEvent;
use App\Models\EinundzwanzigPleb;
use App\Models\Event;
use App\Models\Lecturer;
use App\Models\Meetup;
use App\Models\MeetupEvent;
use App\Models\Notification;
use App\Models\PaymentEvent;
use App\Models\Profile;
use App\Models\ProjectProposal;
use App\Models\RenderedEvent;
use App\Models\Venue;
use App\Models\Vote;
use Illuminate\Support\Facades\Http;
use swentel\nostr\Key\Key as NostrKey;

it('ensures no model uses guarded empty array', function () {
    $models = [
        PaymentEvent::class,
        EinundzwanzigPleb::class,
        Vote::class,
        ProjectProposal::class,
        Venue::class,
        MeetupEvent::class,
        CourseEvent::class,
        Course::class,
        Meetup::class,
        Lecturer::class,
        City::class,
        Event::class,
        RenderedEvent::class,
        Profile::class,
        Category::class,
        Country::class,
        Notification::class,
    ];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        $property = $reflection->getProperty('guarded');
        $instance = $reflection->newInstanceWithoutConstructor();
        $guarded = $property->getValue($instance);

        expect($guarded)
            ->not->toBe([], "{$modelClass} still uses \$guarded = [] which is insecure");
    }
});

it('ensures all models have explicit fillable arrays', function () {
    $models = [
        PaymentEvent::class,
        EinundzwanzigPleb::class,
        Vote::class,
        ProjectProposal::class,
        Venue::class,
        MeetupEvent::class,
        CourseEvent::class,
        Course::class,
        Meetup::class,
        Lecturer::class,
        City::class,
        Event::class,
        RenderedEvent::class,
        Profile::class,
        Category::class,
        Country::class,
        Notification::class,
    ];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        $property = $reflection->getProperty('fillable');
        $instance = $reflection->newInstanceWithoutConstructor();

        expect($property->getValue($instance))
            ->toBeArray("{$modelClass} should have an explicit \$fillable array");
    }
});

it('blocks mass assignment of einundzwanzig_pleb_id on PaymentEvent', function () {
    $paymentEvent = new PaymentEvent;
    $paymentEvent->fill(['einundzwanzig_pleb_id' => 999]);

    expect($paymentEvent->einundzwanzig_pleb_id)->toBeNull();
});

it('verifies EinundzwanzigPleb fillable does not contain application_for or association_status', function () {
    $reflection = new ReflectionClass(EinundzwanzigPleb::class);
    $property = $reflection->getProperty('fillable');
    $instance = $reflection->newInstanceWithoutConstructor();
    $fillable = $property->getValue($instance);

    expect($fillable)->not->toContain('application_for')
        ->not->toContain('association_status')
        ->not->toContain('id')
        ->toContain('npub')
        ->toContain('pubkey')
        ->toContain('email')
        ->toContain('no_email')
        ->toContain('nip05_handle')
        ->toContain('statutes_accepted_at')
        ->toContain('applied_at');
});

it('blocks mass assignment of association_status on EinundzwanzigPleb via fill', function () {
    $pleb = new EinundzwanzigPleb;
    $pleb->fill([
        'npub' => 'npub1test',
        'association_status' => AssociationStatus::HONORARY->value,
        'application_for' => AssociationStatus::HONORARY->value,
    ]);

    expect($pleb->npub)->toBe('npub1test')
        ->and($pleb->getAttributes())->not->toHaveKey('association_status')
        ->and($pleb->getAttributes())->not->toHaveKey('application_for');
});

it('blocks mass assignment of association_status on EinundzwanzigPleb via create', function () {
    $pleb = EinundzwanzigPleb::query()->create([
        'npub' => 'npub1masscreate',
        'pubkey' => str_repeat('a', 64),
        'association_status' => AssociationStatus::HONORARY->value,
    ]);

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::DEFAULT);
});

it('blocks mass assignment of association_status on EinundzwanzigPleb via update', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
    ]);

    $pleb->update([
        'nip05_handle' => 'someone@example.com',
        'association_status' => AssociationStatus::HONORARY->value,
    ]);

    $fresh = $pleb->fresh();

    expect($fresh->nip05_handle)->toBe('someone@example.com')
        ->and($fresh->association_status)->toBe(AssociationStatus::DEFAULT);
});

it('blocks mass assignment of accepted and sats_paid on ProjectProposal', function () {
    $proposal = new ProjectProposal;
    $proposal->fill([
        'name' => 'Test',
        'accepted' => true,
        'sats_paid' => 100000,
        'einundzwanzig_pleb_id' => 1,
        'slug' => 'injected-slug',
    ]);

    expect($proposal->accepted)->toBeNull()
        ->and($proposal->sats_paid)->toBeNull()
        ->and($proposal->einundzwanzig_pleb_id)->toBeNull()
        ->and($proposal->slug)->toBeNull()
        ->and($proposal->name)->toBe('Test');
});

it('blocks mass assignment of created_by and slug on Venue', function () {
    $venue = new Venue;
    $venue->fill([
        'name' => 'Test Venue',
        'created_by' => 999,
        'slug' => 'injected-slug',
    ]);

    expect($venue->name)->toBe('Test Venue')
        ->and($venue->created_by)->toBeNull()
        ->and($venue->slug)->toBeNull();
});

it('blocks mass assignment of meetup_id and created_by on MeetupEvent', function () {
    $event = new MeetupEvent;
    $event->fill([
        'start' => '2025-01-01',
        'meetup_id' => 999,
        'created_by' => 999,
        'attendees' => ['a'],
    ]);

    expect($event->start)->not->toBeNull()
        ->and($event->meetup_id)->toBeNull()
        ->and($event->created_by)->toBeNull()
        ->and($event->attendees)->toBeNull();
});

it('blocks mass assignment of course_id venue_id and created_by on CourseEvent', function () {
    $event = new CourseEvent;
    $event->fill([
        'from' => '2025-01-01',
        'to' => '2025-01-02',
        'course_id' => 999,
        'venue_id' => 999,
        'created_by' => 999,
    ]);

    expect($event->from)->not->toBeNull()
        ->and($event->to)->not->toBeNull()
        ->and($event->course_id)->toBeNull()
        ->and($event->venue_id)->toBeNull()
        ->and($event->created_by)->toBeNull();
});

it('blocks mass assignment of lecturer_id and created_by on Course', function () {
    $course = new Course;
    $course->fill([
        'name' => 'Test Course',
        'description' => 'Test',
        'lecturer_id' => 999,
        'created_by' => 999,
    ]);

    expect($course->name)->toBe('Test Course')
        ->and($course->description)->toBe('Test')
        ->and($course->lecturer_id)->toBeNull()
        ->and($course->created_by)->toBeNull();
});

it('blocks mass assignment of city_id created_by and slug on Meetup', function () {
    $meetup = new Meetup;
    $meetup->fill([
        'name' => 'Test Meetup',
        'city_id' => 999,
        'created_by' => 999,
        'slug' => 'injected',
        'github_data' => '{}',
        'simplified_geojson' => '{}',
    ]);

    expect($meetup->name)->toBe('Test Meetup')
        ->and($meetup->city_id)->toBeNull()
        ->and($meetup->created_by)->toBeNull()
        ->and($meetup->slug)->toBeNull();
});

it('blocks mass assignment of active created_by and slug on Lecturer', function () {
    $lecturer = new Lecturer;
    $lecturer->fill([
        'name' => 'Test Lecturer',
        'active' => true,
        'created_by' => 999,
        'slug' => 'injected',
    ]);

    expect($lecturer->name)->toBe('Test Lecturer')
        ->and($lecturer->active)->toBeNull()
        ->and($lecturer->created_by)->toBeNull()
        ->and($lecturer->slug)->toBeNull();
});

it('blocks mass assignment of country_id created_by and slug on City', function () {
    $city = new City;
    $city->fill([
        'name' => 'Test City',
        'country_id' => 999,
        'created_by' => 999,
        'slug' => 'injected',
        'osm_relation' => '{}',
        'simplified_geojson' => '{}',
    ]);

    expect($city->name)->toBe('Test City')
        ->and($city->country_id)->toBeNull()
        ->and($city->created_by)->toBeNull()
        ->and($city->slug)->toBeNull();
});

it('blocks mass assignment of einundzwanzig_pleb_id and category on Notification', function () {
    $notification = new Notification;
    $notification->fill([
        'name' => 'Test News',
        'description' => 'Test',
        'einundzwanzig_pleb_id' => 999,
        'category' => 1,
    ]);

    expect($notification->name)->toBe('Test News')
        ->and($notification->description)->toBe('Test')
        ->and($notification->einundzwanzig_pleb_id)->toBeNull()
        ->and($notification->category)->toBeNull();
});

it('blocks mass assignment of code and language_codes on Country', function () {
    $country = new Country;
    $country->fill([
        'name' => 'Test',
        'code' => 'XX',
        'language_codes' => ['en'],
    ]);

    expect($country->name)->toBe('Test')
        ->and($country->code)->toBeNull()
        ->and($country->language_codes)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The same guarantees, one layer out: through the public /api/v1 endpoints.
|--------------------------------------------------------------------------
|
| The tests above prove the MODEL refuses these fields. These prove the
| ENDPOINTS never hand them over — a controller that passed `validated()`
| wholesale into `fill()` would still be stopped by `$fillable`, but a
| controller that assigned an attribute explicitly would not, and the writing
| surface is where that mistake gets made.
*/

const MASS_CLIENT_KEY = 'mass111111111111111111111111111111111111111111111111111mass111';

/**
 * @return array{privkey: string, pubkey: string, pleb: EinundzwanzigPleb}
 */
function massSubject(): array
{
    config(['einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => MASS_CLIENT_KEY]]);

    $privkey = (new NostrKey)->generatePrivateKey();
    $pubkey = (new NostrKey)->getPublicKey($privkey);

    $pleb = EinundzwanzigPleb::factory()->create([
        'pubkey' => $pubkey,
        'npub' => (new NostrKey)->convertPublicKeyToBech32($pubkey),
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
    ]);

    return ['privkey' => $privkey, 'pubkey' => $pubkey, 'pleb' => $pleb];
}

it('ignores paid and association_status sent to POST /api/v1/membership/applications', function () {
    $subject = massSubject();

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $subject['pleb']->id,
        'year' => (int) now()->year,
        'paid' => false,
    ]);

    $response = apiV1SignedRequest(
        'POST',
        '/api/v1/membership/applications',
        MASS_CLIENT_KEY,
        $subject['privkey'],
        [
            'statutes_accepted' => true,
            'association_status' => AssociationStatus::HONORARY->value,
            'application_for' => AssociationStatus::HONORARY->value,
            'paid' => true,
        ],
    )['response'];

    $response->assertOk();

    $pleb = $subject['pleb']->fresh();

    expect($pleb->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and($pleb->application_for)->toBeNull()
        ->and((bool) $pleb->paymentEvents()->where('year', (int) now()->year)->value('paid'))->toBeFalse();
});

it('refuses an application naming a foreign pubkey and writes nothing', function () {
    $subject = massSubject();
    $victim = EinundzwanzigPleb::factory()->create([
        'application_text' => 'the victim’s own prose',
    ]);

    $response = apiV1SignedRequest(
        'POST',
        '/api/v1/membership/applications',
        MASS_CLIENT_KEY,
        $subject['privkey'],
        [
            'statutes_accepted' => true,
            'pubkey' => $victim->pubkey,
            'application_text' => 'written in somebody else’s name',
        ],
    )['response'];

    $response->assertForbidden();

    expect($victim->fresh()->application_text)->toBe('the victim’s own prose')
        ->and($subject['pleb']->fresh()->application_text)->toBeNull()
        ->and($subject['pleb']->fresh()->applied_at)->toBeNull();
});

it('refuses an application naming a foreign npub and writes nothing', function () {
    $subject = massSubject();
    $strangerPubkey = (new NostrKey)->getPublicKey((new NostrKey)->generatePrivateKey());

    $response = apiV1SignedRequest(
        'POST',
        '/api/v1/membership/applications',
        MASS_CLIENT_KEY,
        $subject['privkey'],
        [
            'statutes_accepted' => true,
            'npub' => (new NostrKey)->convertPublicKeyToBech32($strangerPubkey),
        ],
    )['response'];

    $response->assertForbidden();

    expect($subject['pleb']->fresh()->applied_at)->toBeNull();
});

it('keeps the signed pubkey and npub on the record an application creates', function () {
    $privkey = (new NostrKey)->generatePrivateKey();
    $pubkey = (new NostrKey)->getPublicKey($privkey);

    config(['einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => MASS_CLIENT_KEY]]);

    /*
     * The identity of a new record comes from the signature. Sending one's OWN
     * pubkey passes the claim guard, so this is the case where a controller
     * could quietly take the identity from the body instead — and where a
     * differing npub in the same body would end up on the record.
     */
    $response = apiV1SignedRequest(
        'POST',
        '/api/v1/membership/applications',
        MASS_CLIENT_KEY,
        $privkey,
        [
            'statutes_accepted' => true,
            'pubkey' => $pubkey,
            'npub' => 'npub1thisisnotmyrealnpubatall',
        ],
    )['response'];

    // The npub does not start with the signer's, so the claim guard refuses
    // before anything is written.
    $response->assertForbidden();

    expect(EinundzwanzigPleb::query()->where('pubkey', $pubkey)->exists())->toBeFalse();
});

it('ignores paid and association_status sent to the invoice endpoint', function () {
    $subject = massSubject();

    config([
        'services.btc_pay.base_url' => 'https://pay.einundzwanzig.space',
        'services.btc_pay.store_id' => 'test-store',
    ]);

    Http::fake(['pay.einundzwanzig.space/*' => Http::response([
        'id' => 'inv-new',
        'checkoutLink' => 'https://pay.einundzwanzig.space/i/inv-new',
    ])]);

    $response = apiV1SignedRequest(
        'POST',
        '/api/v1/membership/payments/'.now()->year.'/invoice',
        MASS_CLIENT_KEY,
        $subject['privkey'],
        [
            'paid' => true,
            'association_status' => AssociationStatus::HONORARY->value,
        ],
    )['response'];

    $response->assertOk()->assertJsonPath('data.payment.paid', false);

    expect($subject['pleb']->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and((bool) PaymentEvent::query()->where('einundzwanzig_pleb_id', $subject['pleb']->id)->value('paid'))
        ->toBeFalse();
});

it('allows fillable fields on PaymentEvent', function () {
    $paymentEvent = new PaymentEvent;
    $paymentEvent->fill([
        'year' => 2025,
        'event_id' => 'test-event',
        'amount' => 21000,
        'paid' => true,
        'btc_pay_invoice' => 'inv-123',
    ]);

    expect($paymentEvent->year)->toBe(2025)
        ->and($paymentEvent->event_id)->toBe('test-event')
        ->and($paymentEvent->amount)->toBe(21000)
        ->and($paymentEvent->paid)->toBeTrue()
        ->and($paymentEvent->btc_pay_invoice)->toBe('inv-123');
});
