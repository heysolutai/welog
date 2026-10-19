#!/bin/sh
# Dump do Postgres da WeLog.
#
# Roda no container `backup` (mesma imagem do banco, para o pg_dump casar com
# a versão do servidor). Chamado pelo entrypoint.sh no horário agendado.
set -eu

BACKUP_DIR=${BACKUP_DIR:-/backups}
RETAIN_DAILY=${BACKUP_RETAIN_DAILY:-14}
RETAIN_WEEKLY=${BACKUP_RETAIN_WEEKLY:-8}
MIN_BYTES=${BACKUP_MIN_BYTES:-4096}

DAILY_DIR="$BACKUP_DIR/daily"
WEEKLY_DIR="$BACKUP_DIR/weekly"
STATUS_FILE="$BACKUP_DIR/last-status"

log() { echo "[backup] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

fail() {
    log "FALHA: $*"
    # Não deixa lixo parcial ocupando disco até a limpeza do dia seguinte
    rm -f "${TMP:-}" 2>/dev/null || true
    echo "$(date -Iseconds) FALHA $*" > "$STATUS_FILE"
    exit 1
}

mkdir -p "$DAILY_DIR" "$WEEKLY_DIR"

STAMP=$(date +%Y%m%d-%H%M%S)
FILE="$DAILY_DIR/welog-$STAMP.dump"
TMP="$FILE.part"

log "iniciando dump de ${PGDATABASE}@${PGHOST}"

# --format=custom: comprimido e restaurável seletivamente com pg_restore
#
# O stderr do pg_dump é CAPTURADO e entra no status. Antes a falha virava o
# literal "pg_dump retornou erro", que não distingue senha errada de servidor
# mais novo que o cliente — e quem lê o status é justamente quem está longe do
# log do container, decidindo se pode apagar algo.
ERRO_LOG=$(mktemp)

if ! pg_dump --format=custom --compress=9 --file="$TMP" 2>"$ERRO_LOG"; then
    MOTIVO=$(tr '\n' ' ' < "$ERRO_LOG" | sed 's/  */ /g' | cut -c1-300)
    cat "$ERRO_LOG" >&2
    rm -f "$ERRO_LOG"

    case "$MOTIVO" in
        *'server version'*|*'aborting because of server version mismatch'*)
            fail "pg_dump mais ANTIGO que o servidor. Suba PG_VERSION no .env e recrie a imagem: $MOTIVO"
            ;;
        *)
            fail "pg_dump: ${MOTIVO:-sem mensagem no stderr}"
            ;;
    esac
fi

rm -f "$ERRO_LOG"

SIZE=$(wc -c < "$TMP" | tr -d ' ')

# Um dump "bem-sucedido" porém vazio é o pior cenário: parece backup e não é.
[ "$SIZE" -ge "$MIN_BYTES" ] || fail "dump com $SIZE bytes (mínimo $MIN_BYTES) — provavelmente vazio"

# Só vira arquivo definitivo depois de validado; um .part nunca é confundido
# com backup bom se o processo morrer no meio.
mv "$TMP" "$FILE"
log "dump gravado: $FILE ($SIZE bytes)"

# Domingo ganha cópia semanal, com retenção mais longa que a diária
if [ "$(date +%u)" = "7" ]; then
    cp "$FILE" "$WEEKLY_DIR/welog-$STAMP.dump"
    log "cópia semanal gravada"
fi

find "$DAILY_DIR" -name 'welog-*.dump' -type f -mtime +"$RETAIN_DAILY" -delete
find "$WEEKLY_DIR" -name 'welog-*.dump' -type f -mtime +"$((RETAIN_WEEKLY * 7))" -delete
find "$BACKUP_DIR" -name '*.part' -type f -mtime +1 -delete

# Cópia externa (Backblaze B2). Backup que vive só no mesmo host não protege
# contra perder o host. O script é no-op se B2_BUCKET não estiver configurado.
if [ -f /scripts/post-backup.sh ]; then
    sh /scripts/post-backup.sh "$FILE" || fail "cópia externa falhou"
fi

echo "$(date -Iseconds) OK $FILE $SIZE" > "$STATUS_FILE"
log "concluído"
