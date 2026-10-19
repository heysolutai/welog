# Checklist de Go-Live — WeLog

Passos para levar a plataforma de UAT a produção, conforme o cronograma do
Functional Design (Go Live em até 40 dias; estabilização de 7 dias).

## 1. Infraestrutura
- [x] Provisionar servidor/cloud com Docker e Docker Compose
- [x] Apontar DNS do domínio (registro A) para o IP do servidor
- [ ] TLS é emitido/renovado automaticamente pelo Let's Encrypt — ver passo 2.1 (não há mais cópia manual de `.pem`)
- [x] Backup automático do Postgres: serviço `backup` no compose de produção
      (dump diário, retenção 14d/8sem) — ver [BACKUP.md](BACKUP.md)
- [x] Persistência do Redis (AOF já habilitado no compose de produção)
- [ ] Conferir o backup depois do 1º deploy: `php artisan welog:backup:status`
- [ ] Cópia externa no Backblaze B2 — preencher `B2_*` no `.env`, criar a
      application key **restrita ao bucket e sem `deleteFiles`** e configurar a
      **lifecycle rule** de retenção (ver [BACKUP.md](BACKUP.md))
- [ ] Testar uma restauração real antes do go-live (procedimento em BACKUP.md)

## 2. Configuração (`.env` de produção)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domínio>`
- [ ] `APP_KEY` gerada (`php artisan key:generate`)
- [ ] Banco/Redis com credenciais fortes
- [ ] Correio real (SMTP/SES/Resend) no lugar do Mailpit — blocos prontos no
      `.env.example`; detalhes e DNS em [EMAIL.md](EMAIL.md)
- [ ] `MAIL_FROM_ADDRESS` no domínio da WeGift, com **SPF, DKIM e DMARC** publicados
- [ ] Validar o envio no servidor: `php artisan welog:mail:test voce@suaempresa.com`
- [ ] Tokens/credenciais das integrações (ver [INTEGRACOES.md](INTEGRACOES.md))
- [ ] `OMIE_WEBHOOK_TOKEN` forte e único
- [ ] `WELOG_DOMAIN` e `LETSENCRYPT_EMAIL` preenchidos (usados pelo bootstrap do TLS)

### 2.1 TLS (Let's Encrypt) — só na 1ª vez
Com o DNS já apontado e o `.env` preenchido, emita o primeiro certificado:
```bash
./docker/nginx/init-letsencrypt.sh          # use STAGING=1 ./... para testar sem gastar cota
```
O script sobe a stack, emite o certificado real e recarrega o nginx. Depois disso o
serviço `certbot` renova sozinho (a cada 12h) e o nginx recarrega a cada 6h. Não
precisa rodar de novo.

## 3. Deploy
O `init-letsencrypt.sh` acima já executa o `up -d --build`. Em seguida, prepare o app:
```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force   # só na 1ª vez
docker compose -f docker-compose.prod.yml exec app php artisan config:cache route:cache view:cache
```
Nos deploys seguintes (sem reemitir TLS), basta `up -d --build` + `migrate --force` + `config:cache`.
- [ ] Trocar as senhas dos usuários WeGift semeados no primeiro acesso

## 4. Integrações (virar de `fake` para `api`)
- [ ] Omie: `OMIE_DRIVER=api` + credenciais; cadastrar a URL do webhook no painel da Omie
- [ ] Omie: `OMIE_PROJECT_CODES` com o código do projeto "Venda" (descobrir com
      `php artisan welog:omie:projetos`) — sem ele TODO pedido da conta vira Projeto
- [ ] Omie: carga inicial dos pedidos que já existem:
      `php artisan welog:omie:sync-projects --dias=90 --dry-run` e, conferido, sem `--dry-run`
- [ ] Omie: limpar os Projetos que entraram ANTES do filtro (não são Venda) — o sync
      só cria, nunca apaga: `php artisan welog:projects:purge-non-venda --dias=365`
      (simulação) e, conferido, `--execute --force`. Confira o backup antes.
- [ ] Trello: `TRELLO_DRIVER=api` + key/token/secret/board + `TRELLO_WEBHOOK_CALLBACK_URL`; depois `php artisan welog:trello:setup --sem-cards` (mapeia listas + webhook, sem criar cards) e, para o teste, `--pedido=<nº do pedido>` cria o card de UM Projeto; sem flags cria os cards de todos que faltam (idempotente)
      — **`TRELLO_API_SECRET` é obrigatório em produção**: sem ele o webhook é recusado (403)
- [ ] Correios: `CORREIOS_DRIVER=api` + contrato; habilitar prepostagem se aplicável
      e conferir `CORREIOS_CODIGO_SERVICO` (03298 PAC × 03220 SEDEX) no painel CWS
- [ ] Flash: `FLASH_DRIVER=api` — **depende da especificação da API** (fornecedor em transição); ver [INTEGRACOES.md](INTEGRACOES.md)

## 5. Operação
- [ ] Confirmar que os serviços `worker` e `scheduler` estão de pé (sem eles, filas e agendamentos não rodam)
- [ ] Verificar o rastreio da Flash (`php artisan welog:flash:testar` e, com pedidos faturados, `welog:flash:rastrear`)
- [ ] Acompanhar os jobs com falha (`php artisan queue:failed`) nas primeiras semanas
- [ ] Definir política de retenção dos logs (`storage/logs/integrations-*.log`, rotação de 14 dias já configurada)
- [ ] Sentry: preencher `SENTRY_LARAVEL_DSN` e `VITE_SENTRY_DSN` (projetos
      separados), restringir *Allowed Domains* na Client Key e validar a
      filtragem de PII com `php artisan welog:sentry:test --pii` —
      ver [OBSERVABILIDADE.md](OBSERVABILIDADE.md)
- [ ] Conferir `OMIE_REQUESTS_PER_MINUTE` (padrão 180) — o teto real da conta na
      Omie; estourá-lo bloqueia a integração inteira por ~30 min
- [ ] Retenção do banco: `model:prune` roda às 03:30 (webhook_events 90d,
      tracking_events 365d; eventos com falha são preservados)

## 6. Segurança

**Implementado:**
- Rate limiting em login/senha (5/min por e-mail+IP) e webhooks (120/min por IP)
- Webhooks verificados (token no path da Omie; HMAC-SHA1 do Trello, fail-closed)
- Confiança em proxy restrita ao ingresso nginx

**⚠️ Aberto — saiu no commit `6afb8fb` (nginx HTTP-only) e não voltou:**
- [ ] **TLS**: `prod.conf` não tem bloco HTTPS; produção serve HTTP puro, com
      senha de login e token de convite em claro. O `location` do desafio ACME
      permanece, então a emissão funciona — falta reintroduzir o bloco 443 com
      fallback self-signed, para o nginx não voltar a entrar em crash-loop
      quando o certificado não existir.
- [ ] **HSTS, X-Frame-Options, X-Content-Type-Options**: não existem nem no
      nginx nem em middleware. Só fazem sentido depois do TLS (HSTS em HTTP é
      ignorado).
- [ ] `SESSION_SECURE_COOKIE=true` — **não ligue antes do TLS**: em HTTP o
      cookie deixa de ser enviado e ninguém consegue autenticar.

## 7. Pós Go-Live (estabilização — 7 dias)
- [ ] Monitorar entregas de e-mail e webhooks (fila `mail` em `queue:failed`,
      painel de reputação/bounces do provedor — ver [EMAIL.md](EMAIL.md))
- [ ] Ajustes finos de UX conforme feedback (não caracterizam mudança de escopo)
- [ ] Encerrar a estabilização e entrar no modelo de manutenção (Cláusulas 5ª e 6ª do contrato)
