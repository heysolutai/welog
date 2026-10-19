# Observabilidade — Sentry

Monitoramento de erros no backend (PHP) e no navegador (Vue), com filtragem de
dados pessoais antes do envio.

---

## ⚠️ O ponto que define esta configuração

A WeLog trafega **CPF, endereço de entrega, telefone e e-mail de clientes
finais**. Sentry é um serviço de terceiros: mandar um stack trace com o corpo
da requisição de um resgate é incidente de LGPD, não detalhe de configuração.

Por isso:

| Proteção | Onde |
|---|---|
| `send_default_pii => false` | [config/sentry.php](../config/sentry.php) — não anexa corpo, cookies nem IP |
| `sql_bindings => false` | binding é o **valor** da query: CPF, e-mail, endereço |
| `before_send` filtra por nome de campo | `cpf`, `zip`, `street`, `recipient_name`… |
| `before_send` mascara por padrão no valor | CPF/CNPJ e e-mail embutidos em mensagem ou URL |
| Usuário identificado só por ID | nome, e-mail e IP são removidos |
| Mesmo filtro no navegador | [resources/js/sentry.js](../resources/js/sentry.js) |

A dupla camada é proposital: filtrar por nome pega o dado estruturado, mascarar
por padrão pega o mesmo dado dentro de `"Cliente 529.982.247-25 não encontrado"`.

Coberto por `tests/Unit/SentryScrubberTest.php` (7 testes) e
`tests/Feature/SentryIntegrationTest.php` (6). **Se mexer na configuração, rode
a suíte** — uma config publicada por cima apagaria os ganchos sem avisar.

---

## Configurar

Crie **dois projetos** no Sentry: um `php`/`laravel` e um `javascript-vue`. São
DSNs diferentes.

No `.env` do servidor:

```env
SENTRY_LARAVEL_DSN=https://...@o000000.ingest.sentry.io/000000
SENTRY_ENVIRONMENT=production
SENTRY_TRACES_SAMPLE_RATE=0
SENTRY_PROFILES_SAMPLE_RATE=0

VITE_SENTRY_DSN=https://...@o000000.ingest.sentry.io/111111
```

**Deixe vazio em dev e nos testes.** Sem DSN o SDK não inicializa — e no
frontend o bundler ainda remove o código do Sentry por inteiro (≈100 KB a
menos).

O `SENTRY_RELEASE` é preenchido pelo CI a cada deploy com o SHA do commit; não
mexa nele à mão.

### Por que o DSN do frontend vai por build arg

O Vite injeta as `VITE_*` no bundle **durante o build da imagem**, e o `.env`
não entra lá (está no `.dockerignore`). Por isso `docker-compose.prod.yml`
repassa `VITE_SENTRY_DSN` como build arg — ver
[Dockerfile.prod](../docker/php/Dockerfile.prod).

Consequência: **trocar o DSN do frontend exige rebuild da imagem**, não basta
editar o `.env`.

DSN de browser é público por natureza (fica no JS que qualquer um lê). Não é
segredo; o que protege o projeto é a lista de domínios permitidos, em
*Settings → Client Keys → Allowed Domains*. Configure com seu domínio.

---

## Validar

```bash
docker compose -f docker-compose.prod.yml exec app php artisan welog:sentry:test
```

Mostra DSN, environment, release e as proteções ativas, e envia um evento.

Para conferir a filtragem de verdade:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan welog:sentry:test --pii
```

Envia uma mensagem contendo CPF e e-mail falsos. **No painel do Sentry eles
devem aparecer como `[filtrado]`.** Se aparecerem legíveis, o `before_send` não
está no caminho — investigue antes de deixar em produção.

---

## O que NÃO vira alerta

`ignore_exceptions` descarta o que é comportamento esperado:

- `DomainException` — violação de regra de negócio, cuja mensagem é escrita
  para o usuário ler ("Projeto já foi liberado", "Este kit já foi resgatado")
- Validação, autenticação, autorização, 404, 405, CSRF expirado
- `ThrottleRequestsException` — rate limiting funcionando é o sistema acertando

Sem esse filtro o Sentry viraria um segundo access log: cada CPF inválido numa
planilha de cadastro em lote geraria um "erro". Ruído afogando sinal é a forma
mais comum de um monitoramento parar de ser lido.

**O que continua sendo reportado:** falha de integração (Omie, Correios,
Trello), erro de banco, job que esgotou as tentativas, exceção não tratada,
`ApiThrottleException` (teto de consumo da Omie estourado é problema de
capacidade, não comportamento normal).

---

## Contexto anexado

[SentryContext](../app/Support/Observability/SentryContext.php) adiciona a cada
evento, sem PII:

- `user.id` — só o ID; cruze com o banco se precisar de mais
- `user.role` e `user.company_id` como tags (filtráveis no painel)
- Driver de cada integração (`fake` × `api`) — responde na hora "isto aconteceu
  com o gateway real?"

---

## Custo

O plano gratuito do Sentry tem cota mensal de eventos. Dois cuidados já
aplicados: `traces_sample_rate=0` (transações de performance são cobradas à
parte, e o volume aqui viria dos jobs de integração) e a lista de exceções
ignoradas.

Se a cota apertar, o primeiro lugar a olhar é *Issues* ordenado por volume —
normalmente é uma exceção repetitiva que deveria estar em `ignore_exceptions`
ou ser corrigida.
