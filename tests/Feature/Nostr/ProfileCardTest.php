<?php

use App\Models\EinundzwanzigPleb;
use App\Models\PlebDmRelay;
use App\Models\Profile;
use App\Support\NostrAuth;
use Einundzwanzig\Group\Nostr\ProfileCache;
use swentel\nostr\Key\Key;

require_once __DIR__.'/../Services/Election/helpers.php';

/**
 * The card behind every Nostr name chip: links and profile checks for
 * signed-in users, membership rows for the board only. The relay lookup is
 * replaced by a stub, no test dials a relay.
 */
function stubProfileCards(array $events = []): void
{
    app()->instance(ProfileCache::class, new class($events) extends ProfileCache
    {
        public function __construct(private array $events) {}

        public function get(array $pubkeys): array
        {
            return $this->events;
        }
    });
}

function kindZero(string $pubkey, array $content): stdClass
{
    return (object) ['pubkey' => $pubkey, 'kind' => 0, 'created_at' => 1_760_000_000, 'content' => json_encode($content)];
}

it('keeps the card from guests and rejects anything but a hex pubkey', function () {
    stubProfileCards();
    $member = EinundzwanzigPleb::factory()->active()->create();

    $this->get(route('nostr.profile-card', ['pubkey' => $member->pubkey]))->assertForbidden();

    NostrAuth::login($member->pubkey);

    $this->get('/association/nostr-profile/npub1notahexkey')->assertNotFound();
});

it('shows a member the relay profile with links and checks, but no membership rows', function () {
    $viewer = EinundzwanzigPleb::factory()->active()->create();
    $other = EinundzwanzigPleb::factory()->active()->create();
    stubProfileCards([kindZero($other->pubkey, [
        'name' => 'Satoshi',
        'picture' => 'https://example.com/a.png',
        'nip05' => 'satoshi@example.com',
        'lud16' => 'satoshi@getalby.com',
        'website' => 'javascript:alert(1)',
    ])]);
    NostrAuth::login($viewer->pubkey);

    $this->get(route('nostr.profile-card', ['pubkey' => $other->pubkey]))
        ->assertOk()
        ->assertSee('Satoshi')
        ->assertSee('https://njump.me/'.(new Key)->convertPublicKeyToBech32($other->pubkey), false)
        ->assertSee('data-test="check-picture" data-ok="yes"', false)
        ->assertSee('data-test="check-lightning" data-ok="yes"', false)
        ->assertSee('example.com\\/.well-known\\/nostr.json?name=satoshi', false)
        ->assertDontSee('javascript:alert', false)
        ->assertDontSee('data-test="card-portal"', false);
});

it('falls back to the portal profile and shows the board fee and DM inbox state', function () {
    [$board] = electionBoard(1);
    $member = EinundzwanzigPleb::factory()->active()->create();
    Profile::query()->updateOrCreate(['pubkey' => $member->pubkey], ['name' => 'Ida aus dem Portal', 'picture' => null]);
    PlebDmRelay::query()->create(['einundzwanzig_pleb_id' => $member->id, 'event_id' => str_repeat('e', 64), 'event_created_at' => time(), 'relays' => ['wss://relay.example']]);
    stubProfileCards();
    NostrAuth::login($board->pubkey);

    $this->get(route('nostr.profile-card', ['pubkey' => $member->pubkey]))
        ->assertOk()
        ->assertSee('Ida aus dem Portal')
        ->assertSee('data-test="check-picture" data-ok="no"', false)
        ->assertSee('data-test="card-portal"', false)
        ->assertSee('data-test="check-fee" data-ok="no"', false)
        ->assertSee('data-test="check-inbox" data-ok="yes"', false);
});

it('renders names in the lists as chips with avatar and keeps erased members plain', function () {
    $pubkey = str_repeat('a', 64);
    Profile::query()->create(['pubkey' => $pubkey, 'name' => 'Bild', 'picture' => 'https://example.com/b.png']);

    $chip = $this->blade('<x-nostr-profile :pubkey="$p" name="Bild" />', ['p' => $pubkey]);
    $chip->assertSee('data-test="nostr-profile"', false)->assertSee('https://example.com/b.png', false);

    $this->blade('<x-nostr-profile :pubkey="null" name="Ehemaliges Mitglied" />')
        ->assertSee('Ehemaliges Mitglied')
        ->assertDontSee('data-test="nostr-profile"', false);
});

it('leaves the name on the ballot plain, so a click on it still selects the candidate', function () {
    $html = (string) $this->blade('<x-nostr-profile trigger="avatar" :pubkey="$p" name="Anna" class="probe" />', ['p' => str_repeat('b', 64)]);

    expect($html)->toContain('data-test="nostr-profile"')
        ->and($html)->toMatch('#<span class="[^"]*probe[^"]*">#')
        ->and($html)->toContain('<span class="min-w-0 wrap-anywhere">Anna</span>');
    expect(substr($html, strpos($html, 'data-test="nostr-profile"'), strpos($html, '</button>') - strpos($html, 'data-test="nostr-profile"')))->not->toContain('Anna</span>');
});
