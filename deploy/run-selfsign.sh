#!/bin/bash
set -e
# find the named volume that compose created for haproxy certs
V=$(docker volume ls -q | grep -E 'haproxy_certs$' | head -n1)

# generate a 1-day self-signed cert inside that volume
docker run --rm -v "$V":/certs alpine:3 sh -lc '
  apk add --no-cache openssl >/dev/null &&
  openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
    -subj "/CN=rugbyref.eu" \
    -keyout /certs/rugbyref.eu.key \
    -out    /certs/rugbyref.eu.crt &&
  cat /certs/rugbyref.eu.crt /certs/rugbyref.eu.key > /certs/rugbyref.eu.pem
'