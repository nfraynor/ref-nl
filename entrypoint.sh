#!/bin/bash
set -e

DATADIR="/var/lib/mysql"

# Ensure the MySQL/MariaDB data directory is owned by the mysql user
# This is important for bind mounts, as the host directory might have different ownership.
echo "Ensuring $DATADIR ownership is mysql:mysql..."
chown -R mysql:mysql "$DATADIR"
chmod -R 700 "$DATADIR" # MariaDB/MySQL prefers stricter permissions on its data directory

# Initialize MariaDB data directory if it's empty
# (e.g., first run with an empty bind mount)
if [ -z "$(ls -A $DATADIR)" ]; then
    echo "Data directory is empty. Initializing MariaDB..."
    mysql_install_db --user=mysql --datadir="$DATADIR"
    echo "MariaDB data directory initialized."
fi

# Start MariaDB/MySQL server
echo "Attempting to start MariaDB/MySQL server..."
# Using mysqld_safe ensures it daemonizes correctly and logs issues.
# The & backgrounds it so this script can continue.
mysqld_safe --user=mysql --datadir="$DATADIR" &

# Give MariaDB/MySQL a few seconds to initialize
echo "Waiting for MariaDB/MySQL to initialize (up to 30 seconds)..."
for i in {1..30}; do
    if mysqladmin ping -hlocalhost --silent; then
        echo "MariaDB/MySQL started."
        break
    fi
    echo -n "."
    sleep 1
done

if ! mysqladmin ping -hlocalhost --silent; then
    echo "ERROR: MariaDB/MySQL failed to start after 30 seconds."
    # Attempt to print logs if available
    if [ -f "$DATADIR/$(hostname).err" ]; then
        echo "Displaying MariaDB/MySQL error log:"
        cat "$DATADIR/$(hostname).err"
    fi
    exit 1
fi

# Check if the database exists.
# Need to handle case where root password isn't set yet on first init.
# Try without password first. If it fails, it means it's likely set (e.g. by a previous run or init).
DB_EXISTS_QUERY="SHOW DATABASES LIKE 'refnl';"
if mysql -uroot -e "$DB_EXISTS_QUERY" > /dev/null 2>&1; then
    DB_EXISTS=$(mysql -uroot -e "$DB_EXISTS_QUERY" | grep "refnl" > /dev/null; echo "$?")
elif mysql -uroot -p"password" -e "$DB_EXISTS_QUERY" > /dev/null 2>&1; then
    DB_EXISTS=$(mysql -uroot -p"password" -e "$DB_EXISTS_QUERY" | grep "refnl" > /dev/null; echo "$?")
else
    # If neither works, assume DB doesn't exist and root password needs setting.
    DB_EXISTS=1
fi

if [ "$DB_EXISTS" -eq "1" ]; then
    echo "Database 'refnl' does not exist. Creating and provisioning..."
    # Create database
    mysql -uroot -e "CREATE DATABASE IF NOT EXISTS refnl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    # Set/update root password and ensure root can connect from localhost (usually default)
    # Note: Initial MySQL setup often has root without a password or with a temporary one.
    # This command will set the root password.
    mysql -uroot -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'password';"
    mysql -uroot -p"password" -e "GRANT ALL PRIVILEGES ON refnl.* TO 'root'@'localhost';"
    mysql -uroot -p"password" -e "FLUSH PRIVILEGES;"

    echo "MySQL root password set to 'password' and granted privileges on 'refnl' database."

    # Run provisioning and seeding scripts
    # The application will now connect as root with the new password.
    # WORKDIR is /app, scripts are in php/provisioning/
    php php/provisioning/provision.php
    php php/provisioning/seed.php
    echo "Database created and provisioned."
else
    echo "Database 'refnl' already exists."
fi

# Execute the CMD from the Dockerfile (apache2-foreground)
exec "$@"
