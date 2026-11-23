# syntax=docker/dockerfile:1.7

FROM php:8.4-fpm-alpine AS php-base

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev oniguruma-dev \
    && apk add --no-cache icu-libs libpq oniguruma \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring opcache pcntl pdo_pgsql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
      --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

FROM node:22-alpine AS assets
WORKDIR /var/www/html
COPY package.json ./
RUN --mount=type=cache,target=/root/.npm npm install --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
COPY .env.example ./.env
RUN npm run build

FROM php-base AS runtime
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build /var/www/html/public/build
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && echo 'clear_env = no' > /usr/local/etc/php-fpm.d/zz-env.conf \
    && printf '%s\n' 'opcache.enable=1' 'opcache.validate_timestamps=0' 'opcache.memory_consumption=128' > /usr/local/etc/php/conf.d/opcache-production.ini
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM nginx:1.27-alpine AS web
COPY --from=runtime /var/www/html/public /var/www/html/public
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
