FROM php:8.1-apache

RUN apt-get update && apt-get install -y \
    libzip-dev unzip git \
 && docker-php-ext-install pdo pdo_mysql mysqli zip \
 && rm -rf /var/lib/apt/lists/*

# Enable useful modules
RUN a2enmod rewrite headers

# VHost + entrypoint
COPY ./docker-builds/php/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY ./docker-builds/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Put the app where your vhost points
WORKDIR /app
COPY . /app

# Permissions for Apache user
RUN chown -R www-data:www-data /app

# If you want to store ACME challenges inside the app tree:
RUN mkdir -p /app/php/public/.well-known/acme-challenge

EXPOSE 80

# Use absolute path; ensure entrypoint.sh uses LF line endings and starts with #!/bin/sh or #!/bin/bash
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
