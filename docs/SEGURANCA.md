# Segurança da informação — WeLog

O que a plataforma garante, **onde está a evidência de cada garantia** e os
procedimentos que sustentam as respostas dos questionários de segurança dos
clientes. Um assessment com aviso de 30 dias pede evidência de tudo que foi
respondido — este documento é o roteiro para reunir essas evidências.

> Regra de ouro: não responder "sim" no questionário para o que não está aqui
> com evidência. Se uma garantia deixar de valer, `welog:seguranca` acusa.

---

## Checagem automática

```bash
docker compose -f docker-compose.prod.yml exec app php artisan welog:seguranca
```

Confere no servidor, com resultado OK/FALHA (sai com código 1 se algo falhar):

| Garantia | Como é conferida |
|---|---|
| Ambiente de produção, debug desligado, chave da aplicação | `APP_ENV`, `APP_DEBUG`, `APP_KEY` |
| Conexão com o banco cifrada (TLS) | `DB_SSLMODE` exigente **e** `pg_stat_ssl` da conexão real |
| Cookie de sessão só em HTTPS | `SESSION_SECURE_COOKIE` |
| Backup diário em dia | `welog:backup:status` (idade ≤ 36h, última execução OK) |
| Trilha de auditoria gravando | registros nos últimos 7 dias |
| Revisão de acessos trimestral | último `welog:acessos:revisao` há menos de 100 dias |
| NF simulada desligada | `WELOG_SIMULATED_INVOICES` |

Rode antes de responder um questionário e anexe a saída como evidência.

---

## Mapa: garantia → evidência

### Criptografia em trânsito
- HTTPS com TLS 1.2/1.3 e HSTS — `docker/nginx/prod.conf`. Evidência externa:
  teste do domínio no SSL Labs (`ssllabs.com/ssltest`).
- Cloudflare na frente (proxy, firewall, DDoS) — `docker/nginx/cloudflare.conf`.
- Banco: `DB_SSLMODE=require` (mínimo) ou `verify-full` — conferido por
  `welog:seguranca`. Ver `docs/BACKUP.md` § TLS.
- Integrações (Omie, transportadoras, e-mail) e envio de backup: só HTTPS.

### Criptografia em repouso
- Banco: Managed Database com criptografia de disco do provedor.
- Backups no Backblaze B2 com SSE-B2 — todo envio pede `--sse AES256` e é
  **reprovado** se o objeto não ficar cifrado (`docker/backup/post-backup.sh`).
- Senhas: hash bcrypt, nunca reversível.

### Controle de acesso
- Perfis com permissões distintas (Admin WeGift, N1, N2, Admin Cliente, Admin
  de Projeto, Usuário) — `app/Http/Middleware/EnsureRole.php`.
- Cada Cliente só enxerga os próprios dados (escopo por empresa nos controllers).
- Limite de tentativas: login 5/min por conta; primeiro acesso 3 a cada 10 min
  por e-mail; teto por IP — `AppServiceProvider::configureRateLimiters`.
- Servidor: acesso por chave SSH; `.env` com permissão 600.

### Trilha de auditoria
- Tabela `audit_logs`, só inclusão (o modelo recusa editar e apagar).
  Retenção: `WELOG_AUDIT_RETENTION_DAYS` (padrão 730 dias = 24 meses).
- Registra: login, logout, **login falho**, definição de senha; criação,
  alteração de perfil, ativação/desativação e exclusão de usuários; Admin
  Cliente/Projeto criado; alteração e exclusão de Cliente; liberação,
  bloqueio e exclusão de Projeto; cadastro, importação em planilha e remoção
  de colaboradores; CPF alterado; resgate cancelado; importação de parceiros;
  **exportação de dados pessoais** (relatório de resgates e a própria trilha).
- Cada registro guarda quem (cópia de nome/e-mail/perfil — sobrevive à
  exclusão da conta), o quê, alvo, quando, IP e navegador. Nunca senha ou
  token; CPF alterado registra que mudou, não o número.
- Consulta: **Configurações → Auditoria** (só Admin WeGift), com filtro por
  grupo, período e busca. Botão **Exportar (.csv)** gera a evidência.

### Disponibilidade
- Backup diário com cópia externa em outro fornecedor — `docs/BACKUP.md`.
- Restauração testada (registro em `docs/BACKUP.md` § Restaurar).
- Monitoramento de erros em tempo real (Sentry); logs técnicos por 14 dias.

### Gestão de vulnerabilidades
- **Contínua:** a esteira do GitLab roda `composer audit` e `npm audit`
  (alta/crítica) a cada push e **barra o deploy** com dependência vulnerável
  (`.gitlab-ci.yml`, jobs `dependencias` e `dependencias_js`).
- **Anual:** teste de intrusão (pentest) externo — procedimento abaixo.

---

## Procedimento: revisão trimestral de acessos

Todo início de trimestre (jan, abr, jul, out):

```bash
docker compose -f docker-compose.prod.yml exec app \
  php artisan welog:acessos:revisao --responsavel="Nome de quem revisa" \
  --csv=storage/app/revisao-acessos-$(date +%Y-%m).csv
```

1. O comando lista toda conta com acesso além do próprio resgate e marca
   **REVISAR** a que está ativa sem login há mais de
   `WELOG_ACCESS_REVIEW_IDLE_DAYS` (padrão 90).
2. Para cada conta marcada (e para quem saiu da equipe ou do cliente):
   desative em **Equipe WeGift** ou na ficha do Cliente.
3. Guarde o CSV. A execução fica registrada na trilha de auditoria — é a
   evidência de que a revisão aconteceu.

---

## Procedimento: resposta a incidente com dados pessoais

Incidente = acesso indevido, vazamento ou perda de dados pessoais
(confirmado ou fortemente suspeito).

1. **Conter** (imediato): revogar a credencial envolvida, desativar contas
   comprometidas, trocar segredos do `.env` afetados (`APP_KEY` derruba todas
   as sessões), bloquear IP no Cloudflare.
2. **Apurar** (até 24h): trilha de auditoria (Configurações → Auditoria e
   exportação CSV), logs do nginx e da aplicação, Sentry. Definir quais dados,
   de quais clientes e quantos titulares.
3. **Notificar o cliente** (até **3 dias úteis** do conhecimento): e-mail ao
   responsável pelo contrato e ao encarregado (DPO) do cliente, com natureza
   do incidente, dados e titulares afetados, riscos, medidas tomadas e
   próximos passos. Atualizações até o encerramento.
4. **ANPD e titulares**: quando houver risco ou dano relevante, comunicação à
   ANPD e apoio ao cliente na comunicação aos titulares — art. 48 da LGPD e
   Resolução CD/ANPD nº 15/2024 (prazo de 3 dias úteis).
5. **Registrar**: o incidente, a apuração e as notificações, mesmo os que não
   exigiram comunicação à ANPD (a resolução exige o registro por 5 anos).

Contato de segurança: **[definir e-mail, ex.: seguranca@empresa.com.br]**.
Responsável: **[nome]**. Encarregado (DPO): **[nome]**.

---

## Procedimento: teste de intrusão anual

- Uma vez por ano, e depois de mudança grande de arquitetura, contratar
  pentest externo da aplicação e do servidor.
- Corrigir achados críticos/altos antes do próximo deploy; médios em até 30
  dias. Guardar relatório e evidência de correção.
- Último teste: **[data — preencher]** (teste realizado pela equipe de
  segurança do cliente em 10/2026).

---

## Checklist do servidor (antes de um assessment)

- [ ] `welog:seguranca` todo verde
- [ ] `DB_SSLMODE=require` ou `verify-full` no `.env` de produção
- [ ] Trusted Sources do banco restrito ao IP da VPS (painel DigitalOcean)
- [ ] B2: bucket privado, Default Encryption ligado, chave sem `deleteFiles`
- [ ] `.env` com `chmod 600`; nenhum usuário além do deploy no grupo `docker`
- [ ] SSH só por chave (`PasswordAuthentication no`)
- [ ] Revisão de acessos do trimestre feita
- [ ] Esteira com `dependencias` e `dependencias_js` verdes
