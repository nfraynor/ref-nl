# Use an official PHP image with Apache
FROM php:8.1-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    default-mysql-client \
    default-mysql-server \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli zip

# Set up Apache
COPY ./php/public /var/www/html/
RUN chown -R www-data:www-data /var/www/html
RUN a2enmod rewrite

# Configure Apache virtual host to point to the public directory
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Copy application code
COPY ./php /usr/src/app/php
COPY ./sql /usr/src/app/sql

# Set working directory
WORKDIR /usr/src/app/php

# Expose port 80 for Apache
EXPOSE 80

# Add a script to initialize the database and start Apache
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
