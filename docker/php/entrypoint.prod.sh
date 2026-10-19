#!/bin/sh
# Entrypoint do container de aplicação em produção.
#
# Existe por causa de uma armadilha do Docker: um volume nomeado só é populado
# a partir da imagem quando está VAZIO. Como o nginx lê os assets de
# `app-public`, sem este passo o primeiro deploy congelaria o `public/build`
# para sempre — todo deploy seguinte subiria backend novo servindo o
# JavaScript antigo, sem erro nenhum aparecendo.
#
# Roda como root de propósito: um volume criado por uma imagem anterior mantém
# a posse antiga (o Docker não a reajusta), então precisamos poder corrigi-la.
# O php-fpm master segue como root e derruba os workers para www-data pela
# diretiva `user` do pool — que é o arranjo padrão da imagem oficial.
set -eu

APP_USER=www-data

fix_owner() {
    # `|| true`: em bind mount de host o chown pode não ser permitido, e isso
    # não é motivo para o container não subir.
    chown -R "$APP_USER:$APP_USER" "$1" 2>/dev/null || true
}

if [ -d /opt/public-dist ]; then
    fix_owner /var/www/public
    echo '[entrypoint] publicando assets no volume compartilhado…'
    cp -a /opt/public-dist/. /var/www/public/
fi

# storage/ vem de um volume compartilhado com worker e scheduler; garante a
# árvore que o Laravel espera mesmo num volume recém-criado.
mkdir -p \
    /var/www/storage/app/private \
    /var/www/storage/app/public \
    /var/www/storage/framework/cache/data \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/storage/logs

fix_owner /var/www/storage
fix_owner /var/www/bootstrap/cache

# `php artisan storage:link` equivalente, sem depender de banco nem de config
# cacheada. Precisa vir DEPOIS do cp acima: `public/` é volume, então o symlink
# não sobrevive na imagem e some a cada recreate — e sem ele toda foto de kit
# enviada pela WeGift responde 404.
ln -sfn /var/www/storage/app/public /var/www/public/storage

# worker e scheduler compartilham esta imagem, mas rodam `php artisan`, não
# php-fpm — para esses o master root não faz sentido.
case "${1:-}" in
    php-fpm) exec "$@" ;;
    *)
        # `setpriv` troca o USUÁRIO, não o AMBIENTE: sem esta linha o processo
        # vira www-data carregando HOME=/root. O libpq resolve o certificado
        # de cliente a partir do HOME (`~/.postgresql/postgresql.crt`), bate
        # num diretório 0700 de root e derruba a CONEXÃO INTEIRA com
        # "Permission denied" — não é aviso, é falha de conectar.
        #
        # O sintoma engana: o site continua de pé (php-fpm não passa por
        # aqui) e só a fila morre, com todo job falhando em milissegundos,
        # antes de qualquer I/O de rede. Em /var/www o arquivo simplesmente
        # não existe, e aí o libpq segue adiante sem cliente TLS — que é o
        # esperado para o Postgres gerenciado (sslmode=require, sem mTLS).
        export HOME=/var/www
        exec setpriv --reuid="$APP_USER" --regid="$APP_USER" --init-groups "$@"
        ;;
esac
