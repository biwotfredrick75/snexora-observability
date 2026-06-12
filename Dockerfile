FROM php:8.3-fpm-bookworm AS base

# System packages + Microsoft ODBC Driver 18 (required for sqlsrv extension)
RUN apt-get update && apt-get install -y \
        curl gnupg unzip git nginx supervisor libzip-dev libpng-dev \
        libxml2-dev libonig-dev libgd-dev \
    && curl -sSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor > /usr/share/keyrings/microsoft.gpg \
    && echo "deb [arch=amd64 signed-by=/usr/share/keyrings/microsoft.gpg] \
        https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions (mirror local setup)
RUN pecl install sqlsrv pdo_sqlsrv \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && docker-php-ext-install \
        pdo pdo_mysql bcmath mbstring xml zip pcntl gd

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies (cached layer)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Copy application source
COPY . .

# Finalise composer + set permissions
RUN composer dump-autoload --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chmod 600 storage/oauth-private.key \
    && chmod 640 storage/oauth-public.key

# Nginx + Supervisor config
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Startup script
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8000 8089

ENTRYPOINT ["/start.sh"]
