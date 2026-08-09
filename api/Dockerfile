# Do not bump to PHP 8.5: react/promise v2.11 (pulled in transitively by
# luracast/restler) uses syntax 8.5 deprecates and has no upgrade path.
FROM php:8.4.23-fpm-alpine AS base

# pdo_mysql is not in the stock image but composer.json requires it. apcu backs
# the rate limiter and needs a build toolchain, removed again in the same layer.
RUN set -eux; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql opcache; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS; \
    pecl install apcu-5.1.24; \
    docker-php-ext-enable apcu; \
    apk del .build-deps

# Amazon's RDS root certificates. Pointing DB_SSL_CA here is what turns on TLS
# to the database; without it the driver connects in clear text.
COPY docker/certs/rds-global-bundle.pem /etc/ssl/certs/rds-global-bundle.pem

COPY docker/php-fpm-env.conf /usr/local/etc/php-fpm.d/zz-env.conf

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]


# Build-time only: Composer and the tools it shells out to.
FROM base AS build
COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip


FROM build AS dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-inventory-tracker.ini"
# Source arrives via bind mount in development; see docker-compose.yml.


# Resolves dependencies and bakes the source in, so nothing is mounted at run
# time and the image is the deployable unit.
FROM build AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist

COPY . .

# Authoritative classmap: the autoloader stops hitting the filesystem for
# classes it does not already know about.
RUN composer dump-autoload --no-dev --classmap-authoritative

# Generated at build time because production disables autogeneration. The
# directory is created here because .dockerignore keeps var/ out of the context.
RUN mkdir -p var/proxies \
    && APP_ENV=production php bin/doctrine orm:generate-proxies var/proxies


FROM base AS prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php-production.ini "$PHP_INI_DIR/conf.d/zz-inventory-tracker.ini"

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

# Restler writes its route cache here on the first request.
RUN mkdir -p /var/www/html/var/cache \
    && chown -R www-data:www-data /var/www/html/var
