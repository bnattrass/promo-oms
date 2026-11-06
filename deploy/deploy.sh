#!/usr/bin/env bash
# Promo OMS - deploy the latest code from GitHub. Run on the server:
#   sudo bash /var/www/promo-oms/deploy/deploy.sh
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/promo-oms}"
cd "$APP_DIR"

sudo -u www-data php artisan down --retry=30 || true
sudo -u www-data git pull --ff-only
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
sudo -u www-data php artisan filament:optimize || true
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan up
echo "Deployed $(git log -1 --format='%h %s')"
