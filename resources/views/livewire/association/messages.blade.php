<?php

use App\Enums\DmDeliveryStatus;
use App\Models\EinundzwanzigPleb;
use App\Services\Dm\DirectMessageInbox;
use App\Support\NostrAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Nachrichten vom Verein')]
class extends Component
{
    /** The delivery shown in full; one at a time. */
    public ?int $openId = null;

    /**
     * The member behind the session, or null. Resolved on every request,
     * never stored on the component.
     */
    protected function viewer(): ?EinundzwanzigPleb
    {
        $pleb = NostrAuth::user()?->getPleb();

        return $pleb instanceof EinundzwanzigPleb && ! $pleb->isErased() ? $pleb : null;
    }

    #[Computed]
    public function isMember(): bool
    {
        return $this->viewer() !== null;
    }

    /**
     * @return Collection<int, array{delivery_id: int, status: string, type: string, label: string, text: string|null, received_at: string, seen_at: string|null}>
     */
    #[Computed]
    public function messages(): Collection
    {
        $viewer = $this->viewer();

        return $viewer === null ? collect() : app(DirectMessageInbox::class)->messages($viewer);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return $this->messages->whereNull('seen_at')->count();
    }

    /**
     * True when at least one message reached the member only here because
     * their Nostr account names no relays for private messages.
     */
    #[Computed]
    public function hasNoInbox(): bool
    {
        return $this->messages->contains('status', DmDeliveryStatus::NoInbox->value);
    }

    /**
     * Open a message (or close it again) and mark it seen. An id that is not
     * in the member's own inbox changes nothing.
     */
    public function open(int $deliveryId): void
    {
        if ($this->openId === $deliveryId) {
            $this->openId = null;

            return;
        }

        $viewer = $this->viewer();

        if ($viewer === null || ! $this->messages->contains('delivery_id', $deliveryId)) {
            return;
        }

        $this->openId = $deliveryId;

        if (app(DirectMessageInbox::class)->markSeen($viewer, $deliveryId)) {
            unset($this->messages, $this->unreadCount);

            // The navigation sits in the layout, outside this component.
            $this->js('document.querySelectorAll("[data-inbox-link] [data-flux-navlist-badge]").forEach((badge) => { badge.textContent = '.$this->unreadCount.'; badge.hidden = '.$this->unreadCount.' === 0; })');
        }
    }

    public function receivedAt(string $iso): string
    {
        return Carbon::parse($iso)->timezone('Europe/Berlin')->locale('de')->isoFormat('D. MMMM YYYY, HH:mm');
    }

    /**
     * The first line that says something: the greeting every standard text
     * opens with ("Hallo Satoshi,") would make every row look alike.
     */
    public function preview(?string $text): string
    {
        $lines = collect(preg_split('/\R/u', (string) $text))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values();

        $first = $lines->first(fn (string $line, int $index): bool => ! ($index === 0 && $lines->count() > 1 && preg_match('/^(hallo|hi|liebe|lieber|moin|servus)\b.{0,70},$/iu', $line)));

        return $first ?? '';
    }
};
?>

{{-- pb-32: the floating "Connect with Nostr" button of window.nostr.js sits
     at the bottom of the viewport; the last line must scroll clear of it. --}}
<div class="relative mx-auto w-full max-w-3xl pb-32">
    @if (! $this->isMember)
        <flux:callout variant="warning" icon="exclamation-circle">
            <flux:callout.heading>{{ __('Nachrichten sind nur für Mitglieder sichtbar') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Bitte melde dich zunächst mit Nostr an.') }}</flux:callout.text>
        </flux:callout>
    @else
        <header class="mb-8">
            <h1 class="text-3xl font-bold leading-tight text-text-primary sm:text-4xl">{{ __('Nachrichten vom Verein') }}</h1>
            @if ($this->messages->isNotEmpty())
                <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                    @if ($this->unreadCount > 0)
                        <strong class="font-bold text-text-primary">{{ __(':count ungelesen.', ['count' => $this->unreadCount]) }}</strong>
                    @endif
                    {{ __('Was der Vorstand dir als Nostr-Direktnachricht schickt, steht auch hier. Antworten auf diese Nachrichten liest niemand; wen du stattdessen erreichst, steht am Ende jeder Nachricht.') }}
                </p>
            @endif
        </header>

        @if ($this->messages->isEmpty())
            <section class="rounded-lg border border-dashed border-border-default px-6 py-10" aria-labelledby="no-messages">
                <h2 id="no-messages" class="text-xl font-bold text-text-primary">{{ __('Noch keine Nachrichten') }}</h2>
                <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                    {{ __('Hier erscheinen die Nachrichten, die der Vorstand an Mitglieder schickt, etwa zur Wahl oder zum Mitgliedsbeitrag.') }}
                    {{ __('Hast du in deinem Nostr-Konto Relays für private Nachrichten angegeben, kommen sie auch dort an und du liest sie in jedem Client mit privaten Nachrichten (NIP-17), zum Beispiel Amethyst oder 0xchat.') }}
                </p>
            </section>
        @else
            @if ($this->hasNoInbox)
                <p role="note" class="mb-6 flex max-w-prose items-start gap-3 text-base leading-normal text-text-secondary">
                    <flux:icon.inbox class="mt-0.5 size-5 shrink-0 text-text-tertiary" aria-hidden="true" />
                    <span>{{ __('Dein Nostr-Konto gibt keine Relays für private Nachrichten an, deshalb kann der Verein dir dort nichts zustellen und du liest seine Nachrichten nur hier.') }}</span>
                </p>
            @endif

            <ul class="divide-y divide-border-default overflow-hidden rounded-lg border border-border-default bg-bg-surface" aria-label="{{ __('Nachrichten, neueste zuerst') }}">
                @foreach ($this->messages as $message)
                    @php
                        $isOpen = $this->openId === $message['delivery_id'];
                        $isUnread = $message['seen_at'] === null;
                        $onlyHere = $message['status'] !== \App\Enums\DmDeliveryStatus::Sent->value;
                    @endphp
                    <li wire:key="message-{{ $message['delivery_id'] }}" @class(['relative', 'bg-bg-elevated' => $isOpen])>
                        {{-- Unread: a white rule on the leading edge plus the word "Neu"; colour alone carries nothing. --}}
                        @if ($isUnread)
                            <span class="absolute inset-y-0 start-0 w-1 bg-text-primary" aria-hidden="true"></span>
                        @endif
                        <button type="button"
                                wire:click="open({{ $message['delivery_id'] }})"
                                aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                aria-controls="message-{{ $message['delivery_id'] }}"
                                class="grid min-h-11 w-full grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-4 gap-y-1 px-5 py-4 text-start hover:bg-white/[4%] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-orange-500">
                            <span class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
                                @if ($isUnread)
                                    <span class="rounded-sm border border-text-primary px-1.5 text-sm font-bold text-text-primary">{{ __('Neu') }}</span>
                                @endif
                                <span @class(['text-base', 'font-bold text-text-primary' => $isUnread, 'font-medium text-text-secondary' => ! $isUnread])>{{ $message['label'] }}</span>
                                @if ($onlyHere)
                                    <span class="text-sm text-text-tertiary">{{ __('nur hier') }}</span>
                                @endif
                            </span>
                            <time datetime="{{ $message['received_at'] }}" class="justify-self-end text-sm whitespace-nowrap text-text-tertiary max-sm:col-span-2 max-sm:row-start-2 max-sm:justify-self-start">
                                {{ $this->receivedAt($message['received_at']) }}
                            </time>
                            @unless ($isOpen)
                                <span class="col-span-2 truncate text-base text-text-secondary">{{ $this->preview($message['text']) }}</span>
                            @endunless
                        </button>

                        @if ($isOpen)
                            <div id="message-{{ $message['delivery_id'] }}" class="px-5 pb-6">
                                @if ($message['text'] === null)
                                    <p class="text-base text-text-secondary">{{ __('Diese Nachricht lässt sich nicht mehr anzeigen. Der Vorstand hat sie nach dem Versand geändert.') }}</p>
                                @else
                                    <div class="max-w-[65ch] text-base leading-7 break-words whitespace-pre-line text-text-primary [&_a]:py-4 [&_a]:underline [&_a]:decoration-text-tertiary [&_a]:underline-offset-4 [&_a:hover]:decoration-text-primary [&_a]:focus-visible:outline-2 [&_a]:focus-visible:outline-offset-2 [&_a]:focus-visible:outline-orange-500">{{ \App\Support\PlainTextLinks::toHtml($message['text']) }}</div>
                                @endif
                                <p class="mt-4 text-sm leading-normal text-text-tertiary">
                                    @switch($message['status'])
                                        @case(\App\Enums\DmDeliveryStatus::Sent->value)
                                            {{ __('Auch als Nostr-Direktnachricht an dich gegangen.') }}
                                            @break
                                        @case(\App\Enums\DmDeliveryStatus::NoInbox->value)
                                            {{ __('Nur hier: Dein Nostr-Konto gibt keine Relays für private Nachrichten an.') }}
                                            @break
                                        @default
                                            {{ __('Nur hier: Über Nostr ist diese Nachricht nicht angekommen.') }}
                                    @endswitch
                                </p>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
