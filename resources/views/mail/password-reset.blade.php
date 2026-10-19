<x-mail::message>
# Olá, {{ $userName }}!

Recebemos um pedido para **redefinir a senha** da sua conta na **WeLog**.
Para criar uma senha nova, é só clicar no botão abaixo.

<x-mail::button :url="$resetUrl">
Redefinir minha senha
</x-mail::button>

<x-mail::panel>
**Não foi você?** Pode ignorar este e-mail: a sua senha atual continua valendo e nada muda na sua conta.
</x-mail::panel>

O link acima vale por **{{ $validoPor }} minutos**. Se ele expirar, peça um novo na opção
**"Esqueci minha senha"**, na tela de acesso.

Qualquer dúvida, é só responder este e-mail.

Um abraço,<br>
**Equipe WeGift**

<x-slot:subcopy>
Se o botão não funcionar, copie e cole este endereço no seu navegador:
[{{ $resetUrl }}]({{ $resetUrl }})
</x-slot:subcopy>
</x-mail::message>
