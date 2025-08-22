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

# Copy the entire project context to /app
COPY . /app

# Set up Apache
# Ownership will be set on /app, which includes php/public
RUN chown -R www-data:www-data /app/php
RUN a2enmod rewrite

# Configure Apache virtual host (will point to /app/php/public)
# Ensure 000-default.conf is copied from the new location if it's part of the project repo
# If 000-default.conf is a standalone file managed by you, this copy is fine.
# Assuming 000-default.conf is at the root of the build context (where Dockerfile is)
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Set working directory to the project root within the container
WORKDIR /app

# Add VOLUME instruction for MySQL data persistence
VOLUME /var/lib/mysql

# Expose port 80 for Apache
EXPOSE 80

# Add a script to initialize the database and start Apache
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]