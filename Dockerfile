# Pakai image PHP + Apache
FROM php:8.2-apache

# Install ekstensi PostgreSQL untuk PDO
RUN apt-get update \
    && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

WORKDIR /var/www/html

# Copy semua file project ke dalam container
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html/api

# Expose port default Apache
EXPOSE 80
