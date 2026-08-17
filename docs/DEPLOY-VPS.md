# Deploying OPES360 on a VPS

A runbook for a single Linux VPS (Ubuntu/Debian assumed). For docker-compose
and shared-hosting paths see `docs/DEPLOYMENT.md` and `docs/DEPLOY-NAMECHEAP.md`.

## Requirements

- PHP **8.2+** (composer.json pins `^8.2`; platform config 8.2.0) with the
  usual Laravel extensions: `mbstring`, `xml`, `curl`, `zip`, `gd`, `intl`,
  `pdo_mysql`.
- MySQL 8, nginx + php-fpm, Composer, Node (only if building assets on the box).
- A system user that owns the release directory; never run artisan as root.

## 1. First install

```bash
cd /var/www/opes360
composer install --no-dev --optimize-autoloader
npm ci && npm run build            # or upload prebuilt public/build
cp .env.example .env && php artisan key:generate
```

Edit `.env` — the settings that matter on a real VPS:

```dotenv
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=database          # anything but "sync"; a worker must run
OPES_DEMO_LOGINS=false             # demo logins DEFAULT ON in config; turn off
CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl   # see step 4
```

`OPES_DEMO_LOGINS` defaults to **true** in `config/opes.php` — production must
set it to `false` the moment real businesses hold data.

```bash
php artisan migrate --force
php artisan storage:link
php artisan opes:seed-workflows
php artisan config:cache
php artisan route:cache
```

## 2. Scheduler (cron)

One crontab line for the deploy user; everything scheduled runs through it:

```cron
* * * * * cd /var/www/opes360 && php artisan schedule:run >> /dev/null 2>&1
```

## 3. Queue worker (supervisor)

`QUEUE_CONNECTION` must not be `sync` — uploads are scanned, search is indexed
and notifications are sent on the queue. `/etc/supervisor/conf.d/opes360.conf`:

```ini
[program:opes360-worker]
command=php /var/www/opes360/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start opes360-worker
```

## 4. Virus scanning (ClamAV)

`app/Support/UploadGate.php` reads `CLAMAV_SOCKET`. **If it is unset, uploads
are accepted without scanning and nothing warns you.**

```bash
apt install clamav-daemon
systemctl enable --now clamav-daemon
```

Then in `.env`, one of:

```dotenv
CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl
# or
CLAMAV_SOCKET=tcp://127.0.0.1:3310
```

## 5. Every deploy

```bash
cd /var/www/opes360
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan opes:seed-workflows      # idempotent; seeds any new default workflows
php artisan opes:search-reindex      # rebuild the search index
php artisan queue:restart            # workers pick up the new code
php artisan up
```

## 6. Backups

Nightly MySQL dump, kept off the box:

```cron
0 2 * * * mysqldump --single-transaction --routines opes360 | gzip > /backups/opes360-$(date +\%F).sql.gz
```

Back up `storage/app` too — uploaded documents live there, not in the database.

## Post-deploy checks

- Login page shows **no demo sign-in buttons**.
- Upload a file: it should reach "clean" status (queue + clamd both working).
- `php artisan schedule:run` in the crontab log shows tasks firing each minute.
