FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev \
    libsqlite3-dev zip unzip default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite mbstring xml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
