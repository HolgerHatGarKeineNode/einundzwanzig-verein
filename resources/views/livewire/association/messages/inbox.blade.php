<?php

use App\Jobs\Dm\SendInboxReply;
use App\Models\DmInboxMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\NotificationKey;
use App\Support\NostrAuth;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The board inbox: what members answered to the portal's direct messages,
 * and the board's replies. Read by every board member; opening a thread marks
 * the member's messages as read. Data helpers are protected, so a guest's
 * snapshot cannot call them over the wire.
 */
new
#[Title('Posteingang des Vorstands')]
class extends Component
{
    private const MAX_REPLY_CHARS = 4000;

    private const CONVERSATIONS_SHOWN = 100;

    #[Locked]
    public bool $isGuest = false;

    #[Url(as: 'mitglied')]
    public ?int $memberId = null;

    public string $reply = '';

    public function mount(): void
    {
        if (! NostrAuth::check()) {
            $this->isGuest = true;

            return;
        }

        $this->actor();

        if ($this->memberId !== null) {
            $this->open($this->memberId);
        }
    }

    public function open(int $memberId): void
    {
        $this->actor();

        if (! DmInboxMessage::query()->where('einundzwanzig_pleb_id', $memberId)->exists()) {
            $this->memberId = null;

            return;
        }

        $this->memberId = $memberId;
        $this->reply = '';
        $this->resetErrorBag();

        DmInboxMessage::query()
            ->where('einundzwanzig_pleb_id', $memberId)
            ->where('direction', DmInboxMessage::IN)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function close(): void
    {
        $this->actor();
        $this->memberId = null;
        $this->reply = '';
    }

    public function send(): void
    {
        $actor = $this->actor();

        $this->validate(
            ['reply' => ['required', 'string', 'min:2', 'max:'.self::MAX_REPLY_CHARS]],
            [
                'reply.required' => __('Schreib eine Antwort.'),
                'reply.min' => __('Schreib eine Antwort mit mindestens zwei Zeichen.'),
                'reply.max' => __('Die Antwort darf höchstens :max Zeichen lang sein.', ['max' => self::MAX_REPLY_CHARS]),
            ],
        );

        $member = $this->memberId === null ? null : EinundzwanzigPleb::query()->notErased()->find($this->memberId);

        if ($member === null || strlen(trim($this->reply)) > DirectMessageSigner::MAX_TEXT_BYTES) {
            $this->addError('reply', __('Diese Antwort lässt sich nicht senden. Lade die Seite neu.'));

            return;
        }

        $message = DmInboxMessage::query()->create([
            'einundzwanzig_pleb_id' => $member->id,
            'direction' => DmInboxMessage::OUT,
            'body' => trim($this->reply),
            'sent_at' => now(),
            'author_pleb_id' => $actor->id,
            'status' => DmInboxMessage::STATUS_QUEUED,
        ]);

        SendInboxReply::dispatch((int) $message->id);

        $this->reply = '';
        Flux::toast(__('Die Antwort an :name ist unterwegs.', ['name' => MemberProposal::authorNameOf($member)]), variant: 'success');
    }

    /**
     * One row per member who wrote, newest conversation first.
     *
     * @return Collection<int, array{member_id: int, name: string, preview: string, last_at: \Illuminate\Support\Carbon, unread: int}>
     */
    protected function conversations(): Collection
    {
        $latest = DmInboxMessage::query()
            ->selectRaw('einundzwanzig_pleb_id, max(id) as last_id')
            ->groupBy('einundzwanzig_pleb_id')
            ->orderByDesc('last_id')
            ->limit(self::CONVERSATIONS_SHOWN)
            ->pluck('last_id', 'einundzwanzig_pleb_id');

        $unread = DmInboxMessage::query()
            ->where('direction', DmInboxMessage::IN)
            ->whereNull('read_at')
            ->whereIn('einundzwanzig_pleb_id', $latest->keys())
            ->selectRaw('einundzwanzig_pleb_id, count(*) as unread')
            ->groupBy('einundzwanzig_pleb_id')
            ->pluck('unread', 'einundzwanzig_pleb_id');

        $last = DmInboxMessage::query()->with('member.profile')->whereIn('id', $latest->values())->get()->keyBy('id');

        return $latest->map(function (int $lastId, int $memberId) use ($last, $unread): array {
            $message = $last[$lastId];

            return [
                'member_id' => $memberId,
                'name' => MemberProposal::authorNameOf($message->member),
                'preview' => ($message->direction === DmInboxMessage::OUT ? __('Vorstand: ') : '').str($message->body)->squish()->limit(90)->toString(),
                'last_at' => $message->sent_at,
                'unread' => (int) ($unread[$memberId] ?? 0),
            ];
        })->values();
    }

    /**
     * @return Collection<int, DmInboxMessage>
     */
    protected function thread(): Collection
    {
        if ($this->memberId === null) {
            return collect();
        }

        return DmInboxMessage::query()
            ->with('author.profile')
            ->where('einundzwanzig_pleb_id', $this->memberId)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();
    }

    protected function memberName(): string
    {
        return MemberProposal::authorNameOf(EinundzwanzigPleb::query()->with('profile')->find($this->memberId));
    }

    protected function memberHasInbox(): bool
    {
        return EinundzwanzigPleb::query()->find($this->memberId)?->dmRelays()->exists() ?? false;
    }

    protected function inboxIsOn(): bool
    {
        return config('einundzwanzig.config.dm_inbox_relays', []) !== [] && NotificationKey::fromConfig()->isUsable();
    }

    protected function statusLabel(?string $status): string
    {
        return match ($status) {
            DmInboxMessage::STATUS_SENT => __('zugestellt'),
            DmInboxMessage::STATUS_NO_INBOX => __('nicht zugestellt: keine Nostr-Postfach-Relays'),
            DmInboxMessage::STATUS_FAILED => __('nicht zugestellt: kein Relay hat sie angenommen'),
            default => __('wird gesendet'),
        };
    }

    protected function actor(): EinundzwanzigPleb
    {
        $pleb = NostrAuth::user()?->getPleb();

        abort_unless($pleb instanceof EinundzwanzigPleb && ! $pleb->isErased() && $pleb->isBoardMember(), 403);

        return $pleb;
    }
};
?>

<div class="election-ui relative mx-auto w-full max-w-5xl pb-32" @unless ($isGuest) wire:poll.60s @endunless>
    @if ($isGuest)
        <div class="max-w-2xl">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:callout.heading>{{ __('Melde dich mit Nostr an') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Der Posteingang ist dem Vorstand vorbehalten. Melde dich unten in der Navigation an.') }}</flux:callout.text>
            </flux:callout>
        </div>
    @else
        @php
            $conversations = $this->conversations();
            $thread = $this->thread();
        @endphp

        <header class="mb-8 max-w-2xl">
            <h1 class="text-3xl font-bold leading-tight text-text-primary">{{ __('Posteingang des Vorstands') }}</h1>
            <p class="mt-2 text-base text-text-secondary">
                {{ __('Antworten von Mitgliedern auf die Nostr-Nachrichten des Vereins. Jedes Vorstandsmitglied sieht alle Gespräche; eure Antwort geht als Nostr-Direktnachricht vom Vereinskonto.') }}
            </p>
        </header>

        @unless ($this->inboxIsOn())
            <div role="status" class="mb-8 rounded-r-lg border-l-4 border-warning bg-bg-elevated p-4" data-test="inbox-off">
                <p class="flex items-start gap-3 text-base text-text-primary">
                    <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
                    <span><span class="font-bold">{{ __('Posteingang ist aus.') }}</span> {{ __('Es fehlt der Absenderschlüssel (NOSTR_NOTIFICATION_KEY) oder die Liste der Postfach-Relays (NOSTR_DM_INBOX_RELAYS). Neue Antworten kommen erst an, wenn beides gesetzt ist.') }}</span>
                </p>
            </div>
        @endunless

        <div class="grid gap-6 md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <section aria-label="{{ __('Gespräche') }}" @class(['hidden md:block' => $memberId !== null])>
                @if ($conversations->isEmpty())
                    <p class="rounded-lg border border-border-default p-6 text-base text-text-secondary" data-test="inbox-empty">{{ __('Noch hat kein Mitglied geantwortet.') }}</p>
                @else
                    <ul class="divide-y divide-border-subtle overflow-hidden rounded-lg border border-border-default">
                        @foreach ($conversations as $conversation)
                            <li wire:key="conversation-{{ $conversation['member_id'] }}">
                                <button type="button" wire:click="open({{ $conversation['member_id'] }})"
                                        @class(['flex min-h-11 w-full flex-col gap-1 px-4 py-3 text-left hover:bg-bg-elevated', 'bg-bg-elevated' => $memberId === $conversation['member_id']])
                                        @if ($memberId === $conversation['member_id']) aria-current="true" @endif
                                        data-test="conversation-{{ $conversation['member_id'] }}">
                                    <span class="flex w-full items-baseline justify-between gap-3">
                                        <span class="min-w-0 break-words text-base font-bold text-text-primary">{{ $conversation['name'] }}</span>
                                        <span class="shrink-0 text-sm tabular-nums text-text-tertiary">{{ $conversation['last_at']->timezone('Europe/Berlin')->format('d.m. H:i') }}</span>
                                    </span>
                                    <span class="flex w-full items-start justify-between gap-3">
                                        <span class="min-w-0 break-words text-sm text-text-secondary">{{ $conversation['preview'] }}</span>
                                        @if ($conversation['unread'] > 0)
                                            <flux:badge color="orange" size="sm" class="shrink-0" data-test="unread-{{ $conversation['member_id'] }}">{{ trans_choice('{1} 1 neu|[2,*] :count neu', $conversation['unread'], ['count' => $conversation['unread']]) }}</flux:badge>
                                        @endif
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-label="{{ __('Gespräch') }}" @class(['hidden md:block' => $memberId === null])>
                @if ($memberId === null)
                    <p class="rounded-lg border border-dashed border-border-default p-6 text-base text-text-secondary">{{ __('Wähle links ein Gespräch.') }}</p>
                @else
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="min-w-0 break-words text-xl font-bold text-text-primary" data-test="thread-name">{{ $this->memberName() }}</h2>
                        <flux:button wire:click="close" variant="ghost" icon="arrow-left" class="min-h-11 md:hidden">{{ __('Alle Gespräche') }}</flux:button>
                    </div>

                    <ol class="flex flex-col gap-3" data-test="thread">
                        @foreach ($thread as $message)
                            <li wire:key="inbox-message-{{ $message->id }}"
                                @class(['max-w-[90%] rounded-lg p-4', 'self-start border border-border-default' => $message->direction === \App\Models\DmInboxMessage::IN, 'self-end bg-bg-elevated' => $message->direction === \App\Models\DmInboxMessage::OUT])>
                                <p class="mb-1 text-sm text-text-tertiary">
                                    @if ($message->direction === \App\Models\DmInboxMessage::OUT)
                                        {{ __('Vorstand, :name', ['name' => \App\Models\MemberProposal::authorNameOf($message->author)]) }} ·
                                    @endif
                                    <span class="tabular-nums">{{ $message->sent_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                                    @if ($message->direction === \App\Models\DmInboxMessage::OUT)
                                        · <span @class(['text-danger' => in_array($message->status, [\App\Models\DmInboxMessage::STATUS_NO_INBOX, \App\Models\DmInboxMessage::STATUS_FAILED], true)])>{{ $this->statusLabel($message->status) }}</span>
                                    @endif
                                </p>
                                <p class="whitespace-pre-line break-words text-base text-text-primary">{{ $message->body }}</p>
                            </li>
                        @endforeach
                    </ol>

                    <form wire:submit="send" class="mt-6 flex flex-col gap-3">
                        @unless ($this->memberHasInbox())
                            <p class="text-sm text-warning" data-test="no-member-inbox">{{ __('Dieses Mitglied hat keine Nostr-Postfach-Relays veröffentlicht. Eine Antwort lässt sich speichern, kommt aber nicht an.') }}</p>
                        @endunless
                        <flux:field>
                            <flux:label>{{ __('Antwort') }}</flux:label>
                            <flux:textarea wire:model="reply" rows="4" maxlength="4000" data-test="reply" />
                            <flux:error name="reply" />
                        </flux:field>
                        <div class="flex justify-end">
                            <flux:button type="submit" variant="primary" icon="paper-airplane" class="min-h-11 w-full sm:w-auto" data-test="send-reply">{{ __('Antwort senden') }}</flux:button>
                        </div>
                    </form>
                @endif
            </section>
        </div>
    @endif
</div>
