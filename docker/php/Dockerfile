# slot4u — PHP-FPM 8.3 image (dev + prod-azonos alap)
# Egy image szolgálja ki az app (fpm), reverb és horizon konténereket.
FROM php:8.3-fpm-bookworm

# Rendszer-függőségek a PHP extensionökhöz
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libonig-dev default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql intl zip gd bcmath pcntl opcache sodium \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer (hivatalos image-ből másolva)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Nem-root felhasználó, hogy a bind-mountolt fájlok jogai stimmeljenek WSL-en
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g www-data www-data

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-slot4u.ini

WORKDIR /var/www/html
USER www-data

EXPOSE 9000
CMD ["php-fpm"]
