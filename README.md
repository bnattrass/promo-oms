# Promo OMS

Order management for a promotional products business: live supplier catalogues (Amrod first), branded quoting with GP% and commission, sales orders, automatic purchase orders, receiving, PDFs and email follow-ups.

Built with Laravel 13 and Filament 5. Multi-company and multi-supplier from day one.

## Run it on your Mac (Laravel Herd)

You need Laravel Herd running (it includes PHP and Composer). Open **Terminal** (Cmd + Space, type Terminal) and paste these one at a time.

```bash
# 1. Go to the project (after unzipping promo-oms into your Herd folder)
cd ~/Herd/promo-oms

# 2. Download Laravel, Filament and the other packages (takes a minute or two)
composer install

# 3. Create your settings file
cp .env.example .env
open -e .env
```

In the `.env` file that opens, set your login details and save:

```
SEED_COMPANY_NAME="Cape IT Guyz"
SEED_ADMIN_NAME="Brett"
SEED_ADMIN_EMAIL=you@yourdomain.co.za
SEED_ADMIN_PASSWORD=pick-a-strong-password
```

Then back in Terminal:

```bash
# 4. Generate the app key, create the database and the first company, admin, Amrod supplier and email templates
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
```

Open **http://promo-oms.test** and log in with the email and password from step 3.

## Connect Amrod

1. Go to **Purchasing → Suppliers → Amrod → Edit**.
2. Enter the Amrod **API username (email)**, **password** and **customer code**, then **Save**.
3. Click **Test connection**. You should see "Login successful".
4. Run the first full import in Terminal (it can take 5–15 minutes; Amrod is offline 00:00–01:00):

   ```bash
   php artisan suppliers:sync AMROD --full
   ```

5. Tick **Sync automatically on schedule** on the supplier once the import looks right.

If products import with blank names, colours or branding, Amrod's field names differ from what the importer expects (their docs publish no sample). Run this and send the output so the mapping in `app/Suppliers/Amrod/AmrodMapper.php` can be adjusted:

```bash
php artisan suppliers:inspect AMROD products
php artisan suppliers:inspect AMROD stock
php artisan suppliers:inspect AMROD inclusive_brandings
```

## Background work (queue and schedule)

The **Sync now** button, scheduled syncs and follow-up emails run in the background. Locally, keep these running in two extra Terminal tabs (Cmd + T):

```bash
php artisan queue:work --queue=sync,default --timeout=3600
php artisan schedule:work
```

Locally, emails are written to `storage/logs/laravel.log` instead of being sent (`MAIL_MAILER=log`). To send for real, set the Microsoft 365 SMTP details in `.env`:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USERNAME=orders@yourdomain.co.za
MAIL_PASSWORD=app-password
MAIL_FROM_ADDRESS=orders@yourdomain.co.za
```

## Daily use

1. **Customers**: add the customer, contacts, payment terms and deposit %.
2. **Quotes → New quote**: pick the customer and save, then **Add line**: search the Amrod item, add branding positions and methods, set qty and GP%. Branding cost, setups, GP, commission and VAT calculate live.
3. **Email quote**: sends the PDF; an automatic follow-up goes out after the configured working days (max 2).
4. **Convert to sales order**: copies everything; if the customer pays a deposit the order waits in *Awaiting deposit* with reminders.
5. **Record deposit** → **Create purchase orders** (one per supplier) → **Email to supplier**.
6. **Supplier confirmed** → **Receive goods** (partial deliveries become back orders) → order moves to *Ready*.

**Custom lines:** on a quote, *Add custom line* for courier, design/artwork, setup or labour. Enter your cost and a GP %, or a fixed sell price (e.g. R650/hour design).
**GP per line:** type a new GP %, quantity or unit sell price straight into the lines table and press Enter; the line and quote totals re-price.
**Team:** Settings → *Team & sales people* (admins only): add sales people with a role, commission % and monthly target.
**Reports:** Sales → *Sales reports*: sales, GP, commission and win rate by sales person, month, customer and products vs services, with CSV export. Sales people only see their own figures.

Add staff from the Team screen, or with `php artisan app:user`. Company details, logo, VAT, prefixes and default GP% are under the company menu (top left) → **Company settings**.

## Adding another supplier API

1. Create `app/Suppliers/<Name>/<Name>Connector.php` implementing `App\Suppliers\SupplierConnector` (see the Amrod connector).
2. Register it in `App\Suppliers\SupplierRegistry::DRIVERS`.
3. It then appears in the **API connector** dropdown on the supplier form, with its own login fields.

## Going live (Azure + Laravel Forge)

- Create an Ubuntu VM (B2s) in Azure **South Africa North** and connect it to Forge.
- Create the site from the private GitHub repo; use MySQL (`DB_CONNECTION=mysql`) and Redis (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`).
- In Forge enable the **scheduler** and a **Horizon** daemon (`php artisan horizon`); set `memory_limit` to at least 1024M for the CLI.
- Set `APP_ENV=production`, `APP_DEBUG=false`, real mail settings, then `php artisan migrate --force --seed`.

## Where things live

| Area | Code |
| --- | --- |
| Amrod API client, sync, field mapping | `app/Suppliers/Amrod/` |
| Sync runner, schedule | `app/Services/SupplierSyncService.php`, `routes/console.php` |
| Pricing (branding lookup, GP, VAT) | `app/Services/Pricing/` |
| Quote → order → PO flow, PDFs, email | `app/Services/Orders/` |
| Screens | `app/Filament/` |
| PDF layouts | `resources/views/pdf/` |
| Database | `database/migrations/` |
