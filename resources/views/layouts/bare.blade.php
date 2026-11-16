{{-- A page without the portal frame: the share view for the video call and the printable minutes. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'EINUNDZWANZIG' }}</title>
    @googlefonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-bg-page text-text-primary antialiased print:bg-white print:text-black">
{{ $slot }}

@persist('toast')
<flux:toast/>
@endpersist

@fluxScripts
@livewireScriptConfig
</body>
</html>
