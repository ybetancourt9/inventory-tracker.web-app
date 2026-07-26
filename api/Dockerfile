# Pinned to an exact patch. See the project's image-pinning convention.
# NOT PHP 8.5: react/promise v2.11 (reached transitively via luracast/restler ->
# logicalsteps/async) uses `case X;` syntax that 8.5 deprecates, and the ^2.8 pin
# in logicalsteps/async leaves no upgrade path. Restler's own SETUP.md recommends
# PHP 8.3/8.4.
FROM php:8.4.23-fpm-alpine

# pdo_mysql is NOT in the stock php:*-fpm-alpine image but composer.json requires
# it; opcache is a straight win even in dev (validate_timestamps keeps it honest).
RUN set -eux; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql opcache

# Composer runs inside the container so the host never needs PHP installed.
# Pinned, same as every other image in this project.
COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer

# git + unzip let Composer use dist archives and VCS sources without falling back
# to slow paths; both are small.
RUN apk add --no-cache git unzip

# Start from PHP's own development baseline, then layer our overrides on top so
# they survive base-image updates.
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-inventory-tracker.ini"
COPY docker/php-fpm-env.conf /usr/local/etc/php-fpm.d/zz-env.conf

WORKDIR /var/www/html

# Source arrives via bind mount in development (see docker-compose.yml). A
# production image would COPY the source and run `composer install --no-dev
# --optimize-autoloader` here instead.

EXPOSE 9000
CMD ["php-fpm"]
