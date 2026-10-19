#!/bin/sh
# Cópia externa do dump para o Backblaze B2 (endpoint S3-compatível).
#
# Chamado pelo backup.sh com o caminho do dump recém-validado. Falha aqui conta
# como falha do backup: um dump que não saiu do host não é cópia de segurança.
#
# A RETENÇÃO NO B2 é feita por lifecycle rule do bucket, não por este script —
# assim a chave usada aqui não precisa de permissão de apagar, e um servidor
# comprometido não consegue destruir o histórico. Ver docs/BACKUP.md.
set -eu

DUMP=${1:?caminho do dump não informado}

# Sem bucket configurado o backup segue só local (com o alerta do go-live).
if [ -z "${B2_BUCKET:-}" ]; then
    echo '[b2] B2_BUCKET não configurado — pulando cópia externa'
    exit 0
fi

: "${B2_ENDPOINT:?B2_ENDPOINT não configurado (ex.: https://s3.us-west-004.backblazeb2.com)}"
: "${AWS_ACCESS_KEY_ID:?keyID da application key do B2 não configurado}"
: "${AWS_SECRET_ACCESS_KEY:?applicationKey do B2 não configurada}"

PREFIX=${B2_PREFIX:-welog}
# Mantém daily/ e weekly/ na mesma estrutura do host, para a lifecycle rule
# poder ter janelas diferentes por prefixo.
RELATIVE=${DUMP#/backups/}
TARGET="s3://${B2_BUCKET}/${PREFIX}/${RELATIVE}"

echo "[b2] enviando $(basename "$DUMP") para ${TARGET}"

# `--sse AES256` pede ao B2 criptografia em repouso (SSE-B2) neste objeto,
# independente de a criptografia padrão estar ligada no bucket. O dump tem
# dado pessoal de milhares de pessoas: cifrado não pode depender de alguém
# lembrar de um clique no painel.
aws s3 cp "$DUMP" "$TARGET" \
    --endpoint-url "$B2_ENDPOINT" \
    --sse AES256 \
    --only-show-errors \
    --no-progress

# Confere que o objeto existe, tem o mesmo tamanho e ficou cifrado — `cp` sem
# erro não é garantia suficiente para algo que só será lido num desastre.
LOCAL_SIZE=$(wc -c < "$DUMP" | tr -d ' ')
read -r REMOTE_SIZE REMOTE_SSE <<EOF
$(aws s3api head-object \
    --endpoint-url "$B2_ENDPOINT" \
    --bucket "$B2_BUCKET" \
    --key "${PREFIX}/${RELATIVE}" \
    --query '[ContentLength, ServerSideEncryption]' --output text)
EOF

if [ "$LOCAL_SIZE" != "$REMOTE_SIZE" ]; then
    echo "[b2] FALHA: tamanho divergente (local ${LOCAL_SIZE}, remoto ${REMOTE_SIZE})"
    exit 1
fi

if [ "$REMOTE_SSE" != "AES256" ]; then
    echo "[b2] FALHA: objeto sem criptografia em repouso (ServerSideEncryption=${REMOTE_SSE})"
    exit 1
fi

echo "[b2] confirmado no B2: ${REMOTE_SIZE} bytes, cifrado (${REMOTE_SSE})"
