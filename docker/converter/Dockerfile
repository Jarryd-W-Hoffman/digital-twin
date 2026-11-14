# Runs Meridian's queue worker for the "conversions" queue, with the tools
# those jobs call. Point clouds: PDAL (reads LAS, LAZ and E57; reprojects and
# thins) and py3dtiles (tiles the result as 3D Tiles). Terrain: GDAL (reads
# and reprojects DEMs), pydelatin (triangulates) and quantized-mesh-encoder,
# driven by meridian-terrain. Map layers and imagery: GDAL, driven by
# meridian-vector and meridian-imagery. Survey volumes: GDAL and numpy, driven by
# meridian-volume. IFC: IfcOpenShell from PyPI (its wheel bundles
# OpenCascade; the conda-forge build can't share an environment with PDAL
# and GDAL, and doubles the image), driven by meridian-ifc. Photogrammetry
# meshes: Obj2Tiles (OpenDroneMap, AGPL-3.0, run as a separate program; its
# self-contained release, checked against its SHA-256, with its licence and
# notices in /opt/obj2tiles), driven by meridian-mesh. Alpine's PDAL has no E57 reader and
# py3dtiles' numba doesn't build on musl, and Debian doesn't package PDAL,
# so the tools come from conda-forge and PyPI on Debian.
FROM mambaorg/micromamba:2.5.0 AS micromamba

FROM debian:trixie-slim AS obj2tiles
ARG OBJ2TILES_VERSION=1.9.0
ARG OBJ2TILES_SHA256=91d9078b24e1fcc96d7d026add082288fde7d96e46e2e73988cc7515eb88c338
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl unzip \
    && base=https://github.com/OpenDroneMap/Obj2Tiles/releases/download/v${OBJ2TILES_VERSION} \
    && curl -fsSL -o /tmp/obj2tiles.zip "$base/Obj2Tiles-Linux64.zip" \
    && echo "${OBJ2TILES_SHA256}  /tmp/obj2tiles.zip" | sha256sum -c - \
    && mkdir -p /opt/obj2tiles \
    && unzip -q /tmp/obj2tiles.zip -d /opt/obj2tiles \
    && curl -fsSL -o /opt/obj2tiles/LICENSE.md "$base/LICENSE.md" \
    && curl -fsSL -o /opt/obj2tiles/THIRD_PARTY_NOTICES.md "$base/THIRD_PARTY_NOTICES.md" \
    && printf 'Obj2Tiles %s, unmodified, from https://github.com/OpenDroneMap/Obj2Tiles\nSource: https://github.com/OpenDroneMap/Obj2Tiles/archive/refs/tags/v%s.tar.gz\n' \
        "${OBJ2TILES_VERSION}" "${OBJ2TILES_VERSION}" > /opt/obj2tiles/SOURCE \
    && chmod +x /opt/obj2tiles/Obj2Tiles

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

RUN micromamba create -y -p /opt/pointclouds -c conda-forge python=3.12 pdal=2.10.2 \
        gdal=3.13.3 pydelatin=0.4.0 quantized-mesh-encoder=0.5.0 pip \
    && /opt/pointclouds/bin/pip install --no-cache-dir "py3dtiles[las]==12.1.1" "ifcopenshell==0.9.0.post1" \
    && micromamba clean -a -y

COPY --from=obj2tiles /opt/obj2tiles /opt/obj2tiles

ENV PATH=/opt/pointclouds/bin:$PATH \
    OBJ2TILES=/opt/obj2tiles/Obj2Tiles \
    PROJ_DATA=/opt/pointclouds/share/proj \
    GDAL_DATA=/opt/pointclouds/share/gdal

COPY meridian-terrain /usr/local/bin/meridian-terrain
COPY meridian-ifc /usr/local/bin/meridian-ifc
COPY meridian-vector /usr/local/bin/meridian-vector
COPY meridian-imagery /usr/local/bin/meridian-imagery
COPY meridian-volume /usr/local/bin/meridian-volume
COPY meridian-mesh /usr/local/bin/meridian-mesh

USER www

WORKDIR /var/www/html

# Conversions can take hours on a large scan; retry_after on the queue
# connection must stay above this timeout.
CMD ["php", "artisan", "queue:work", "--queue=conversions", "--tries=2", "--timeout=14400", "--sleep=3"]
