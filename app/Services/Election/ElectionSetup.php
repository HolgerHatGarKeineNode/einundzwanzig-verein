<?php

namespace App\Services\Election;

use App\Auth\NostrUser;
use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionRoundMode;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\Concerns\AuthorizesElectionActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The board sets up an election and its rounds, and changes them as long as
 * no round has been opened.
 *
 * Every round set up here is LIVE: the association votes in its meeting, in
 * the video call, and the board opens each round there (user, 2026-11-14).
 * The window mode stays in the model for the engine, it is not offered.
 *
 * Rights are asked at the moment of the act, like in `ElectionStewardship`,
 * and every act writes one audit line naming the board member.
 *
 * @phpstan-type ElectionAttributes array{name: string, meeting_at: \DateTimeInterface|string|null, call_url?: string|null, fee_rule: \App\Enums\ElectionFeeRule|string, fee_year: int|null, honorary_without_fee: bool, is_test?: bool}
 * @phpstan-type RoundAttributes array{id?: int|null, title: string, seats: int, counting_rule: ElectionCountingRule|string}
 */
class ElectionSetup
{
    use AuthorizesElectionActor;

    public function __construct(protected ElectionAuditLog $audit) {}

    /**
     * @param  ElectionAttributes  $attributes
     * @param  list<RoundAttributes>  $rounds  in ballot order
     *
     * @throws AuthorizationException
     */
    public function create(EinundzwanzigPleb $actor, array $attributes, array $rounds): Election
    {
        return DB::transaction(function () use ($actor, $attributes, $rounds): Election {
            Gate::forUser(new NostrUser((string) $actor->pubkey))->authorize('create', Election::class);

            $election = Election::query()->create([
                ...$this->electionAttributes($attributes),
                'is_test' => (bool) ($attributes['is_test'] ?? false),
            ]);

            $this->syncRounds($election, $rounds);

            $this->audit->append($election, ElectionAuditAction::ElectionCreated, $actor);

            return $election->load('rounds');
        });
    }

    /**
     * Change an election whose rounds are all drafts. Rounds missing from
     * `$rounds` are removed — refused for a round that holds candidacies,
     * those are withdrawn or rejected first. Whether it is a test election
     * stays as it was created.
     *
     * @param  ElectionAttributes  $attributes
     * @param  list<RoundAttributes>  $rounds  in ballot order
     *
     * @throws AuthorizationException
     * @throws ElectionRoundStateException
     * @throws ElectionActionRefusedException
     */
    public function update(Election $election, EinundzwanzigPleb $actor, array $attributes, array $rounds): Election
    {
        return DB::transaction(function () use ($election, $actor, $attributes, $rounds): Election {
            $election = Election::query()->lockForUpdate()->findOrFail($election->getKey());

            $this->authorizeActor($actor, 'update', $election);

            if ($election->rounds()->whereNotNull('opened_at')->exists()) {
                throw new ElectionRoundStateException('An election cannot be changed once one of its rounds has been opened.');
            }

            $election->fill($this->electionAttributes($attributes))->save();

            $this->syncRounds($election, $rounds);

            $this->audit->append($election, ElectionAuditAction::ElectionUpdated, $actor);

            return $election->load('rounds');
        });
    }

    /**
     * Change only the meeting time and the call link — also after a round has
     * been opened, because the call room may move on the evening itself.
     * Everything else of the election stays as `update()` left it.
     *
     * @throws AuthorizationException
     */
    public function updateMeeting(Election $election, EinundzwanzigPleb $actor, \DateTimeInterface|string|null $meetingAt, ?string $callUrl): Election
    {
        return DB::transaction(function () use ($election, $actor, $meetingAt, $callUrl): Election {
            $election = Election::query()->lockForUpdate()->findOrFail($election->getKey());

            $this->authorizeActor($actor, 'update', $election);

            $election->forceFill([
                'meeting_at' => $meetingAt ?: null,
                'call_url' => filled($callUrl) ? trim($callUrl) : null,
            ])->save();

            $this->audit->append($election, ElectionAuditAction::ElectionMeetingUpdated, $actor);

            return $election->load('rounds');
        });
    }

    /**
     * @param  ElectionAttributes  $attributes
     * @return array<string, mixed>
     */
    protected function electionAttributes(array $attributes): array
    {
        return [
            'name' => trim($attributes['name']),
            'meeting_at' => $attributes['meeting_at'] ?: null,
            'call_url' => filled($attributes['call_url'] ?? null) ? trim($attributes['call_url']) : null,
            'fee_rule' => $attributes['fee_rule'],
            'fee_year' => $attributes['fee_year'],
            'honorary_without_fee' => (bool) $attributes['honorary_without_fee'],
        ];
    }

    /**
     * Bring the first-level rounds of `$election` in line with `$rounds`.
     * Run-offs are not set up here; they only arise after a round is sealed.
     *
     * Positions are unique per election, so the kept rounds move out of the
     * way first and are then numbered 1, 2, 3 … in the order given.
     *
     * @param  list<RoundAttributes>  $rounds
     *
     * @throws ElectionActionRefusedException
     */
    protected function syncRounds(Election $election, array $rounds): void
    {
        $existing = $election->rounds()->whereNull('parent_round_id')->get()->keyBy('id');
        $keptIds = collect($rounds)->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id)->all();

        foreach ($existing as $id => $round) {
            if (in_array((int) $id, $keptIds, true)) {
                continue;
            }

            if ($round->candidates()->exists()) {
                throw new ElectionActionRefusedException('A round that holds candidacies cannot be removed.');
            }

            $round->delete();
        }

        $offset = (int) $election->rounds()->max('position') + 1000;

        foreach ($existing->only($keptIds) as $round) {
            $round->forceFill(['position' => $round->position + $offset])->save();
        }

        foreach (array_values($rounds) as $index => $data) {
            $values = [
                'title' => trim($data['title']),
                'seats' => (int) $data['seats'],
                'counting_rule' => $data['counting_rule'],
                'mode' => ElectionRoundMode::Live,
                'position' => $index + 1,
            ];

            $id = isset($data['id']) ? (int) $data['id'] : null;

            if ($id !== null && $existing->has($id)) {
                $existing->get($id)->fill($values)->save();

                continue;
            }

            ElectionRound::query()->create([...$values, 'election_event_id' => $election->getKey()]);
        }
    }
}
