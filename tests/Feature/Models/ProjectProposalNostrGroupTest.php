<?php

use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use swentel\nostr\Key\Key;

/**
 * Deckt ausschließlich die Datenmodell-Ebene des privaten NIP-29-Chatraums ab
 * (siehe ProjectProposal::nostrGroupId() & Co.): keine Relay-Aufrufe, kein
 * Nostr-Verkehr. Policy-Tests für createChatRoom/viewChatRoom leben in
 * tests/Feature/Policies/ProjectProposalChatRoomPolicyTest.php.
 */

/**
 * Erzeugt ein frisches, gültiges npub/hex-Pubkey-Paar per bech32 — lokal
 * gerechnet, kein Netzwerk. Liefert [npub, hex].
 *
 * @return array{0: string, 1: string}
 */
function generateNpubHexPair(): array
{
    $key = new Key;
    $privateHex = $key->generatePrivateKey();
    $publicHex = $key->getPublicKey($privateHex);

    return [$key->convertPublicKeyToBech32($publicHex), $publicHex];
}

// nostrGroupId()

it('derives a deterministic room id from the proposal', function () {
    $project = ProjectProposal::factory()->create();

    expect($project->nostrGroupId())->toBe($project->nostrGroupId());
});

it('prefixes the room id with "p" to separate it from meetup rooms', function () {
    $project = ProjectProposal::factory()->create();

    expect($project->nostrGroupId())->toStartWith('p');
});

it('produces a 13 character room id', function () {
    $project = ProjectProposal::factory()->create();

    expect($project->nostrGroupId())->toHaveLength(13);
});

it('gives different proposals different room ids', function () {
    $first = ProjectProposal::factory()->create();
    $second = ProjectProposal::factory()->create();

    expect($first->nostrGroupId())->not->toBe($second->nostrGroupId());
});

it('keeps the room id stable when the proposal is renamed, even though the slug changes', function () {
    // Die Raum-ID hängt bewusst an der ID, nicht am Slug (HasSlug generiert
    // den Slug aus dem Namen neu). Sonst würde eine Umbenennung den
    // bestehenden Raum mitsamt Verlauf verwaisen lassen.
    $project = ProjectProposal::factory()->create(['name' => 'Ursprünglicher Name']);
    $roomId = $project->nostrGroupId();
    $originalSlug = $project->slug;

    $project->update(['name' => 'Ein ganz anderer Name']);
    $project->refresh();

    expect($project->slug)->not->toBe($originalSlug)
        ->and($project->nostrGroupId())->toBe($roomId);
});

// hasNostrGroup()

it('has no chat room by default', function () {
    $project = ProjectProposal::factory()->create();

    expect($project->hasNostrGroup())->toBeFalse();
});

it('has a chat room once nostr_group_h is set', function () {
    $project = ProjectProposal::factory()->create(['nostr_group_h' => 'p'.str_repeat('a', 12)]);

    expect($project->hasNostrGroup())->toBeTrue();
});

it('treats an empty string nostr_group_h as no chat room', function () {
    $project = ProjectProposal::factory()->create(['nostr_group_h' => '']);

    expect($project->hasNostrGroup())->toBeFalse();
});

// boardPubkeys() & boardNpubsUndecodable()
//
// Stand nach 2089ba4: die Vorstands-Pubkeys werden per bech32 direkt aus den
// npubs der Konfiguration gerechnet (swentel\nostr\Key\Key), nicht mehr aus
// der einundzwanzig_plebs-Tabelle gelesen. boardNpubsWithoutPubkey() gibt es
// dadurch nicht mehr; boardNpubsUndecodable() ersetzt es mit einer anderen
// Semantik (Konfigurationsfehler statt fehlender Stammdaten).

it('derives one hex pubkey per valid board npub, matching a direct bech32 decode', function () {
    [$npubA, $hexA] = generateNpubHexPair();
    [$npubB, $hexB] = generateNpubHexPair();
    config(['einundzwanzig.config.current_board' => [$npubA, $npubB]]);

    expect(ProjectProposal::boardPubkeys())->toEqualCanonicalizing([$hexA, $hexB]);
});

it('drops a board npub that fails to decode, without losing the others', function () {
    [$goodNpub, $goodHex] = generateNpubHexPair();
    config(['einundzwanzig.config.current_board' => [$goodNpub, 'npub1notarealbech32string']]);

    expect(ProjectProposal::boardPubkeys())->toBe([$goodHex]);
});

it('returns exactly one 64 character hex pubkey per configured board npub', function () {
    [$npubA] = generateNpubHexPair();
    [$npubB] = generateNpubHexPair();
    [$npubC] = generateNpubHexPair();
    config(['einundzwanzig.config.current_board' => [$npubA, $npubB, $npubC]]);

    $pubkeys = ProjectProposal::boardPubkeys();

    expect($pubkeys)->toHaveCount(3)->each->toBeNostrHexKey();
});

it('deduplicates a board npub that appears twice in the config', function () {
    [$npub, $hex] = generateNpubHexPair();
    config(['einundzwanzig.config.current_board' => [$npub, $npub]]);

    expect(ProjectProposal::boardPubkeys())->toBe([$hex]);
});

it('reports no undecodable board npubs when the configured board is valid', function () {
    [$npubA] = generateNpubHexPair();
    [$npubB] = generateNpubHexPair();
    config(['einundzwanzig.config.current_board' => [$npubA, $npubB]]);

    expect(ProjectProposal::boardNpubsUndecodable())->toBeEmpty();
});

it('lists a board npub as undecodable when it fails bech32 decoding', function () {
    [$goodNpub] = generateNpubHexPair();
    $badNpub = 'npub1notarealbech32string';
    config(['einundzwanzig.config.current_board' => [$goodNpub, $badNpub]]);

    expect(ProjectProposal::boardNpubsUndecodable())->toBe([$badNpub]);
});

// nostrGroupMemberPubkeys()

it('includes every configured board npub (decoded to hex) and the submitter', function () {
    [$npubA, $hexA] = generateNpubHexPair();
    [$npubB, $hexB] = generateNpubHexPair();
    config(['einundzwanzig.config.current_board' => [$npubA, $npubB]]);

    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);

    $pubkeys = $project->nostrGroupMemberPubkeys();

    expect($pubkeys)->toContain($hexA)
        ->toContain($hexB)
        ->toContain($submitter->pubkey)
        ->toHaveCount(3);
});

it('includes a board member even when no pleb record exists for their npub', function () {
    // Der Sinn der Umstellung auf bech32-Berechnung (Commit 2089ba4): ein
    // Vorstandsmitglied ohne Pleb-Datensatz gehört trotzdem in den Raum.
    [$npub, $hex] = generateNpubHexPair();
    config(['einundzwanzig.config.current_board' => [$npub]]);

    expect(EinundzwanzigPleb::query()->where('npub', $npub)->exists())->toBeFalse();

    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);

    expect($project->nostrGroupMemberPubkeys())->toContain($hex);
});

it('lists a board member who also submitted the proposal only once', function () {
    [$npub, $hex] = generateNpubHexPair();
    $submitter = EinundzwanzigPleb::factory()->create(['npub' => $npub, 'pubkey' => $hex]);
    config(['einundzwanzig.config.current_board' => [$npub]]);

    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);

    $pubkeys = $project->nostrGroupMemberPubkeys();

    expect($pubkeys)->toBe([$hex]);
});

it('falls back to just the board pubkeys when the submitter has no usable pubkey', function () {
    // Die Spalte pubkey ist NOT NULL, ein leerer String ist der praktisch
    // erreichbare "kein Pubkey"-Fall.
    [$npub, $hex] = generateNpubHexPair();
    config(['einundzwanzig.config.current_board' => [$npub]]);

    $submitter = EinundzwanzigPleb::factory()->create(['pubkey' => '']);
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);

    expect($project->nostrGroupMemberPubkeys())->toBe([$hex]);
});

it('drops a board npub that fails to decode from the room membership too', function () {
    [$goodNpub, $goodHex] = generateNpubHexPair();
    config(['einundzwanzig.config.current_board' => [$goodNpub, 'npub1notarealbech32string']]);

    $submitter = EinundzwanzigPleb::factory()->create();
    $project = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $submitter->id]);

    $pubkeys = $project->nostrGroupMemberPubkeys();

    expect($pubkeys)->toContain($goodHex)
        ->toContain($submitter->pubkey)
        ->toHaveCount(2);
});

// Mass assignment

it('blocks mass assignment of nostr_group_h and nostr_group_created_at on ProjectProposal', function () {
    $proposal = new ProjectProposal;
    $proposal->fill([
        'name' => 'Test',
        'nostr_group_h' => 'injected',
        'nostr_group_created_at' => now(),
    ]);

    expect($proposal->nostr_group_h)->toBeNull()
        ->and($proposal->nostr_group_created_at)->toBeNull()
        ->and($proposal->name)->toBe('Test');
});

it('blocks mass assignment of nostr_group_h and nostr_group_created_at via create()', function () {
    $submitter = EinundzwanzigPleb::factory()->create();

    $proposal = new ProjectProposal;
    $proposal->einundzwanzig_pleb_id = $submitter->id; // nicht fillable, direkt gesetzt
    $proposal->fill([
        'name' => 'Test',
        'description' => 'Test',
        'support_in_sats' => 1000,
        'nostr_group_h' => 'injected',
        'nostr_group_created_at' => now(),
    ]);
    $proposal->save();

    expect($proposal->fresh()->nostr_group_h)->toBeNull()
        ->and($proposal->fresh()->nostr_group_created_at)->toBeNull();
});
