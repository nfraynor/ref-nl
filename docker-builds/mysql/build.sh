#!/bin/bash
set -e

IMAGE="nfraynor/ref-app-db"

echo "🛠️ Building $IMAGE ..."
docker build -t $IMAGE .

echo "🚀 Pushing $IMAGE ..."
docker push $IMAGE

echo "✅ Done."
