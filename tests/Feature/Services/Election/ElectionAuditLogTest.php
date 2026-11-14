<?php

use App\Enums\ElectionAuditAction;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Services\Election\ElectionAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

/*
 * The audit log of an election: one line per privileged action, appended
 * only, each line hashing the one before it.
 */

/**
 * An election with three lines in its log.
 */
function electionWithAuditLines(): Election
{
    $election = Election::factory()->create();
    $actor = EinundzwanzigPleb::factory()->active()->create();
    $subject = EinundzwanzigPleb::factory()->active()->create();
    $log = app(ElectionAuditLog::class);

    $log->append($election, ElectionAuditAction::LeadAppointed, $actor, subject: $subject);
    $log->append($election, ElectionAuditAction::ElectionAnnounced, $actor);
    $log->append($election, ElectionAuditAction::VoterEnrolledByHand, $actor, subject: $subject, details: ['reason' => 'Zahlung per Überweisung belegt']);

    return $election;
}

it('numbers the lines of an election and chains each to the one before', function () {
    $election = electionWithAuditLines();

    $entries = ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->get();

    expect($entries->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($entries[0]->previous_hash)->toBe(ElectionAuditLog::GENESIS)
        ->and($entries[1]->previous_hash)->toBe($entries[0]->hash)
        ->and($entries[2]->previous_hash)->toBe($entries[1]->hash)
        ->and($entries[2]->details)->toBe(['reason' => 'Zahlung per Überweisung belegt'])
        ->and(app(ElectionAuditLog::class)->verify($election))->toBeTrue()
        ->and(app(ElectionAuditLog::class)->headHash($election))->toBe($entries[2]->hash);
});

it('holds for an election without lines and names the genesis as its head', function () {
    $election = Election::factory()->create();

    expect(app(ElectionAuditLog::class)->verify($election))->toBeTrue()
        ->and(app(ElectionAuditLog::class)->headHash($election))->toBe(ElectionAuditLog::GENESIS);
});

/*
 * Mutation that must turn this red: let `verify()` recompute only each
 * line's own hash, without the sequence and the link to the line before —
 * the deleted line then goes unnoticed.
 */
it('breaks the chain when a line is changed, removed or rehashed after the fact', function (Closure $tamper) {
    $election = electionWithAuditLines();
    $entries = DB::table('election_audit_entries')->where('election_event_id', $election->id)->orderBy('sequence')->get();

    $tamper($entries);

    expect(app(ElectionAuditLog::class)->verify($election))->toBeFalse();
})->with([
    'a changed reason' => [fn ($entries) => DB::table('election_audit_entries')->where('id', $entries[2]->id)->update(['details' => json_encode(['reason' => 'etwas anderes'])])],
    'a changed actor' => [fn ($entries) => DB::table('election_audit_entries')->where('id', $entries[1]->id)->update(['actor_pleb_id' => EinundzwanzigPleb::factory()->create()->id])],
    'a changed action' => [fn ($entries) => DB::table('election_audit_entries')->where('id', $entries[0]->id)->update(['action' => ElectionAuditAction::RoundAnnulled->value])],
    'a changed time' => [fn ($entries) => DB::table('election_audit_entries')->where('id', $entries[0]->id)->update(['created_at' => now()->subYear()])],
    'a removed line' => [fn ($entries) => DB::table('election_audit_entries')->where('id', $entries[1]->id)->delete()],
    'a line rehashed over changed content' => [function ($entries) {
        DB::table('election_audit_entries')->where('id', $entries[1]->id)->update([
            'action' => ElectionAuditAction::RoundAnnulled->value,
            'hash' => hash('sha256', 'forged'),
        ]);
    }],
]);

it('keeps the chains of two elections apart', function () {
    $first = electionWithAuditLines();
    $second = electionWithAuditLines();

    DB::table('election_audit_entries')->where('election_event_id', $first->id)->where('sequence', 2)->update(['action' => 'forged']);

    expect(app(ElectionAuditLog::class)->verify($first))->toBeFalse()
        ->and(app(ElectionAuditLog::class)->verify($second))->toBeTrue()
        ->and(ElectionAuditEntry::query()->where('election_event_id', $second->id)->min('sequence'))->toBe(1);
});

it('refuses to change or delete a line through the model', function () {
    $election = electionWithAuditLines();
    $entry = ElectionAuditEntry::query()->where('election_event_id', $election->id)->firstOrFail();

    expect(fn () => $entry->update(['action' => ElectionAuditAction::RoundAnnulled]))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(app(ElectionAuditLog::class)->verify($election))->toBeTrue();
});

/*
 * Mutation that must turn this red: drop the key check in
 * `ElectionAuditLog::checkedDetails()` — a `choice` or `receipt` detail is
 * then written.
 */
it('takes no detail outside its fixed list, so no ballot choice and no receipt can be written', function (array $details) {
    $election = Election::factory()->create();

    expect(fn () => app(ElectionAuditLog::class)->append($election, ElectionAuditAction::RoundOpened, null, details: $details))
        ->toThrow(InvalidArgumentException::class);

    expect(ElectionAuditEntry::query()->count())->toBe(0);
})->with([
    'a choice' => [['choice' => 'yes']],
    'a receipt' => [['receipt' => str_repeat('a', 32)]],
    'a ballot id' => [['ballot_id' => str_repeat('b', 32)]],
    'a reason of the wrong type' => [['reason' => 42]],
    'an empty reason' => [['reason' => '   ']],
    'an overlong reason' => [['reason' => str_repeat('x', ElectionAuditLog::MAX_REASON_LENGTH + 1)]],
]);

it('has no column that could hold a ballot choice or a receipt', function () {
    expect(Schema::getColumnListing('election_audit_entries'))->toEqualCanonicalizing([
        'id', 'election_event_id', 'sequence', 'action', 'election_round_id',
        'actor_pleb_id', 'subject_pleb_id', 'details',
        'previous_hash', 'hash', 'created_at',
    ]);
});
