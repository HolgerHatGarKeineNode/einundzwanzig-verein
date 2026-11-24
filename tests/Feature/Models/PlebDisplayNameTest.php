<?php

use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Blade;
use swentel\nostr\Key\Key;

/*
 * One rule for printing a member on a page: profile name, else display
 * name, else the shortened npub; an erased member is a former member.
 * Before, news, the project card, the project page, the members list and
 * the profile page each had their own order and their own cut of the npub.
 */

function plebWithProfile(?string $name, ?string $displayName): EinundzwanzigPleb
{
    $pleb = EinundzwanzigPleb::factory()->create();
    Profile::factory()->create(['pubkey' => $pleb->pubkey, 'name' => $name, 'display_name' => $displayName]);

    return $pleb->load('profile');
}

it('prints the profile name, then the display name, then the short npub', function (?string $name, ?string $displayName, ?string $expected) {
    $pleb = plebWithProfile($name, $displayName);

    expect($pleb->displayName())->toBe($expected ?? $pleb->shortNpub())
        ->and(Blade::render('<x-pleb-name :pleb="$pleb" />', ['pleb' => $pleb]))->toContain(e($expected ?? $pleb->shortNpub()));
})->with([
    'both' => ['Satoshi', 'Sato', 'Satoshi'],
    'display name only' => [null, 'Sato', 'Sato'],
    'blank name' => ['  ', 'Sato', 'Sato'],
    'neither' => [null, '', null],
]);

it('prints the short npub without a profile', function () {
    $pubkey = str_repeat('ab', 32);
    $npub = (new Key)->convertPublicKeyToBech32($pubkey);
    $pleb = EinundzwanzigPleb::factory()->create(['pubkey' => $pubkey, 'npub' => $npub]);

    expect($pleb->displayName())->toBe(mb_substr($npub, 0, 12).'…'.mb_substr($npub, -6))
        ->and(mb_strlen($pleb->shortNpub()))->toBe(19);
});

it('prints an erased member as a former member', function () {
    $pleb = plebWithProfile('Satoshi', 'Sato');
    app(MembershipService::class)->erasePersonalData($pleb);

    expect($pleb->fresh()->displayName())->toBe('Ehemaliges Mitglied')
        ->and(Blade::render('<x-pleb-name :pleb="null" />'))->toContain('Ehemaliges Mitglied');
});

it('leaves a short value uncut', function () {
    expect(EinundzwanzigPleb::shortenNpub('npub1short'))->toBe('npub1short');
});

it('labels a profile by name, then display name, else nothing', function () {
    expect((new Profile(['name' => 'A', 'display_name' => 'B']))->label())->toBe('A')
        ->and((new Profile(['name' => '', 'display_name' => 'B']))->label())->toBe('B')
        ->and((new Profile(['name' => null, 'display_name' => ' ']))->label())->toBeNull();
});
