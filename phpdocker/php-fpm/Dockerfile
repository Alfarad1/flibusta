FROM php:8.3-fpm-alpine

WORKDIR "/application"

# Расширения собираются штатным docker-php-ext-install из исходников PHP в образе:
# без PECL (pecl.php.net бывает недоступен, и сборка висит на таймаутах) и без скачивания установщика.
# gd — с теми же форматами, что давал install-php-extensions (обложки читаются через imagecreatefromstring).
RUN apk add --no-cache postgresql-client python3 coreutils \
    libpng libjpeg-turbo freetype libwebp libavif libxpm libzip bzip2 libpq \
  && apk add --no-cache --virtual .build-deps \
    libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev libavif-dev libxpm-dev \
    libzip-dev bzip2-dev postgresql-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif --with-xpm \
  && docker-php-ext-install -j"$(nproc)" gd bz2 pdo_pgsql zip \
  && apk del .build-deps
ARG FB2C_VERSION=1.78.5
RUN wget -qO /tmp/fb2c.zip "https://github.com/rupor-github/fb2converter/releases/download/v${FB2C_VERSION}/fb2c-linux-amd64.zip" \
  && mkdir -p /opt/fb2c && unzip -q /tmp/fb2c.zip -d /opt/fb2c && chmod +x /opt/fb2c/* && rm /tmp/fb2c.zip
ARG PUID=82
ARG PGID=82
RUN apk add --no-cache shadow \
  && groupmod -o -g "$PGID" www-data \
  && usermod -o -u "$PUID" -g "$PGID" www-data \
  && apk del shadow
