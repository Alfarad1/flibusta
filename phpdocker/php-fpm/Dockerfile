FROM php:8.3-fpm-alpine

ARG PUID=82
ARG PGID=82

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

WORKDIR "/application"

RUN apk --update add postgresql-client;\
  apk add python3;\
  apk add coreutils;
RUN chmod +x /usr/local/bin/install-php-extensions; install-php-extensions gd bz2 pdo_pgsql zip
ARG FB2C_VERSION=1.78.5
RUN wget -qO /tmp/fb2c.zip "https://github.com/rupor-github/fb2converter/releases/download/v${FB2C_VERSION}/fb2c-linux-amd64.zip" \
  && mkdir -p /opt/fb2c && unzip -q /tmp/fb2c.zip -d /opt/fb2c && chmod +x /opt/fb2c/* && rm /tmp/fb2c.zip
RUN apk add --no-cache shadow \
  && groupmod -o -g "$PGID" www-data \
  && usermod -o -u "$PUID" -g "$PGID" www-data \
  && apk del shadow
