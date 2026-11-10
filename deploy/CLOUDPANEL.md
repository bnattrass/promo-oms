# Deploying Promo OMS on CloudPanel

CloudPanel already provides Nginx, PHP-FPM, MySQL, SSL and cron, so the app is installed as a normal
CloudPanel **PHP site**. Everything below runs as the **site user** over SSH (no sudo needed), except
the steps marked **[CloudPanel UI]**, which the server owner does in the CloudPanel web interface.

## 1. [CloudPanel UI] Site, PHP and database
1. **Sites → Add Site → Create a PHP Site**
   - Application: **Laravel 11+** (sets the web root to `public/`)
   - Domain: e.g. `orders.example.co.za` (DNS A record must already point at the server)
   - PHP version: **8.4**
   - Site user: the SSH user (e.g. `itguys-orders`)
2. Site → **Settings**: check *Root Directory* is `htdocs/<domain>/public`.
3. Site → **PHP Settings**: `memory_limit` 512M, `max_execution_time` 120, `upload_max_filesize` 20M, `post_max_size` 25M.
4. Site → **Databases → Add Database**: name `promo_oms`, user `promo_oms`, a strong password. Note the password.
5. Site → **SSL/TLS → Actions → New Let's Encrypt Certificate**.

## 2. [SSH as site user] Get the code
```bash
cd ~/htdocs/<domain>
# CloudPanel puts a placeholder index file here; the folder must be empty for git clone.
ls -A                       # check it only holds CloudPanel's default files, then:
rm -rf ./* ./.[!.]* 2>/dev/null || true

# Deploy key so the server can read the private GitHub repo
ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C "promo-oms@<domain>"   # skip if it already exists
cat ~/.ssh/id_ed25519.pub
# -> add it in GitHub: repo Settings -> Deploy keys -> Add deploy key (read-only)
ssh-keyscan github.com >> ~/.ssh/known_hosts

git clone git@github.com:bnattrass/promo-oms.git .
```

## 3. [SSH] Configure and install
```bash
cp deploy/env.cloudpanel.example .env
nano .env
```
The template already uses the database for cache, sessions and queue (no Redis or Supervisor needed on CloudPanel).
Set at least:
```
APP_URL=https://<domain>
DB_DATABASE=promo_oms
DB_USERNAME=promo_oms
DB_PASSWORD=<the password from step 1.4>
SEED_COMPANY_NAME="Maverick Brands"
SEED_ADMIN_NAME="Brett"
SEED_ADMIN_EMAIL=brett@maverickbrands.co.za
SEED_ADMIN_PASSWORD='<temporary password, change after first login>'
```
Then:
```bash
php8.4 $(which composer) install --no-dev --optimize-autoloader --no-interaction
php8.4 artisan key:generate --force
php8.4 artisan migrate --force --seed
php8.4 artisan storage:link
php8.4 artisan livewire:publish --assets   # CloudPanel serves .js from disk: publish Livewire's script
php8.4 artisan filament:assets
php8.4 artisan optimize
php8.4 artisan filament:optimize || true
chmod -R ug+rwX storage bootstrap/cache
```
Open `https://<domain>` -> redirects to `/admin`; log in with the SEED_ADMIN details.

## 4. Cron (scheduler + queue)
**[CloudPanel UI]** Site → **Cron Jobs → Add Cron Job**, or `crontab -e` as the site user:
```
* * * * * cd /home/<site-user>/htdocs/<domain> && php8.4 artisan schedule:run >> /dev/null 2>&1
```
With `QUEUE_VIA_SCHEDULER=true` this one line runs the supplier syncs, follow-up emails **and** the queue
(the Sync now button, emails).

## 5. First Amrod import
In the app: **Purchasing → Suppliers → Amrod → Edit** → enter API username, password, customer code → Save →
**Test connection** → tick **Sync automatically on schedule**. Then over SSH:
```bash
php8.4 -d memory_limit=1024M artisan suppliers:sync AMROD --full
```

## 6. Email
Fill the `MAIL_` lines in `.env` (Microsoft 365: `smtp.office365.com`, port 587, mailbox with Authenticated SMTP on), then
`php8.4 artisan optimize`.

## Updates
```bash
cd ~/htdocs/<domain> && bash deploy/deploy-cloudpanel.sh
```

## Backups
CloudPanel → **Backups** (enable remote backups) covers files and databases. Make sure `.env` is included: its APP_KEY
decrypts the stored supplier passwords.

## Troubleshooting
- **Sign in button does nothing / page never reacts:** Livewire's JavaScript isn't loading
  (`curl -s -o /dev/null -w "%{http_code}" https://<domain>/livewire/livewire.min.js` returns 404).
  Run `php8.4 artisan livewire:publish --assets && php8.4 artisan filament:assets && php8.4 artisan optimize`.
- **Access denied for user** on `db:show`: DB_USERNAME must be the *database* user from CloudPanel → Databases,
  not the site/SSH user.

## Checks
| What | Command |
|---|---|
| App log | `tail -n 100 storage/logs/laravel*.log` |
| Scheduler is running | `php8.4 artisan schedule:list` |
| Queue backlog | `php8.4 artisan queue:monitor database:default,database:sync` |
| Failed jobs | `php8.4 artisan queue:failed` |
