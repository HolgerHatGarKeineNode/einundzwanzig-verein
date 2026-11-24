@props([
    'pleb' => null,
])

{{--
    A member's name as every page prints it (EinundzwanzigPleb::displayName()):
    profile name, else display name, else the shortened npub; an erased or
    deleted member is a former member.
--}}
<span {{ $attributes }}>{{ $pleb?->displayName() ?? __('Ehemaliges Mitglied') }}</span>
