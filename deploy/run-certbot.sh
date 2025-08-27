#!/bin/bash
set -e

STACK_FILE="deploy-stack.yml"
DOMAIN="rugbyref.eu"
EMAIL="you@rugbyref.eu"
WEBROOT="/acme"

echo "📁 Ensuring ACME dir exists..."
docker compose -f "$STACK_FILE" exec apache mkdir -p "$WEBROOT"

echo "🔑 Requesting Let's Encrypt certificate for $DOMAIN and www.$DOMAIN..."
docker compose -f "$STACK_FILE" run --rm certbot certonly \
  --webroot -w "$WEBROOT" \
  -d "$DOMAIN" -d "www.$DOMAIN" \
  --email "$EMAIL" --agree-tos --no-eff-email

echo "📦 Building HAProxy PEM..."
docker compose -f "$STACK_FILE" exec certbot sh -lc "\
  cat /etc/letsencrypt/live/$DOMAIN/fullchain.pem \
      /etc/letsencrypt/live/$DOMAIN/privkey.pem \
   > /etc/haproxy/certs/$DOMAIN.pem"

echo "🔄 Restarting HAProxy..."
docker compose -f "$STACK_FILE" restart haproxy

echo "✅ Certificate installed and HAProxy restarted for $DOMAIN"
