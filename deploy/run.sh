#!/bin/bash
set -e

echo "🔄 Pulling latest images..."
docker compose pull

echo "🚀 Rebuilding and starting containers..."
docker compose up -d --build

echo "🧹 Cleaning up unused images..."
docker image prune -f

echo "✅ Stack is up and running!"
