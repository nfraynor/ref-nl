# RugbyRef.nl -- Development & Deployment Guide

This project provides a PHP/Apache web app with a MySQL backend, fronted
by HAProxy for HTTPS termination and load-balancing.

------------------------------------------------------------------------

## ✅ Local Development (Simple PHP Server)

### 1️⃣ Ensure PHP is Installed

``` bash
php -v
```

If PHP is missing, install PHP (available on Windows, macOS, Linux).

### 2️⃣ Run with PHP's Built-In Server

From the project root (where `/php/public/` lives):

``` bash
php -S localhost:8000 -t php/public
```

### 3️⃣ Open Your Browser

Visit:

    http://localhost:8000

You can now browse matches, referees, assignments, etc.

------------------------------------------------------------------------

## ✅ Running with Docker (Multi-Container: Apache, MySQL, HAProxy)

The production setup uses **three containers**: - **MySQL** → Database
storage - **Apache/PHP** → Runs the PHP application - **HAProxy** →
Terminates HTTPS, forces redirects to HTTPS, forwards traffic to Apache

### 1️⃣ Build Images

From the project root:

``` bash
docker compose build
```

This builds: - `db` from `./db/Dockerfile` - `apache` from
`./apache/Dockerfile` - `haproxy` from `./haproxy/Dockerfile`

### 2️⃣ Configure Environment

Create a `.env` file in the project root:

``` env
DB_ROOT_PASSWORD=super-strong-root-secret
DB_APP_PASSWORD=another-strong-secret
```

### 3️⃣ Start the Stack

``` bash
docker compose up -d
```

Services: - `db` → MySQL 8.4 with persistent volume (`db_data`) -
`apache` → PHP/Apache serving the app - `haproxy` → Public entrypoint on
ports 80/443

### 4️⃣ Issue SSL Certificates (Let's Encrypt)

On first deployment, request certificates:

``` bash
docker compose run --rm certbot certonly   --webroot -w /var/www/html/php/public   -d rugbyref.nl -d www.rugbyref.nl   --email you@rugbyref.nl --agree-tos --no-eff-email
```

Then concatenate fullchain + privkey for HAProxy:

``` bash
docker compose exec certbot sh -c '\
  cat /etc/letsencrypt/live/rugbyref.nl/fullchain.pem \
      /etc/letsencrypt/live/rugbyref.nl/privkey.pem \
   > /etc/haproxy/certs/rugbyref.nl.pem'

docker compose restart haproxy
```

Now HAProxy terminates TLS and forwards to Apache.

### 5️⃣ Open Your Browser

Visit:

    https://rugbyref.nl

Traffic on HTTP (port 80) is automatically redirected to HTTPS.

------------------------------------------------------------------------

## ✅ Renewing Certificates

Certificates must be renewed every \~60--90 days.

Simple cron job on host (daily):

``` bash
#!/bin/sh
cd /path/to/project
docker compose run --rm certbot renew --webroot -w /var/www/html/php/public
docker compose exec certbot sh -c '\
  cat /etc/letsencrypt/live/rugbyref.nl/fullchain.pem \
      /etc/letsencrypt/live/rugbyref.nl/privkey.pem \
   > /etc/haproxy/certs/rugbyref.nl.pem'
docker compose kill -s HUP haproxy || docker compose restart haproxy
```

------------------------------------------------------------------------

## ✅ Useful Commands

Action           Command
  ---------------- ------------------------------------------
Start stack      `docker compose up -d`
Stop stack       `docker compose down`
View logs        `docker compose logs -f`
Access MySQL     `docker compose exec db mysql -uroot -p`
Rebuild images   `docker compose build --no-cache`

------------------------------------------------------------------------

## ✅ (Legacy) Manual PHP + MySQL

You can still run locally without Docker:

1.  Start MySQL manually and update `php/config/database.php` with your
    credentials.\

2.  Run migrations:

    ``` bash
    php provision.php
    php seed.php
    ```

3.  Serve with PHP:

    ``` bash
    php -S localhost:8000 -t php/public
    ```

4.  Visit `http://localhost:8000`.

------------------------------------------------------------------------

✅ With this, you can develop locally with PHP's built-in server or run
the full HAProxy + Apache + MySQL stack in Docker for production.
