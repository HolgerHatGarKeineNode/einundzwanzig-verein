<?php

use App\Jobs\Dm\SendInboxReply;
use App\Models\DmInboxMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Models\Profile;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\NotificationKey;
use App\Support\NostrAuth;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use swentel\nostr\Key\Key;

/**
 * The board inbox: every direct message to the association's key, from
 * members and anyone else, and the board's replies. Read by every board member; opening a thread marks
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

    /** The other side's pubkey (hex) of the open conversation. */
    #[Url(as: 'kontakt')]
    public ?string $contact = null;

    public string $reply = '';

    /**
     * Members by pubkey, null for anyone who is none, read once per request:
     * the list names up to CONVERSATIONS_SHOWN contacts and the open thread
     * asks for its member several times. Private, so never in the snapshot.
     *
     * @var array<string, EinundzwanzigPleb|null>
     */
    private array $members = [];

    /**
     * Cached Nostr profiles by pubkey, for contacts who are no member.
     *
     * @var array<string, Profile|null>
     */
    private array $profiles = [];

    public function mount(): void
    {
        if (! NostrAuth::check()) {
            $this->isGuest = true;

            return;
        }

        $this->actor();

        if ($this->contact !== null) {
            $this->open($this->contact);
        }
    }

    public function open(string $contact): void
    {
        $this->actor();

        if (! DmInboxMessage::query()->where('counterparty_pubkey', $contact)->exists()) {
            $this->contact = null;

            return;
        }

        $this->contact = $contact;
        $this->reply = '';
        $this->resetErrorBag();

        DmInboxMessage::query()
            ->where('counterparty_pubkey', $contact)
            ->where('direction', DmInboxMessage::IN)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function close(): void
    {
        $this->actor();
        $this->contact = null;
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

        $last = $this->contact === null ? null : DmInboxMessage::query()->where('counterparty_pubkey', $this->contact)->latest('id')->first();
        $member = $this->memberFor($this->contact);

        if ($last === null || ($last->einundzwanzig_pleb_id !== null && $member === null) || strlen(trim($this->reply)) > DirectMessageSigner::MAX_TEXT_BYTES) {
            $this->addError('reply', __('Diese Antwort lässt sich nicht senden. Lade die Seite neu.'));

            return;
        }

        $message = DmInboxMessage::query()->create([
            'einundzwanzig_pleb_id' => $member?->id,
            'counterparty_pubkey' => $this->contact,
            'direction' => DmInboxMessage::OUT,
            'body' => trim($this->reply),
            'sent_at' => now(),
            'author_pleb_id' => $actor->id,
            'status' => DmInboxMessage::STATUS_QUEUED,
        ]);

        SendInboxReply::dispatch((int) $message->id);

        $this->reply = '';
        Flux::toast(__('Die Antwort an :name ist unterwegs.', ['name' => $this->nameOf($this->contact)]), variant: 'success');
    }

    /**
     * One row per person who wrote, newest conversation first.
     *
     * @return Collection<int, array{contact: string, name: string, is_member: bool, preview: string, last_at: \Illuminate\Support\Carbon, unread: int}>
     */
    protected function conversations(): Collection
    {
        $latest = DmInboxMessage::query()
            ->whereNotNull('counterparty_pubkey')
            ->selectRaw('counterparty_pubkey, max(id) as last_id')
            ->groupBy('counterparty_pubkey')
            ->orderByDesc('last_id')
            ->limit(self::CONVERSATIONS_SHOWN)
            ->pluck('last_id', 'counterparty_pubkey');

        $unread = DmInboxMessage::query()
            ->where('direction', DmInboxMessage::IN)
            ->whereNull('read_at')
            ->whereIn('counterparty_pubkey', $latest->keys())
            ->selectRaw('counterparty_pubkey, count(*) as unread')
            ->groupBy('counterparty_pubkey')
            ->pluck('unread', 'counterparty_pubkey');

        $last = DmInboxMessage::query()->whereIn('id', $latest->values())->get()->keyBy('id');
        $this->loadContacts($latest->keys()->all());
        app(\App\Support\NostrAvatars::class)->preload($latest->keys());

        return $latest->map(function (int $lastId, string $contact) use ($last, $unread): array {
            $message = $last[$lastId];

            return [
                'contact' => $contact,
                'name' => $this->nameOf($contact),
                'is_member' => $this->memberFor($contact) !== null,
                'preview' => ($message->direction === DmInboxMessage::OUT ? __('Vorstand: ') : '').str($message->body)->squish()->limit(90)->toString(),
                'last_at' => $message->sent_at,
                'unread' => (int) ($unread[$contact] ?? 0),
            ];
        })->values();
    }

    /**
     * @return Collection<int, DmInboxMessage>
     */
    protected function thread(): Collection
    {
        if ($this->contact === null) {
            return collect();
        }

        return DmInboxMessage::query()
            ->with('author.profile')
            ->where('counterparty_pubkey', $this->contact)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Reads the members and, for everyone else, the cached profiles behind
     * `$contacts` in two queries, so naming the list does not query per row.
     *
     * @param  list<string>  $contacts
     */
    protected function loadContacts(array $contacts): void
    {
        $missing = array_values(array_diff($contacts, array_keys($this->members)));

        if ($missing === []) {
            return;
        }

        $members = EinundzwanzigPleb::query()->notErased()->with(['profile', 'dmRelays'])->whereIn('pubkey', $missing)->get()->keyBy('pubkey');
        $others = [];

        foreach ($missing as $contact) {
            $this->members[$contact] = $members->get($contact);

            if ($this->members[$contact] === null && ! array_key_exists($contact, $this->profiles)) {
                $others[] = $contact;
            }
        }

        if ($others !== []) {
            $profiles = Profile::query()->whereIn('pubkey', $others)->get()->keyBy('pubkey');

            foreach ($others as $contact) {
                $this->profiles[$contact] = $profiles->get($contact);
            }
        }
    }

    protected function memberFor(?string $contact): ?EinundzwanzigPleb
    {
        if ($contact === null) {
            return null;
        }

        $this->loadContacts([$contact]);

        return $this->members[$contact];
    }

    /**
     * A member's portal name; for anyone else the cached Nostr profile name or a short npub.
     */
    protected function nameOf(?string $contact): string
    {
        if ($contact === null) {
            return '';
        }

        if (($member = $this->memberFor($contact)) !== null) {
            return MemberProposal::authorNameOf($member);
        }

        // Loaded with the member lookup above: a contact who is no member has an entry here.
        $profile = $this->profiles[$contact] ?? null;

        if (filled($profile?->name) || filled($profile?->display_name)) {
            return (string) ($profile->name ?: $profile->display_name);
        }

        $npub = (new Key)->convertPublicKeyToBech32($contact);

        return substr($npub, 0, 12).'…'.substr($npub, -6);
    }

    protected function contactIsMember(): bool
    {
        return $this->memberFor($this->contact) !== null;
    }

    protected function memberHasInbox(): bool
    {
        $member = $this->memberFor($this->contact);

        // Anyone else's list is read when the answer is sent.
        return $member === null || $member->dmRelays !== null;
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
                {{ __('Alle Nostr-Direktnachrichten an das Vereinskonto, von Mitgliedern und allen anderen. Jedes Vorstandsmitglied sieht alle Gespräche; eure Antwort geht als Nostr-Direktnachricht vom Vereinskonto.') }}
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
            <section aria-label="{{ __('Gespräche') }}" @class(['hidden md:block' => $contact !== null])>
                @if ($conversations->isEmpty())
                    <p class="rounded-lg border border-border-default p-6 text-base text-text-secondary" data-test="inbox-empty">{{ __('Noch ist keine Nachricht angekommen.') }}</p>
                @else
                    <ul class="divide-y divide-border-subtle overflow-hidden rounded-lg border border-border-default">
                        @foreach ($conversations as $conversation)
                            {{-- The open button spans the whole row; the name chip sits above it, so its card opens instead of the thread. --}}
                            <li wire:key="conversation-{{ $conversation['contact'] }}"
                                @class(['relative flex min-h-11 flex-col gap-1 px-4 py-3 hover:bg-bg-elevated', 'bg-bg-elevated' => $contact === $conversation['contact']])
                                data-test="conversation-{{ $conversation['contact'] }}">
                                <button type="button" wire:click="open('{{ $conversation['contact'] }}')"
                                        class="absolute inset-0 cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-orange-500"
                                        @if ($contact === $conversation['contact']) aria-current="true" @endif
                                        aria-label="{{ __('Unterhaltung mit :name öffnen', ['name' => $conversation['name']]) }}"
                                        data-test="open-{{ $conversation['contact'] }}"></button>
                                <span class="pointer-events-none relative flex w-full items-baseline justify-between gap-3">
                                    <span class="min-w-0 break-words text-base font-bold text-text-primary"><span class="pointer-events-auto relative"><x-nostr-profile :pubkey="$conversation['contact']" :name="$conversation['name']" /></span>@unless ($conversation['is_member'])<span class="ms-2 whitespace-nowrap text-sm font-normal text-text-tertiary" data-test="not-member">{{ __('kein Mitglied') }}</span>@endunless</span>
                                    <span class="shrink-0 text-sm tabular-nums text-text-tertiary">{{ $conversation['last_at']->timezone('Europe/Berlin')->format('d.m. H:i') }}</span>
                                </span>
                                <span class="pointer-events-none relative flex w-full items-start justify-between gap-3">
                                    <span class="min-w-0 break-words text-sm text-text-secondary">{{ $conversation['preview'] }}</span>
                                    @if ($conversation['unread'] > 0)
                                        {{-- Unread as on the member's message page: white border and the word, no state colour. --}}
                                        <span class="shrink-0 rounded-sm border border-text-primary px-1.5 text-sm font-bold whitespace-nowrap text-text-primary" data-test="unread-{{ $conversation['contact'] }}">{{ trans_choice('{1} 1 neu|[2,*] :count neu', $conversation['unread'], ['count' => $conversation['unread']]) }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-label="{{ __('Gespräch') }}" @class(['hidden md:block' => $contact === null])>
                @if ($contact === null)
                    <p class="rounded-lg border border-dashed border-border-default p-6 text-base text-text-secondary">{{ __('Wähle links ein Gespräch.') }}</p>
                @else
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h2 class="min-w-0 break-words text-xl font-bold text-text-primary" data-test="thread-name"><x-nostr-profile :pubkey="$contact" :name="$this->nameOf($contact)" size="lg" />@unless ($this->contactIsMember())<span class="ms-2 whitespace-nowrap text-sm font-normal text-text-tertiary">{{ __('kein Mitglied') }}</span>@endunless</h2>
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
