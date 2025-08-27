#!/bin/bash
set -e

echo "🔄 Pulling latest images..."
docker compose pull -f deploy-stack.yml

echo "🚀 Rebuilding and starting containers..."
docker compose up -f deploy-stack.yml -d --build

echo "🧹 Cleaning up unused images..."
docker image prune -f

echo "✅ Stack is up and running!"
