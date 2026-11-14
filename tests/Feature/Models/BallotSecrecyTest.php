<?php

use App\Enums\ElectionCountingRule;
use App\Models\ElectionBallotChoice;
use App\Models\ElectionMark;
use App\Models\ElectionReceipt;
use App\Services\Election\OpenRound;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Services/Election/helpers.php';

/*
 * The secrecy of the ballot as an inventory.
 *
 * A vote is three rows that share nothing: the mark (who has voted), the
 * ballot rows (what was chosen) and the receipt (that a ballot was taken in).
 * Anything that lets two of them be paired again breaks the secrecy — a
 * timestamp, an id that grows with time, a column pointing from one to the
 * other. None of that shows up as a failing behaviour, so it is pinned here
 * as an exact allowlist: adding a column to one of the three tables has to be
 * a decision made in this file.
 */

const ELECTION_SECRET_TABLES = [
    'election_marks' => ['election_round_id', 'einundzwanzig_pleb_id'],
    'election_ballot_choices' => ['id', 'election_round_id', 'election_candidate_id', 'choice'],
    'election_receipts' => ['election_round_id', 'hash'],
];

const ELECTION_SECRET_MODELS = [ElectionMark::class, ElectionBallotChoice::class, ElectionReceipt::class];

it('keeps exactly the allowed columns on the three ballot tables', function (string $table, array $columns) {
    expect(Schema::getColumnListing($table))->toBe($columns);
})->with(collect(ELECTION_SECRET_TABLES)->map(fn (array $columns, string $table): array => [$table, $columns])->all());

it('has no auto-incrementing column on the three ballot tables', function (string $table) {
    $incrementing = collect(Schema::getColumns($table))->where('auto_increment', true)->pluck('name')->all();

    expect($incrementing)->toBe([]);
})->with(array_keys(ELECTION_SECRET_TABLES));

it('lets a ballot row and a receipt point at nothing but the round and the candidate', function () {
    $targets = fn (string $table): array => collect(Schema::getForeignKeys($table))
        ->pluck('foreign_table')
        ->sort()
        ->values()
        ->all();

    expect($targets('election_ballot_choices'))->toBe(['election_candidates', 'election_rounds'])
        ->and($targets('election_receipts'))->toBe(['election_rounds'])
        ->and($targets('election_marks'))->toBe(['einundzwanzig_plebs', 'election_rounds']);
});

it('guards one mark per member and round with a unique index', function () {
    $unique = collect(Schema::getIndexes('election_marks'))
        ->where('unique', true)
        ->pluck('columns')
        ->all();

    expect($unique)->toBe([['election_round_id', 'einundzwanzig_pleb_id']]);
});

it('refuses a second mark for the same member in the database itself', function () {
    $mark = ElectionMark::factory()->create();

    expect(fn () => DB::table('election_marks')->insert([
        'election_round_id' => $mark->election_round_id,
        'einundzwanzig_pleb_id' => $mark->einundzwanzig_pleb_id,
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('election_marks')->count())->toBe(1);
});

it('writes no timestamps from the three ballot models', function (string $model) {
    expect((new $model)->usesTimestamps())->toBeFalse();
})->with(ELECTION_SECRET_MODELS);

it('uses no time-ordered id trait on the three ballot models', function (string $model) {
    expect(class_uses_recursive($model))
        ->not->toHaveKey(HasUuids::class)
        ->not->toHaveKey(HasVersion4Uuids::class)
        ->not->toHaveKey(HasUlids::class);
})->with(ELECTION_SECRET_MODELS);

it('declares no relation on the three ballot models', function (string $model) {
    $relations = collect((new ReflectionClass($model))->getMethods())
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $model)
        ->filter(function (ReflectionMethod $method): bool {
            $type = $method->getReturnType();

            return $type instanceof ReflectionNamedType && is_a($type->getName(), Relation::class, true);
        })
        ->map->getName()
        ->values()
        ->all();

    expect($relations)->toBe([]);
})->with(ELECTION_SECRET_MODELS);

it('gives every ballot row a random id that is not a UUID', function () {
    $voter = electionVoter();
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2]);

    castElectionBallot($round, $voter, $round->candidates()->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 'for'])->all());

    $ids = DB::table('election_ballot_choices')->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids->every(fn (string $id): bool => preg_match('/^[0-9a-f]{32}$/D', $id) === 1))->toBeTrue();

    expect(ElectionBallotChoice::factory()->create()->id)->toMatch('/^[0-9a-f]{32}$/D');
});

it('stores a cast as rows that name the member in the mark only', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    $candidate = $round->candidates()->firstOrFail();

    $code = castElectionBallot($round, $voter, [$candidate->id => 'for']);

    expect((array) DB::table('election_marks')->sole())->toBe([
        'election_round_id' => $round->id,
        'einundzwanzig_pleb_id' => $voter->id,
    ]);

    expect(collect((array) DB::table('election_ballot_choices')->sole())->except('id')->all())->toBe([
        'election_round_id' => $round->id,
        'election_candidate_id' => $candidate->id,
        'choice' => 'for',
    ]);

    expect((array) DB::table('election_receipts')->sole())->toBe([
        'election_round_id' => $round->id,
        'hash' => ElectionReceipt::hashFor($round->id, $code),
    ]);
});

it('keeps the ballot out of the message of a failed insert', function () {
    $voter = electionVoter();
    $round = draftElectionRound(candidates: 1);

    // A candidate id that cannot occur by accident anywhere in the message.
    $candidateId = 7340021;
    DB::table('election_candidates')->where('election_round_id', $round->id)->update(['id' => $candidateId]);

    $round = app(OpenRound::class)->handle($round->fresh());

    DB::statement("CREATE TRIGGER refuse_ballot_rows BEFORE INSERT ON election_ballot_choices BEGIN SELECT RAISE(ABORT, 'ballot row refused'); END");

    $thrown = null;

    try {
        castElectionBallot($round, $voter, [$candidateId => 'for']);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($thrown->getMessage())->toContain('ballot row refused');

    for ($exception = $thrown; $exception !== null; $exception = $exception->getPrevious()) {
        expect($exception->getMessage())
            ->not->toContain((string) $candidateId)
            ->not->toMatch('/\bfor\b/');
    }

    expect(electionRowCounts($round))->toBe(['marks' => 0, 'choices' => 0, 'receipts' => 0]);
});
