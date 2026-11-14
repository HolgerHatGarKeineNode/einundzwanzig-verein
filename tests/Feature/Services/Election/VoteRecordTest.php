<?php

use App\Models\EinundzwanzigPleb;
use App\Models\ElectionRound;
use App\Services\Election\VoteEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The vote record and the event format it stores.
 */

/**
 * A raw vote row, written past the application.
 *
 * @return array<string, mixed>
 */
function rawElectionVoteRow(ElectionRound $round, EinundzwanzigPleb $member): array
{
    return [
        'election_round_id' => $round->id,
        'einundzwanzig_pleb_id' => $member->id,
        'pubkey' => $member->pubkey,
        'event_id' => str_repeat('a', 64),
        'event' => '{}',
        'choices' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('keeps round, member, pubkey, the signed event and the choices of a vote', function () {
    expect(Schema::getColumnListing('election_votes'))->toEqualCanonicalizing([
        'id', 'election_round_id', 'einundzwanzig_pleb_id', 'pubkey', 'event_id', 'event', 'choices', 'created_at', 'updated_at',
    ]);
});

it('refuses a second vote of the same member in the same round in the database itself', function () {
    $round = ElectionRound::factory()->create();
    $member = EinundzwanzigPleb::factory()->active()->create();

    DB::table('election_votes')->insert(rawElectionVoteRow($round, $member));

    expect(fn () => DB::transaction(fn () => DB::table('election_votes')->insert(rawElectionVoteRow($round, $member))))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('election_votes')->count())->toBe(1);

    DB::table('election_votes')->insert(rawElectionVoteRow(ElectionRound::factory()->create(), $member));

    expect(DB::table('election_votes')->count())->toBe(2);
});

it('binds the event template to the election, the round and the choices', function () {
    $round = ElectionRound::factory()->create();

    $template = VoteEvent::template($round, [7 => 'yes', 9 => 'no'], 1_800_000_000);

    expect($template)->toBe([
        'kind' => 22121,
        'created_at' => 1_800_000_000,
        'tags' => [
            ['election', (string) $round->election_event_id],
            ['round', (string) $round->id],
            ['vote', '7', 'yes'],
            ['vote', '9', 'no'],
        ],
        'content' => '',
    ])
        ->and(VoteEvent::selection($template))->toBe([7 => 'yes', 9 => 'no'])
        ->and(VoteEvent::selection(VoteEvent::template($round, [], 1_800_000_000)))->toBe([]);
});

it('reads no selection from a malformed or doubled vote tag', function (array $tags) {
    expect(VoteEvent::selection(['tags' => $tags]))->toBeNull();
})->with([
    'a candidate named twice' => [[['vote', '7', 'for'], ['vote', '7', 'for']]],
    'a candidate id that is not a number' => [[['vote', '7a', 'for']]],
    'a candidate id with a leading zero' => [[['vote', '07', 'for']]],
    'a vote tag without a choice' => [[['vote', '7']]],
    'a vote tag with an extra value' => [[['vote', '7', 'for', 'x']]],
]);
