#!/usr/bin/env bash
# Promo OMS - deploy latest code on CloudPanel (run as the site user, from the site folder).
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP:-php8.4}"
command -v "$PHP" >/dev/null || PHP=php
COMPOSER="$(command -v composer)"

$PHP artisan down --retry=30 || true
trap '$PHP artisan up' EXIT
git pull --ff-only
$PHP "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
$PHP artisan migrate --force
$PHP artisan optimize
$PHP artisan filament:optimize || true
$PHP artisan queue:restart
echo "Deployed $(git log -1 --format='%h %s')"
