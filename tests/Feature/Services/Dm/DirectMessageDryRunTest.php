<?php

use App\Enums\AssociationStatus;
use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Enums\ElectionFeeRule;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\PlebDmRelay;
use App\Models\Profile;
use App\Services\Dm\DirectMessageDryRun;
use App\Services\Dm\StartDirectMessageRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Jobs/DirectMessageHelpers.php';

beforeEach(fn () => armDirectMessages());

/**
 * @return array<string, int>
 */
function dmTableCounts(): array
{
    return collect(['dm_messages', 'dm_runs', 'dm_deliveries', 'dm_delivery_relays'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
}

it('reports count, previews and unreachable members of a dry run and writes nothing', function () {
    $election = Election::factory()->feeRule(ElectionFeeRule::Year, 2027)->create(['name' => 'Vorstandswahl 2027']);
    $withInbox = EinundzwanzigPleb::factory()->active()->create();
    PlebDmRelay::factory()->for($withInbox, 'pleb')->create();
    Profile::query()->create(['pubkey' => $withInbox->pubkey, 'name' => 'Satoshi']);
    $withoutInbox = EinundzwanzigPleb::factory()->active()->create();
    $emptyInbox = EinundzwanzigPleb::factory()->active()->create();
    PlebDmRelay::factory()->for($emptyInbox, 'pleb')->create(['relays' => []]);
    EinundzwanzigPleb::factory()->active()->withPaidYear(2027)->create();

    // An existing message and run, so "nothing written" is not "nothing there".
    DmMessage::factory()->create()->freezeRun([$withInbox]);
    $before = dmTableCounts();

    $result = app(DirectMessageDryRun::class)->handle(
        DmMessageType::FeeReminder,
        DmAudience::FeeOutstanding,
        $election,
        ['year' => '2027', 'link' => 'https://verein.example/beitrag'],
        sampleSize: 2,
    );

    expect(dmTableCounts())->toBe($before)
        ->and($result->count)->toBe(3)
        ->and($result->description)->toBe('3 Mitglieder ohne bezahlten Beitrag für 2027 (Wahl „Vorstandswahl 2027“)')
        ->and($result->problems)->toBe([])
        ->and($result->unreachableIds)->toBe([$withoutInbox->id, $emptyInbox->id])
        ->and(array_column($result->previews, 'recipient_id'))->toBe([$withInbox->id, $withoutInbox->id])
        ->and($result->previews[0]['text'])->toStartWith("Hallo Satoshi,\n\nlaut Vereinsportal ist dein Mitgliedsbeitrag für 2027 noch offen.")
        ->and($result->previews[0]['text'])->toContain('https://verein.example/beitrag')
        ->and($result->previews[1]['text'])->toStartWith('Hallo Mitglied,');
});

it('returns the text problems of a dry run instead of previews', function () {
    EinundzwanzigPleb::factory()->active()->create();

    $result = app(DirectMessageDryRun::class)->handle(DmMessageType::FeeReminder, DmAudience::Members, variables: ['year' => '2027']);

    expect($result->count)->toBe(1)
        ->and($result->problems)->toBe(['no value for {link}'])
        ->and($result->previews)->toBe([])
        ->and(dmTableCounts())->toBe(['dm_messages' => 0, 'dm_runs' => 0, 'dm_deliveries' => 0, 'dm_delivery_relays' => 0]);
});

it('freezes the recipient list when a run starts; a member who qualifies later is not added', function () {
    Queue::fake();
    $first = EinundzwanzigPleb::factory()->active()->create();
    $second = EinundzwanzigPleb::factory()->state(['association_status' => AssociationStatus::HONORARY])->create();
    EinundzwanzigPleb::factory()->create();

    $run = app(StartDirectMessageRun::class)->handle(DmMessage::factory()->create(), DmAudience::Members);

    expect($run->fresh()->recipient_ids)->toBe([$first->id, $second->id])
        ->and($run->recipient_count)->toBe(2);
    Queue::assertPushed(DispatchBulkDirectMessages::class, fn (DispatchBulkDirectMessages $job): bool => $job->runId === $run->id);

    $late = EinundzwanzigPleb::factory()->active()->create();
    (new DispatchBulkDirectMessages($run->id))->handle();

    expect(DmDelivery::query()->where('dm_run_id', $run->id)->orderBy('einundzwanzig_pleb_id')->pluck('einundzwanzig_pleb_id')->all())
        ->toBe([$first->id, $second->id])
        ->and($late->id)->not->toBeIn(DmDelivery::query()->pluck('einundzwanzig_pleb_id')->all());
});
