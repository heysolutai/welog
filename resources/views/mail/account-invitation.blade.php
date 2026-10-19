<x-mail::message>
# Olá, {{ $userName }}!

Sua conta na **WeLog** está pronta. Falta só um passo: **criar a sua senha de acesso**.
{{ $intro }}

<x-mail::button :url="$setupUrl">
Criar minha senha
</x-mail::button>

<x-mail::panel>
**Como funciona depois disso:** {{ $comoFunciona }}
</x-mail::panel>

O link acima vale por **7 dias**. Se ele expirar, use a opção **"Esqueci minha senha"**
na tela de acesso com este mesmo e-mail: um link novo chega em instantes.

Qualquer dúvida, é só responder este e-mail.

Um abraço,<br>
**Equipe WeGift**

<x-slot:subcopy>
Se o botão não funcionar, copie e cole este endereço no seu navegador:
[{{ $setupUrl }}]({{ $setupUrl }})
</x-slot:subcopy>
</x-mail::message>
