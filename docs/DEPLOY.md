# Deploy na VPS

> **Antes de tudo, na VPS:** coloque `COMPOSE_FILE=docker-compose.prod.yml` no
> `.env`. Sem isso, um `docker compose up` sem `-f` sobe o compose de
> **desenvolvimento**, que faz bind-mount do repositório — e o container morre
> com `Failed opening required '/var/www/vendor/autoload.php'`, porque em
> produção o `vendor/` vive dentro da imagem, não no disco. O sintoma que
> denuncia é o aviso `NGROK_AUTHTOKEN is not set`: ngrok só existe no compose
> de dev.

## Resposta curta

**Não é só `pull` + `up`.** Faltam três coisas, e a mais perigosa é silenciosa:

1. `-f docker-compose.prod.yml` — sem isso o Docker usa o compose de
   desenvolvimento (com Vite, Mailpit e portas expostas).
2. `--build` — os assets do frontend são compilados **dentro da imagem**
   (multi-stage). Sem rebuild, o backend é novo e o JavaScript é o antigo.
3. `migrate --force` e `config:cache` — migrations não rodam sozinhas, e a
   configuração em cache não enxerga mudanças do `.env` até ser refeita.

Com o script pronto, o deploy é:

```bash
./deploy.sh
```

---

## Passo a passo

### 1. Antes de subir: conferir variáveis novas

Comparar o `.env` do servidor com o `.env.example` do commit que está entrando.
Variável nova ausente = comportamento silenciosamente diferente.

```bash
git pull --ff-only
comm -23 <(grep -oE '^[A-Z][A-Z0-9_]*=' .env.example | tr -d '=' | sort -u) \
         <(grep -oE '^[A-Z][A-Z0-9_]*=' .env         | tr -d '=' | sort -u)
```

O que aparecer só do lado esquerdo precisa ser avaliado.

> **`TRELLO_API_SECRET` virou obrigatório.** Desde a Fase 8 o webhook do Trello
> é fail-closed: sem o secret, os POSTs são recusados com 403 e o pipeline dos
> Projetos para de atualizar.

### 2. Subir

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

`--build` recria também `worker` e `scheduler` — sem isso eles continuariam
executando o código antigo com o banco já migrado.

### 3. Migrar e recachear

```bash
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec -T app php artisan config:clear
docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache
docker compose -f docker-compose.prod.yml exec -T app php artisan route:cache
docker compose -f docker-compose.prod.yml exec -T app php artisan view:cache
```

O `config:clear` antes do `config:cache` não é redundância: sem ele, um cache
corrompido de um deploy anterior sobrevive.

Para migration destrutiva ou demorada, feche a porta antes:

```bash
docker compose -f docker-compose.prod.yml exec -T app php artisan down --render=errors::503
# … migrate …
docker compose -f docker-compose.prod.yml exec -T app php artisan up
```

### 4. Conferir

```bash
docker compose -f docker-compose.prod.yml ps          # tudo Up? worker e scheduler inclusive
docker compose -f docker-compose.prod.yml exec -T app php artisan about
docker compose -f docker-compose.prod.yml exec -T app php artisan queue:failed
docker compose -f docker-compose.prod.yml exec -T app php artisan welog:backup:status
```

No navegador, com o cache desligado: confirmar que o `public/build/manifest.json`
servido corresponde ao build novo (é justamente o que quebrava antes).

---

## Mudei uma variável no `.env`. E agora?

`restart` **não** basta, e é aqui que se perde tempo achando que a variável
"não pegou".

O `.env` não está dentro da imagem: o `.dockerignore` o exclui de propósito
(credenciais não viajam em imagem). Ele chega como **variável de ambiente**,
pelo `env_file: .env` de cada serviço do compose — e o Compose lê esse arquivo
quando **cria** o container. `docker compose restart` para e inicia o MESMO
container, com o MESMO ambiente de antes; a variável nova não entra. O mesmo
vale para `php artisan config:clear`: ele descarta o cache da configuração, mas
o valor que nunca chegou ao processo continua não existindo.

```bash
# 1. Editar o .env no servidor
nano .env

# 2. RECRIAR os containers (não `restart`)
docker compose -f docker-compose.prod.yml up -d

# 3. Recachear a configuração
docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache
```

Para conferir o que o processo realmente enxerga, sem confiar no arquivo:

```bash
docker compose -f docker-compose.prod.yml exec -T app printenv TRELLO_CARD_TEMPLATES
docker compose -f docker-compose.prod.yml exec -T worker-webhooks printenv TRELLO_CARD_TEMPLATES
```

Os dois precisam responder. O `app` é quem atende o comando de diagnóstico e o
`artisan`; quem cria o card quando o webhook chega é o **worker**, e ele pode
estar rodando com um ambiente mais antigo que o do `app` se só um foi recriado.
## As três armadilhas que existiam aqui

Corrigidas em 27/07/2026 — registradas porque explicam decisões do Dockerfile
que parecem arbitrárias.

**Assets congelados.** Um volume nomeado só é populado a partir da imagem
quando está **vazio**. Como o nginx lê `app-public`, o primeiro deploy fixava o
`public/build` para sempre: todo deploy seguinte servia o JavaScript antigo,
sem erro nenhum. Hoje a imagem guarda o `public/` em `/opt/public-dist` e o
[entrypoint.prod.sh](../docker/php/entrypoint.prod.sh) republica a cada subida.

**`storage/` sem volume.** Uploads sumiam a cada recreate, e `app`, `worker` e
`scheduler` tinham filesystems diferentes — o `welog:consume-appnoz-directory`
no scheduler não enxergava o inbox gravado pelo app. Hoje os três compartilham
`app-storage`.

**`.env` dentro da imagem.** O `COPY . .` levava o `.env` com as credenciais
reais e todo o histórico `.git` para as camadas da imagem. Resolvido com
[.dockerignore](../.dockerignore).

---

## Build morrendo com exit 137

`137` é `128 + 9` — SIGKILL. Na prática, quase sempre o OOM killer do kernel
derrubando o `npm run build` dentro do `docker compose build app`. Não é erro do
código: o job simplesmente para, sem mensagem de compilação nenhuma.

**Quanto o build pede.** Medido em 06/08/2026, com 2679 módulos: **pico de
1,14 GB** só no `npm run build`. O `npm ci` é uma camada separada, então os dois
picos não se somam. Esse número cresce a cada dependência de frontend — quando o
pipeline foi escrito era ~500 MB.

Confirme que foi OOM antes de mexer em qualquer outra coisa:

```bash
free -h
dmesg -T | grep -i -E 'out of memory|killed process' | tail -5
```

**A correção é swap.** Parar `worker` e `scheduler` durante o build (o que o
pipeline já faz) alivia algumas centenas de MB, mas não fecha a conta numa VPS de
2 GB. Swap é exatamente a ferramenta certa aqui: o pico é curto e acontece uma vez
por deploy — o custo em I/O não aparece em lugar nenhum que o usuário perceba.

```bash
sudo fallocate -l 4G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab   # sobrevive a reboot
sudo sysctl -w vm.swappiness=10                              # só usa sob pressão
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swap.conf
```

Confira com `free -h` — a linha `Swap:` tem de mostrar 4,0 GB. O próximo deploy
já passa.

**Se preferir não usar swap**, a alternativa estrutural é tirar o build da VPS:
compilar os assets no runner compartilhado (stage `test`), publicar `public/build`
como artefato e fazer o `Dockerfile.prod` copiá-lo em vez de rodar o `npm run
build`. Elimina o pico da máquina de produção, ao custo de o pipeline ter de
carregar o artefato entre os dois runners.

---

## Rollback

As imagens anteriores continuam no host:

```bash
docker images welog-app-prod
docker tag welog-app-prod:<id-anterior> welog-app-prod:latest
docker compose -f docker-compose.prod.yml up -d --no-build
```

**Migration não volta sozinha.** Se o deploy incluiu migration destrutiva, o
rollback do código não desfaz o schema — restaure o banco pelo procedimento de
[BACKUP.md](BACKUP.md). É por isso que vale rodar `welog:backup:status` *antes*
de um deploy com migration pesada.

---

## Primeira instalação

Só na primeira vez, e nesta ordem:

```bash
cp .env.example .env && nano .env      # APP_KEY, banco, integrações, B2
./docker/nginx/init-letsencrypt.sh     # emite o certificado e sobe a stack
docker compose -f docker-compose.prod.yml exec -T app php artisan key:generate --force
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec -T app php artisan db:seed --force
```

Depois do seed, **trocar a senha dos usuários semeados** — ver [GO-LIVE.md](GO-LIVE.md).
