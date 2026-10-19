<?php

namespace App\Mail;

use App\Mail\Concerns\QueuedDelivery;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Convite de primeiro acesso à WeLog.
 *
 * O mesmo e-mail atende os três públicos, mas o que vem DEPOIS da senha é
 * diferente em cada um: o cliente final resgata um presente, o RH cadastra
 * as pessoas da empresa dele, e a equipe WeGift opera os Projetos. Falar de
 * "seu presente" para um operador é o tipo de detalhe que denuncia sistema
 * genérico, então o texto muda por perfil.
 */
class AccountInvitationMail extends Mailable
{
    use Queueable, QueuedDelivery, SerializesModels;

    public function __construct(public User $user, public string $token)
    {
        $this->queueForDelivery();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bem-vindo à WeLog: crie sua senha de acesso');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.account-invitation',
            with: [
                'userName' => $this->user->name,
                'setupUrl' => route('password.set.form', [
                    'token' => $this->token,
                    'email' => $this->user->email,
                ]),
            ] + $this->textoDoPerfil(),
        );
    }

    /**
     * @return array{intro: string, comoFunciona: string}
     */
    private function textoDoPerfil(): array
    {
        if ($this->user->isEndCustomer()) {
            // Menção à empresa (UAT): quem recebe o convite precisa saber QUEM
            // o cadastrou, senão o e-mail parece golpe. `label` é o nome de
            // exibição definido pela WeGift, com a razão social da Omie de
            // reserva. O valor entra no Blade via {{ }}, que escapa HTML —
            // nome de empresa é dado de terceiros.
            $empresa = $this->user->company?->label;

            return [
                'intro' => $empresa
                    ? 'Você foi cadastrado(a) pela '.$empresa.' para receber um presente, e é com essa senha '
                        .'que você entra na plataforma para escolher e acompanhar o que vai receber.'
                    : 'É com ela que você entra na plataforma para escolher e acompanhar o seu presente.',
                'comoFunciona' => 'você entra com o seu e-mail e a senha que criar, confere o presente disponível, '
                    .'informa o endereço de entrega e acompanha o envio por aqui mesmo.',
            ];
        }

        if ($this->user->isProjectAdmin()) {
            $projeto = $this->user->adminProject?->name;

            return [
                'intro' => 'É com ela que você acessa '
                    .($projeto ? 'o Projeto '.$projeto : 'o seu Projeto')
                    .' na WeLog para cadastrar quem vai receber os presentes e acompanhar cada etapa e entrega.',
                'comoFunciona' => 'você entra com o seu e-mail e a senha que criar, cadastra as pessoas do seu Projeto '
                    .'(uma a uma ou por planilha) e recebe por e-mail o aviso a cada mudança de etapa. Quando os kits '
                    .'estiverem prontos, a WeGift libera o resgate e todo mundo recebe o aviso.',
            ];
        }

        if ($this->user->isClientAdmin()) {
            // Também o nome de exibição: o Admin Cliente conhece a própria
            // empresa pelo nome do dia a dia, não pela razão social.
            $empresa = $this->user->company?->label;

            return [
                'intro' => 'É com ela que você acessa a área da '.($empresa ?: 'sua empresa')
                    .' na WeLog para cadastrar quem vai receber os presentes e acompanhar cada entrega.',
                'comoFunciona' => 'você entra com o seu e-mail e a senha que criar, cadastra as pessoas do Projeto '
                    .'(uma a uma ou por planilha) e acompanha os resgates. Quando os kits estiverem prontos, '
                    .'a WeGift libera o resgate e todo mundo recebe o aviso por e-mail.',
            ];
        }

        return [
            'intro' => 'É com ela que você entra na plataforma para acompanhar os Projetos, cadastrar Colaboradores e '
                .'liberar os resgates.',
            'comoFunciona' => 'você entra com o seu e-mail e a senha que criar e já tem acesso aos Projetos, '
                .'Resgates e Importações Parceiros, conforme o seu perfil.',
        ];
    }
}
