<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Passagem única de tudo que a plataforma escreve para o COLABORADOR.
 *
 * Existe por causa do cliente grande que quer falar com o próprio pessoal pela
 * comunicação interna dele, e não por um remetente que ninguém lá reconhece.
 * A customização `welog_sends_emails` é o interruptor; aqui é onde ele age.
 *
 * Um ponto só, e não um `if` em cada `Mail::to(...)`: os disparos estão
 * espalhados por seis lugares — dois jobs, um controller e três serviços — e a
 * cada e-mail novo alguém esqueceria de repetir a verificação. O esquecimento
 * seria silencioso: a mensagem sai, o cliente que pediu para a WeLog não falar
 * com o pessoal dele descobre pelo colaborador.
 *
 * O convite de acesso NÃO passa por aqui, de propósito — ver `nunca` abaixo.
 */
class NotificaColaborador
{
    /**
     * Enfileira, a menos que o Cliente cuide da própria comunicação.
     *
     * @return bool false quando o envio foi suprimido pela customização
     */
    public static function enfileirar(?User $colaborador, Mailable $mensagem): bool
    {
        if ($colaborador === null || trim((string) $colaborador->email) === '') {
            return false;
        }

        if (! self::plataformaEscreve($colaborador, $mensagem)) {
            return false;
        }

        Mail::to($colaborador->email)->queue($mensagem);

        return true;
    }

    /**
     * O Cliente do colaborador deixou esta mensagem com a WeLog?
     *
     * Vale a chave geral e, para as etapas da jornada, a chave de cada uma
     * (Estúdio de mensagens): o Cliente pode querer os avisos de entrega e
     * não o primeiro aviso de liberação.
     *
     * Colaborador sem empresa vinculada recebe: a ausência de Cliente não é
     * uma escolha de ninguém, e silenciar por omissão de cadastro deixaria a
     * pessoa sem saber que tem brinde para resgatar.
     */
    private static function plataformaEscreve(User $colaborador, Mailable $mensagem): bool
    {
        $empresa = $colaborador->company;

        if ($empresa === null) {
            return true;
        }

        return $mensagem instanceof TemplatedMailable
            ? $empresa->enviaEtapa($mensagem->chaveDoModelo())
            : $empresa->customizacao('welog_sends_emails');
    }
}
