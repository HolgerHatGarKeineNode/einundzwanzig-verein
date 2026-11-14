<?php

use App\Enums\ElectionBallotRefusal;
use App\Exceptions\ElectionBallotRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\CastBallot;
use App\Services\Election\OpenRound;
use App\Support\Board;
use Illuminate\Support\Facades\DB;
use swentel\nostr\Key\Key;

/*
 * Shared by the election tests. Required explicitly by each file that uses
 * it, so a single file still runs on its own.
 *
 * Rounds are opened through `OpenRound` and ballots cast through `CastBallot`
 * — no helper writes a state the application could not have produced.
 */

/**
 * An ACTIVE member whose fee for `$year` (default: the current year) is paid.
 */
function electionVoter(?int $year = null): EinundzwanzigPleb
{
    return EinundzwanzigPleb::factory()->active()->withPaidYear($year ?? (int) now()->year)->create();
}

/**
 * A draft live round with candidates, in its own election unless one is given.
 *
 * @param  array<string, mixed>  $attributes
 */
function draftElectionRound(array $attributes = [], int $candidates = 2, ?Election $election = null): ElectionRound
{
    return ElectionRound::factory()
        ->withCandidates($candidates)
        ->create([
            'election_event_id' => $election?->getKey() ?? Election::factory(),
            ...$attributes,
        ]);
}

/**
 * A live round, opened now. Members created BEFORE this call are on its roll
 * if they are entitled.
 *
 * @param  array<string, mixed>  $attributes
 */
function openElectionRound(array $attributes = [], int $candidates = 2, ?Election $election = null): ElectionRound
{
    return app(OpenRound::class)->handle(draftElectionRound($attributes, $candidates, $election));
}

function electionReceiptCode(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Cast a ballot and return the receipt code used. A blank ballot by default.
 *
 * @param  array<array-key, mixed>  $selection
 */
function castElectionBallot(ElectionRound $round, EinundzwanzigPleb $voter, array $selection = []): string
{
    $code = electionReceiptCode();

    app(CastBallot::class)->handle($round, $voter, $selection, $code);

    return $code;
}

/**
 * The reason a cast was refused with, or null if it went through.
 */
function electionRefusal(Closure $cast): ?ElectionBallotRefusal
{
    try {
        $cast();
    } catch (ElectionBallotRefusedException $exception) {
        return $exception->reason;
    }

    return null;
}

/**
 * How many marks, ballot rows and receipts a round holds.
 *
 * @return array{marks: int, choices: int, receipts: int}
 */
function electionRowCounts(ElectionRound $round): array
{
    $count = fn (string $table): int => DB::table($table)->where('election_round_id', $round->getKey())->count();

    return [
        'marks' => $count('election_marks'),
        'choices' => $count('election_ballot_choices'),
        'receipts' => $count('election_receipts'),
    ];
}

/**
 * `$count` ACTIVE members with a paid fee who make up the whole board — the
 * configured board list is replaced by their npubs.
 *
 * @return list<EinundzwanzigPleb>
 */
function electionBoard(int $count = 2): array
{
    $key = new Key;
    $members = [];

    for ($i = 0; $i < $count; $i++) {
        $pubkey = bin2hex(random_bytes(32));
        $members[] = EinundzwanzigPleb::factory()->active()->withPaidYear((int) now()->year)->create([
            'pubkey' => $pubkey,
            'npub' => $key->convertPublicKeyToBech32($pubkey),
        ]);
    }

    config(['einundzwanzig.config.current_board' => array_map(fn (EinundzwanzigPleb $member): string => $member->npub, $members)]);
    Board::flush();

    return $members;
}
