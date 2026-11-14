<?php

use App\Enums\DmDeliveryStatus;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmDelivery;
use App\Models\DmDeliveryRelay;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';

beforeEach(fn () => armDirectMessages());

/**
 * Every dm_* table and the columns of its rows that contain one of the
 * needles, as "table.column". Every column is read, whatever its type: a
 * column added later is covered without touching this test.
 *
 * @param  list<string>  $needles
 * @return array{tables: list<string>, hits: list<string>}
 */
function dmColumnsContaining(array $needles): array
{
    $tables = collect(Schema::getTables())
        ->pluck('name')
        ->filter(fn (string $table): bool => str_starts_with($table, 'dm_'))
        ->sort()
        ->values()
        ->all();

    $hits = [];

    foreach ($tables as $table) {
        foreach (DB::table($table)->get() as $row) {
            foreach ((array) $row as $column => $value) {
                foreach ($needles as $needle) {
                    if ($value !== null && str_contains((string) $value, $needle)) {
                        $hits[] = "{$table}.{$column}";
                    }
                }
            }
        }
    }

    return ['tables' => $tables, 'hits' => array_values(array_unique($hits))];
}

/*
 * DoD 466. Mutation "npub column without erasure": add a column to a dm_*
 * table that stores the recipient's npub and leave it out of
 * erasePersonalData() → red.
 */
it('leaves no text column of the direct message tables holding the erased member\'s pubkey or npub', function () {
    $relay = FakeRelayTransport::bind();
    [$member] = dmRecipient(['wss://reads-here.example/']);
    $member->forceFill(['npub' => (new Key)->convertPublicKeyToBech32($member->pubkey)])->save();
    Profile::factory()->create(['pubkey' => $member->pubkey, 'display_name' => 'Satoshi Erased']);
    [$other] = dmRecipient(['wss://other.example/']);

    // One delivery sent, one that failed, one never picked up.
    $relay->thenAccept()->thenAccept();
    DispatchBulkDirectMessages::dispatchSync(DmMessage::factory()->create()->freezeRun([$member, $other])->id);
    $relay->thenFail('timeout');
    DispatchBulkDirectMessages::dispatchSync(DmMessage::factory()->create()->freezeRun([$member])->id);
    $waiting = dmDelivery($member);

    $pubkey = $member->pubkey;
    $npub = $member->npub;
    $needles = [$pubkey, $npub, 'Satoshi Erased', 'wss://reads-here.example/'];

    // The scan sees the member before the erasure; otherwise "nothing found"
    // below would hold for a scan that reads nothing.
    $before = dmColumnsContaining($needles);
    expect($before['tables'])->toBe(['dm_deliveries', 'dm_delivery_relays', 'dm_messages', 'dm_runs'])
        ->and($before['hits'])->toContain('dm_deliveries.wrap', 'dm_deliveries.plaintext', 'dm_delivery_relays.relay_url');

    app(MembershipService::class)->erasePersonalData($member);

    expect(dmColumnsContaining($needles)['hits'])->toBe([]);

    $deliveries = DmDelivery::query()->where('einundzwanzig_pleb_id', $member->id)->orderBy('id')->get();

    expect($deliveries)->toHaveCount(3)
        ->and($deliveries->pluck('status')->all())->toBe([DmDeliveryStatus::Sent, DmDeliveryStatus::Failed, DmDeliveryStatus::Pending])
        ->and($deliveries->pluck('rumor_id')->filter()->all())->toBe([])
        ->and($waiting->fresh()->wrap)->toBeNull();
});

it('leaves other members\' deliveries as they were', function () {
    FakeRelayTransport::bind();
    [$member] = dmRecipient();
    [$other] = dmRecipient(['wss://other.example/']);
    DispatchBulkDirectMessages::dispatchSync(DmMessage::factory()->create()->freezeRun([$member, $other])->id);

    $before = DmDelivery::query()->where('einundzwanzig_pleb_id', $other->id)->sole()->toArray();

    app(MembershipService::class)->erasePersonalData(EinundzwanzigPleb::query()->findOrFail($member->id));

    $after = DmDelivery::query()->where('einundzwanzig_pleb_id', $other->id)->sole();

    expect($after->toArray())->toBe($before)
        ->and($after->wrap['tags'])->toBe([['p', $other->pubkey]])
        ->and(DmDeliveryRelay::query()->where('dm_delivery_id', $after->id)->count())->toBe(1);
});
