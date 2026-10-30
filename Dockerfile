FROM php:8.4-cli-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev libxml2-dev unzip \
    && docker-php-ext-install -j1 pdo_pgsql mbstring dom xml xmlwriter pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN printf 'expose_php=Off\nlog_errors=On\ndisplay_errors=Off\nzend.exception_ignore_args=On\nmemory_limit=128M\npost_max_size=32K\nupload_max_filesize=16K\n' > /usr/local/etc/php/conf.d/accesspolicy.ini
WORKDIR /app
COPY composer.json composer.lock ./

FROM base AS test
RUN --mount=type=cache,target=/tmp/composer-cache COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-interaction --prefer-dist --no-scripts --no-plugins
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-interaction --no-plugins
CMD ["php", "vendor/bin/phpunit", "--testdox"]

FROM base AS build
RUN --mount=type=cache,target=/tmp/composer-cache COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-plugins
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-plugins

FROM base AS runtime
COPY --from=build --chown=10001:10001 /app /app
RUN groupadd -g 10001 accesspolicy && useradd -u 10001 -g 10001 --no-create-home accesspolicy
ENV PHP_CLI_SERVER_WORKERS=4
USER 10001:10001
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
