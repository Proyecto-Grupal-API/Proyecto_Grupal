# PHP-FPM 8.3 para el backend Laravel 13 de Campus Digital (Equipo 7).
# Incluye ext-mongodb 2.x (requerida por mongodb/mongodb 2.4 y laravel-mongodb 5.11)
# y Composer para instalar dependencias dentro del contenedor.
FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libssl-dev libcurl4-openssl-dev pkg-config libicu-dev \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && docker-php-ext-install zip intl bcmath pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/zz-campus.ini
COPY entrypoint.sh /usr/local/bin/campus-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/campus-entrypoint && chmod +x /usr/local/bin/campus-entrypoint

WORKDIR /var/www

ENTRYPOINT ["campus-entrypoint"]
CMD ["php-fpm"]
