#!/usr/bin/env bash
# Promo OMS - one-time server setup for a blank Ubuntu 24.04 LTS VPS.
#
# Run as root (or with sudo):
#   sudo DOMAIN=orders.example.co.za EMAIL=you@example.co.za bash setup-server.sh
#
# Installs Nginx, PHP 8.4, MySQL 8, Redis, Supervisor, Composer and Certbot, clones the app,
# creates the database, configures the queue worker and scheduler, and issues an SSL certificate.
set -euo pipefail

DOMAIN="${DOMAIN:?Set DOMAIN, e.g. DOMAIN=orders.example.co.za}"
EMAIL="${EMAIL:?Set EMAIL for the SSL certificate and the first admin login}"
REPO="${REPO:-git@github.com:bnattrass/promo-oms.git}"
APP_DIR="${APP_DIR:-/var/www/promo-oms}"
PHP_VERSION="8.4"
DB_NAME="promo_oms"
DB_USER="promo_oms"
DB_PASS="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 28)"
ADMIN_PASS="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 16)"

echo "==> Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common ca-certificates curl git unzip ufw
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y nginx mysql-server redis-server supervisor certbot python3-certbot-nginx \
  php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-bcmath php${PHP_VERSION}-curl \
  php${PHP_VERSION}-gd php${PHP_VERSION}-intl php${PHP_VERSION}-mbstring php${PHP_VERSION}-mysql \
  php${PHP_VERSION}-xml php${PHP_VERSION}-zip php${PHP_VERSION}-redis php${PHP_VERSION}-sqlite3 php${PHP_VERSION}-opcache

if ! command -v composer >/dev/null; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "==> PHP settings"
for sapi in fpm cli; do
  ini="/etc/php/${PHP_VERSION}/${sapi}/conf.d/99-promo-oms.ini"
  mem=$([ "$sapi" = cli ] && echo 1024M || echo 512M)
  cat > "$ini" <<INI
memory_limit = ${mem}
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 120
date.timezone = Africa/Johannesburg
INI
done
systemctl restart php${PHP_VERSION}-fpm

echo "==> Firewall"
ufw allow OpenSSH >/dev/null; ufw allow 'Nginx Full' >/dev/null; ufw --force enable >/dev/null

echo "==> Database"
# Re-running the script keeps the existing database password from .env.
if [ -f "${APP_DIR}/.env" ] && grep -q '^DB_PASSWORD=.\+' "${APP_DIR}/.env"; then
  DB_PASS="$(grep '^DB_PASSWORD=' "${APP_DIR}/.env" | cut -d= -f2-)"
  ADMIN_PASS="(unchanged - existing install)"
fi
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}'; GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

echo "==> GitHub deploy key"
mkdir -p /var/www && chown www-data:www-data /var/www
WWW_HOME="/var/www"
if [ ! -f "${WWW_HOME}/.ssh/id_ed25519" ]; then
  sudo -u www-data mkdir -p "${WWW_HOME}/.ssh"
  sudo -u www-data ssh-keygen -t ed25519 -N "" -f "${WWW_HOME}/.ssh/id_ed25519" -C "promo-oms@${DOMAIN}" >/dev/null
  sudo -u www-data bash -c "ssh-keyscan github.com >> ${WWW_HOME}/.ssh/known_hosts 2>/dev/null"
fi
if [[ "$REPO" == git@* ]] && ! sudo -u www-data git ls-remote "$REPO" >/dev/null 2>&1; then
  echo
  echo "Add this key to GitHub: repo -> Settings -> Deploy keys -> Add deploy key (read-only is fine):"
  echo
  cat "${WWW_HOME}/.ssh/id_ed25519.pub"
  echo
  read -rp "Press Enter once the deploy key has been added... "
fi

echo "==> Application"
if [ ! -d "${APP_DIR}/.git" ]; then
  sudo -u www-data git clone "$REPO" "$APP_DIR"
fi
cd "$APP_DIR"
if [ ! -f .env ]; then
  sudo -u www-data cp deploy/env.production.example .env
  sed -i "s#^APP_URL=.*#APP_URL=https://${DOMAIN}#" .env
  sed -i "s#^DB_PASSWORD=.*#DB_PASSWORD=${DB_PASS}#" .env
  sed -i "s#^SEED_ADMIN_EMAIL=.*#SEED_ADMIN_EMAIL=${EMAIL}#" .env
  sed -i "s#^SEED_ADMIN_PASSWORD=.*#SEED_ADMIN_PASSWORD=${ADMIN_PASS}#" .env
  FIRST_INSTALL=1
fi
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
if [ "${FIRST_INSTALL:-0}" = 1 ]; then
  sudo -u www-data php artisan key:generate --force
fi
sudo -u www-data php artisan migrate --force --seed
sudo -u www-data php artisan storage:link || true
sudo -u www-data php artisan optimize
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwX storage bootstrap/cache

echo "==> Nginx"
sed -e "s#__DOMAIN__#${DOMAIN}#g" -e "s#__APP_DIR__#${APP_DIR}#g" -e "s#__PHP__#${PHP_VERSION}#g" \
  deploy/nginx.conf > /etc/nginx/sites-available/promo-oms
ln -sf /etc/nginx/sites-available/promo-oms /etc/nginx/sites-enabled/promo-oms
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> Queue worker and scheduler"
sed -e "s#__APP_DIR__#${APP_DIR}#g" deploy/supervisor.conf > /etc/supervisor/conf.d/promo-oms.conf
supervisorctl reread && supervisorctl update
echo "* * * * * www-data cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/promo-oms
chmod 644 /etc/cron.d/promo-oms

echo "==> SSL certificate"
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect || \
  echo "!! Certbot failed - check the DNS A record for ${DOMAIN} points to this server, then run: certbot --nginx -d ${DOMAIN}"

cat <<DONE

============================================================
 Promo OMS is installed:  https://${DOMAIN}

 First login:   ${EMAIL}
 Password:      ${ADMIN_PASS}     (change it after logging in)

 Database:      ${DB_NAME} / user ${DB_USER}
 DB password:   ${DB_PASS}        (also saved in ${APP_DIR}/.env)

 Next: set the MAIL_ settings in ${APP_DIR}/.env, then run
       sudo -u www-data php artisan optimize
 Updates:  sudo bash ${APP_DIR}/deploy/deploy.sh
============================================================
DONE
