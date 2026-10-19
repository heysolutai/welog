#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Bootstrap do TLS (Let's Encrypt) para o WeLog em produção.
#
# Rode UMA vez no servidor, a partir da raiz do projeto, DEPOIS de:
#   1. apontar o DNS do domínio para este servidor (registro A);
#   2. definir WELOG_DOMAIN e LETSENCRYPT_EMAIL no .env de produção.
#
# Resolve o impasse ovo-galinha do primeiro certificado: cria um certificado
# temporário (dummy) só para o nginx conseguir subir, sobe o nginx para servir
# o desafio ACME, emite o certificado real via webroot e recarrega o nginx.
#
# Depois disto NÃO precisa rodar de novo: o serviço "certbot" renova o
# certificado sozinho e o nginx recarrega a cada 6h.
#
# Teste sem gastar a cota do Let's Encrypt (5 emissões/semana por domínio):
#   STAGING=1 ./docker/nginx/init-letsencrypt.sh
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/../.."   # raiz do projeto

COMPOSE="docker compose -f docker-compose.prod.yml"
CERT_NAME="welog"
LIVE="/etc/letsencrypt/live/$CERT_NAME"

env_val() { grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | sed 's/^"//;s/"$//'; }
DOMAIN="${WELOG_DOMAIN:-$(env_val WELOG_DOMAIN)}"
EMAIL="${LETSENCRYPT_EMAIL:-$(env_val LETSENCRYPT_EMAIL)}"

if [ -z "$DOMAIN" ] || [ -z "$EMAIL" ]; then
  echo "ERRO: defina WELOG_DOMAIN e LETSENCRYPT_EMAIL no .env antes de rodar." >&2
  exit 1
fi

staging_arg=""
[ "${STAGING:-0}" = "1" ] && staging_arg="--staging"

echo "==> Domínio: $DOMAIN | E-mail: $EMAIL ${staging_arg:+[STAGING]}"

echo "==> 1/4 Certificado temporário (dummy) para o nginx conseguir subir…"
$COMPOSE run --rm --entrypoint sh certbot -c "\
  mkdir -p $LIVE && \
  openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
    -keyout $LIVE/privkey.pem -out $LIVE/fullchain.pem -subj '/CN=$DOMAIN'"

echo "==> 2/4 Construindo e subindo a stack (nginx passa a servir o desafio ACME)…"
$COMPOSE up -d --build

echo "==> 3/4 Removendo o dummy e emitindo o certificado real…"
$COMPOSE run --rm --entrypoint sh certbot -c "\
  rm -rf /etc/letsencrypt/live/$CERT_NAME \
         /etc/letsencrypt/archive/$CERT_NAME \
         /etc/letsencrypt/renewal/$CERT_NAME.conf"
# --entrypoint certbot é obrigatório: o serviço "certbot" tem um entrypoint
# próprio (loop de renovação) que engoliria o "certonly" e ficaria preso nele.
$COMPOSE run --rm --entrypoint certbot certbot certonly --webroot -w /var/www/certbot \
  --cert-name "$CERT_NAME" -d "$DOMAIN" \
  --email "$EMAIL" --agree-tos --no-eff-email --non-interactive $staging_arg

echo "==> 4/4 Recarregando o nginx com o certificado real…"
$COMPOSE exec nginx nginx -s reload

echo ""
echo "==> Pronto! HTTPS ativo em https://$DOMAIN"
echo "    Renovação automática a cargo do serviço 'certbot' (a cada 12h)."
