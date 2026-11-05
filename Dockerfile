##############################################
# Stage 1: Build do painel Vue
##############################################
FROM node:22-alpine AS frontend

ARG NODE_OPTIONS="--max-old-space-size=768"
ENV NODE_OPTIONS=$NODE_OPTIONS

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

##############################################
# Stage 2: PHP + Nginx de producao
##############################################
# O composer.lock exige PHP >= 8.4.1 (pacotes symfony).
FROM php:8.4-fpm

# GD precisa de JPEG e WebP: as fotos dos animais sao convertidas para WebP.
RUN apt-get update && apt-get install -y \
    curl \
    libjpeg62-turbo-dev \
    libonig-dev \
    libpng-dev \
    libpq-dev \
    libwebp-dev \
    libxml2-dev \
    nginx \
    supervisor \
    unzip \
    zip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install bcmath exif gd mbstring opcache pcntl pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Dependencias antes do codigo: o vendor so e reinstalado quando o composer.lock muda.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

COPY backend/ ./
COPY --from=frontend /app/dist ./spa

RUN composer dump-autoload --no-dev --optimize --no-interaction

RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/producao.ini "$PHP_INI_DIR/conf.d/zz-producao.ini"

RUN rm /etc/nginx/sites-enabled/default
COPY docker/nginx/default.conf /etc/nginx/sites-enabled/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY docker/entrypoint.prod.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

CMD ["entrypoint.sh"]
