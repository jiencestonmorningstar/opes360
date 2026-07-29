# syntax=docker/dockerfile:1

# OPES360 production image.
#
# Multi-stage so the shipped image carries neither Composer nor Node: the final
# layer holds PHP, the extensions the app actually uses, and the built artefacts.
#
# Deliberately *not* a single container running everything. The queue worker and
# the scheduler are separate services in docker-compose.yml sharing this image,
# because a web container that also runs cron cannot be scaled or restarted
# independently — and the queue is what delivers password resets, so its health
# needs to be visible on its own.

# ---- PHP dependencies -------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Copied before the source so a change to application code does not invalidate
# the dependency layer.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---- Front-end build --------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
# Tailwind scans Blade and PHP for class names, so the build needs the source
# that references them — without this every utility is tree-shaken away.
COPY app ./app
COPY config ./config

RUN npm run build

# ---- Runtime ----------------------------------------------------------------
FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache \
        nginx \
        supervisor \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        gd \
        intl \
        zip \
        opcache \
    && apk del icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev \
    && apk add --no-cache icu-libs libzip libpng libjpeg-turbo freetype

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

COPY docker/php.ini /usr/local/etc/php/conf.d/opes360.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# The web user must own the two directories the app writes to, and nothing else.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080

# Laravel's built-in health endpoint. It boots the framework, so a container
# answering 200 here has a working config and autoloader, not merely a live port.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/up') !== false ? 0 : 1);"

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
