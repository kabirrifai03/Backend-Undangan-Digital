# Pakai image PHP + Apache
FROM php:8.2-apache

# Copy semua file project ke dalam container
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html/api

# Expose port default Apache
EXPOSE 80
