# Deploying OPES360 to Namecheap Stellar Plus (cPanel)

Written for shared hosting specifically. The generic guide in
[`DEPLOYMENT.md`](DEPLOYMENT.md) assumes a server you control; this one assumes
cPanel, no root, and no long-running processes.

**Verified:** the release archive this produces was extracted, migrated, seeded
and served against MariaDB 10.11 in production mode — every page 200, CSP
enforced, no console errors. The full 203-test suite passes on MariaDB as well as
MySQL, so the database difference costs nothing.

**Not verified:** anything specific to Namecheap's account (their PHP build,
their limits, their mail server). Those are checked by `opes:doctor` on the
server, not here.

---

## Before you start

| Requirement | Where | Notes |
|---|---|---|
| SSH access | cPanel → **SSH Access** | **Required.** Migrations, key generation and cache building all run through `php artisan`. Without a shell there is no safe way to do them. |
| PHP 8.2, 8.3 or 8.4 | cPanel → **MultiPHP Manager** | Laravel 12 needs 8.2 minimum. |
| PHP extensions | cPanel → **Select PHP Extensions** | `pdo_mysql`, `mbstring`, `gd`, `bcmath`, `zip`, `fileinfo`, `openssl`, `intl` |
| A MySQL database | cPanel → **MySQL Databases** | cPanel prefixes names: asking for `opes360` gives you `youruser_opes360`. Use the full prefixed name in `.env`. |
| An email account | cPanel → **Email Accounts** | e.g. `no-reply@yourdomain.com`. Its SMTP details go in `.env`. |

### One honest caveat about shared hosting

This application stores encrypted tax identifiers and financial records, and on
shared hosting the machine is shared with other tenants. That is a real
difference from a dedicated server, and worth knowing rather than discovering.
It is a reasonable place to run a pilot; it is worth revisiting before the
business depends on it.

---

## 1. Build the release (on your machine, not the server)

Shared hosting has no Node and unreliable Composer, so both run locally and ship
inside the archive:

```bash
./scripts/build-release.sh
```

Produces `build/opes360-YYYYMMDD-HHMM.zip`, about 27 MB. It contains the vendor
directory and the compiled front-end, and deliberately excludes `.env`, tests
and `node_modules`. The script fails rather than producing a broken archive if
the assets did not build or a `.env` crept in.

---

## 2. Upload and extract

1. cPanel → **File Manager** → your home directory (*not* `public_html`).
2. Upload the zip, then **Extract**. You get `~/opes360-YYYYMMDD-HHMM/`.
3. Rename it to `~/opes360`.

The application lives **outside** `public_html` on purpose. `.env` holds the key
that decrypts every stored tax ID; if the app root were web-served, a single
misconfigured rule would expose it.

---

## 3. Point the document root at `public/`

Over SSH:

```bash
cd ~
mv public_html public_html.bak      # keep whatever was there
ln -s ~/opes360/public public_html
```

If symlinks are refused, use the fallback instead — copy the contents of
`~/opes360/public` into `public_html` and edit its `index.php`, changing the two
`__DIR__.'/../'` paths to `__DIR__.'/../opes360/'`. The symlink is preferable:
with the copy, every deploy has to repeat it.

---

## 4. Configure

```bash
cd ~/opes360
cp .env.example .env
php artisan key:generate
nano .env
```

```dotenv
APP_NAME=OPES360
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=youruser_opes360
DB_USERNAME=youruser_opes
DB_PASSWORD=the-password-you-set

# cPanel mail. Port 465 with SSL is the most reliable on Namecheap; 587 with TLS
# also works. The from-address must be a real mailbox on this domain, or the
# server will refuse to relay it.
MAIL_MAILER=smtp
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=no-reply@yourdomain.com
MAIL_PASSWORD=the-mailbox-password
MAIL_FROM_ADDRESS=no-reply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

**`APP_KEY` is generated once and never changed.** Encrypted tax IDs and 2FA
secrets are unreadable without the key that wrote them; rotating it destroys them
silently. Copy it somewhere safe — off this server.

`APP_URL` must be the `https://` address. Every QR code and the service worker
are built from it, so an `http://` value prints codes that resolve to nothing.

---

## 5. Set it up

```bash
cd ~/opes360

php artisan migrate --force
php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan view:cache

chmod -R 775 storage bootstrap/cache
```

Then confirm before letting anyone in:

```bash
php artisan opes:doctor --mail=you@youremail.com
```

It exits non-zero on anything blocking and sends a real test message. Do not skip
this — it catches mail still pointed at the log file, debug mode left on, a
missing storage link, and a queue with no worker, all of which look fine in a
browser.

---

## 6. Cron jobs — the part shared hosting gets wrong

There are no daemons here, so both background jobs run from cron.
cPanel → **Cron Jobs**. Replace `youruser` and check `which php` for the right
binary — cPanel often has several and the default is not always the one
MultiPHP selected.

**Scheduler** — every minute:

```
* * * * * cd /home/youruser/opes360 && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Queue** — every minute:

```
* * * * * cd /home/youruser/opes360 && /usr/local/bin/php artisan queue:work --stop-when-empty --tries=3 --max-time=50 >> /dev/null 2>&1
```

`--stop-when-empty` is what makes this work without a daemon: the process drains
the queue and exits rather than sitting resident, which shared hosting would kill
anyway. `--max-time=50` keeps it inside the minute so two runs never overlap.

The cost is latency: a password reset email arrives within a minute rather than
instantly. That is acceptable. **No queue worker at all is not** — the mail is
queued, so without cron nobody can ever reset a password and nothing anywhere
reports an error.

> If Namecheap limits cron to every 5 minutes on your plan, use `*/5` for both.
> The scheduler's own jobs are hourly and daily, so nothing is missed; only the
> mail delay grows.

---

## 7. Check it works

- Visit `https://yourdomain.com` — the login page loads over HTTPS.
- Register a business, or sign in if you seeded the demo.
- Issue an invoice, print it, scan the QR — the verification page says **Verified**.
- Request a password reset and confirm the email **arrives at an external
  address**, not just your own domain.
- Install the PWA on a real phone. Then turn the phone's data off, issue an
  invoice, turn it back on, and confirm it syncs carrying the number it printed.

That last one is the whole product working end to end.

---

## 8. Deploying an update

```bash
# On your machine
./scripts/build-release.sh

# Upload and extract to ~/opes360-NEW, then on the server:
cd ~
cp opes360/.env opes360-NEW/.env
cp -R opes360/storage/app/public/. opes360-NEW/storage/app/public/

mv opes360 opes360-OLD && mv opes360-NEW opes360

cd opes360
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
chmod -R 775 storage bootstrap/cache
php artisan opes:doctor
```

Keep `opes360-OLD` until the new release has been used for a day. Rolling back is
then a `mv` — the fastest recovery you will have on this kind of hosting.

**Uploaded files and the database are not in the archive**, which is the point:
a bad deploy cannot destroy them. Back both up before migrating anyway.

---

## What shared hosting costs you

Worth knowing up front rather than discovering:

- **Mail is delayed by up to a minute** (or five), because the queue runs from
  cron rather than a resident worker.
- **No error tracking.** A 500 appears in `storage/logs/laravel.log` and nowhere
  else. Check it after deploying.
- **No zero-downtime deploy.** The `mv` in §8 is a brief interruption. At pilot
  scale that is fine.
- **Uploads live on local disk.** Moving to S3 later means setting
  `FILESYSTEM_DISK=s3` and migrating the existing files.
- **Resource limits are per-account.** Namecheap counts entry processes and CPU;
  a Laravel app serving a pilot sits well inside them, but a marketing spike can
  trip the limit and return 508s. Watch cPanel → **Resource Usage** after launch.
