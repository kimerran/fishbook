# Multi-stage Dockerfile for the Fishbook backend.
# Dev: docker compose mounts the source and runs `php artisan serve`.
# Prod: future slice 1.5 uses the `app` stage.

FROM php:8.3-cli-alpine AS base

RUN apk add --no-cache \
      git \
      unzip \
      libzip-dev \
      icu-dev \
      postgresql-dev \
      oniguruma-dev \
      libpng-dev \
      libjpeg-turbo-dev \
      libwebp-dev \
      freetype-dev \
    && apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS \
      autoconf \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install pdo pdo_pgsql zip intl mbstring gd bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- Dependencies stage (cached when composer.* unchanged) ---
FROM base AS deps
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist

# --- Application stage (used in prod) ---
FROM base AS app
COPY --from=deps /var/www/html/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
