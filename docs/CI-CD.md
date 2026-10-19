# CI/CD no GitLab

Mesmo modelo do projeto Virttus: **runner self-hosted na própria VPS**. É isso
que mantém o pipeline curto — sem runner no servidor seriam necessários chave
SSH, `known_hosts`, registry e deploy token só para chegar até a máquina.

[.gitlab-ci.yml](../.gitlab-ci.yml) tem dois jobs:

| Stage | Job | Onde roda | O que faz |
|---|---|---|---|
| test | `testes` | runner compartilhado | `php artisan test` (~1 min) |
| deploy | `deploy_prod` | runner da VPS (`welog-platform`) | `git reset` + build + migrate |

**Nenhuma variável de CI é necessária.**

---

## Setup

### Runner na VPS

Se ainda não existir um com a tag `welog-platform`:

```bash
# na VPS
curl -L https://packages.gitlab.com/install/repositories/runner/gitlab-runner/script.deb.sh | sudo bash
sudo apt install gitlab-runner
sudo gitlab-runner register \
  --url https://gitlab.com \
  --token <token de Settings → CI/CD → Runners> \
  --executor shell \
  --tag-list welog-platform
sudo usermod -aG docker gitlab-runner
sudo systemctl restart gitlab-runner
```

Executor **shell** (não docker): o job precisa falar com o Docker do host.

O diretório do projeto na VPS (`/home/welog-platform` no script — ajuste se for
outro) tem de ser legível e gravável pelo usuário `gitlab-runner`.

### Job de teste

Roda em runner compartilhado do GitLab.com, sem tag. Se sua conta não tiver
minutos de shared runner, aponte para o runner da VPS trocando a imagem por
execução em Docker:

```yaml
testes:
  tags: [welog-platform]
  image: null
  script:
    - docker run --rm -v "$PWD":/app -w /app php:8.4-cli sh -c 'apt-get update -qq && apt-get install -y -qq git unzip libzip-dev >/dev/null && docker-php-ext-install zip >/dev/null && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && cp .env.example .env && composer install -q && php artisan key:generate -q && php artisan test'
```

---

## O que o deploy faz

1. `git fetch` + `git reset --hard origin/main` — idempotente, descarta
   qualquer sujeira na working tree
2. `docker compose build app` — **obrigatório**: os assets do frontend são
   compilados dentro da imagem. Sem rebuild, backend novo servindo JavaScript
   antigo, sem erro nenhum aparecendo
3. `--force-recreate app worker scheduler` — os três usam a mesma imagem;
   sem recriar, worker e scheduler seguiriam com código velho contra o banco já
   migrado
4. `migrate --force` e recriação dos caches
5. `queue:restart` — encerra os workers em execução no fim do job atual

`resource_group: production` impede dois deploys simultâneos se atropelando no
mesmo diretório.

O `nginx` não é rebuildado porque aqui ele é `nginx:alpine` puro — os assets
chegam pelo volume `app-public`, republicado a cada subida pelo
[entrypoint.prod.sh](../docker/php/entrypoint.prod.sh).

---

## `Job failed: exited with status 137`

137 é SIGKILL — na prática, **o kernel matou o processo por falta de memória**.
O suspeito é o build do frontend: Vite/Rolldown pede **~500 MB de pico**, e ele
roda na VPS, concorrendo com app, worker, scheduler, nginx, redis e backup.

Confirme antes de tratar:

```bash
sudo dmesg -T | grep -iE "out of memory|killed process" | tail
free -h                    # a máquina tem swap?
docker stats --no-stream --format "table {{.Name}}\t{{.MemUsage}}"
```

### Saídas, da mais barata para a mais definitiva

**1. Swap.** A maioria dos provedores entrega VPS sem swap nenhuma. 2 GB de
swap resolvem o pico do build sem custo — o build fica um pouco mais lento,
mas não morre.

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab   # persiste no reboot
sudo sysctl -w vm.swappiness=10                              # só usa sob pressão
```

**2. Liberar memória durante o build** — já está no pipeline: `worker` e
`scheduler` param antes do `build` e voltam no `up`. Eles não atendem
requisição, e os jobs ficam esperando no Redis.

**3. Limpar cache de build.** Camadas antigas enchem o disco, e disco cheio
produz falhas parecidas:

```bash
docker system df
docker builder prune -f --filter until=168h
```

**4. Build fora da VPS.** É a solução de fundo: o CI constrói a imagem, publica
no registry do GitLab, e a VPS só faz `pull`. A VPS deixa de precisar de RAM
para buildar.

O custo é o que este pipeline evitou de propósito: chave SSH ou runner com
acesso ao registry, `docker login` na VPS, deploy token e a variável
`WELOG_IMAGE` (o compose já a aceita, com fallback para build local). Se o
swap não der conta, esse é o caminho — e o compose já está preparado.

---

## Rollback

```bash
cd /home/welog-platform
git reset --hard <sha-anterior>
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml up -d --force-recreate app worker scheduler
```

**Migration não volta com isso.** Se o deploy trouxe migration destrutiva,
restaure pelo [BACKUP.md](BACKUP.md).

---

## Deploy manual

O [deploy.sh](../deploy.sh) continua funcionando como caminho de emergência
quando o GitLab estiver fora. Ele faz o mesmo, mais a conferência de variáveis
novas no `.env` e o estado do backup.

---

## Duas armadilhas encontradas ao montar isto

Ambas passavam despercebidas porque o ambiente de desenvolvimento esconde.

**A suíte dependia do build do frontend.** Os testes de tela Inertia rendem o
layout com `@vite`; sem `public/build/manifest.json` falhavam — e num clone
limpo, que é o que o CI faz, não existe build. Resolvido com `withoutVite()` no
`Tests\TestCase`.

**`resources/js/Pages` × `js/pages`.** O padrão do inertia-laravel aponta para
`js/pages`, minúsculo; o projeto usa `Pages`. O bind mount do Docker no Windows
é case-insensitive, então funcionava localmente e falhava em qualquer Linux —
CI **e servidor**. Resolvido com [config/inertia.php](../config/inertia.php)
explicitando o caminho.
