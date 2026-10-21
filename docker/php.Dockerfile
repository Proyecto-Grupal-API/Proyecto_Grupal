FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip libssl-dev pkg-config curl \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# El proyecto corre con bind-mount sobre Windows (Docker Desktop), que es muy
# lento para I/O de archivos pequenos. Por defecto PHP/OPcache hace un stat()
# de CADA archivo de Laravel en CADA peticion para revisar si cambio, y eso
# vuelve lentisima cualquier peticion (incluso una simple lectura de cache en
# memoria). Desactivamos esa revalidacion y subimos el cache de rutas (realpath)
# para que el framework no vuelva a tocar el disco en cada request.
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'realpath_cache_size=4096K'; \
    echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/perf.ini

WORKDIR /var/www
EXPOSE 8000
