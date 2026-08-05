<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;

beforeEach(function () {
    PaymentEvent::query()->delete();
    EinundzwanzigPleb::query()->delete();
});

test('returns paid members for a specific year', function () {
    $member1 = EinundzwanzigPleb::factory()->create([
        'npub' => 'npub1abc',
        'pubkey' => 'pubkey1',
        'nip05_handle' => 'user1@example.com',
        'association_status' => AssociationStatus::ACTIVE,
    ]);

    $member2 = EinundzwanzigPleb::factory()->create([
        'npub' => 'npub2def',
        'pubkey' => 'pubkey2',
        'nip05_handle' => 'user2@example.com',
        'association_status' => AssociationStatus::ACTIVE,
    ]);

    $member3 = EinundzwanzigPleb::factory()->create([
        'npub' => 'npub3ghi',
        'pubkey' => 'pubkey3',
        'association_status' => AssociationStatus::ACTIVE,
    ]);

    $year = 2024;

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $member1->id,
        'year' => $year,
        'paid' => true,
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $member2->id,
        'year' => $year,
        'paid' => true,
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $member3->id,
        'year' => $year,
        'paid' => false,
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $member1->id,
        'year' => 2023,
        'paid' => true,
    ]);

    $response = $this->getJson("/api/members/{$year}");

    $response->assertStatus(200);

    $response->assertJsonCount(2);

    $response->assertJsonFragment([
        'npub' => 'npub1abc',
        'pubkey' => 'pubkey1',
        'nip05_handle' => 'user1@example.com',
    ]);

    $response->assertJsonFragment([
        'npub' => 'npub2def',
        'pubkey' => 'pubkey2',
        'nip05_handle' => 'user2@example.com',
    ]);

    $response->assertJsonMissing([
        'npub' => 'npub3ghi',
    ]);
});

test('returns empty array when no members paid for year', function () {
    $year = 2024;

    $response = $this->getJson("/api/members/{$year}");

    $response->assertStatus(200);

    $response->assertJson([]);
});

test('only returns npub, pubkey, and nip05_handle fields', function () {
    $member = EinundzwanzigPleb::factory()->create([
        'npub' => 'npub1abc',
        'pubkey' => 'pubkey1',
        'nip05_handle' => 'user1@example.com',
        'association_status' => AssociationStatus::ACTIVE,
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $member->id,
        'year' => 2024,
        'paid' => true,
    ]);

    $response = $this->getJson('/api/members/2024');

    $response->assertStatus(200);

    $json = $response->json();
    expect($json[0])->toHaveKeys(['id', 'npub', 'pubkey', 'nip05_handle'])->not->toHaveKeys([
        'email',
        'association_status',
        'no_email',
        'application_for',
    ]);
});

test('includes nip05_handle in response when available', function () {
    $member = EinundzwanzigPleb::factory()->create([
        'npub' => 'npub1abc',
        'pubkey' => 'pubkey1',
        'nip05_handle' => 'verified@example.com',
        'association_status' => AssociationStatus::ACTIVE,
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $member->id,
        'year' => 2024,
        'paid' => true,
    ]);

    $response = $this->getJson('/api/members/2024');

    $response->assertStatus(200);

    $response->assertJsonFragment([
        'nip05_handle' => 'verified@example.com',
    ]);
});

test('nip05_handle is null in response when not set', function () {
    $member = EinundzwanzigPleb::factory()->create([
        'npub' => 'npub1abc',
        'pubkey' => 'pubkey1',
        'nip05_handle' => null,
        'association_status' => AssociationStatus::ACTIVE,
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $member->id,
        'year' => 2024,
        'paid' => true,
    ]);

    $response = $this->getJson('/api/members/2024');

    $response->assertStatus(200);

    $json = $response->json();
    expect($json[0]['nip05_handle'])->toBeNull();
});
