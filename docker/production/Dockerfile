# Meridian's production images, built from the repository root:
#
#   app        php-fpm with the code and built front end (also runs the queue
#              worker, the scheduler and migrations)
#   web        Caddy: HTTPS, the public files, and PHP through FastCGI
#   converter  the conversions worker: docker/converter's tools plus the code
#   gateway    a twin's gateway, for running one beside the control plane
#
# docker/production/compose.yml builds them all; see docs/manual/23-deploying.md.

FROM php:8.5-fpm-alpine AS php

ARG UID=1000
ARG GID=1000

RUN addgroup -g ${GID} www && adduser -u ${UID} -G www -s /bin/sh -D www

RUN apk add --no-cache icu-libs libpng libjpeg-turbo freetype libzip libpq libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
        icu-dev libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev libpq-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_pgsql bcmath gd intl zip pcntl sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY docker/production/php.ini /usr/local/etc/php/conf.d/meridian.ini

WORKDIR /var/www/html

# Composer and npm installs, and the front-end build. Wayfinder's Vite plugin
# runs artisan to generate the route helpers, so this stage has PHP and Node.
FROM php AS build

RUN apk add --no-cache nodejs npm git unzip && npm install -g npm@11
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json ./
COPY scripts ./scripts
RUN npm ci --no-audit --no-fund

COPY . .
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && npm run build \
    && rm -rf node_modules

FROM php AS app

COPY --from=build --chown=www:www /var/www/html /var/www/html
COPY docker/production/entrypoint.sh /usr/local/bin/meridian-entrypoint

USER www
ENTRYPOINT ["meridian-entrypoint"]
CMD ["php-fpm"]
EXPOSE 9000

FROM caddy:2.10-alpine AS web

COPY --from=build /var/www/html/public /var/www/html/public
COPY docker/production/Caddyfile /etc/caddy/Caddyfile

# The converter's tools come from docker/converter, built by compose as the
# converter-tools context; the code goes on top.
FROM converter-tools AS converter

COPY --from=build --chown=www:www /var/www/html /var/www/html
COPY docker/production/entrypoint.sh /usr/local/bin/meridian-entrypoint
USER www
ENTRYPOINT ["meridian-entrypoint"]
CMD ["php", "artisan", "queue:work", "conversions", "--queue=conversions", "--tries=2", "--timeout=14400", "--sleep=3"]

FROM node:24-alpine AS gateway

WORKDIR /srv/meridian
COPY package.json package-lock.json ./
COPY scripts ./scripts
RUN npm ci --no-audit --no-fund
COPY tsconfig.json ./
COPY gateway ./gateway
COPY resources/js ./resources/js
USER node
ENV GATEWAY_HOST=0.0.0.0 GATEWAY_PORT=8787
EXPOSE 8787
CMD ["npx", "tsx", "gateway/gateway.ts"]
