FROM php:8.5-cli-alpine

ARG UID=1000
ARG GID=1000

RUN addgroup -g ${GID} www && \
    adduser -u ${UID} -G www -s /bin/sh -D www

RUN apk add --no-cache \
    bash \
    curl \
    supervisor \
    python3 \
    py3-pip \
    autoconf \
    build-base \
    libxml2-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    libpq-dev \
    linux-headers

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
    && pecl install redis \
    && docker-php-ext-enable redis

RUN mkdir -p /etc/supervisor/conf.d \
    && mkdir -p /var/log/supervisor \
    && mkdir -p /var/www/html

COPY supervisord.conf /etc/supervisor/supervisord.conf
# Make our config the system default so bare `supervisorctl` (which reads
# /etc/supervisord.conf) connects to the same socket and RPC interface.
COPY supervisord.conf /etc/supervisord.conf
COPY entrypoint.sh /usr/local/bin/docker-entrypoint

RUN chmod +x /usr/local/bin/docker-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/docker-entrypoint"]
