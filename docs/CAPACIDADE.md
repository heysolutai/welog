# Capacidade — campanha de milhares de pessoas ao mesmo tempo

O cenário que este documento dimensiona: o e-mail de liberação sai e **nove mil
colaboradores abrem nos primeiros minutos**. Todos criam senha, entram no
portal e resgatam — na mesma janela.

A pergunta não é "quantas requisições o servidor aguenta por dia". É quantas
ele aguenta **ao mesmo tempo**, e o que acontece com as que não couberem.

---

## O teto que existia

Três coisas seguravam a plataforma muito abaixo do que a máquina dá, e nenhuma
delas era o tamanho da VPS.

**PHP-FPM com cinco processos.** A imagem `php:8.4-fpm` traz o pool de exemplo,
`pm.max_children = 5`. Cinco requisições PHP simultâneas na plataforma inteira.
A sexta pessoa espera na fila do socket, a centésima recebe 502. Nenhum ajuste
de código passa por esse número.

**Limite de vinte tentativas de login por minuto e por IP.** Nove mil
colaboradores de um mesmo cliente saem todos pela mesma saída de internet da
empresa: para a plataforma, são nove mil pessoas num punhado de IPs. Vinte
criavam a senha e o resto levava 429 — na primeira vez que a pessoa toca no
sistema.

**Dois laços grandes dentro da requisição.** A liberação percorria uma volta por
autorização, e o cadastro em lote uma volta por linha da planilha. Cada volta
gasta um hash bcrypt (~60ms) para gerar o token de primeiro acesso. Nove mil
voltas passam de dez minutos, e o nginx corta a requisição aos cinco: metade era
atendida, o operador via 504 e — no caso da liberação — o `notified_at` já
estava gravado para todos, escondendo quem tinha ficado para trás.

Os três estão resolvidos. O que segue é o dimensionamento.

---

## A conta

Cada processo PHP desta aplicação ocupa **~48 MB** residentes (Laravel com
OPcache compartilhado; medir com `ps -o rss= -C php-fpm` sob carga, não confiar
nesta linha para sempre).

```
max_children = (RAM da máquina − 1 GB de sistema/nginx/Redis) ÷ 48 MB
```

O `1 GB` reservado cobre o sistema operacional, o nginx, o Redis e a folga para
o build do frontend no deploy (que sozinho pede ~1,15 GB de pico — ver
`docs/DEPLOY.md`).

Requisições por segundo que isso entrega depende do tempo de cada uma. As telas
do portal ficam em torno de **80ms**, então:

```
req/s ≈ max_children ÷ 0,08
```

| VPS       | `PHP_FPM_MAX_CHILDREN` | req/s aprox. | Atende o pico de… |
|-----------|------------------------|--------------|-------------------|
| 2 GB      | 12 (`dynamic`)         | ~150         | poucas centenas de pessoas na janela |
| 4 GB      | 48 (`static`)          | ~600         | ~1.500 pessoas nos primeiros minutos |
| 8 GB      | 120 (`static`)         | ~1.500       | ~4.000 |
| 16 GB     | 250 (`static`)         | ~3.000       | **9.000 com folga** |

As variáveis vivem no `.env` de produção e valem com `up -d app` — não é preciso
reconstruir a imagem:

```env
PHP_FPM_MODE=static
PHP_FPM_MAX_CHILDREN=250
PHP_FPM_START_SERVERS=32   # só usado quando MODE=dynamic
```

`static` e não `dynamic` no dia da campanha: com `dynamic`, os primeiros
segundos do pico são gastos FORKANDO processo enquanto as requisições se
empilham — exatamente quando não há tempo sobrando.

---

## O banco é o outro teto

O Postgres é externo (gerenciado). PHP não tem pool de conexões: **cada processo
FPM abre a sua**. Com `max_children = 250`, são até 250 conexões simultâneas ao
banco, mais as dos workers.

Planos gerenciados pequenos não têm isso. Na DigitalOcean, o plano de 1 GB
permite 22 conexões; o de 2 GB, 47; o de 4 GB, 97. Estourar o limite não
degrada: o banco recusa, e a tela quebra.

**Com mais de ~50 processos FPM, é obrigatório um pooler.** As duas saídas:

1. **Connection pooling do provedor** (DigitalOcean oferece pronto, modo
   `transaction`). Aponte `DB_HOST`/`DB_PORT` para o pool em vez do banco.
   É o caminho mais curto e não muda nada na aplicação.
2. **PgBouncer** num container da stack, modo `transaction`.

Modo `transaction`, não `session`: é ele que permite centenas de clientes
compartilharem dezenas de conexões reais. **Junto, ligue `DB_POOL_TRANSACTION=true`
no `.env`.** Sem isso, o PDO prepara cada consulta numa ida ao banco e executa
na seguinte, que nesse modo pode cair em outra conexão: o erro `prepared
statement "pdo_stmt_…" does not exist` aparece de vez em quando, sob carga. A
opção manda consulta e parâmetros juntos (ver `config/database.php`). Não use
`EMULATE_PREPARES` no lugar: ele manda booleano como `1` e o Postgres recusa.

Tamanho do banco para o pico: **4 GB / 2 vCPU** com pooling cobre as nove mil,
porque as consultas do portal são pequenas e indexadas (ver abaixo).

---

## Fila e e-mail

Nove mil e-mails de liberação não saem de uma vez, e não deveriam.

- O anúncio é processado em **lotes de 200**, e o job se reenfileira entre
  lotes — o disparo não monopoliza o worker enquanto outros e-mails esperam.
- A taxa de saída é a do provedor. O SES começa em **14 mensagens/segundo** por
  padrão: nove mil levam ~11 minutos. Se precisar de menos, peça aumento de
  cota ANTES da campanha (o pedido leva ~24h).
- Workers: para a campanha, suba a fila de e-mail em processos dedicados.
  ```
  docker compose -f docker-compose.prod.yml up -d --scale worker=4
  ```
  Mais do que isso só adianta se o provedor acompanhar.

A importação da planilha usa a fila `imports`, em lotes de 50 linhas.

---

## O que já está pronto no código

| Item | Onde |
|---|---|
| Pool do FPM dimensionável por env | `docker/php/www.prod.conf` |
| OPcache com `validate_timestamps=0` e 20k arquivos | `docker/php/Dockerfile.prod` |
| Limite de login por conta (não por escritório) | `config/welog.php` → `PORTAL_AUTH_IP_LIMIT` |
| Liberação em lotes, retomável, fora da requisição | `App\Jobs\FluxoA\NotifyProjectRelease` |
| Importação em lotes, com progresso na tela | `App\Jobs\FluxoA\ImportEntitlementRows` |
| Índice de `project_entitlements` por `user_id` | migração `..._index_entitlements_for_portal_load` |
| Sessão, cache e fila no Redis | `.env` |

---

## Antes da campanha — lista de conferência

1. `PHP_FPM_MODE=static` e `PHP_FPM_MAX_CHILDREN` conforme a tabela; `up -d app`.
2. Pooler do banco ligado e `DB_HOST` apontando para ele.
3. Cota de envio do provedor de e-mail confirmada para o volume.
4. `PORTAL_AUTH_IP_LIMIT` conferido contra o maior cliente da campanha.
5. Workers escalados (`--scale worker=4`).
6. **Backup verificado** — hoje ele não roda em produção (`PG_VERSION`, ver
   `docs/BACKUP.md`). Campanha grande sem backup é risco que não se corre.
7. Teste de carga contra a produção em janela combinada, com o roteiro abaixo.

### Roteiro do teste de carga

O caminho que as nove mil pessoas percorrem, na ordem: abrir o link do e-mail,
criar a senha, ver o catálogo, resgatar. `scripts/carga/portal.js` (k6) executa
exatamente isso; o que se observa durante:

- `pm.status_path` do FPM (`/fpm-status`): `listen queue` tem de voltar a zero
  entre rajadas. Se ficar subindo, `max_children` está curto.
- Conexões no banco contra o limite do plano.
- Tamanho da fila `mail` no Redis.
