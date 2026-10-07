#!/usr/bin/env bash
set -euo pipefail
cd /opt/vision-law
git pull --ff-only
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci --silent
npm run build
php artisan migrate --force
php artisan optimize
systemctl restart visionlaw-web
