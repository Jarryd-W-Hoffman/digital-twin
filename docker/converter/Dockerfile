# Runs Meridian's queue worker for the "conversions" queue, with the tools
# those jobs call. Point clouds: PDAL (reads LAS, LAZ and E57; reprojects and
# thins) and py3dtiles (tiles the result as 3D Tiles). Terrain: GDAL (reads
# and reprojects DEMs), pydelatin (triangulates) and quantized-mesh-encoder,
# driven by meridian-terrain. Alpine's PDAL has no E57 reader and
# py3dtiles' numba doesn't build on musl, and Debian doesn't package PDAL,
# so the tools come from conda-forge and PyPI on Debian.
FROM mambaorg/micromamba:2.5.0 AS micromamba

FROM php:8.5-cli-trixie

ARG UID=1000
ARG GID=1000

RUN groupadd -g ${GID} www && useradd -u ${UID} -g www -m -s /bin/sh www

RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libpq-dev \
    libxml2-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

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
    sockets \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=micromamba /bin/micromamba /usr/local/bin/micromamba

RUN micromamba create -y -p /opt/pointclouds -c conda-forge python=3.12 pdal=2.10.1 \
        gdal=3.13.3 pydelatin=0.4.0 quantized-mesh-encoder=0.5.0 pip \
    && /opt/pointclouds/bin/pip install --no-cache-dir "py3dtiles[las]==12.1.1" \
    && micromamba clean -a -y

ENV PATH=/opt/pointclouds/bin:$PATH \
    PROJ_DATA=/opt/pointclouds/share/proj \
    GDAL_DATA=/opt/pointclouds/share/gdal

COPY meridian-terrain /usr/local/bin/meridian-terrain

USER www

WORKDIR /var/www/html

# Conversions can take hours on a large scan; retry_after on the queue
# connection must stay above this timeout.
CMD ["php", "artisan", "queue:work", "--queue=conversions", "--tries=2", "--timeout=14400", "--sleep=3"]
