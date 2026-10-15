# Build frontend
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# Production image
FROM php:8.4-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    oniguruma-dev \
    icu-dev \
    postgresql-dev \
    # pg_dump for the nightly backup (backup:run). postgresql-dev provides the
    # headers pdo_pgsql compiles against; the CLIENT package is what ships the
    # dump binary, and without it the nightly backup fails with "not found".
    postgresql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_pgsql \
        pdo \
        mbstring \
        gd \
        zip \
        bcmath \
        intl \
        opcache \
        pcntl \
    && apk add --no-cache --virtual .phpredis-deps autoconf g++ make \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .phpredis-deps \
    && rm -rf /root/.postgresql

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# PHP dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# App code
COPY . .
COPY --from=frontend /app/public/build ./public/build

# Storage & bootstrap cache dirs
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions \
    storage/framework/views bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Run post-install scripts now that full app is present
RUN composer run-script post-autoload-dump || true

# Nginx config
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# PHP-FPM config
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# PHP config
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

# Entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
