<?php

namespace App\Livewire\Traits;

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Models\Election;
use App\Services\Dm\DirectMessageText;
use App\Support\DisplayTime;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;

/**
 * What the two writing tabs of the board's message centre share: the
 * elections to pick from, the values a message type starts with, and the
 * German wording of what keeps a text from being sent. "Neue Nachricht"
 * writes a message now, "Zeitplan" plans one with the type's default text.
 *
 * Needs `boardActor()` (WithViewer) from the component: the election list is
 * board-only like every other read of the message centre.
 */
trait WithMessageDrafts
{
    use WithMessageDates;

    /**
     * @return EloquentCollection<int, Election>
     */
    #[Computed]
    public function elections(): EloquentCollection
    {
        $this->boardActor();

        return Election::query()->with('rounds:id,election_event_id,title,position')->orderByDesc('id')->get();
    }

    public function variableLabel(string $variable): string
    {
        return match ($variable) {
            'assembly_date' => __('Termin der Versammlung'),
            'link' => __('Link im Text'),
            'deadline' => __('Frist für Kandidaturen'),
            'year' => __('Beitragsjahr'),
            'round' => __('Wahlgang'),
            'closes_at' => __('Wahlgang schließt'),
            default => $variable,
        };
    }

    public function variableHint(string $variable): string
    {
        return match ($variable) {
            'assembly_date' => __('z. B. 20. März 2027, 19:30 Uhr'),
            'deadline' => __('z. B. 6. März 2027, 23:59 Uhr'),
            'closes_at' => __('z. B. 20:15 Uhr'),
            default => '',
        };
    }

    /**
     * @param  array<string, string>  $values
     * @return array{text: list<string>, variables: array<string, string>, general: list<string>}
     */
    protected function problemsOf(DmMessageType $type, string $text, array $values): array
    {
        $out = ['text' => [], 'variables' => [], 'general' => []];
        $allowed = implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $type->variables()));

        foreach (app(DirectMessageText::class)->problems($type, $text, $values) as $problem) {
            if ($problem === 'the text is empty') {
                $out['text'][] = __('Der Text ist leer.');
            } elseif (preg_match('/^unknown placeholder \{(.*)\}$/u', $problem, $match) === 1) {
                $out['text'][] = __('Den Platzhalter {:name} kennt diese Nachricht nicht. Möglich sind: :allowed.', ['name' => $match[1], 'allowed' => $allowed]);
            } elseif (preg_match('/^no value for \{(.*)\}$/u', $problem, $match) === 1) {
                $out['variables'][$match[1]] = __('Trag hier ein, was im Text für {:name} stehen soll.', ['name' => $match[1]]);
            } elseif (str_starts_with($problem, 'no reply contact')) {
                $out['general'][] = __('Es ist kein Kontakt für Rückfragen eingestellt (DM_REPLY_CONTACT). Ohne ihn geht keine Nachricht raus.');
            }
        }

        return $out;
    }

    /**
     * Only the values the type knows, as strings.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, string>
     */
    protected function valuesFor(DmMessageType $type, array $given): array
    {
        $values = [];

        foreach ($type->messageVariables() as $variable) {
            $values[$variable] = trim((string) ($given[$variable] ?? ''));
        }

        return $values;
    }

    protected function defaultElection(): ?Election
    {
        return $this->elections->firstWhere('is_test', false) ?? $this->elections->first();
    }

    protected function defaultAudience(DmMessageType $type): DmAudience
    {
        return match ($type) {
            DmMessageType::FeeReminder => DmAudience::FeeOutstanding,
            DmMessageType::ElectionStartsNow, DmMessageType::RoundOpened, DmMessageType::RoundClosingSoon => DmAudience::EntitledVoters,
            default => DmAudience::Members,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function defaultValues(DmMessageType $type, ?Election $election): array
    {
        $values = [];

        foreach ($type->messageVariables() as $variable) {
            $values[$variable] = match ($variable) {
                'link' => $type === DmMessageType::FeeReminder ? route('association.profile') : route('association.election'),
                'assembly_date' => $election?->meeting_at === null ? '' : $this->longDate($election->meeting_at),
                'year' => (string) ($election?->fee_year ?? now(DisplayTime::zone())->year),
                'round' => (string) ($election?->rounds->first()?->title ?? ''),
                default => '',
            };
        }

        return $values;
    }
}
