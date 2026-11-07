# Server setup (blank Ubuntu VPS)

> **Using CloudPanel?** Use [CLOUDPANEL.md](CLOUDPANEL.md) instead. This guide and setup-server.sh are for a blank server only.

Everything needed to run Promo OMS on a fresh **Ubuntu 24.04 LTS** server.

## 1. The server
- 2 vCPU, 4 GB RAM, 60 GB SSD, in South Africa
- Static public IP; inbound ports 22, 80, 443 open
- Outbound HTTPS (Amrod API, GitHub, Let's Encrypt) and SMTP port 587 allowed
- A DNS **A record** for the chosen domain (e.g. `orders.maverickbrands.co.za`) pointing at the IP, **before** step 2 (needed for the SSL certificate)

## 2. One-time setup (about 10 minutes)
SSH in, then:

```bash
curl -fsSL https://raw.githubusercontent.com/bnattrass/promo-oms/main/deploy/setup-server.sh -o setup-server.sh
sudo DOMAIN=orders.maverickbrands.co.za EMAIL=brett@maverickbrands.co.za bash setup-server.sh
```

If the GitHub repo is **private**, the curl line won't work. Copy `setup-server.sh` onto the server another way (e.g. `scp`), and run the same `sudo DOMAIN=... bash setup-server.sh` command.

Partway through, the script prints a **deploy key**. Add it in GitHub: repo → *Settings → Deploy keys → Add deploy key* (read-only is fine), then press Enter.

At the end it prints the site URL, the **first login password** and the database password. Send the login to Brett.

What it installs: Nginx, PHP 8.4 (+ bcmath, curl, gd, intl, mbstring, mysql, xml, zip, redis), MySQL 8, Redis, Supervisor (queue worker), a cron entry for the scheduler, Composer, and a Let's Encrypt certificate with HTTP → HTTPS redirect. Firewall (ufw) allows SSH, HTTP and HTTPS only.

## 3. Email
Edit `/var/www/promo-oms/.env` and fill in the `MAIL_` lines (Microsoft 365 SMTP; the mailbox needs *Authenticated SMTP* enabled), then:
```bash
sudo -u www-data php artisan optimize
```

## 4. Updates
Whenever new code is pushed to GitHub:
```bash
sudo bash /var/www/promo-oms/deploy/deploy.sh
```

## Useful commands
| What | Command (in /var/www/promo-oms) |
|---|---|
| App log | `tail -n 100 storage/logs/laravel-$(date +%F).log` |
| Queue worker log | `tail -n 100 storage/logs/worker.log` |
| Worker status / restart | `sudo supervisorctl status` / `sudo supervisorctl restart all` |
| Run the Amrod import by hand | `sudo -u www-data php artisan suppliers:sync AMROD --full` |
| Add a user | `sudo -u www-data php artisan app:user` |

## Backups
Back up nightly at minimum: the MySQL database `promo_oms` (e.g. `mysqldump`, or the host's snapshot feature), `/var/www/promo-oms/.env`, and `/var/www/promo-oms/storage/app`. The `.env` holds the APP_KEY, which is required to decrypt stored supplier passwords: lose it and those must be re-entered.
