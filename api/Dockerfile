# Do not bump to PHP 8.5: react/promise v2.11 (pulled in transitively by
# luracast/restler) uses syntax 8.5 deprecates and has no upgrade path.
FROM php:8.4.23-fpm-alpine

# pdo_mysql is not in the stock image but composer.json requires it.
RUN set -eux; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql opcache

COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer

# Needed by Composer for dist archives and VCS sources.
RUN apk add --no-cache git unzip

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-inventory-tracker.ini"
COPY docker/php-fpm-env.conf /usr/local/etc/php-fpm.d/zz-env.conf

WORKDIR /var/www/html

# Source arrives via bind mount in development; see docker-compose.yml.

EXPOSE 9000
CMD ["php-fpm"]
