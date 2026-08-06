@php
    $currentRoute = request()->route()->getName();
    $isCurrentRouteClass = 'bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-100';
    $isNotCurrentRouteClass = 'text-gray-600 dark:text-gray-400';
@endphp

    <!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @hasSection('meta')
        @yield('meta')
    @else
        {!! seo($SEOData ?? null) !!}
    @endif
    <title>{{ $title ?? 'Page Title' }}</title>
    <script src="https://kit.fontawesome.com/866fd3d0ab.js" crossorigin="anonymous"></script>
    @googlefonts
    {{-- Seiteneigene Skripte, die VOR app.js laufen muessen. app.js startet
         Alpine; wer eine Alpine-Komponente bereitstellt, muss sie vorher
         registriert haben, sonst verarbeitet Alpine das x-data-Element
         erfolglos und die Komponente greift nie. --}}
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-bg-page antialiased">
<flux:sidebar sticky stashable
              aria-label="Hauptnavigation"
              class="w-72 border-e border-border-default bg-bg-surface">
    <flux:sidebar.toggle class="lg:hidden" icon="x-mark" aria-label="Menü schließen"/>

    <flux:sidebar.header>
        <flux:sidebar.brand href="/" name="EINUNDZWANZIG">
            <img src="{{ asset('einundzwanzig-alpha.jpg') }}" alt="Logo" class="h-6 w-6">
        </flux:sidebar.brand>
    </flux:sidebar.header>

    @if(\App\Support\NostrAuth::check())
        <flux:navlist variant="outline">
            <flux:navlist.group heading="Mitgliedsbereich" class="grid">
                <flux:navlist.item icon="identification" :href="route('association.profile')"
                                   :current="request()->routeIs('association.profile')"
                                   wire:navigate>Meine Mitgliedschaft</flux:navlist.item>
                <flux:navlist.item icon="rss" :href="route('association.news')"
                                   :current="request()->routeIs('association.news')"
                                   wire:navigate>News</flux:navlist.item>
                <flux:navlist.item icon="gift" :href="route('association.benefits')"
                                   :current="request()->routeIs('association.benefits')"
                                   wire:navigate>Vorteile</flux:navlist.item>
                <flux:navlist.item icon="heart" :href="route('association.projectSupport')"
                                   :current="request()->routeIs('association.projectSupport*')"
                                   wire:navigate>Projekt-Unterstützungen</flux:navlist.item>
            </flux:navlist.group>

            <flux:navlist.group heading="Admin" class="grid">
                <flux:navlist.item icon="users" :href="route('association.members.admin')"
                                   :current="request()->routeIs('association.members.admin')"
                                   wire:navigate>Mitglieder</flux:navlist.item>
            </flux:navlist.group>
        </flux:navlist>
    @endif

    <flux:spacer/>

    <flux:navlist variant="outline">
        <flux:navlist.group heading="Info" class="grid">
            <flux:navlist.item icon="information-circle"
                               href="https://einundzwanzig.space/kontakt/"
                               target="_blank">Impressum</flux:navlist.item>
        </flux:navlist.group>
    </flux:navlist>

    <div class="px-1.5 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))]">
        <livewire:auth-button location="sidebar"/>
    </div>
</flux:sidebar>

<flux:header class="lg:hidden bg-bg-surface">
    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" aria-label="Menü öffnen"/>

    <flux:spacer/>

    <flux:brand href="/" name="EINUNDZWANZIG">
        <img src="{{ asset('einundzwanzig-alpha.jpg') }}" alt="Logo" class="h-6 w-6">
    </flux:brand>
</flux:header>

<flux:main>
    {{ $slot }}
</flux:main>

@persist('toast')
<flux:toast/>
@endpersist

@fluxScripts
@livewireScriptConfig
<script>
    if (!localStorage.getItem('flux.appearance')) {
        localStorage.setItem('flux.appearance', 'dark');
    }
    window.wnjParams = {
        position: 'bottom',
        accent: 'orange',
        startHidden: false,
        compactMode: false,
        disableOverflowFix: false,
    }
</script>
<script src="{{ asset('dist/window.nostr.min.js.js') }}"></script>
</body>
</html>
