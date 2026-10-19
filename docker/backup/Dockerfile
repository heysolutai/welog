# Imagem do serviço de backup.
#
# A versão TEM de ser >= a do servidor: o `pg_dump` recusa-se a dumpar um
# Postgres mais novo que ele ("server version mismatch") e o backup passaria a
# falhar todo dia. Confira com `SELECT version()` e ajuste PG_VERSION no .env —
# o Managed Database da DigitalOcean costuma vir em 16 ou 17.
#
# Acrescenta o aws-cli para a cópia externa no Backblaze B2 (endpoint
# S3-compatível). Ver docs/BACKUP.md.
ARG PG_VERSION=16
FROM postgres:${PG_VERSION}-alpine

RUN apk add --no-cache aws-cli

ENTRYPOINT ["/bin/sh", "/scripts/entrypoint.sh"]
