<?php

use App\Models\Election;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use swentel\nostr\Event\Event as NostrEvent;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;

new class extends Component {
    #[Locked]
    public bool $isAllowed = false;

    #[Locked]
    public bool $showLog = false;

    #[Locked]
    public ?string $currentPubkey = null;

    #[Locked]
    public ?EinundzwanzigPleb $currentPleb = null;

    public array $events = [];

    public array $boardEvents = [];

    public Election $election;

    public array $plebs = [];

    public string $search = '';

    public string $signThisEvent = '';

    public bool $isNotClosed = true;

    public array $positions = [
        'presidency' => ['icon' => 'fa-crown', 'title' => 'Präsidium'],
        'board' => ['icon' => 'fa-users', 'title' => 'Vizepräsidium'],
    ];

    protected $listeners = [
        'nostrLoggedIn' => 'handleNostrLoggedIn',
        'nostrLoggedOut' => 'handleNostrLoggedOut',
    ];

    #[Computed]
    public function loadedEvents(): \Illuminate\Support\Collection
    {
        return collect($this->events)
            ->map(function ($event) {
                $profile = Profile::query()
                    ->where('pubkey', $event['pubkey'])
                    ->first()
                    ?->toArray();
                $votedFor = Profile::query()
                    ->where('pubkey', str($event['content'])->before(',')->toString())
                    ->first()
                    ?->toArray();

                return [
                    'id' => $event['id'],
                    'kind' => $event['kind'],
                    'content' => $event['content'],
                    'pubkey' => $event['pubkey'],
                    'tags' => $event['tags'],
                    'created_at' => $event['created_at'],
                    'profile' => $profile,
                    'votedFor' => $votedFor,
                    'type' => str($event['content'])->after(',')->toString(),
                ];
            })
            ->sortByDesc('created_at')
            ->unique(fn ($event) => $event['pubkey'].$event['type'])
            ->values();
    }

    #[Computed]
    public function loadedBoardEvents(): \Illuminate\Support\Collection
    {
        return collect($this->boardEvents)
            ->map(function ($event) {
                $profile = Profile::query()
                    ->where('pubkey', $event['pubkey'])
                    ->first()
                    ?->toArray();
                $votedFor = Profile::query()
                    ->where('pubkey', str($event['content'])->before(',')->toString())
                    ->first()
                    ?->toArray();

                return [
                    'id' => $event['id'],
                    'kind' => $event['kind'],
                    'content' => $event['content'],
                    'pubkey' => $event['pubkey'],
                    'tags' => $event['tags'],
                    'created_at' => $event['created_at'],
                    'profile' => $profile,
                    'votedFor' => $votedFor,
                    'type' => str($event['content'])->after(',')->toString(),
                ];
            })
            ->sortByDesc('created_at')
            ->values();
    }

    #[Computed]
    public function electionConfig(): \Illuminate\Support\Collection
    {
        $loadedEvents = $this->loadedEvents();

        return collect($this->election->candidates)
            ->map(function ($c) use ($loadedEvents) {
                $candidates = Profile::query()
                    ->whereIn('pubkey', $c['c'])
                    ->get()
                    ->map(function ($p) use ($loadedEvents, $c) {
                        $votedClass = ' bg-green-500/20 text-green-700';
                        $notVotedClass = ' bg-zinc-500/20 text-zinc-100';
                        $hasVoted = $loadedEvents
                            ->filter(fn ($e) => $e['type'] === $c['type'] && $e['pubkey'] === $this->currentPubkey)
                            ->firstWhere('votedFor.pubkey', $p->pubkey);

                        return [
                            'pubkey' => $p->pubkey,
                            'name' => $p->name,
                            'picture' => $p->picture,
                            'votedClass' => $hasVoted ? $votedClass : $notVotedClass,
                        ];
                    });

                return [
                    'type' => $c['type'],
                    'c' => $c['c'],
                    'candidates' => $candidates,
                ];
            });
    }

    #[Computed]
    public function electionConfigBoard(): \Illuminate\Support\Collection
    {
        $loadedBoardEvents = $this->loadedBoardEvents();

        return collect($this->election->candidates)
            ->map(function ($c) use ($loadedBoardEvents) {
                $candidates = Profile::query()
                    ->whereIn('pubkey', $c['c'])
                    ->get()
                    ->map(function ($p) use ($loadedBoardEvents, $c) {
                        $votedClass = ' bg-green-500/20 text-green-700';
                        $notVotedClass = ' bg-zinc-500/20 text-zinc-100';
                        $hasVoted = $loadedBoardEvents
                            ->filter(fn ($e) => $e['type'] === $c['type'] && $e['pubkey'] === $this->currentPubkey)
                            ->firstWhere('votedFor.pubkey', $p->pubkey);

                        return [
                            'pubkey' => $p->pubkey,
                            'name' => $p->name,
                            'picture' => $p->picture,
                            'votedClass' => $hasVoted ? $votedClass : $notVotedClass,
                            'hasVoted' => $hasVoted,
                        ];
                    });

                return [
                    'type' => $c['type'],
                    'c' => $c['c'],
                    'candidates' => $candidates,
                ];
            });
    }

    public function mount(Election $election): void
    {
        $this->election = $election;
        $this->plebs = EinundzwanzigPleb::query()
            ->with(['profile'])
            ->whereIn('association_status', [3, 4])
            ->orderBy('association_status', 'desc')
            ->get()
            ->toArray();
        $this->loadEvents();
        $this->loadBoardEvents();
        if ($this->election->end_time?->isPast() || ! config('services.voting')) {
            $this->isNotClosed = false;
        }

        $nostrUser = NostrAuth::user();
        if ($nostrUser) {
            $this->currentPubkey = $nostrUser->getPubkey();
            $this->currentPleb = $nostrUser->getPleb();
            $this->isAllowed = Gate::forUser($nostrUser)->allows('vote', $this->election);
        }
    }

    public function handleNostrLoggedIn($signedEvent = null): void
    {
        $executed = RateLimiter::attempt(
            'nostr-login:'.request()->ip(),
            10,
            function () {},
        );

        if (! $executed) {
            abort(429, 'Too many login attempts.');
        }

        $pubkey = NostrAuth::loginWithSignedEvent($signedEvent);

        $this->currentPubkey = $pubkey;
        $this->currentPleb = EinundzwanzigPleb::query()
            ->where('pubkey', $pubkey)->first();

        $nostrUser = NostrAuth::user();
        $this->isAllowed = $nostrUser && Gate::forUser($nostrUser)->allows('vote', $this->election);
    }

    public function handleNostrLoggedOut(): void
    {
        $this->currentPubkey = null;
        $this->currentPleb = null;
        $this->isAllowed = false;
    }

    public function updatedSearch($value): void
    {
        $this->plebs = EinundzwanzigPleb::query()
            ->with(['profile'])
            ->whereIn('association_status', [3, 4])
            ->where(fn ($query) => $query
                ->where('pubkey', 'like', "%$value%")
                ->orWhereHas('profile', fn ($query) => $query->whereLike('name', "%$value%")))
            ->orderBy('association_status', 'desc')
            ->get()
            ->toArray();
    }

    public function handleNewVote(): void
    {
        $this->loadEvents();
        $this->loadBoardEvents();
    }

    public function loadEvents(): void
    {
        $this->events = $this->loadNostrEvents([32122]);
    }

    public function loadBoardEvents(): void
    {
        $this->boardEvents = $this->loadNostrEvents([2121]);
    }

    public function loadNostrEvents($kinds): array
    {
        $relayUrl = config('services.relay');
        if (! $relayUrl) {
            return [];
        }

        $subscription = new Subscription;
        $subscriptionId = $subscription->setId();
        $filter = new Filter;
        $filter->setKinds($kinds);
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);
        $relaySet = new RelaySet;
        $relaySet->setRelays([new Relay($relayUrl)]);
        $request = new Request($relaySet, $requestMessage);
        $response = $request->send();

        return collect($response[$relayUrl] ?? [])
            ->map(function ($event) {
                if (! isset($event->event)) {
                    return false;
                }

                return [
                    'id' => $event->event->id,
                    'kind' => $event->event->kind,
                    'content' => $event->event->content,
                    'pubkey' => $event->event->pubkey,
                    'tags' => $event->event->tags,
                    'created_at' => $event->event->created_at,
                ];
            })
            ->filter()
            ->toArray();
    }

    public function vote($pubkey, $type, $board = false): void
    {
        Gate::forUser(NostrAuth::user())->authorize('vote', $this->election);

        $executed = RateLimiter::attempt(
            'voting:'.request()->ip(),
            10,
            function () {},
        );

        if (! $executed) {
            abort(429, 'Too many voting attempts.');
        }

        if ($this->election->end_time?->isPast()) {
            $this->isNotClosed = false;

            return;
        }
        $note = new NostrEvent;
        $note->setKind($board ? 2121 : 32122);
        if (! $board) {
            $dTag = sprintf('%s,%s,%s', $this->currentPleb->pubkey, date('Y'), $type);
            $note->setTags([['d', $dTag]]);
        }
        $note->setContent("$pubkey,$type");
        $this->signThisEvent = $note->toJson();
    }

    public function checkElection(): void
    {
        if ($this->election->end_time?->isPast()) {
            $this->isNotClosed = false;
        }
    }

    public function signEvent($event): void
    {
        $executed = RateLimiter::attempt(
            'voting:'.request()->ip(),
            10,
            function () {},
        );

        if (! $executed) {
            abort(429, 'Too many voting attempts.');
        }

        $note = new NostrEvent;
        $note->setId($event['id']);
        $note->setSignature($event['sig']);
        $note->setKind($event['kind']);
        $note->setContent($event['content']);
        $note->setPublicKey($event['pubkey']);
        $note->setTags($event['tags']);
        $note->setCreatedAt($event['created_at']);
        $relayUrl = config('services.relay');
        if (! $relayUrl) {
            return;
        }
        $eventMessage = new EventMessage($note);
        $relay = new Relay($relayUrl);
        $relay->setMessage($eventMessage);
        $relay->send();
    }

};
?>

<div>
    @if($isAllowed)
        <div x-cloak class="relative flex h-full" x-data="nostrApp(@this)"
             wire:poll.600000ms="checkElection">

            <!-- Inbox sidebar -->
            <div id="inbox-sidebar"
                 class="absolute z-20 top-0 bottom-0 w-full md:w-auto md:static md:top-auto md:bottom-auto -mr-px md:translate-x-0 transition-transform duration-200 ease-in-out"
                 :class="inboxSidebarOpen ? 'translate-x-0' : '-translate-x-full'">
                <div
                    class="sticky top-16 bg-white dark:bg-[#1B1B1B] overflow-x-hidden overflow-y-auto no-scrollbar shrink-0 border-r border-zinc-200 dark:border-zinc-700/60 md:w-[18rem] xl:w-[20rem] h-[calc(100dvh-64px)]">

                    <!-- #Marketing group -->
                    <div>
                        <!-- Group header -->
                        <div class="sticky top-0 z-10">
                            <div class="flex items-center bg-white dark:bg-[#1B1B1B] border-b border-zinc-200 dark:border-zinc-700/60 px-5 h-16">
                                <div class="w-full flex items-center justify-between">
                                    <!-- Channel menu -->
                                    <div class="relative" x-data="{ open: false }">
                                        <button class="grow flex items-center truncate" aria-haspopup="true"
                                                @click.prevent="open = !open" :aria-expanded="open">
                                            <div class="truncate">
                                        <span
                                            class="font-semibold text-zinc-800 dark:text-zinc-100">2024</span>
                                            </div>
                                            <svg
                                                class="w-3 h-3 shrink-0 ml-1 fill-current text-zinc-400 dark:text-zinc-500"
                                                viewBox="0 0 12 12">
                                                <path d="M5.9 11.4L.5 6l1.4-1.4 4 4 4-4L11.3 6z"/>
                                            </svg>
                                        </button>
                                        <div
                                            class="origin-top-right z-10 absolute top-full left-0 min-w-60 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700/60 py-1.5 rounded-lg shadow-lg overflow-hidden mt-1"
                                            @click.outside="open = false" @keydown.escape.window="open = false"
                                            x-show="open"
                                            x-transition:enter="transition ease-out duration-200 transform"
                                            x-transition:enter-start="opacity-0 -translate-y-2"
                                            x-transition:enter-end="opacity-100 translate-y-0"
                                            x-transition:leave="transition ease-out duration-200"
                                            x-transition:leave-start="opacity-100"
                                            x-transition:leave-end="opacity-0"
                                            x-cloak>
                                            <ul>
                                                <li>
                                                    <a class="font-medium text-sm text-zinc-600 dark:text-zinc-300 hover:text-zinc-800 dark:hover:text-zinc-200 block py-1.5 px-3"
                                                   href="#0" @click="open = false" @focus="open = true"
                                                   @focusout="open = false">
                                                    <div class="flex items-center justify-between">
                                                        <div class="grow flex items-center truncate">
                                                            <div class="truncate">2024</div>
                                                        </div>
                                                        <svg
                                                            class="w-3 h-3 shrink-0 fill-current text-orange-500 ml-1"
                                                            viewBox="0 0 12 12">
                                                            <path
                                                                d="M10.28 1.28L3.989 7.575 1.695 5.28A1 1 0 00.28 6.695l3 3a1 1 0 001.414 0l7-7A1 1 0 0010.28 1.28z"/>
                                                        </svg>
                                                    </div>
                                                </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Group body -->
                        <div class="px-5 py-4">
                            <!-- Search form -->
                            <form class="relative">
                                <label for="inbox-search" class="sr-only">Search</label>
                                <input
                                    wire:model.live.debounce="search"
                                    id="inbox-search" class="form-input w-full pl-9 bg-white dark:bg-zinc-800"
                                    type="search" placeholder="Suche…"/>
                                <button class="absolute inset-0 right-auto group" type="submit" aria-label="Search">
                                    <svg
                                        class="shrink-0 fill-current text-zinc-400 dark:text-zinc-500 group-hover:text-zinc-500 dark:group-hover:text-zinc-400 ml-3 mr-2"
                                        width="16" height="16" viewBox="0 0 16 16"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M7 14c-3.86 0-7-3.14-7-7s3.14-7 7-7 7 3.14 7 7-3.14 7-7 7zM7 2C4.243 2 2 4.243 2 7s2.243 5 5 5 5-2.243 5-5-2.243-5-5-5z"/>
                                        <path
                                            d="M15.707 14.293L13.314 11.9a8.019 8.019 0 01-1.414 1.414l2.393 2.393a.997.997 0 001.414 0 .999.999 0 000-1.414z"/>
                                    </svg>
                                </button>
                            </form>
                            <!-- Inbox -->
                            <div class="mt-4">
                                <div class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase mb-3">
                                    Plebs
                                </div>
                                <ul class="mb-6">
                                    @foreach($plebs as $pleb)
                                        <li class="-mx-2">
                                            <div class="flex w-full p-2 rounded text-left">
                                                <img class="w-8 h-8 rounded-full mr-2 bg-black"
                                                     src="{{ $pleb['profile']['picture'] ?? 'https://robohash.org/test' }}"
                                                     onerror="this.onerror=null; this.src='https://robohash.org/test';"
                                                     width="32"
                                                     height="32"
                                                     alt="A"/>
                                                <div class="grow truncate">
                                                    <div class="flex items-center justify-between mb-1.5">
                                                        <div class="truncate">
                                                <span
                                                    class="text-sm font-semibold text-zinc-800 dark:text-zinc-100 truncate">{{ $pleb['profile']['name'] ?? $pleb['pubkey'] }}</span>
                                                        </div>
                                                        <div class="text-xs text-zinc-500 font-medium">
                                                            <x-badge
                                                                :color="\App\Enums\AssociationStatus::from($pleb['association_status'])->color()"
                                                                :label="\App\Enums\AssociationStatus::from($pleb['association_status'])->label()"/>
                                                        </div>
                                                    </div>
                                                    <div
                                                        class="text-xs font-medium text-zinc-800 dark:text-zinc-100 truncate mb-0.5">
                                                        <div class="flex items-center space-x-2 h-5">
                                                            @foreach($positions as $name => $p)
                                                                @php
                                                                    $votedResult = $this->loadedEvents->filter(fn ($e) => $e['pubkey'] === $pleb['pubkey'])->firstWhere('type', $name);
                                                                @endphp
                                                                <div class="flex space-x-2"
                                                                     wire:key="p_{{ $name }}">
                                                                    @if($votedResult)
                                                                        <i class="fa-sharp-duotone fa-solid {{ $p['icon'] }} w-4 h-4 fill-current text-green-500"></i>
                                                                    @endif
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Inbox body -->
                @if($currentPubkey)
                    <div class="grow flex flex-col md:translate-x-0 transition-transform duration-300 ease-in-out"
                         :class="inboxSidebarOpen ? 'translate-x-1/3' : 'translate-x-0'">

                        <!-- Header -->
                        <div class="sticky top-16">
                            <div
                                class="flex items-center justify-between before:absolute before:inset-0 before:backdrop-blur-md before:bg-zinc-50/90 dark:before:bg-[#1B1B1B]/90 before:-z-10 border-b border-zinc-200 dark:border-zinc-700/60 px-4 sm:px-6 md:px-5 h-16">
                                <div
                                     class="flex flex-col space-y-2 sm:space-y-0 sm:flex-row justify-between items-center w-full">
                                     <div>
                                         @if($isNotClosed)
                                             <flux:badge color="success" label="Die Wahl ist geöffnet bis zum {{ $election->end_time?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}"/>
                                         @else
                                             <flux:badge color="danger" label="Die Wahl ist geschlossen"/>
                                         @endif
                                     </div>
                                     <div>
                                         <flux:button secondary
                                                   :href="route('association.election.admin', ['election' => $election])"
                                                   label="Wahl-Admin">
                                        </flux:button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Body -->
                        <div class="grow px-4 sm:px-6 md:px-5 py-4">

                            <!-- Mail subject -->
                            <header class="sm:flex sm:items-start space-x-4 mb-4">
                                <h1 class="text-xl leading-snug text-zinc-800 dark:text-zinc-100 font-bold mb-1 sm:mb-0 ml-2">
                                    Wahlen
                                </h1>
                                <button
                                    class="text-xs inline-flex font-bold bg-amber-500/20 text-sky-700 rounded-full text-center px-2.5 py-1 whitespace-nowrap">
                                    2024
                                </button>
                            </header>

                            <!-- Messages box -->
                            <div
                                class="shadow-sm rounded-xl px-6 divide-y divide-zinc-200 dark:divide-zinc-700/60">

                                <!-- Mail -->
                                <div class="py-6">
                                    <h1 class="text-xl leading-snug text-zinc-800 dark:text-zinc-100 font-bold mb-1 sm:mb-0 ml-2">
                                        Wahl des Präsidiums
                                    </h1>
                                    <div class="grid sm:grid-cols-2 gap-6">
                                        <flux:card>
<header>
                                                    <div class="flex items-center justify-between">
                                                        <i class="fa-sharp-duotone fa-solid {{ $positions['presidency']['icon'] }} w-9 h-9 fill-current text-white"></i>
                                                    </div>
                                                </header>
                                                <div class="grow mt-2">
                                                    <div
                                                        class="inline-flex text-zinc-800 dark:text-zinc-100 hover:text-zinc-900 dark:hover:text-white mb-1">
                                                        <h2 class="text-xl leading-snug font-semibold">{{ $positions['presidency']['title'] }}</h2>
                                                    </div>
                                                    <div class="text-sm">
                                                        @php
                                                            $votedResult = $this->loadedEvents->filter(fn ($event) => $event['pubkey'] === $currentPubkey)->firstWhere('type', 'presidency');
                                                            $votedName = $votedResult['votedFor']['name'] ?? 'error';
                                                        @endphp
                                                        @if($votedResult)
                                                            <span>Du hast "{{ $votedName }}" gewählt</span>
                                                        @else
                                                            <span>Wähle deinen Kandidaten für das Präsidium.</span>
                                                        @endif
                                                    </div>
                                                </div>
                                                <footer class="mt-5">
                                                    <div class="grid sm:grid-cols-2 gap-2">
                                                        @foreach($this->electionConfig->firstWhere('type', 'presidency')['candidates'] ?? [] as $c)
                                                            <div
                                                                @if($isNotClosed)wire:click="vote('{{ $c['pubkey'] }}', 'presidency')"
                                                                @endif
                                                                class="{{ $c['votedClass'] }} cursor-pointer text-xs inline-flex font-medium rounded-full text-center px-2.5 py-1">
                                                                <div class="flex items-center">
                                                                    <img class="w-6 h-6 rounded-full mr-2 bg-black"
                                                                         src="{{ $c['picture'] ?? 'https://robohash.org/' . $c['pubkey'] }}"
                                                                         onerror="this.onerror=null; this.src='https://robohash.org/{{ $c['pubkey'] }}';"
                                                                         width="24" height="24"
                                                                         alt="{{ $c['name'] }}"/>
                                                                    {{ $c['name'] }}
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </footer>
                                            </div>
                                        </flux:card>
                                    </div>

                                        <h1 class="mt-6 text-xl leading-snug text-zinc-800 dark:text-zinc-100 font-bold mb-1 sm:mb-0 ml-2">
                                        Wahl der übrigen Vorstandsmitglieder
                                    </h1>
                                    <div class="grid gap-6">
                                        <flux:card>
<div class="flex flex-col h-full p-5">
                                                <div class="grow mt-2">
                                                    <div class="text-sm">
                                                        <span>Klicke auf den Kandidaten, um seine Position als Vorstandsmitglied zu bestätigen.</span>
                                                    </div>
                                                </div>
                                                <footer class="mt-5">
                                                    <div class="grid sm:grid-cols-4 gap-2">
                                                        @foreach($this->electionConfigBoard->firstWhere('type', 'board')['candidates'] ?? [] as $c)
                                                            <div
                                                                @if($isNotClosed && !$c['hasVoted'])wire:click="vote('{{ $c['pubkey'] }}', 'board', true)"
                                                                @endif
                                                                class="{{ $c['votedClass'] }} cursor-pointer text-xs inline-flex font-medium rounded-full text-center px-2.5 py-1">
                                                                <div class="flex items-center">
                                                                    <img class="w-6 h-6 rounded-full mr-2 bg-black"
                                                                         src="{{ $c['picture'] ?? 'https://robohash.org/' . $c['pubkey'] }}"
                                                                         onerror="this.onerror=null; this.src='https://robohash.org/{{ $c['pubkey'] }}';"
                                                                         width="24" height="24"
                                                                         alt="{{ $c['name'] }}"/>
                                                                    {{ $c['name'] }}
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </footer>
                                        </flux:card>
                                        </div>

                                    </div>
                                </div>

                            </div>

                            <!-- Log events -->
                            <div x-cloak x-show="showLog" class="mt-6 hidden sm:block">
                                <flux:card mb-8>
                                    <header class="px-5 py-4">
                                        <h2 class="font-semibold text-zinc-800 dark:text-zinc-100">Präsidium Log <span
                                                class="text-zinc-400 dark:text-zinc-500 font-medium">{{ count($this->loadedEvents) }}</span>
                                        </h2>
                                    </header>
                                    <div>
                                        <!-- Table -->
                                        <div class="overflow-x-auto">
                                            <table
                                                class="table-auto w-full dark:text-zinc-300 divide-y divide-zinc-100 dark:divide-zinc-700/60">
                                                <!-- Table header -->
                                                <thead
                                                    class="text-xs uppercase text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-zinc-900/20 border-t border-zinc-100 dark:border-zinc-700/60">
                                                <tr>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">ID</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Kind</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Pubkey</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Created At</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Voted For</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Type</div>
                                                    </th>
                                                </tr>
                                                </thead>
                                                <!-- Table body -->
                                                <tbody class="text-sm">
                                                @foreach($this->loadedEvents as $event)
                                                    <tr>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div
                                                                class="font-medium">{{ Str::limit($event['id'], 10) }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['kind'] }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['profile']['name'] ?? '' }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['created_at'] }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['votedFor']['name'] ?? '' }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['type'] }}</div>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                            </flux:card>
                            </div>
                            <div x-cloak x-show="showLog" class="mt-6 hidden sm:block">
                                <flux:card mb-8>
                                    <header class="px-5 py-4">
                                        <h2 class="font-semibold text-zinc-800 dark:text-zinc-100">Board Log <span
                                                class="text-zinc-400 dark:text-zinc-500 font-medium">{{ count($this->loadedBoardEvents) }}</span>
                                        </h2>
                                    </header>
                                    <div>
                                        <!-- Table -->
                                        <div class="overflow-x-auto">
                                            <table
                                                class="table-auto w-full dark:text-zinc-300 divide-y divide-zinc-100 dark:divide-zinc-700/60">
                                                <!-- Table header -->
                                                <thead
                                                    class="text-xs uppercase text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-zinc-900/20 border-t border-zinc-100 dark:border-zinc-700/60">
                                                <tr>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">ID</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Kind</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Pubkey</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Created At</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Voted For</div>
                                                    </th>
                                                    <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                        <div class="font-semibold text-left">Type</div>
                                                    </th>
                                                </tr>
                                                </thead>
                                                <!-- Table body -->
                                                <tbody class="text-sm">
                                                @foreach($this->loadedBoardEvents as $event)
                                                    <tr>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div
                                                                class="font-medium">{{ Str::limit($event['id'], 10) }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['kind'] }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['profile']['name'] ?? '' }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['created_at'] }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['votedFor']['name'] ?? '' }}</div>
                                                        </td>
                                                        <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                            <div>{{ $event['type'] }}</div>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                            </flux:card>
                            </div>

                        </div>

                    </div>
                </div>
                @endif

            </div>
        @else
            <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
                <flux:callout variant="warning" icon="exclamation-circle">
                    <flux:heading>Zugriff auf Wahlen nicht möglich</flux:heading>
                    <p>
                        Zugriff auf die Wahlen ist nur für spezielle autorisierte Benutzer möglich.
                    </p>
                    <p class="mt-3">
                        @if(!NostrAuth::check())
                            Bitte melde dich zunächst mit Nostr an.
                        @else
                            Dein Benutzer-Account ist nicht für diese Funktion autorisiert. Bitte kontaktiere den Vorstand, wenn du Zugriff benötigst.
                        @endif
                    </p>
                </flux:callout>
            </div>
        @endif

    </div>
