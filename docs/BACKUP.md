# Backup e restauração — WeLog

O que é copiado, com que frequência, como conferir e — o que de fato importa —
**como restaurar**.

---

## Como funciona

O serviço `backup` do `docker-compose.prod.yml` roda um `pg_dump` diário contra
o Postgres **externo** (`DB_HOST`/`DB_PORT` do `.env` — o banco não faz parte
desta stack). A imagem é `postgres:16-alpine` para o cliente casar com a versão
do servidor.

Os clientes `pg_*` leem `PGHOST`, `PGPORT`, `PGUSER`, `PGPASSWORD` e `PGSSLMODE`
do ambiente do container, então os comandos abaixo não precisam de `-h`/`-U`.

### Managed Database da DigitalOcean

Três pontos que quebram o backup ou a segurança se passarem batidos:

**1. Versão do `pg_dump`.** Ele se recusa a dumpar um servidor mais novo que
ele — o backup passaria a falhar todo dia, e você só descobriria ao restaurar.
Confira e ajuste:

```sql
SELECT version();
```
```env
PG_VERSION=18    # no .env, igual (ou maior) à versão do cluster
```
```bash
docker compose -f docker-compose.prod.yml build backup
docker compose -f docker-compose.prod.yml up -d backup
```

> **Confira isto depois de todo upgrade do cluster.** A DigitalOcean atualiza
> o Managed Database sem avisar, e o `pg_dump` mais antigo que o servidor
> aborta. Em 20/09/2026 o cluster estava em 18.6 com a imagem em 16: o dump
> falhava desde o upgrade e a base ficou sem backup nenhum até alguém abrir o
> status. O `up -d` não é opcional — sem recriar o container, ele continua
> com a imagem antiga.

**2. TLS.** O cluster gerenciado exige conexão cifrada; use no mínimo
`DB_SSLMODE=require`. Para `verify-full` (que também barra man-in-the-middle),
baixe o CA em *Databases → Connection details → Download CA certificate*:

```bash
# na VPS
curl -o docker/backup/certs/ca-certificate.crt '<url do painel>'
```
```env
DB_SSLMODE=verify-full
DB_SSLROOTCERT=/certs/ca-certificate.crt
```

**3. Trusted Sources.** No painel do banco, restrinja o acesso ao droplet da
aplicação. Sem isso o cluster aceita conexão de qualquer IP da internet — a
senha é a única barreira.

> **Os backups da DO não substituem estes dumps.** Ela guarda 7 dias e restaura
> criando um cluster novo dentro da própria DO: não dá para baixar o arquivo,
> nem restaurar em outro lugar, nem sobrevive a perder o acesso à conta. Os
> dumps daqui vão para o B2, num fornecedor diferente. São camadas distintas.

| | |
|---|---|
| Horário | `BACKUP_TIME` (padrão 03:30, no fuso de `WELOG_BUSINESS_TIMEZONE`) |
| Formato | `pg_dump --format=custom --compress=9` — comprimido e restaurável seletivamente |
| Destino | volume `welog-backups`, em `daily/` e `weekly/` |
| Retenção diária | `BACKUP_RETAIN_DAILY` dias (padrão 14) |
| Retenção semanal | domingos, `BACKUP_RETAIN_WEEKLY` semanas (padrão 8) |

Três cuidados embutidos, todos contra o mesmo risco — **descobrir na hora de
restaurar que não havia backup**:

1. O dump é gravado como `.part` e só vira arquivo definitivo depois de
   validado. Um processo morto no meio não deixa arquivo que pareça bom.
2. Dump menor que `BACKUP_MIN_BYTES` (4 KB) é tratado como falha. Um dump
   "bem-sucedido" porém vazio é pior que nenhum.
3. Se o host ficou fora no horário agendado, o serviço roda um dump **assim que
   sobe**, em vez de esperar mais 24h.

O resultado de cada execução vai para `/backups/last-status` e para o log do
container (`docker compose -f docker-compose.prod.yml logs backup`).

---

## Conferir se está funcionando

```bash
docker compose -f docker-compose.prod.yml exec app php artisan welog:backup:status
```

Mostra a última execução, quantidade, idade e tamanho das cópias. **Sai com
código 1** se não houver backup ou se o mais recente passar de 36h — use isso
no monitoramento externo:

```bash
# exemplo de checagem para cron/monitor externo
docker compose -f docker-compose.prod.yml exec -T app \
  php artisan welog:backup:status --max-age=30 || alerta "backup da WeLog atrasado"
```

O volume é montado **somente leitura** no container do app: dá para conferir,
não dá para corromper.

---

## Restaurar

> Testado em 27/07/2026: dump restaurado em banco limpo com contagem idêntica
> em `users`, `projects`, `project_entitlements`, `redemptions`,
> `tracking_events` e `email_templates`.

### 1. Escolher a cópia

```bash
docker compose -f docker-compose.prod.yml exec backup ls -lt /backups/daily
```

### 2. Restaurar em banco NOVO primeiro

Nunca restaure direto por cima do banco de produção antes de conferir o
conteúdo. Restaure ao lado, confira, e só então promova.

```bash
DUMP=/backups/daily/welog-AAAAMMDD-HHMMSS.dump

docker compose -f docker-compose.prod.yml exec backup sh -c "
  createdb welog_restore &&
  pg_restore -d welog_restore --no-owner --no-privileges $DUMP &&
  psql -d welog_restore -c 'select count(*) from users'
"
```

### 3. Promover

Com a aplicação **parada** (para ninguém escrever durante a troca):

```bash
docker compose -f docker-compose.prod.yml stop app worker scheduler nginx

docker compose -f docker-compose.prod.yml exec backup sh -c "
  psql -d postgres -c 'alter database welog rename to welog_antes_do_restore' &&
  psql -d postgres -c 'alter database welog_restore rename to welog'
"

docker compose -f docker-compose.prod.yml start app worker scheduler nginx
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

O banco anterior fica preservado como `welog_antes_do_restore` — só apague
depois de confirmar que a aplicação está saudável.

---

## Cópia externa — Backblaze B2

Os dumps ficam no volume `welog-backups` da VPS — que **não é** a máquina do
banco. Isso já protege contra perder o servidor de banco, mas não contra perder
a VPS. Todo dump validado é enviado ao B2 pelo `post-backup.sh`, via endpoint
S3-compatível.

Falha no envio **reprova o backup inteiro** (`last-status` fica `FALHA`): um
dump que não saiu do host não é cópia de segurança. Depois do `cp` o script
confere o `ContentLength` do objeto contra o tamanho local — `cp` sem erro não
basta para algo que só será lido num desastre.

Objetos ficam em `s3://<bucket>/<B2_PREFIX>/daily/…` e `…/weekly/…`, espelhando
a estrutura local para a lifecycle rule poder ter janelas diferentes por prefixo.

**Criptografia em repouso.** Todo envio pede SSE-B2 (`--sse AES256`) e o script
confere no `head-object` que o objeto ficou cifrado; objeto sem criptografia
**reprova o backup**. Não depende da criptografia padrão do bucket — mas vale
ligá-la também (*Bucket Settings → Default Encryption → Enable*), para cobrir
qualquer arquivo enviado à mão.

### Configurar

**1. Bucket** — crie um bucket **privado** no B2. Anote o *endpoint*
(painel → Buckets, algo como `s3.us-west-004.backblazeb2.com`).

**2. Application key restrita** — em *Account → Application Keys*, crie uma
chave **limitada a este bucket** e **sem `deleteFiles`**. Capacidades
necessárias: `listBuckets`, `listFiles`, `readFiles`, `writeFiles`.

> Isso é o ponto principal do desenho: a retenção é feita pelo B2 (passo 3), não
> pelo servidor. Sem permissão de apagar, um servidor comprometido **não
> consegue destruir o histórico de backups** — só adicionar arquivos.

**3. Lifecycle rule** — no bucket, configure a retenção server-side. Ela roda
mesmo que o container de backup nunca mais suba:

| Prefixo | `daysFromUploadingToHiding` | `daysFromHidingToDeleting` |
|---|---|---|
| `welog/daily/` | 30 | 1 |
| `welog/weekly/` | 180 | 1 |

O `daysFromHidingToDeleting` é o que de fato libera espaço — sem ele o B2 só
esconde a versão e continua cobrando por ela.

**4. `.env` do servidor** — preencha e suba o serviço:

```env
B2_BUCKET=nome-do-seu-bucket
B2_ENDPOINT=https://s3.us-west-004.backblazeb2.com
B2_REGION=us-west-004
B2_PREFIX=welog
B2_KEY_ID=<keyID da application key>
B2_APPLICATION_KEY=<applicationKey>
```

```bash
docker compose -f docker-compose.prod.yml up -d --build backup
docker compose -f docker-compose.prod.yml logs -f backup
```

O serviço faz um dump assim que sobe (se não houver cópia nas últimas 24h), então
o log já mostra se o envio funcionou.

### Conferir o que está no B2

```bash
docker compose -f docker-compose.prod.yml exec backup \
  aws s3 ls "s3://${B2_BUCKET}/${B2_PREFIX}/daily/" --endpoint-url "$B2_ENDPOINT"
```

### Restaurar a partir do B2

```bash
docker compose -f docker-compose.prod.yml exec backup sh -c '
  aws s3 cp "s3://$B2_BUCKET/$B2_PREFIX/daily/welog-AAAAMMDD-HHMMSS.dump" /tmp/r.dump \
    --endpoint-url "$B2_ENDPOINT" &&
  createdb welog_restore &&
  pg_restore -d welog_restore --no-owner --no-privileges /tmp/r.dump
'
```

Daí em diante o procedimento é o mesmo da seção **Promover**, acima.

> Validado em 27/07/2026 contra um endpoint S3 local (MinIO): envio, conferência
> de tamanho, download e `pg_restore` a partir da cópia remota com contagens
> idênticas. Credencial inválida reprovou o backup como esperado; sem
> `B2_BUCKET` o backup segue local sem falhar.

---

## Configuração

Variáveis do serviço `backup` (todas com padrão; ver `.env.example`):

| Variável | Padrão | O que faz |
|---|---|---|
| `BACKUP_TIME` | `03:30` | Horário do dump diário (HH:MM, fuso do negócio) |
| `BACKUP_RETAIN_DAILY` | `14` | Dias de retenção **local** das cópias diárias |
| `BACKUP_RETAIN_WEEKLY` | `8` | Semanas de retenção **local** das cópias de domingo |
| `BACKUP_MIN_BYTES` | `4096` | Abaixo disso o dump é considerado falho |
| `B2_BUCKET` | — | Vazio desliga a cópia externa (backup fica só local) |
| `B2_ENDPOINT` | — | Endpoint S3 do B2, varia por região |
| `B2_PREFIX` | `welog` | Prefixo dos objetos no bucket |
| `B2_KEY_ID` / `B2_APPLICATION_KEY` | — | Application key restrita ao bucket, **sem delete** |

A retenção local (`BACKUP_RETAIN_*`) é independente da retenção no B2
(lifecycle rule). O disco do servidor guarda a janela curta para restauração
rápida; o B2 guarda a janela longa.

Espaço em disco: o dump é comprimido; estime `retenção_diária + retenção_semanal`
cópias. Acompanhe com `welog:backup:status`, que mostra o total ocupado.
