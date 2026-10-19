#!/usr/bin/env bash
# Deploy da WeLog na VPS. Procedimento completo em docs/DEPLOY.md.
#
#   ./deploy.sh              # pull + build + migrate + cache
#   ./deploy.sh --no-pull    # usa o código que já está no disco
set -euo pipefail

COMPOSE="docker compose -f docker-compose.prod.yml"
PULL=1
[ "${1:-}" = "--no-pull" ] && PULL=0

cd "$(dirname "$0")"

info() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[ -f .env ] || die '.env não encontrado — veja "Primeira instalação" em docs/DEPLOY.md'

if [ "$PULL" = 1 ]; then
    info 'Atualizando o código'
    git pull --ff-only
fi

# Variável nova no .env.example e ausente no .env do servidor é a causa mais
# comum de "funcionou local, quebrou em produção".
info 'Conferindo variáveis de ambiente'
faltando=$(comm -23 \
    <(grep -oE '^[A-Z][A-Z0-9_]*=' .env.example | tr -d '=' | sort -u) \
    <(grep -oE '^[A-Z][A-Z0-9_]*=' .env         | tr -d '=' | sort -u) || true)

if [ -n "$faltando" ]; then
    warn 'Presentes no .env.example e ausentes no .env:'
    echo "$faltando" | sed 's/^/    /'
    read -rp 'Continuar mesmo assim? [s/N] ' r
    [ "$r" = "s" ] || [ "$r" = "S" ] || die 'Deploy cancelado.'
else
    echo '    nenhuma pendência'
fi

# Antes de mexer no banco, confirma que existe de onde voltar.
info 'Estado do backup'
$COMPOSE exec -T app php artisan welog:backup:status || warn 'Backup não confirmado — atenção se este deploy tiver migration.'

info 'Construindo e subindo (worker e scheduler são recriados junto)'
$COMPOSE up -d --build

info 'Migrando o banco'
$COMPOSE exec -T app php artisan migrate --force

info 'Recriando os caches'
$COMPOSE exec -T app php artisan config:clear
$COMPOSE exec -T app php artisan config:cache
$COMPOSE exec -T app php artisan route:cache
$COMPOSE exec -T app php artisan view:cache

# Trello: mapeia as listas do quadro e registra o webhook. Idempotente — com
# TRELLO_DRIVER=fake não faz nada. Falha aqui não derruba o deploy: a operação
# segue e o aviso fica no log.
#
# DEPOIS dos caches, não antes: o comando lê a configuração, e rodando primeiro
# ele enxergava o `config.php` do deploy anterior. Variável preenchida no .env
# valia só no deploy SEGUINTE — foi assim que um card nasceu sem o modelo do
# quadro com `TRELLO_CARD_TEMPLATES` já configurado.
#
# `--sem-cards` porque criar card é decisão da operação, não do deploy: sem ele
# o comando cria o card de TODOS os Projetos que ainda não têm, dezenas de uma
# vez no quadro da produção. O card do dia a dia nasce pelo webhook; o de um
# pedido específico, com `welog:trello:card <pedido> --criar`.
info 'Integração Trello'
$COMPOSE exec -T app php artisan welog:trello:setup --sem-cards || warn 'Trello não configurado por completo — veja a saída acima.'

info 'Situação dos serviços'
$COMPOSE ps

info 'Jobs com falha'
$COMPOSE exec -T app php artisan queue:failed || true

printf '\n\033[1;32m✓ Deploy concluído.\033[0m\n'
echo '  Confira no navegador (com cache desligado) se os assets são os novos.'
