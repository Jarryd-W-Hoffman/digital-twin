FROM php:8.5-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN addgroup -g ${GID} www && \
    adduser -u ${UID} -G www -s /bin/sh -D www

RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    autoconf \
    build-base \
    libxml2-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    libpq-dev \
    linux-headers \
    libstdc++ \
    libgcc \
    nodejs \
    npm \
    zip

# opcache and mbstring are built into PHP 8.5, so they're not listed here.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    bcmath \
    gd \
    xml \
    zip \
    intl \
    pcntl \
    sockets

# Xdebug provides code coverage for the starter kit's 100% coverage gate.
# It stays off unless XDEBUG_MODE is set, e.g. XDEBUG_MODE=coverage.
RUN pecl install redis xdebug \
    && docker-php-ext-enable redis xdebug

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Alpine's npm is too old for vite-plus's npm: overrides; use npm 11.
RUN npm install -g npm@11

RUN mkdir -p /var/www/html && chown -R www:www /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/custom.ini

ENV XDEBUG_MODE=off

USER www

WORKDIR /var/www/html

EXPOSE 9000
