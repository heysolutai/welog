<x-mail::layout>
{{-- Cabeçalho: a marca escrita, sem imagem. Logo por <img> vira quadrado
     vazio em quase todo cliente que bloqueia imagem por padrão. --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
We<span style="color: #d04304;">Log</span>
<div style="margin-top: 2px; font-size: 12px; font-weight: normal; color: #71717a; letter-spacing: 0.5px;">por WeGift</div>
</x-mail::header>
</x-slot:header>

{{-- Corpo --}}
{!! $slot !!}

{{-- Subtexto --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Rodapé em português, sem "All rights reserved" traduzido pela metade. --}}
<x-slot:footer>
<x-mail::footer>
Esta mensagem foi enviada pela plataforma WeLog. Se você não esperava por ela, pode ignorar com segurança.

© {{ date('Y') }} WeGift. Todos os direitos reservados.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
