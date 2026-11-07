# Promo OMS - instructions for Claude

Promotional products order management system: Amrod (and later other) supplier catalogue sync, branded quoting,
sales orders, purchase orders, reports. Laravel 13 + Filament 5 (admin panel at /admin), PHP 8.4.
Do not install Laravel Boost or change the stack; these instructions replace the default Laravel bootstrap notes.

## Where things live
- Supplier API connectors: `app/Suppliers/` (Amrod: `app/Suppliers/Amrod/`; live field names are mapped in `AmrodMapper.php`)
- Pricing (branding lookup, GP, VAT rounding): `app/Services/Pricing/`
- Quote -> order -> PO flow, PDFs, email: `app/Services/Orders/`
- Admin screens: `app/Filament/`
- Schedule: `routes/console.php`; sync runner: `app/Services/SupplierSyncService.php`

## Servers
- **Production runs on CloudPanel.** Follow `deploy/CLOUDPANEL.md` exactly. Do NOT run `deploy/setup-server.sh` there
  (that script is for blank servers and would install a second web server / MySQL).
- The SSH user is a CloudPanel *site user* without sudo. Never edit system files, Nginx vhosts, PHP-FPM pools or other sites;
  use the CloudPanel web UI (or ask the owner to) for domains, SSL, databases, PHP version and vhost.
- Use the `php8.4` binary explicitly if `php` points at another version.

## Safety rules
- Never run `migrate:fresh`, `migrate:reset`, `db:wipe` or delete `.env` / the database on a server with data.
- Never commit `.env` or secrets. Supplier credentials are stored encrypted in the database (APP_KEY in .env decrypts them).
- Deploy updates with `bash deploy/deploy-cloudpanel.sh`.
- Amrod's API is offline 00:00-01:00 SAST (returns 204); syncs in that hour are skipped by design.
