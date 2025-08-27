FROM php:8.1-apache

RUN apt-get update && apt-get install -y \
    libzip-dev unzip git \
 && docker-php-ext-install pdo pdo_mysql mysqli zip \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY ./docker-builds/php/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY ./docker-builds/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# now we can copy from repo root because context is "."
COPY . /app

RUN chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
