# syntax=docker/dockerfile:1
# Local review image. No migration, seed, key generation or Artisan at build time.
FROM php:8.5-cli-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg unzip libonig-dev libxml2-dev libzip-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libssl-dev \
        libgssapi-krb5-2 unixodbc-dev \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/packages-microsoft-prod.deb -o /tmp/microsoft.deb \
    && dpkg -i /tmp/microsoft.deb \
    && rm /tmp/microsoft.deb \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 zip gd pcntl bcmath \
    && pecl install mongodb-2.4.1 pdo_sqlsrv-5.13.0 \
    && docker-php-ext-enable mongodb pdo_sqlsrv \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY docker/e2/php.ini /usr/local/etc/php/conf.d/99-campus-e2.ini
WORKDIR /var/www/html

FROM composer:2 AS composer-bin
FROM php-base AS dependencies
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
COPY app ./app
COPY database ./database
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins --optimize-autoloader \
    && composer check-platform-reqs --no-dev --no-plugins

FROM node:24-bookworm-slim AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.js tailwind.config.js postcss.config.js ./
# app.js imports Ziggy from vendor; use the same Composer lock as PHP.
COPY --from=dependencies /var/www/html/vendor ./vendor
ENV VITE_APP_NAME="Campus Digital"
RUN npm run build

FROM php-base AS app
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY resources ./resources
COPY routes ./routes
COPY public ./public
COPY artisan composer.json composer.lock ./
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=assets /src/public/build ./public/build
COPY docker/e2/start.sh docker/e2/check-runtime.php /opt/campus-e2/
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && sed -i 's/\r$//' /opt/campus-e2/start.sh \
    && chmod 755 /opt/campus-e2/start.sh \
    && sh -n /opt/campus-e2/start.sh \
    && php -l /opt/campus-e2/check-runtime.php \
    && test ! -e .env && test ! -e public/hot && test ! -e bootstrap/cache/config.php
USER www-data
EXPOSE 8080
ENTRYPOINT ["/opt/campus-e2/start.sh"]
