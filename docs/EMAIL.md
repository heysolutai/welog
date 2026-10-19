# Envio de e-mails — WeLog

Como a plataforma envia e-mail, o que configurar em produção e como diagnosticar.

---

## O que a plataforma envia

| Chave (`email_templates`) | Quando dispara | Origem |
|---|---|---|
| — (fixo) | Convite de primeiro acesso, ao cadastrar cliente final ou Admin Cliente | `AccountInvitationMail` |
| `project_released` | Operador libera o Projeto (Fluxo A, etapa 9) | `ProjectReleasedMail` |
| `redemption_confirmed` | Cliente final confirma o resgate | `RedemptionConfirmedMail` |
| `shipment_update` | Expedição e entrega confirmada pelo rastreio | `ShipmentUpdateMail` |

Os três últimos têm **assunto e corpo editáveis** pelo Admin WeGift em
`/admin/email-templates` (Módulo 11). O convite é fixo por ser parte do fluxo
de autenticação.

### Segurança do conteúdo
O corpo do modelo é Markdown do Admin WeGift (confiável e renderizado como
tal). Já os **valores** das variáveis `{{ ... }}` vêm de dados de terceiros —
nome e endereço digitados pelo cliente final, descrição de evento dos Correios
— e são **escapados** antes da interpolação (`EmailTemplate::render`). Sem isso,
um endereço com HTML viraria vetor de phishing dentro de um e-mail legítimo da
marca.

---

## Entrega assíncrona

Todo e-mail é enfileirado na fila **`mail`** (`config/mail.php` → `queue`),
consumida pelo worker:

```
php artisan queue:work redis --queue=integrations,imports,mail,default
```

A fila é separada de propósito: uma enxurrada de jobs de integração não pode
atrasar o convite de acesso de um cliente final.

**Retentativas:** 5 tentativas com backoff de 1min → 5min → 15min → 1h
(`App\Mail\Concerns\QueuedDelivery`). Cobre indisponibilidade curta do
provedor. Esgotadas as tentativas, a mensagem cai em `failed_jobs`.

```bash
php artisan queue:failed                    # o que não foi entregue
php artisan queue:retry {id|all}            # reprocessar
```

---

## Configuração de produção

O `.env.example` traz os blocos prontos (SMTP, SES, Resend, failover). O
essencial:

1. **Domínio próprio no remetente.** `MAIL_FROM_ADDRESS` precisa ser do domínio
   da WeGift — não use `welog.local` nem um endereço de outro domínio, ou a
   mensagem cai em spam.
2. **SPF, DKIM e DMARC no DNS** do domínio remetente. Sem DKIM assinado pelo
   provedor, Gmail e Outlook rejeitam ou marcam como spam envios em volume.
   Registros típicos:
   - SPF: `v=spf1 include:<provedor> ~all` (um único registro TXT SPF no domínio)
   - DKIM: registro CNAME/TXT fornecido pelo provedor
   - DMARC: `v=DMARC1; p=none; rua=mailto:dmarc@seudominio` para começar
     monitorando antes de endurecer para `p=quarantine`.
3. **Porta e criptografia.** 587 com `MAIL_SCHEME=tls` (STARTTLS) é o padrão;
   465 exige `MAIL_SCHEME=smtps`. Provedores de nuvem costumam bloquear a 25.
4. **Não deixe `log` na cadeia de failover.** O envio "teria sucesso" gravando
   em arquivo, a mensagem seria perdida e a retentativa da fila nunca
   aconteceria. Failover só faz sentido entre dois provedores reais.

### Microsoft 365 sem SMTP (API do Graph)

Para a caixa do 365 que não tem "SMTP autenticado" (a Microsoft está
desligando o SMTP com usuário e senha). O envio sai pela API do Microsoft Graph
com um aplicativo do Entra ID: sem senha de usuário e sem depender de MFA.

No [Entra](https://entra.microsoft.com), com uma conta de administrador:

1. **Registros de aplicativo → Novo registro.** Nome "WeLog e-mail", "Somente
   contas deste diretório". Anote o **ID do aplicativo (cliente)** e o **ID do
   diretório (locatário)**.
2. **Certificados e segredos → Novo segredo do cliente.** Copie o **Valor**
   na hora (ele não aparece de novo) e anote quando vence: vencido, o envio para.
3. **Permissões de API → Adicionar → Microsoft Graph → Permissões de
   aplicativo → `Mail.Send`**, e depois **Conceder consentimento do
   administrador**.
4. **Recomendado: prender o aplicativo à caixa que envia.** Sem isso, o
   `Mail.Send` de aplicativo pode enviar como qualquer caixa do locatário. No
   PowerShell do Exchange Online:

   ```powershell
   New-ApplicationAccessPolicy -AppId <ID do aplicativo> `
     -PolicyScopeGroupId atendimento@seudominio.com.br `
     -AccessRight RestrictAccess -Description "WeLog envia só desta caixa"
   ```

No `.env`:

```dotenv
MAIL_MAILER=microsoft-graph
MICROSOFT_GRAPH_TENANT_ID=<ID do diretório>
MICROSOFT_GRAPH_CLIENT_ID=<ID do aplicativo>
MICROSOFT_GRAPH_CLIENT_SECRET=<Valor do segredo>
MAIL_FROM_ADDRESS="atendimento@seudominio.com.br"
```

`MICROSOFT_GRAPH_SENDER` só é preciso quando a caixa que envia é outra que não
a do `MAIL_FROM_ADDRESS`. Nesse caso, ela precisa da permissão "Enviar como"
sobre o remetente. SPF do 365: `include:spf.protection.outlook.com`. O DKIM é
ativado no portal do Microsoft Defender.

### Validar depois do deploy

```bash
docker compose -f docker-compose.prod.yml exec app \
  php artisan welog:mail:test voce@suaempresa.com
```

O comando imprime o mailer/host/porta/remetente em uso e envia de forma
**síncrona** — erro de credencial, TLS ou DNS aparece no terminal, em vez de
virar um job silencioso em `failed_jobs`.

---

## Desenvolvimento

O compose de dev sobe o **Mailpit**: nenhum e-mail sai para a internet e todos
ficam visíveis em <http://localhost:8025>. É o `MAIL_HOST=mailpit` /
`MAIL_PORT=1025` do `.env.example`.

Nos testes automatizados o mailer é `array` e as asserções usam `Mail::fake()`.

---

## Diagnóstico

| Sintoma | Onde olhar |
|---|---|
| Ninguém recebe nada | O worker está de pé? `docker compose ps worker` e `php artisan queue:failed` |
| Só o convite não chega | `users.invited_at` foi gravado? Reenvie pela tela do Projeto ou por `/admin/users` |
| Chega, mas cai em spam | SPF/DKIM/DMARC do domínio de `MAIL_FROM_ADDRESS`; painel de reputação do provedor |
| E-mail de entrega não sai | Rastreio precisa mapear para o status `entregue` — ver `TrackingStatus::fromCorreios` e `storage/logs/integrations-*.log` |
| Erro de autenticação SMTP | `php artisan welog:mail:test` mostra a exceção completa |
| Mudou o `.env` e nada mudou | `php artisan config:clear` (produção usa `config:cache`) |
