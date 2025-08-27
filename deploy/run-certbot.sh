#!/bin/bash
set -e

STACK_FILE="deploy-stack.yml"
DOMAIN="rugbyref.eu"
EMAIL="n.f.raynor@gmail.com"

# Preferred webroot inside the containers (adjust if your docroot differs)
WEBROOT_CANDIDATES=(
  "/var/www/html/php/public"
  "/var/www/html/public"
  "/var/www/html"
)

# Determine a working webroot path inside certbot container
echo "🔎 Detecting webroot inside certbot container..."
FOUND_WEBROOT=""
for p in "${WEBROOT_CANDIDATES[@]}"; do
  if docker compose -f "$STACK_FILE" run --rm certbot test -d "$p"; then
    FOUND_WEBROOT="$p"
    break
  fi
done

if [ -z "$FOUND_WEBROOT" ]; then
  echo "❌ Could not find a valid webroot path in certbot container."
  echo "   Checked: ${WEBROOT_CANDIDATES[*]}"
  echo "   Make sure deploy-stack.yml mounts the ACME volume at the right path."
  exit 1
fi

echo "✅ Using webroot: $FOUND_WEBROOT"

# Ensure the challenge directory exists via Apache (so path is served over HTTP)
echo "📁 Ensuring challenge directory exists in Apache container..."
docker compose -f "$STACK_FILE" exec apache mkdir -p "$FOUND_WEBROOT/.well-known/acme-challenge"

# Issue / renew certs
echo "🔑 Requesting Let's Encrypt certificate for $DOMAIN and www.$DOMAIN..."
docker compose -f "$STACK_FILE" run --rm certbot certonly \
  --webroot -w "$FOUND_WEBROOT" \
  -d "$DOMAIN" -d "www.$DOMAIN" \
  --email "$EMAIL" --agree-tos --no-eff-email

# Build combined PEM for HAProxy
echo "📦 Building combined PEM for HAProxy..."
docker compose -f "$STACK_FILE" exec certbot sh -c "\
  cat /etc/letsencrypt/live/$DOMAIN/fullchain.pem \
      /etc/letsencrypt/live/$DOMAIN/privkey.pem \
   > /etc/haproxy/certs/$DOMAIN.pem"

# Reload HAProxy
echo "🔄 Restarting HAProxy..."
docker compose -f "$STACK_FILE" restart haproxy

echo "✅ Certificate installed and HAProxy restarted for $DOMAIN"
