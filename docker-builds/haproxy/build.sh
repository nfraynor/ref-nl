#!/bin/bash
set -e

IMAGE="nfraynor/ref-app-haproxy"

echo "🛠️ Building $IMAGE ..."
docker build -t $IMAGE .

echo "🚀 Pushing $IMAGE ..."
docker push $IMAGE

echo "✅ Done."
