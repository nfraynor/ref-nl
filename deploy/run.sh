#!/bin/bash
set -e

echo "🔄 Pulling latest images..."
docker compose -f deploy-stack.yml pull

echo "🚀 Rebuilding and starting containers..."
docker compose -f deploy-stack.yml up -d --build

echo "🧹 Cleaning up unused images..."
docker image prune -f

echo "✅ Stack is up and running!"
