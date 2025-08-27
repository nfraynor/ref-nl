#!/bin/bash
set -e

STACK_FILE="deploy-stack.yml"
DOMAIN="rugbyref.eu"
EMAIL="n.f.raynor@gmail.com"

echo "🔑 Requesting Let's Encrypt certificate for $DOMAIN and www.$DOMAIN..."

docker compose -f $STACK_FILE run --rm certbot certonly \
  --webroot -w /var/www/html/php/public \
  -d $DOMAIN -d www.$DOMAIN \
  --email $EMAIL --agree-tos --no-eff-email

echo "📦 Building combined PEM for HAProxy..."
docker compose -f $STACK_FILE exec certbot sh -c "\
  cat /etc/letsencrypt/live/$DOMAIN/fullchain.pem \
      /etc/letsencrypt/live/$DOMAIN/privkey.pem \
   > /etc/haproxy/certs/$DOMAIN.pem"

echo "🔄 Restarting HAProxy..."
docker compose -f $STACK_FILE restart haproxy

echo "✅ Certificate installed and HAProxy restarted for $DOMAIN"
