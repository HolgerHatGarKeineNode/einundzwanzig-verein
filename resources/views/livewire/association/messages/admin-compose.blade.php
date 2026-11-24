<?php

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Livewire\Traits\WithMessageDrafts;
use App\Livewire\Traits\WithViewer;
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
use App\Services\Dm\StartDirectMessageRun;
use App\Services\Nostr\NotificationKey;
use Flux\Flux;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * "Neue Nachricht" of the board's message centre: a message from type to
 * confirmed sending — type, audience, text, preview, dry run, test to self,
 * send.
 *
 * A child of `association.messages.admin`, mounted with the page and shown
 * while its tab is open, so the draft outlives a tab switch.
 * Board only and callable on its own like every Livewire component: every
 * public method and every computed property resolves the acting board
 * member from the session and answers 403 to anybody else. A confirmed
 * sending is handed to the page as `message-sent` with the run's id.
 */
new class extends Component
{
    use WithMessageDrafts;
    use WithViewer;

    private const UNREACHABLE_NAMES_SHOWN = 8;

    /**
     * Whether the tab is open. The page keeps this tab mounted while another
     * one is open, so a half-written message survives a look at another tab;
     * hidden, it renders nothing.
     */
    #[Reactive]
    public bool $visible = false;

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

    public function mount(): void
    {
        $this->boardActor();

        $this->electionId = $this->defaultElection()?->id;
        $this->applyTypeDefaults(DmMessageType::ElectionAnnouncement);
        $this->text = DmMessageType::ElectionAnnouncement->defaultText();
    }

    public function updatedType(): void
    {
        $this->boardActor();

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
        $this->boardActor();

        $this->audience = (DmAudience::tryFrom($this->audience) ?? DmAudience::Members)->value;
        $this->forgetDraft();
    }

    public function updatedElectionId(): void
    {
        $this->boardActor();

        $election = $this->composeElection;
        $this->electionId = $election?->id;
        $this->applyTypeDefaults($this->composeType(), onlyElectionValues: true);
        $this->forgetDraft();
    }

    public function updatedText(): void
    {
        $this->boardActor();
        $this->forgetDraft();
    }

    public function updatedVariables(): void
    {
        $this->boardActor();
        $this->forgetDraft();
    }

    public function restoreDefaultText(): void
    {
        $this->boardActor();

        $this->text = $this->composeType()->defaultText();
        $this->forgetDraft();
    }

    /**
     * Count the audience and look at who cannot be reached. Writes nothing.
     */
    public function runDryRun(DirectMessageDryRun $dryRun): void
    {
        $this->boardActor();

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
        $actor = $this->boardActor();

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
     * dry run, so the number sent is always the number shown. A started
     * run is handed to the page as `message-sent`, which opens it in the
     * history.
     */
    public function confirmSend(ComposeDirectMessage $compose, StartDirectMessageRun $start, DirectMessagePause $pause): void
    {
        $actor = $this->boardActor();

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
        $this->dispatch('message-sent', runId: $run->id);

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

    #[Computed]
    public function composeElection(): ?Election
    {
        $this->boardActor();

        return $this->electionId === null ? null : $this->elections->firstWhere('id', $this->electionId);
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
        $this->boardActor();

        return app(NotificationKey::class)->isUsable();
    }

    /**
     * The audience sentence for the draft, counted now.
     */
    #[Computed]
    public function audienceSentence(): ?string
    {
        $this->boardActor();

        $audience = $this->composeAudience();

        if ($audience->needsElection() && $this->composeElection === null) {
            return null;
        }

        return app(DirectMessageAudience::class)->describe($audience, $this->composeElectionOrNull());
    }

    #[Computed]
    public function audienceCount(): int
    {
        $this->boardActor();

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
        $this->boardActor();

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
        $this->boardActor();

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

    public function dryRunIsCurrent(): bool
    {
        return $this->dryRun !== null && $this->dryRun['signature'] === $this->draftSignature();
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
};
?>

<div>
@if ($visible)
@php
    $composeType = \App\Enums\DmMessageType::tryFrom($type) ?? \App\Enums\DmMessageType::Individual;
    $composeAudience = \App\Enums\DmAudience::tryFrom($audience) ?? \App\Enums\DmAudience::Members;
    $problems = $this->draftProblems;
    $hasProblems = $problems['text'] !== [] || $problems['variables'] !== [] || $problems['general'] !== [];
    $needsElection = $composeAudience->needsElection();
    $showElection = $this->elections->isNotEmpty() && ($needsElection || $composeType !== \App\Enums\DmMessageType::Individual);
    $count = $this->audienceCount;
    $current = $this->dryRunIsCurrent();
    $sendLabel = trans_choice('An ein Mitglied senden|An :count Mitglieder senden', $dryRun['count'] ?? 0, ['count' => $dryRun['count'] ?? 0]);
    $stepNumber = 'flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-border-default text-sm font-bold text-text-secondary';
@endphp

{{-- One page in the order the decision is made: what, to whom, which words,
     how they read, how many, send. A real sequence, so a numbered list. --}}
<form wire:submit="runDryRun" class="max-w-3xl [&_input]:min-h-11 [&_select]:min-h-11">
    @if ($problems['general'] !== [])
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-8">
            @foreach ($problems['general'] as $problem)
                <flux:callout.text>{{ $problem }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    <ol class="space-y-12">
        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">1</span>
            <div>
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Art der Nachricht') }}</h2>
                <div class="mt-4">
                    <flux:select wire:model.live="type" :label="__('Nachricht')" data-test="compose-type">
                        @foreach (\App\Enums\DmMessageType::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">2</span>
            <div>
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Empfänger') }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="audience" :label="__('Wer bekommt sie')" data-test="compose-audience">
                        @foreach (\App\Enums\DmAudience::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($showElection)
                        <flux:select wire:model.live="electionId" :label="__('Wahl')" data-test="compose-election">
                            @foreach ($this->elections as $item)
                                <flux:select.option value="{{ $item->id }}">{{ $item->is_test ? __(':name (Probewahl)', ['name' => $item->name]) : $item->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                </div>
                {{-- The sentence is the answer to "to whom": counted now, in words. --}}
                <p class="mt-6 text-xl font-semibold leading-snug text-text-primary break-words" aria-live="polite" data-test="audience-sentence">
                    @if ($needsElection && $this->composeElection === null)
                        {{ __('Für diese Empfänger braucht es eine Wahl. Lege sie unter „Wahl leiten“ an.') }}
                    @else
                        {{ $this->audienceSentence }}
                    @endif
                </p>
                @if ($count === 0 && ! ($needsElection && $this->composeElection === null))
                    <p class="mt-2 text-base text-text-secondary">{{ __('Auf diese Auswahl passt heute niemand.') }}</p>
                @endif
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">3</span>
            <div class="min-w-0">
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Text') }}</h2>
                @if ($composeType->messageVariables() !== [])
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach ($composeType->messageVariables() as $variable)
                            <flux:field wire:key="variable-{{ $composeType->value }}-{{ $variable }}" @class(['sm:col-span-2' => $variable === 'link'])>
                                <flux:label>{{ $this->variableLabel($variable) }} <span class="ml-1 font-normal text-text-tertiary">{{ '{'.$variable.'}' }}</span></flux:label>
                                <flux:input wire:model.live.debounce.400ms="variables.{{ $variable }}" :placeholder="$this->variableHint($variable)" data-test="variable-{{ $variable }}" />
                                @isset ($problems['variables'][$variable])
                                    <p class="mt-1 text-sm text-text-secondary" data-test="variable-problem-{{ $variable }}">{{ $problems['variables'][$variable] }}</p>
                                @endisset
                            </flux:field>
                        @endforeach
                    </div>
                @endif

                <flux:field class="mt-6">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <flux:label for="compose-text">{{ __('Nachricht') }}</flux:label>
                        @if ($text !== $composeType->defaultText())
                            <flux:button type="button" wire:click="restoreDefaultText" variant="ghost" size="sm" class="min-h-11" data-test="restore-text">{{ __('Standardtext wiederherstellen') }}</flux:button>
                        @endif
                    </div>
                    <flux:textarea id="compose-text" wire:model.live.debounce.400ms="text" rows="9" class="leading-7" data-test="compose-text"
                                   :aria-invalid="$problems['text'] !== [] ? 'true' : null" aria-describedby="compose-text-help" />
                    @foreach ($problems['text'] as $problem)
                        <p class="mt-2 flex items-start gap-2 text-sm text-danger" data-test="text-problem">
                            <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ $problem }}
                        </p>
                    @endforeach
                    <p id="compose-text-help" class="mt-2 text-sm text-text-secondary">
                        {{ __('Nur Text, keine Formatierung: Nostr-Clients zeigen ihn, wie er ist. Platzhalter in geschweiften Klammern werden ersetzt:') }}
                        @foreach ($composeType->variables() as $variable)
                            <span class="whitespace-nowrap text-text-primary">{{ '{'.$variable.'}' }}</span>@if (! $loop->last), @endif
                        @endforeach
                        @if (in_array('name', $composeType->variables(), true))
                            {{ __('({name} ist der Name des Empfängers).') }}
                        @endif
                        {{ __('Der Hinweis, wohin Fragen gehen, wird unten angehängt.') }}
                    </p>
                </flux:field>
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">4</span>
            <div class="min-w-0">
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Vorschau') }}</h2>
                @if ($hasProblems)
                    <p class="mt-2 text-base text-text-secondary">{{ __('Die Vorschau erscheint, sobald der Text vollständig ist.') }}</p>
                @elseif ($this->previews === [])
                    <p class="mt-2 text-base text-text-secondary">{{ __('Ohne Empfänger gibt es keine Vorschau.') }}</p>
                @else
                    <p class="mt-2 text-base text-text-secondary">{{ trans_choice('So liest es der erste Empfänger.|So lesen es die ersten :count Empfänger.', count($this->previews), ['count' => count($this->previews)]) }}</p>
                    <div class="mt-4 space-y-6" data-test="previews">
                        @foreach ($this->previews as $preview)
                            <figure wire:key="preview-{{ $loop->index }}" class="max-w-[65ch]">
                                <figcaption class="text-sm text-text-tertiary break-words">{{ __('An :name, von EINUNDZWANZIG', ['name' => $preview['name']]) }}</figcaption>
                                <div class="mt-2 rounded-lg rounded-tl-none border border-border-default bg-bg-elevated px-4 py-3 text-base leading-7 break-words whitespace-pre-line text-text-primary">{{ $preview['text'] }}</div>
                            </figure>
                        @endforeach
                    </div>
                @endif
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">5</span>
            <div class="min-w-0">
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Probelauf') }}</h2>
                <p class="mt-2 text-base text-text-secondary">{{ __('Zählt die Empfänger und zeigt, wer keinen Nostr-Posteingang hat. Gesendet wird dabei nichts.') }}</p>
                <flux:button type="submit" variant="outline" icon="magnifying-glass" class="mt-4 min-h-11 w-full sm:w-auto" :disabled="$hasProblems || $count === 0" data-test="dry-run">
                    {{ __('Probelauf starten') }}
                </flux:button>

                @if ($dryRun !== null)
                    <div @class(['mt-6 rounded-r-lg border-l-4 bg-bg-elevated p-4 sm:p-6', 'border-orange-500' => $current, 'border-border-default' => ! $current]) aria-live="polite" data-test="dry-run-result">
                        @if (! $current)
                            <p class="text-base text-text-secondary">{{ __('Seit dem Probelauf hat sich etwas geändert. Starte ihn neu, dann siehst du die Zahl, die gesendet wird.') }}</p>
                        @else
                            <p class="text-lg text-text-primary">
                                <span class="font-bold">{{ trans_choice('Ein Empfänger.|:count Empfänger.', $dryRun['count'], ['count' => $dryRun['count']]) }}</span>
                                @if ($dryRun['count'] === 0 && ($dryRun['skipped'] ?? 0) > 0)
                                    {{-- Nobody left: the inbox sentence would speak of nobody. --}}
                                    {{ __('Alle haben diese Nachricht in den letzten :hours Stunden schon bekommen.', ['hours' => $dryRun['lock_hours']]) }}
                                @elseif ($dryRun['unreachable_count'] === 0)
                                    {{ __('Alle haben einen Nostr-Posteingang.') }}
                                @else
                                    {{ trans_choice('Einer davon hat keinen Nostr-Posteingang und liest die Nachricht nur im Portal.|:count davon haben keinen Nostr-Posteingang und lesen die Nachricht nur im Portal.', $dryRun['unreachable_count'], ['count' => $dryRun['unreachable_count']]) }}
                                @endif
                            </p>
                            @if ($dryRun['count'] > 0 && ! $this->senderKeyUsable)
                                <p class="mt-2 flex items-start gap-2 text-base font-semibold text-text-primary" data-test="dry-run-no-sender-key">
                                    <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-5 shrink-0 text-danger" aria-hidden="true" />
                                    <span>{{ trans_choice('Aber der Absenderschlüssel fehlt: Es geht keine Nostr-Nachricht raus, das Mitglied liest sie nur im Portal.|Aber der Absenderschlüssel fehlt: Es geht keine Nostr-Nachricht raus, alle :count lesen sie nur im Portal.', $dryRun['count'], ['count' => $dryRun['count']]) }}</span>
                                </p>
                            @endif
                            @if (($dryRun['skipped'] ?? 0) > 0)
                                <p class="mt-2 text-base text-text-secondary" data-test="skipped">
                                    {{ trans_choice('Übersprungen: ein Mitglied, es hat diese Nachricht für diese Zielgruppe in den letzten :hours Stunden schon bekommen.|Übersprungen: :count Mitglieder, sie haben diese Nachricht für diese Zielgruppe in den letzten :hours Stunden schon bekommen.', $dryRun['skipped'], ['count' => $dryRun['skipped'], 'hours' => $dryRun['lock_hours']]) }}
                                </p>
                            @endif
                            @if ($dryRun['unreachable'] !== [])
                                <p class="mt-2 text-sm text-text-secondary break-words" data-test="unreachable">
                                    {{ implode(', ', $dryRun['unreachable']) }}@if ($dryRun['unreachable_count'] > count($dryRun['unreachable'])){{ __(' und :count weitere', ['count' => $dryRun['unreachable_count'] - count($dryRun['unreachable'])]) }}@endif
                                </p>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">6</span>
            <div class="min-w-0">
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Senden') }}</h2>
                <p class="mt-2 text-base text-text-secondary">{{ __('Ein Test geht nur an dich. Danach sendest du an alle, die der Probelauf gezählt hat.') }}</p>
                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <flux:button type="button" wire:click="sendTestToSelf" variant="outline" icon="user" class="min-h-11 w-full sm:w-auto" :disabled="$hasProblems" data-test="send-test">
                        {{ __('Test an mich senden') }}
                    </flux:button>
                    @if ($current && $dryRun['count'] > 0)
                        <flux:modal.trigger name="confirm-send">
                            <flux:button type="button" variant="primary" icon="paper-airplane" class="min-h-11 w-full sm:w-auto" data-test="open-confirm">{{ $sendLabel }}</flux:button>
                        </flux:modal.trigger>
                    @else
                        <flux:button type="button" variant="primary" icon="paper-airplane" class="min-h-11 w-full sm:w-auto" disabled data-test="open-confirm-disabled">{{ $current && ($dryRun['skipped'] ?? 0) > 0 ? __('Alle schon erreicht, nichts zu senden') : __('Erst Probelauf, dann senden') }}</flux:button>
                    @endif
                </div>
            </div>
        </li>
    </ol>
</form>

@if ($current && $dryRun['count'] > 0)
    <flux:modal name="confirm-send" :closable="false" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ trans_choice('„:type“ an ein Mitglied senden?|„:type“ an :count Mitglieder senden?', $dryRun['count'], ['count' => $dryRun['count'], 'type' => $composeType->label()]) }}</flux:heading>
                <flux:text class="mt-2">{{ $this->audienceSentence }}.</flux:text>
                @if ($this->senderKeyUsable)
                    <flux:text class="mt-2">{{ __('Die Empfängerliste wird beim Start festgeschrieben und die Nachricht geht sofort raus. Eine Nostr-Nachricht lässt sich nicht zurückholen.') }}</flux:text>
                @else
                    <flux:text class="mt-2">{{ __('Die Empfängerliste wird beim Start festgeschrieben.') }}</flux:text>
                    <p class="mt-4 flex items-start gap-2 rounded-r-lg border-l-4 border-danger bg-bg-page p-3 text-base text-text-primary" data-test="confirm-no-sender-key">
                        <flux:icon.key variant="mini" class="mt-0.5 size-5 shrink-0 text-danger" aria-hidden="true" />
                        <span>{{ __('Der Absenderschlüssel fehlt: Die Nachricht steht nur im Posteingang im Portal, per Nostr geht nichts raus. Erneut senden lässt sie sich, sobald der Schlüssel eingetragen ist.') }}</span>
                    </p>
                @endif
            </div>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close>
                    {{-- Start focus on the safe choice, as in every election dialog: Enter right after opening sends nothing. --}}
                    <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto" autofocus data-test="cancel-send">{{ __('Abbrechen') }}</flux:button>
                </flux:modal.close>
                <flux:button wire:click="confirmSend" variant="primary" class="min-h-11 w-full sm:w-auto" data-test="confirm-send">{{ $sendLabel }}</flux:button>
            </div>
        </div>
    </flux:modal>
@endif
@endif
</div>
