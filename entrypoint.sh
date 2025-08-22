#!/usr/bin/env bash
set -euo pipefail

DATADIR="/var/lib/mysql"
RUNDIR="/var/run/mysqld"
SOCKET="${RUNDIR}/mysqld.sock"
CONF_DIR="/etc/mysql/conf.d"   # MySQL-compatible include dir
MYSQL_ROOT_PASSWORD="password"
# ---- config via env ----
: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD must be set (e.g. -e MYSQL_ROOT_PASSWORD=...)}"

echo "Ensuring MySQL dirs/ownership..."
mkdir -p "$DATADIR" "$RUNDIR" "$CONF_DIR"
chown -R mysql:mysql "$DATADIR" "$RUNDIR"
chmod 700 "$DATADIR"

# ---- make sure bind-address is 0.0.0.0 and DNS lookups are off ----
# Debian/MariaDB primary file:
if [ -f /etc/mysql/mariadb.conf.d/50-server.cnf ]; then
  sed -i -E "s|^[# ]*bind-address[[:space:]]*=.*|bind-address = 0.0.0.0|" /etc/mysql/mariadb.conf.d/50-server.cnf
fi
# Generic include (applies to MySQL/MariaDB):
cat > "${CONF_DIR}/60-docker.cnf" <<'EOF'
[mysqld]
bind-address=0.0.0.0
skip-name-resolve=ON
# do NOT set: skip-networking
EOF

# ---- initialize data dir if empty ----
if [ ! -d "${DATADIR}/mysql" ] || [ -z "$(ls -A "${DATADIR}/mysql" 2>/dev/null || true)" ]; then
  echo "Initializing data directory..."
  if command -v mysql_install_db >/dev/null 2>&1; then
    mysql_install_db --user=mysql --datadir="$DATADIR"
  else
    mysqld --initialize-insecure --user=mysql --datadir="$DATADIR"
  fi
fi

# ---- start mysqld (background) ----
echo "Starting mysqld..."
mysqld_safe --user=mysql --datadir="$DATADIR" --socket="$SOCKET" --port=3306 >/dev/null 2>&1 &

# ---- wait for readiness via socket ----
echo "Waiting for MySQL to be ready (up to 60s)..."
for i in {1..60}; do
  if mysqladmin --socket="$SOCKET" ping --silent; then
    echo "mysqld is ready."
    break
  fi
  sleep 1
done
if ! mysqladmin --socket="$SOCKET" ping --silent; then
  echo "ERROR: mysqld failed to start in time."
  if [ -f "$DATADIR/$(hostname).err" ]; then
    echo "----- mysqld error log -----"
    cat "$DATADIR/$(hostname).err" || true
  fi
  exit 1
fi

# ---- root password + remote root grant (no provisioning) ----
# Keep root@localhost for socket/tunnel logins; add root@% for remote admin
mysql --socket="$SOCKET" -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}';
CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL


# Optional hardening: require SSL for remote root (comment out if you won’t use SSL clients)
# mysql --socket="$SOCKET" -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "ALTER USER 'root'@'%' REQUIRE SSL;"

# ---- show listener for quick sanity ----
(if command -v ss >/dev/null 2>&1; then ss -lntp; else netstat -lntp; fi) 2>/dev/null | grep 3306 || true

# ---- hand off to Apache ----
exec "$@"
