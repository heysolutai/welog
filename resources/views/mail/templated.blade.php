<x-mail::message>
{!! $antes !!}

@if ($actionUrl && $comBotao)
<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>
@endif

{!! $depois !!}
</x-mail::message>
