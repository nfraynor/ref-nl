#!/bin/bash
set -e

# Start MySQL server
service mysql start

# Wait for MySQL to be ready
echo "Waiting for MySQL to start..."
while ! mysqladmin ping -hlocalhost --silent; do
    sleep 1
done
echo "MySQL started."

# Check if the database exists
DB_EXISTS=$(mysql -uroot -e "SHOW DATABASES LIKE 'refnl';" | grep "refnl" > /dev/null; echo "$?")

if [ "$DB_EXISTS" -eq "1" ]; then
    echo "Database 'refnl' does not exist. Creating and provisioning..."
    # Create database and user
    mysql -uroot -e "CREATE DATABASE IF NOT EXISTS refnl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -uroot -e "CREATE USER IF NOT EXISTS 'refnl_user'@'localhost' IDENTIFIED BY 'password';" # Consider making password configurable
    mysql -uroot -e "GRANT ALL PRIVILEGES ON refnl.* TO 'refnl_user'@'localhost';"
    mysql -uroot -e "FLUSH PRIVILEGES;"

    # Run provisioning and seeding scripts
    php /usr/src/app/php/provisioning/provision.php
    php /usr/src/app/php/provisioning/seed.php
    echo "Database created and provisioned."
else
    echo "Database 'refnl' already exists."
fi

# Execute the CMD from the Dockerfile (apache2-foreground)
exec "$@"
