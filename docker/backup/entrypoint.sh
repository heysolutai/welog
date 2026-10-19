#!/bin/sh
# Agendador do backup. Sem cron de propósito: o busybox crond não herda o
# ambiente do container, e exportar as credenciais do Postgres para um arquivo
# só para o cron ler seria pior que este laço.
set -eu

BACKUP_DIR=${BACKUP_DIR:-/backups}
BACKUP_TIME=${BACKUP_TIME:-03:30}

mkdir -p "$BACKUP_DIR/daily"

echo "[backup] serviço no ar — dump diário às ${BACKUP_TIME} (TZ=${TZ:-UTC})"

# Se o host ficou fora no horário agendado, não espera mais 24h por uma cópia.
if [ -z "$(find "$BACKUP_DIR/daily" -name 'welog-*.dump' -type f -mtime -1 2>/dev/null | head -n1)" ]; then
    echo '[backup] nenhum dump nas últimas 24h — rodando agora'
    sh /scripts/backup.sh || echo '[backup] dump inicial falhou (segue agendado)'
fi

while :; do
    if [ "$(date +%H:%M)" = "$BACKUP_TIME" ]; then
        sh /scripts/backup.sh || echo '[backup] dump falhou (segue agendado)'
        sleep 60 # evita rodar duas vezes dentro do mesmo minuto
    fi
    sleep 20
done
