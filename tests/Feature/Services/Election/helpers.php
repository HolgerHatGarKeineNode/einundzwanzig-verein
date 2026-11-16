<?php

use App\Enums\ElectionBallotRefusal;
use App\Exceptions\ElectionBallotRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\CastBallot;
use App\Services\Election\OpenRound;
use App\Services\Election\VoteEvent;
use App\Services\Nostr\EventSigning;
use App\Support\Board;
use Carbon\CarbonInterface;
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
 * A draft window round with two candidates.
 */
function windowElectionRound(CarbonInterface $opensAt, CarbonInterface $closesAt): ElectionRound
{
    return ElectionRound::factory()->withCandidates()->window($opensAt, $closesAt)->create();
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

/**
 * The throwaway secret key of `$member`, made up on first use: the member's
 * pubkey is replaced by the matching one. Keyed by pubkey, so a database
 * refreshed between tests cannot hand an old key to a new member.
 */
function electionSecretFor(EinundzwanzigPleb $member): string
{
    static $secrets = [];

    if (! isset($secrets[$member->pubkey])) {
        $secret = EventSigning::freshSecret();
        $member->pubkey = (new Key)->getPublicKey($secret);
        $member->save();
        $secrets[$member->pubkey] = $secret;
    }

    return $secrets[$member->pubkey];
}

/**
 * `$tags` plus the random `nonce` tag the member's browser adds before
 * signing (resources/js/electionBallot.js), unless one is there already.
 *
 * @param  array<int, mixed>  $tags
 * @return array<int, mixed>
 */
function withVoteNonce(array $tags): array
{
    foreach ($tags as $tag) {
        if (is_array($tag) && ($tag[0] ?? null) === 'nonce') {
            return $tags;
        }
    }

    return [...$tags, ['nonce', bin2hex(random_bytes(32))]];
}

/**
 * A vote event for `$selection`, signed by `$voter` now. `$overrides`
 * replaces fields of the unsigned template before signing.
 *
 * @param  array<array-key, mixed>  $selection
 * @param  array<string, mixed>  $overrides
 * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: array<int, array<int, string>>, content: string, sig: string}
 */
function signedElectionVote(ElectionRound $round, EinundzwanzigPleb $voter, array $selection = [], array $overrides = []): array
{
    $template = [...VoteEvent::template($round, $selection, now()->getTimestamp()), ...$overrides];
    $template['tags'] = withVoteNonce($template['tags']);

    return EventSigning::sign(electionSecretFor($voter), $template['kind'], $template['tags'], $template['content'], $template['created_at']);
}

/**
 * Sign and cast a vote and return the signed event. A blank vote by default.
 *
 * @param  array<array-key, mixed>  $selection
 * @return array<string, mixed>
 */
function castElectionBallot(ElectionRound $round, EinundzwanzigPleb $voter, array $selection = []): array
{
    $event = signedElectionVote($round, $voter, $selection);

    app(CastBallot::class)->handle($round, $voter, $event);

    return $event;
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
 * How many votes a round holds.
 */
function electionVoteCount(ElectionRound $round): int
{
    return DB::table('election_votes')->where('election_round_id', $round->getKey())->count();
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
