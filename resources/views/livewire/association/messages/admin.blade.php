<?php

use App\Enums\DmAudience;
use App\Enums\DmDeliveryStatus;
use App\Enums\DmMessageType;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmDelivery;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\MemberProposal;
use App\Services\Dm\ComposeDirectMessage;
use App\Services\Dm\DirectMessageAudience;
use App\Services\Dm\DirectMessageAudienceChanged;
use App\Services\Dm\DirectMessageDryRun;
use App\Services\Dm\DirectMessagePause;
use App\Services\Dm\DirectMessageRepeatLock;
use App\Services\Dm\DirectMessageText;
use App\Services\Dm\DirectMessageTextInvalid;
use App\Services\Dm\ScheduleDirectMessage;
use App\Services\Dm\StartDirectMessageRun;
use App\Services\Nostr\NotificationKey;
use App\Services\Nostr\RelayPublishStatus;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The board's message centre: what went out and to whom (Verlauf), a new
 * message from type to confirmed sending (Neue Nachricht), and an
 * election's planned messages with the global pause (Zeitplan).
 *
 * Board only. Every public method and every computed property resolves the
 * acting board member from the session and answers 403 to anybody else, so
 * a page loaded by a board member stops working the moment the session
 * belongs to somebody who is not.
 *
 * Nothing here talks to a relay: sending is queued work
 * (DispatchBulkDirectMessages → SendDirectMessage).
 */
new
#[Title('Nachrichten an Mitglieder')]
class extends Component
{
    private const TABS = ['history', 'compose', 'schedule'];

    private const TIMEZONE = 'Europe/Berlin';

    private const HISTORY_LIMIT = 30;

    private const UNREACHABLE_NAMES_SHOWN = 8;

    /** The election timeline messages the schedule offers. */
    private const SCHEDULE_TYPES = [
        DmMessageType::ElectionAnnouncement,
        DmMessageType::NominationDeadline,
        DmMessageType::FeeReminder,
        DmMessageType::ElectionStartsNow,
    ];

    #[Locked]
    public bool $isGuest = false;

    #[Url(as: 'bereich', except: 'history')]
    public string $tab = 'history';

    #[Locked]
    public ?int $openRunId = null;

    public string $type = 'election_announcement';

    public string $audience = 'members';

    public ?int $electionId = null;

    /** @var array<string, string> */
    public array $variables = [];

    public string $text = '';

    /**
     * The last dry run: what it found and for which draft. Sending is only
     * offered while the draft is still the one the dry run saw, and a start
     * freezes exactly `recipient_ids` or nothing.
     *
     * @var array{signature: string, count: int, recipient_ids: list<int>, unreachable: list<string>, unreachable_count: int, skipped: int, lock_hours: int}|null
     */
    #[Locked]
    public ?array $dryRun = null;

    public ?int $scheduleElectionId = null;

    public string $newType = 'election_announcement';

    public string $newAudience = 'members';

    public string $newDate = '';

    public string $newTime = '09:00';

    /** @var array<string, string> */
    public array $newVariables = [];

    public bool $paused = false;

    public function mount(DirectMessagePause $pause): void
    {
        if (! NostrAuth::check()) {
            $this->isGuest = true;

            return;
        }

        $this->actor();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'history';
        }

        $default = $this->defaultElection();
        $this->electionId = $default?->id;
        $this->scheduleElectionId = $default?->id;
        $this->applyTypeDefaults(DmMessageType::ElectionAnnouncement);
        $this->text = DmMessageType::ElectionAnnouncement->defaultText();
        $this->applyNewTypeDefaults();
        $this->paused = $pause->isPaused();
    }

    public function showTab(string $tab): void
    {
        $this->actor();

        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'history';
    }

    public function updatedTab(): void
    {
        $this->actor();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'history';
        }
    }

    public function toggleRun(int $runId): void
    {
        $this->actor();

        $this->openRunId = $this->openRunId === $runId ? null : DmRun::query()->findOrFail($runId)->id;
        unset($this->openRun);
    }

    /**
     * Hand the run back to the fan-out. The count the board sees and is told
     * is the failed work: `failed` and a claim of a worker that died (shown
     * as failed, see DmDelivery::isStuck). The fan-out also queues `pending`
     * deliveries again — they would go out anyway, and their claim turns the
     * second job into a no-op. `sent` and `no_inbox` are never touched.
     */
    public function resendFailed(int $runId, DirectMessagePause $pause): void
    {
        $this->actor();
        $run = DmRun::query()->findOrFail($runId);

        if ($pause->isPaused()) {
            Flux::toast(__('Der Versand ist pausiert. Setze ihn im Zeitplan fort, dann geht es weiter.'), variant: 'danger');

            return;
        }

        $retry = $run->deliveries()->failedOrStuck(now())->count();

        if ($retry === 0) {
            Flux::toast(__('In dieser Nachricht ist nichts fehlgeschlagen.'));

            return;
        }

        DispatchBulkDirectMessages::dispatch($run->id);

        unset($this->runs, $this->openRun);
        Flux::toast(trans_choice('Eine fehlgeschlagene Zustellung wird erneut gesendet.|:count fehlgeschlagene Zustellungen werden erneut gesendet.', $retry, ['count' => $retry]), variant: 'success');
    }

    public function updatedType(): void
    {
        $this->actor();

        $type = DmMessageType::tryFrom($this->type) ?? DmMessageType::Individual;
        $this->type = $type->value;
        $previousDefaults = array_map(fn (DmMessageType $any): string => $any->defaultText(), DmMessageType::cases());

        if (trim($this->text) === '' || in_array($this->text, $previousDefaults, true)) {
            $this->text = $type->defaultText();
        }

        $this->applyTypeDefaults($type);
        $this->forgetDraft();
    }

    public function updatedAudience(): void
    {
        $this->actor();

        $this->audience = (DmAudience::tryFrom($this->audience) ?? DmAudience::Members)->value;
        $this->forgetDraft();
    }

    public function updatedElectionId(): void
    {
        $this->actor();

        $election = $this->composeElection;
        $this->electionId = $election?->id;
        $this->applyTypeDefaults($this->composeType(), onlyElectionValues: true);
        $this->forgetDraft();
    }

    public function updatedText(): void
    {
        $this->actor();
        $this->forgetDraft();
    }

    public function updatedVariables(): void
    {
        $this->actor();
        $this->forgetDraft();
    }

    public function restoreDefaultText(): void
    {
        $this->actor();

        $this->text = $this->composeType()->defaultText();
        $this->forgetDraft();
    }

    /**
     * Count the audience and look at who cannot be reached. Writes nothing.
     */
    public function runDryRun(DirectMessageDryRun $dryRun): void
    {
        $this->actor();

        $result = $dryRun->handle(
            $this->composeType(),
            $this->composeAudience(),
            $this->composeElectionOrNull(),
            $this->messageVariables(),
            $this->text,
            sampleSize: 0,
        );

        if ($result->problems !== []) {
            $this->dryRun = null;

            return;
        }

        $unreachable = EinundzwanzigPleb::query()
            ->with('profile')
            ->whereKey(array_slice($result->unreachableIds, 0, self::UNREACHABLE_NAMES_SHOWN))
            ->orderBy('id')
            ->get()
            ->map(fn (EinundzwanzigPleb $member): string => MemberProposal::authorNameOf($member))
            ->all();

        $this->dryRun = [
            'signature' => $this->draftSignature(),
            'count' => $result->count,
            'recipient_ids' => $result->recipientIds,
            'unreachable' => $unreachable,
            'unreachable_count' => count($result->unreachableIds),
            'skipped' => count($result->skippedIds),
            'lock_hours' => app(DirectMessageRepeatLock::class)->hours(),
        ];
    }

    /**
     * One real message to the board member writing it, nobody else.
     */
    public function sendTestToSelf(ComposeDirectMessage $compose, StartDirectMessageRun $start, DirectMessagePause $pause): void
    {
        $actor = $this->actor();

        if ($pause->isPaused()) {
            Flux::toast(__('Der Versand ist pausiert. Setze ihn im Zeitplan fort, dann geht auch der Test raus.'), variant: 'danger');

            return;
        }

        try {
            $message = $compose->handle($this->composeType(), $actor, $this->messageVariables(), $this->text);
        } catch (DirectMessageTextInvalid) {
            Flux::toast(__('Der Text ist noch nicht vollständig. Was fehlt, steht unter dem Textfeld.'), variant: 'danger');

            return;
        }

        if ($start->toSelf($message, $actor) === null) {
            $message->delete();
            Flux::toast(__('Der Versand ist pausiert. Setze ihn im Zeitplan fort, dann geht auch der Test raus.'), variant: 'danger');

            return;
        }

        unset($this->runs);
        if (! $this->senderKeyUsable) {
            Flux::toast(__('Test an dich gestartet. Er steht nur in deinem Posteingang im Portal: Ohne Absenderschlüssel kommt er in keinem Nostr-Client an.'), variant: 'warning');

            return;
        }

        Flux::toast(__('Test an dich gestartet. Er steht im Verlauf und kommt in deinem Nostr-Client an.'), variant: 'success');
    }

    /**
     * Start the sending the board confirmed. The frozen recipient list is
     * the dry run's list: when the audience changed since (somebody joined,
     * paid or left), nothing is started and the board is asked for a new
     * dry run, so the number sent is always the number shown.
     */
    public function confirmSend(ComposeDirectMessage $compose, StartDirectMessageRun $start, DirectMessagePause $pause): void
    {
        $actor = $this->actor();

        if ($this->dryRun === null || $this->dryRun['signature'] !== $this->draftSignature()) {
            Flux::modal('confirm-send')->close();
            Flux::toast(__('Seit dem Probelauf hat sich etwas geändert. Starte ihn neu, dann siehst du die Zahl, die gesendet wird.'), variant: 'danger');

            return;
        }

        if ($pause->isPaused()) {
            Flux::modal('confirm-send')->close();
            Flux::toast(__('Der Versand ist pausiert. Setze ihn im Zeitplan fort, dann kannst du senden.'), variant: 'danger');

            return;
        }

        try {
            $message = $compose->handle($this->composeType(), $actor, $this->messageVariables(), $this->text);
        } catch (DirectMessageTextInvalid) {
            Flux::modal('confirm-send')->close();
            Flux::toast(__('Der Text ist noch nicht vollständig. Was fehlt, steht unter dem Textfeld.'), variant: 'danger');

            return;
        }

        try {
            $run = $start->handle($message, $this->composeAudience(), $this->composeElectionOrNull(), $this->dryRun['recipient_ids'] ?? []);
        } catch (DirectMessageAudienceChanged $changed) {
            // The text was written for this sending only.
            $message->delete();
            $this->dryRun = null;
            Flux::modal('confirm-send')->close();
            $reason = $changed->currentCount === $changed->confirmedCount
                ? __('Die Zielgruppe hat sich seit dem Probelauf geändert: gleich viele, aber andere Mitglieder. Es wurde nichts gesendet. Starte den Probelauf neu.')
                : trans_choice('Die Zielgruppe hat sich seit dem Probelauf geändert: jetzt ein Mitglied statt :confirmed. Es wurde nichts gesendet. Starte den Probelauf neu.|Die Zielgruppe hat sich seit dem Probelauf geändert: jetzt :count Mitglieder statt :confirmed. Es wurde nichts gesendet. Starte den Probelauf neu.', $changed->currentCount, ['count' => $changed->currentCount, 'confirmed' => $changed->confirmedCount]);
            Flux::toast($reason, variant: 'danger');

            return;
        } catch (LockTimeoutException) {
            // Another start held the lock too long; nothing was written.
            $message->delete();
            Flux::modal('confirm-send')->close();
            Flux::toast(__('Gerade startet ein anderer Versand. Es wurde nichts gesendet. Versuche es in einer Minute noch einmal.'), variant: 'danger');

            return;
        }

        Flux::modal('confirm-send')->close();

        if ($run === null) {
            Flux::toast(__('Der Versand ist pausiert. Setze ihn im Zeitplan fort, dann kannst du senden.'), variant: 'danger');

            return;
        }

        $this->dryRun = null;
        $this->tab = 'history';
        $this->openRunId = $run->id;
        unset($this->runs, $this->openRun);

        $skipped = count($run->skipped_ids ?? []);

        if ($run->recipient_count === 0 && $skipped > 0) {
            Flux::toast(trans_choice('Nichts gesendet: Ein Mitglied hat diese Nachricht in den letzten :hours Stunden schon bekommen und ist übersprungen.|Nichts gesendet: Alle :count Mitglieder haben diese Nachricht in den letzten :hours Stunden schon bekommen und sind übersprungen.', $skipped, ['count' => $skipped, 'hours' => app(DirectMessageRepeatLock::class)->hours()]), variant: 'warning');

            return;
        }

        $started = trans_choice('Gestartet: geht an ein Mitglied.|Gestartet: geht an :count Mitglieder.', $run->recipient_count, ['count' => $run->recipient_count]);

        if ($skipped > 0) {
            $started .= ' '.trans_choice('Übersprungen: ein Mitglied, es hat sie in den letzten :hours Stunden schon bekommen.|Übersprungen: :count Mitglieder, sie haben sie in den letzten :hours Stunden schon bekommen.', $skipped, ['count' => $skipped, 'hours' => app(DirectMessageRepeatLock::class)->hours()]);
        }

        if (! $this->senderKeyUsable) {
            Flux::toast($started.' '.__('Ohne Absenderschlüssel steht sie nur im Posteingang im Portal.'), variant: 'warning');

            return;
        }

        Flux::toast($started, variant: 'success');
    }

    public function updatedScheduleElectionId(): void
    {
        $this->actor();

        $this->scheduleElectionId = $this->scheduleElection?->id;
        $this->resetErrorBag();
        $this->applyNewTypeDefaults();
    }

    public function updatedNewType(): void
    {
        $this->actor();

        if (! in_array(DmMessageType::tryFrom($this->newType), self::SCHEDULE_TYPES, true)) {
            $this->newType = DmMessageType::ElectionAnnouncement->value;
        }

        $this->resetErrorBag();
        $this->applyNewTypeDefaults();
    }

    public function addSchedule(ScheduleDirectMessage $schedule): void
    {
        $actor = $this->actor();
        $election = $this->scheduleElection ?? abort(404);

        $type = DmMessageType::tryFrom($this->newType);
        $audience = DmAudience::tryFrom($this->newAudience);

        $this->validate([
            'newDate' => ['required', 'date_format:Y-m-d'],
            'newTime' => ['required', 'date_format:H:i'],
        ], [
            'newDate.*' => __('Wähle den Tag, an dem die Nachricht rausgeht.'),
            'newTime.*' => __('Wähle die Uhrzeit, zum Beispiel 09:00.'),
        ]);

        if (! in_array($type, self::SCHEDULE_TYPES, true) || $audience === null) {
            $this->addError('newType', __('Wähle eine der angebotenen Nachrichten.'));

            return;
        }

        $sendAt = Carbon::createFromFormat('Y-m-d H:i', $this->newDate.' '.$this->newTime, self::TIMEZONE)->utc();

        if ($sendAt->isPast()) {
            $this->addError('newDate', __('Dieser Zeitpunkt ist schon vorbei. Wähle einen späteren.'));

            return;
        }

        try {
            $schedule->handle($election, $type, $audience, $sendAt, $this->newMessageVariables($type), author: $actor);
        } catch (DirectMessageTextInvalid) {
            foreach ($this->problemsOf($type, $type->defaultText(), $this->newMessageVariables($type))['variables'] as $variable => $problem) {
                $this->addError('newVariables.'.$variable, $problem);
            }

            return;
        }

        unset($this->scheduleRows, $this->upcoming);
        Flux::toast(__('„:type“ ist eingeplant für :when.', ['type' => $type->label(), 'when' => $this->longDate($sendAt)]), variant: 'success');
    }

    /**
     * Take a planned row out before it has gone out. A row that was already
     * started stays: its run is history.
     */
    public function removeSchedule(int $scheduleId): void
    {
        $this->actor();

        $row = DmSchedule::query()->with('message')->findOrFail($scheduleId);

        if ($row->dm_run_id !== null) {
            Flux::toast(__('Diese Nachricht ist schon raus und steht im Verlauf.'), variant: 'danger');

            return;
        }

        $label = $row->message->type->label();
        $message = $row->message;
        $row->delete();

        // The text was written for this row only.
        if (! $message->runs()->exists() && ! DmSchedule::query()->where('dm_message_id', $message->id)->exists()) {
            $message->delete();
        }

        unset($this->scheduleRows, $this->upcoming);
        Flux::toast(__('„:type“ ist aus dem Zeitplan entfernt.', ['type' => $label]));
    }

    public function updatedPaused(DirectMessagePause $pause): void
    {
        $this->actor();

        if ($this->paused) {
            $pause->pause();
            Flux::toast(__('Versand pausiert. Bis zum Fortsetzen geht keine Nachricht raus.'));
        } else {
            $pause->resume();
            Flux::toast(__('Versand fortgesetzt. Was liegen geblieben ist, geht jetzt raus.'), variant: 'success');
        }

        $this->paused = $pause->isPaused();
        unset($this->pausedAt);
    }

    /**
     * @return EloquentCollection<int, Election>
     */
    #[Computed]
    public function elections(): EloquentCollection
    {
        $this->actor();

        return Election::query()->with('rounds:id,election_event_id,title,position')->orderByDesc('id')->get();
    }

    #[Computed]
    public function composeElection(): ?Election
    {
        $this->actor();

        return $this->electionId === null ? null : $this->elections->firstWhere('id', $this->electionId);
    }

    #[Computed]
    public function scheduleElection(): ?Election
    {
        $this->actor();

        return $this->scheduleElectionId === null ? null : $this->elections->firstWhere('id', $this->scheduleElectionId);
    }

    #[Computed]
    public function pausedAt(): ?CarbonInterface
    {
        $this->actor();

        return app(DirectMessagePause::class)->pausedAt();
    }

    /**
     * The latest runs with their counts per state.
     *
     * @return EloquentCollection<int, DmRun>
     */
    #[Computed]
    public function runs(): EloquentCollection
    {
        $this->actor();

        return DmRun::query()
            ->with(['message.author.profile', 'election'])
            ->withCount([
                'deliveries as sent_count' => fn ($query) => $query->where('status', DmDeliveryStatus::Sent->value),
                'deliveries as no_inbox_count' => fn ($query) => $query->where('status', DmDeliveryStatus::NoInbox->value),
                'deliveries as failed_count' => fn ($query) => $query->failedOrStuck(now()),
                'deliveries as seen_count' => fn ($query) => $query->whereNotNull('seen_at'),
            ])
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();
    }

    /**
     * Planned rows that have not gone out yet, soonest first.
     *
     * @return EloquentCollection<int, DmSchedule>
     */
    #[Computed]
    public function upcoming(): EloquentCollection
    {
        $this->actor();

        return DmSchedule::query()
            ->with(['message', 'election'])
            ->whereNull('dm_run_id')
            ->orderBy('send_at')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function openRun(): ?DmRun
    {
        $this->actor();

        return $this->openRunId === null ? null : DmRun::query()
            ->with(['deliveries' => fn ($query) => $query->with(['recipient.profile', 'relays'])->orderBy('id')])
            ->find($this->openRunId);
    }

    /**
     * Whether the sender key (NOSTR_NOTIFICATION_KEY) can seal a message.
     * Without it every Nostr delivery fails as `no_sender_key`, and only the
     * portal inbox copy arrives, so the board is told before it sends.
     * Yes or no only: the key itself never reaches the page.
     */
    #[Computed]
    public function senderKeyUsable(): bool
    {
        $this->actor();

        return app(NotificationKey::class)->isUsable();
    }

    /**
     * The audience sentence for the draft, counted now.
     */
    #[Computed]
    public function audienceSentence(): ?string
    {
        $this->actor();

        $audience = $this->composeAudience();

        if ($audience->needsElection() && $this->composeElection === null) {
            return null;
        }

        return app(DirectMessageAudience::class)->describe($audience, $this->composeElectionOrNull());
    }

    #[Computed]
    public function audienceCount(): int
    {
        $this->actor();

        $audience = $this->composeAudience();

        if ($audience->needsElection() && $this->composeElection === null) {
            return 0;
        }

        return app(DirectMessageAudience::class)->query($audience, $this->composeElectionOrNull())->count();
    }

    /**
     * What keeps the draft from being sent, in German and placed where it
     * belongs: under the text, under a value, or above everything.
     *
     * @return array{text: list<string>, variables: array<string, string>, general: list<string>}
     */
    #[Computed]
    public function draftProblems(): array
    {
        $this->actor();

        return $this->problemsOf($this->composeType(), $this->text, $this->messageVariables());
    }

    /**
     * The text as the first three recipients would get it.
     *
     * @return list<array{name: string, text: string}>
     */
    #[Computed]
    public function previews(): array
    {
        $this->actor();

        $audience = $this->composeAudience();

        if ($audience->needsElection() && $this->composeElection === null) {
            return [];
        }

        $result = app(DirectMessageDryRun::class)->handle(
            $this->composeType(),
            $audience,
            $this->composeElectionOrNull(),
            $this->messageVariables(),
            $this->text,
        );
        $names = EinundzwanzigPleb::query()->with('profile')->whereKey(array_column($result->previews, 'recipient_id'))->get()->keyBy('id');
        $text = app(DirectMessageText::class);

        // The header names the recipient as {name} in the text does (display name first);
        // without a profile name it falls back to the npub, not to „Mitglied“.
        return array_map(function (array $preview) use ($names, $text): array {
            $recipient = $names->get($preview['recipient_id']);

            return [
                'name' => ($recipient === null ? null : $text->profileNameOf($recipient)) ?? MemberProposal::authorNameOf($recipient),
                'text' => $preview['text'],
            ];
        }, $result->previews);
    }

    /**
     * @return EloquentCollection<int, DmSchedule>
     */
    #[Computed]
    public function scheduleRows(): EloquentCollection
    {
        $this->actor();

        return $this->scheduleElectionId === null ? new EloquentCollection : DmSchedule::query()
            ->with(['message', 'election', 'run'])
            ->where('election_event_id', $this->scheduleElectionId)
            ->orderBy('send_at')
            ->orderBy('id')
            ->get();
    }

    public function dryRunIsCurrent(): bool
    {
        return $this->dryRun !== null && $this->dryRun['signature'] === $this->draftSignature();
    }

    /**
     * @return list<DmMessageType>
     */
    public function scheduleTypes(): array
    {
        return self::SCHEDULE_TYPES;
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
     * To whom a run went, as one sentence.
     */
    protected function recipientsOf(DmRun $run): string
    {
        if ($run->audience !== null) {
            if ($run->audience->needsElection() && $run->election === null) {
                return trans_choice(':count Empfänger: :audience einer inzwischen gelöschten Wahl|:count Empfänger: :audience einer inzwischen gelöschten Wahl', $run->recipient_count, ['audience' => $run->audience->label()]);
            }

            return app(DirectMessageAudience::class)->describe($run->audience, $run->election, $run->recipient_count);
        }

        $authorId = $run->message?->author_pleb_id;

        if ($run->recipient_count === 1 && $authorId !== null && $run->recipient_ids === [(int) $authorId]) {
            return __('Test an :name', ['name' => MemberProposal::authorNameOf($run->message->author)]);
        }

        return trans_choice(':count Empfänger (Zielgruppe nicht gespeichert)|:count Empfänger (Zielgruppe nicht gespeichert)', $run->recipient_count);
    }

    /**
     * The four numbers of a run. Failed includes stuck claims
     * (DmDelivery::isStuck), and it is what a re-send picks up. Waiting
     * covers deliveries in work and recipients the fan-out has not reached
     * yet.
     *
     * Skipped are the members the repeat lock left out (DirectMessageRepeatLock):
     * they got no delivery in this run.
     *
     * @return array{sent: int, no_inbox: int, failed: int, waiting: int, seen: int, retry: int, skipped: int}
     */
    protected function countsOf(DmRun $run): array
    {
        $sent = (int) $run->sent_count;
        $noInbox = (int) $run->no_inbox_count;
        $failed = (int) $run->failed_count;

        return [
            'sent' => $sent,
            'no_inbox' => $noInbox,
            'failed' => $failed,
            'waiting' => max(0, $run->recipient_count - $sent - $noInbox - $failed),
            'seen' => (int) $run->seen_count,
            'retry' => $failed,
            'skipped' => count($run->skipped_ids ?? []),
        ];
    }

    /**
     * @param  array{sent: int, no_inbox: int, failed: int, waiting: int, seen: int, retry: int, skipped: int}  $counts
     */
    public function countsSentence(array $counts): string
    {
        $parts = array_filter([
            $counts['sent'] > 0 ? __(':count an Relays übergeben', ['count' => $counts['sent']]) : null,
            $counts['no_inbox'] > 0 ? __(':count ohne Posteingang', ['count' => $counts['no_inbox']]) : null,
            $counts['failed'] > 0 ? __(':count fehlgeschlagen', ['count' => $counts['failed']]) : null,
            $counts['waiting'] > 0 ? __(':count warten', ['count' => $counts['waiting']]) : null,
            ($counts['skipped'] ?? 0) > 0 ? __(':count übersprungen', ['count' => $counts['skipped']]) : null,
        ]);

        return $parts === [] ? __('Keine Empfänger') : implode(', ', $parts);
    }

    /**
     * The relays' answers for one delivery, as a short sentence.
     */
    protected function relaySummary(DmDelivery $delivery): string
    {
        $relays = $delivery->relays;

        if ($relays->isEmpty()) {
            return $delivery->status === DmDeliveryStatus::NoInbox ? __('keine Relays angegeben') : '';
        }

        $accepted = $relays->where('status', RelayPublishStatus::Accepted)->count();

        return __(':accepted von :total Relays haben angenommen', ['accepted' => $accepted, 'total' => $relays->count()]);
    }

    /**
     * The state of one delivery as the board reads it: a stuck claim is
     * failed, not "being sent".
     */
    protected function deliveryFailed(DmDelivery $delivery): bool
    {
        return $delivery->status->isFailure() || $delivery->isStuck(now());
    }

    protected function deliveryLabel(DmDelivery $delivery): string
    {
        return $delivery->isStuck(now()) ? __('Hängen geblieben, gilt als fehlgeschlagen') : $delivery->status->label();
    }

    protected function nameOf(?EinundzwanzigPleb $member): string
    {
        return MemberProposal::authorNameOf($member);
    }

    public function longDate(CarbonInterface $moment): string
    {
        $local = $moment->copy()->timezone(self::TIMEZONE)->locale('de');

        return $local->isoFormat('dd, D. MMMM YYYY').', '.$local->format('H:i').' '.__('Uhr');
    }

    public function shortDate(CarbonInterface $moment): string
    {
        $local = $moment->copy()->timezone(self::TIMEZONE)->locale('de');

        return $local->isoFormat('D. MMM YYYY').', '.$local->format('H:i');
    }

    /**
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
     * The board member behind the session, or 403. Asked on every request,
     * never remembered on the component.
     */
    protected function actor(): EinundzwanzigPleb
    {
        $pleb = NostrAuth::user()?->getPleb();

        abort_unless($pleb instanceof EinundzwanzigPleb && ! $pleb->isErased() && $pleb->isBoardMember(), 403);

        return $pleb;
    }

    protected function composeType(): DmMessageType
    {
        return DmMessageType::tryFrom($this->type) ?? DmMessageType::Individual;
    }

    protected function composeAudience(): DmAudience
    {
        return DmAudience::tryFrom($this->audience) ?? DmAudience::Members;
    }

    protected function composeElectionOrNull(): ?Election
    {
        return $this->composeAudience()->needsElection() ? $this->composeElection : null;
    }

    /**
     * Only the values the type knows, as strings.
     *
     * @return array<string, string>
     */
    protected function messageVariables(): array
    {
        return $this->valuesFor($this->composeType(), $this->variables);
    }

    /**
     * @return array<string, string>
     */
    protected function newMessageVariables(DmMessageType $type): array
    {
        return $this->valuesFor($type, $this->newVariables);
    }

    /**
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

    protected function draftSignature(): string
    {
        return hash('sha256', json_encode([
            $this->composeType()->value,
            $this->composeAudience()->value,
            $this->composeElectionOrNull()?->id,
            $this->messageVariables(),
            $this->text,
        ], JSON_THROW_ON_ERROR));
    }

    protected function forgetDraft(): void
    {
        unset($this->composeElection, $this->audienceSentence, $this->audienceCount, $this->draftProblems, $this->previews);
    }

    protected function defaultElection(): ?Election
    {
        return $this->elections->firstWhere('is_test', false) ?? $this->elections->first();
    }

    /**
     * Fill the type's values from the election and pick the audience the
     * type is usually for.
     */
    protected function applyTypeDefaults(DmMessageType $type, bool $onlyElectionValues = false): void
    {
        $defaults = $this->defaultValues($type, $this->composeElection);

        if (! $onlyElectionValues) {
            $this->audience = $this->defaultAudience($type)->value;
            $this->variables = $defaults;

            return;
        }

        foreach (['assembly_date', 'year', 'round'] as $fromElection) {
            if (array_key_exists($fromElection, $defaults)) {
                $this->variables[$fromElection] = $defaults[$fromElection];
            }
        }
    }

    protected function applyNewTypeDefaults(): void
    {
        $type = DmMessageType::tryFrom($this->newType) ?? DmMessageType::ElectionAnnouncement;
        $this->newAudience = $this->defaultAudience($type)->value;
        $this->newVariables = $this->defaultValues($type, $this->scheduleElection);
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
                'year' => (string) ($election?->fee_year ?? now(self::TIMEZONE)->year),
                'round' => (string) ($election?->rounds->first()?->title ?? ''),
                default => '',
            };
        }

        return $values;
    }
};
?>

@php
    $pausedAt = $isGuest ? null : $this->pausedAt;
@endphp

{{-- pb-32: the floating "Connect with Nostr" button of window.nostr.js sits
     at the bottom of the viewport; the last line must scroll clear of it. --}}
<div class="election-ui relative mx-auto w-full max-w-5xl pb-32">
    @if ($isGuest)
        <div class="max-w-2xl">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:callout.heading>{{ __('Melde dich mit Nostr an') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Die Nachrichten an Mitglieder sind dem Vorstand vorbehalten. Melde dich unten in der Navigation an.') }}</flux:callout.text>
            </flux:callout>
        </div>
    @else
        <header class="mb-8 flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
            <div class="max-w-2xl">
                <h1 class="text-3xl font-bold leading-tight text-text-primary">{{ __('Nachrichten an Mitglieder') }}</h1>
                <p class="mt-2 text-base text-text-secondary">
                    {{ __('Was der Verein als Nostr-Direktnachricht verschickt. Jede Nachricht steht zusätzlich im Posteingang des Mitglieds im Portal.') }}
                </p>
            </div>
            @php
                // One primary action at a time: an open run with failures offers its re-send as the primary one.
                // On the schedule tab „Einplanen“ is the primary one, as soon as there is an election to plan for.
                $openRunHasFailures = $tab === 'history' && $openRunId !== null && ($this->runs->firstWhere('id', $openRunId)?->failed_count ?? 0) > 0;
                $scheduleHasPrimary = $tab === 'schedule' && $this->scheduleElection !== null;
            @endphp
            @if ($tab !== 'compose')
                <flux:button wire:click="showTab('compose')" icon="pencil-square" :variant="$openRunHasFailures || $scheduleHasPrimary ? 'outline' : 'primary'" class="min-h-11" data-test="start-compose">
                    {{ __('Neue Nachricht') }}
                </flux:button>
            @endif
        </header>

        @unless ($this->senderKeyUsable)
            {{-- Not a reason to block: the portal inbox copy still arrives, and failed deliveries can be re-sent once the key is set. --}}
            <div role="status" class="mb-8 rounded-r-lg border-l-4 border-danger bg-bg-elevated p-4" data-test="sender-key-missing">
                <p class="flex items-start gap-3 text-base text-text-primary">
                    <flux:icon.key class="mt-0.5 size-5 shrink-0 text-danger" aria-hidden="true" />
                    <span><span class="font-bold">{{ __('Absenderschlüssel fehlt.') }}</span> {{ __('Nachrichten erreichen Mitglieder nur im Portal, nicht per Nostr. Das ist eine Server-Einstellung (NOSTR_NOTIFICATION_KEY).') }}</span>
                </p>
            </div>
        @endunless

        @if ($pausedAt)
            <div role="status" class="mb-8 flex flex-col gap-3 rounded-r-lg border-l-4 border-warning bg-bg-elevated p-4 sm:flex-row sm:items-center sm:justify-between" data-test="paused-line">
                <p class="flex items-start gap-3 text-base text-text-primary">
                    <flux:icon.pause-circle class="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
                    <span><span class="font-bold">{{ __('Versand pausiert') }}</span> {{ __('seit :when. Bis zum Fortsetzen geht keine Nachricht raus.', ['when' => $this->longDate($pausedAt)]) }}</span>
                </p>
                @if ($tab !== 'schedule')
                    <flux:button wire:click="showTab('schedule')" variant="outline" class="min-h-11 w-full shrink-0 sm:w-auto">{{ __('Zum Schalter') }}</flux:button>
                @endif
            </div>
        @endif

        @php
            $failedRuns = $this->runs->filter(fn ($run) => $run->failed_count > 0)->count();
            $upcomingCount = $this->upcoming->count();
            $tabs = [
                'history' => [__('Verlauf'), $failedRuns > 0 ? trans_choice(':count mit Fehlern|:count mit Fehlern', $failedRuns) : null],
                'compose' => [__('Neue Nachricht'), null],
                'schedule' => [__('Zeitplan'), $upcomingCount > 0 ? __(':count geplant', ['count' => $upcomingCount]) : null],
            ];
        @endphp
        <nav class="flex flex-wrap gap-x-2 border-b border-border-default" aria-label="{{ __('Bereiche') }}">
            @foreach ($tabs as $key => [$tabLabel, $tabCount])
                <button type="button" wire:click="showTab('{{ $key }}')" wire:key="tab-{{ $key }}"
                        @if ($tab === $key) aria-current="page" @endif
                        data-test="tab-{{ $key }}"
                        @class([
                            '-mb-px min-h-11 border-b-2 px-3 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
                            'border-orange-500 font-bold text-text-primary' => $tab === $key,
                            'border-transparent text-text-secondary hover:text-text-primary' => $tab !== $key,
                        ])>
                    {{ $tabLabel }}@if ($tabCount)<span @class(['ml-2 text-sm font-normal', 'text-danger' => $key === 'history', 'text-text-tertiary' => $key !== 'history'])>{{ $tabCount }}</span>@endif
                </button>
            @endforeach
        </nav>

        <div class="pt-8" data-test="work-area">
            @if ($tab === 'history')
                @include('livewire.association.messages.admin-history')
            @elseif ($tab === 'compose')
                @include('livewire.association.messages.admin-compose')
            @else
                @include('livewire.association.messages.admin-schedule')
            @endif
        </div>
    @endif
</div>
