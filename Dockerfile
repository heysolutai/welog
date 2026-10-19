# --- ESTÁGIO 1: BUILD (Node + PHP + Composer) ---
FROM php:8.3-fpm AS build-stage

# Instala Node 22
RUN apt-get update && apt-get install -y \
    curl gnupg libpq-dev libpng-dev libzip-dev zip unzip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && docker-php-ext-install pdo_pgsql gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /app

# Camada de cache: dependências primeiro
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-plugins --prefer-dist --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

# Copia o projeto e builda
COPY . .
RUN composer dump-autoload
RUN npm run build
RUN composer install --no-dev --optimize-autoloader

# --- ESTÁGIO 2: PRODUÇÃO ---
FROM php:8.3-fpm
ARG user=welog
ARG uid=1000

RUN apt-get update && apt-get install -y \
    libpng-dev libonig-dev libxml2-dev libpq-dev libgmp-dev libzip-dev zip unzip \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gmp gd zip \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN echo "upload_max_filesize=75M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size=75M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini

RUN useradd -G www-data,root -u $uid -d /home/$user $user
WORKDIR /var/www

COPY --chown=$user:$user . .
RUN rm -rf /var/www/public/build \
    && rm -rf /var/www/vendor \
    && rm -f /var/www/bootstrap/cache/*.php

COPY --from=build-stage --chown=$user:$user /app/public/build ./public/build
COPY --from=build-stage --chown=$user:$user /app/vendor ./vendor

RUN chmod -R 755 /var/www/public \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

USER $user
EXPOSE 9000
CMD ["php-fpm"]
