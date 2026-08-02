# Deploying OPES360 to Namecheap shared hosting (cPanel)

**No SSH. No command line on the server. Everything through cPanel and a browser.**

Written for a cPanel account where you have File Manager, MySQL Databases and
Cron Jobs, and nothing else. If you *do* have SSH and prefer it, the artisan
commands are in [`DEPLOYMENT.md`](DEPLOYMENT.md) — but none of them are required.

**Verified:** the whole of this guide was rehearsed against a real MariaDB 10.11
in the split `public_html` layout described below — installed through the browser
installer, every page 200, assets and fonts served, administrator signed in.
The 521-test suite passes on MariaDB as well as MySQL, and all 39 migrations
apply cleanly to an empty schema.

---

## Before you start

| Requirement | Where in cPanel | Notes |
|---|---|---|
| PHP 8.2, 8.3 or 8.4 | **MultiPHP Manager** | Laravel 12 needs 8.2 minimum. |
| PHP extensions | **Select PHP Extensions** | `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `ctype`, `json`. The installer checks these and names anything missing. |
| A MySQL database | **MySQL Databases** | Create the database, create a user, then **add the user to the database with All Privileges**. cPanel prefixes both names. |
| An email account | **Email Accounts** | e.g. `no-reply@yourdomain.com`. Its SMTP details go in `.env` at the end. |
| An SSL certificate | **SSL/TLS Status** | Namecheap issues a free one. The app must be served over `https://`. |

### One honest caveat about shared hosting

This application stores encrypted tax identifiers and financial records, and on
shared hosting the machine is shared with other tenants. That is a real
difference from a dedicated server, and worth knowing rather than discovering.
It is a reasonable place to run a pilot; it is worth revisiting before the
business depends on it.

---

## 1. Build the release, on your own PC

Shared hosting has no Node and unreliable Composer, so both run on your machine
and ship inside the archive. From the project folder:

**Windows** (PowerShell, or Laragon's Terminal — it has php, composer and npm on
PATH):

```powershell
.\scripts\windows\build-release.ps1
```

**macOS or Linux:**

```bash
./scripts/build-release.sh
```

Either produces `build/opes360-YYYYMMDD-HHMM.zip`, about 28 MB. It contains the
vendor directory and the compiled front-end, and refuses to package a `.env`.
Both scripts put your development dependencies back when they finish.

---

## 2. Upload and extract

1. cPanel → **File Manager** → your **home** directory (the one that *contains*
   `public_html`, not `public_html` itself).
2. **Upload** the zip, then select it and choose **Extract**.

That is the whole step. The archive carries the two halves already separated,
so extracting here puts each where it belongs:

```
/home/youruser/
├── opes360/        the application — never web-served; holds .env
└── public_html/    only what the web may reach
```

`public_html` already exists, and the extract merges into it rather than
replacing it.

**Why the split matters.** `.env` holds the key that decrypts every stored tax
ID, along with your database and mailbox passwords. Keeping the application a
level above the web root means those files cannot be requested at all, rather
than relying on a rule to hide them.

Nothing needs moving and nothing needs editing: `index.php` works out where the
application lives and tells the framework where the public folder ended up.

> If `public_html` already holds your host's default `index.html`, it is
> harmless — the shipped `.htaccess` names `index.php` first. Delete it anyway
> if you like a tidy folder.

---

## 3. Optional: import the database first

`install.php` creates the tables itself, so this step is optional. It is worth
doing when the schema step is slow or times out — building 39 tables inside a
web request can exceed the 30-second limit some shared hosts enforce, and an
import through phpMyAdmin has no such limit.

1. cPanel → **phpMyAdmin** → select your **empty** database in the left column.
2. **Import** tab → **Choose File** → `opes360-install.sql` → **Go**.

That file sits **next to the zip** in `build/`, not inside it — you select it
from your own machine, so it would be no use buried in an archive you have
already uploaded. The build script puts it there.

It contains the schema, already recorded as migrated, plus the roles and
permissions the application cannot work without. It contains no businesses, no
users and no administrator — you still create that in `install.php`, which then
finds the tables already in place and simply moves on.

It is generated from the migrations by `php artisan opes:export-schema` against
a real MySQL, and a test fails if a migration has been added without
regenerating it — so it cannot quietly fall behind the code the way a
hand-maintained dump does.

Only import into an empty database. Against one that already holds data, the
import will fail on the existing tables.

---

## 4. Install, in your browser

Visit:

```
https://yourdomain.com/install.php
```

Two short steps:

1. **Your database and email** — the database name, user and password you
   created in cPanel, and the mailbox the app should send from. Use the **full
   prefixed names**: asking for `opes360` gives you `youruser_opes360`. The
   installer tests the database connection before continuing, so a typo shows
   up here rather than as a blank page later. It writes `.env`, generates the
   application key, and switches the app into production mode with the demo
   logins turned off.

   Entering the mail settings here rather than editing `.env` by hand matters
   more than it looks: a password containing `#` is silently truncated at that
   character unless the value is quoted, which then reads as a wrong password
   with nothing in any log to explain it. The installer quotes correctly.
2. **Your administrator** — name, email and a password of at least 12 characters
   with letters, numbers and a symbol. This step also creates the database
   tables and the roles the app needs, so give it up to a minute.

When it finishes, the installer locks itself: visiting it again just says the
app is already installed. Delete `install.php` from `public_html` afterwards as
tidying up.

> **Why the roles matter.** Registration assigns the owner role by looking it
> up, so on a database with no roles every business that signs up would get an
> account that can do nothing. The installer seeds them. This is also why you
> must never run the demo seeder here — it would create a demo company and an
> administrator whose password is literally `password`.

Sign in at `https://yourdomain.com/admin/login` and **turn on two-factor
authentication straight away**.

---

## 5. Cron jobs — the part shared hosting gets wrong

There are no daemons here, so background work runs from cron. cPanel → **Cron
Jobs**. Replace `youruser` with your cPanel username.

Set **Common Settings** to *Once Per Minute* for both.

**Scheduler:**

```
cd /home/youruser/opes360 && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Queue:**

```
cd /home/youruser/opes360 && /usr/local/bin/php artisan queue:work --stop-when-empty --tries=3 --max-time=50 >> /dev/null 2>&1
```

`--stop-when-empty` is what makes this work without a daemon: the process drains
the queue and exits rather than sitting resident, which shared hosting would
kill anyway. `--max-time=50` keeps it inside the minute so two runs never
overlap.

**If the PHP path is wrong**, the cron mail will say "command not found". cPanel
often has several PHP binaries and the default is not always the one MultiPHP
selected — check under **MultiPHP Manager**, or try `/usr/local/bin/ea-php83`.

**No queue worker means no email.** Account mail is queued, so without this cron
nobody can ever reset a password, and nothing anywhere reports an error.

> If your plan limits cron to every 5 minutes, use that for both. The
> scheduler's own jobs are hourly and daily, so nothing is missed; only the mail
> delay grows.

---

## 6. Email

The installer already wrote these if you filled in the email fields in §4.
Publish **SPF and DKIM** for the domain as well (cPanel → **Email
Deliverability**) — mail that lands in spam is indistinguishable from mail that
was never sent.

To change the settings later, File Manager → `opes360` → right-click `.env` →
**Edit**. Keep every password **in double quotes**: unquoted, a value is
truncated at the first `#`, and the resulting login failure looks exactly like a
wrong password.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=yourdomain.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=notifications@yourdomain.com
MAIL_PASSWORD="the-mailbox-password"
MAIL_FROM_ADDRESS=notifications@yourdomain.com
MAIL_FROM_NAME="Your Business"
```

After editing `.env`, the cached config must be rebuilt or the change is
ignored. The scheduler does not do this for you — the simplest way without a
shell is a **one-off cron job**: add one, set it to every minute, let it run
once, then delete it:

```
cd /home/youruser/opes360 && /usr/local/bin/php artisan config:cache >> /dev/null 2>&1
```

The same trick runs any artisan command you ever need without SSH.

---

## 7. Check it works

- Visit `https://yourdomain.com` — the login page loads over HTTPS.
- Register a business. If the account can create an invoice, the roles seeded
  correctly.
- Issue an invoice, print it, scan the QR — the verification page says
  **Verified**.
- Open **Business → Stationery → Business Card**, print one, and scan its QR —
  it should open your public business profile.
- Request a password reset and confirm the email **arrives at an external
  address** (a Gmail account, not your own domain).
- Install the PWA on a real phone, turn its data off, issue an invoice, then
  bring it back online and confirm it syncs with the number it printed.

That last one is the whole product working end to end.

---

## Troubleshooting

**A blank white page.** Almost always the `.env` or a cache. Add the one-off
cron trick from §6 running `php artisan optimize:clear`, then reload.

**"OPES360 could not find its application files."** The app folder is not named
`opes360`, or is not in your home directory beside `public_html`.

**500 error after editing `.env`.** A value containing a space or `#` needs
quoting: `MAIL_FROM_NAME="Your Business"`.

**The site shows a directory listing or your host's default page.** `public_html`
still has its original `index.html` — delete it.

**Card QR codes point at the wrong address.** `APP_URL` in `.env` is wrong.
Fix it, then rebuild the config cache (§6).

---

## Updating later

1. Build a new release on your PC (§1).
2. Upload and extract it in your home directory.
3. Copy your existing `.env` from `opes360` into the new folder.
4. Rename the old folder to `opes360-old`, the new one to `opes360`.
5. Move the new `public` contents into `public_html`, replacing what is there.
6. Run a one-off cron (§6) for `php artisan migrate --force`, then another for
   `php artisan optimize:clear`.

**Upgrading to the release with the partner programme** adds four tables and
three columns to `companies`, so step 6 is not optional for that one — the
secretariat pages 500 without it.

Keep `opes360-old` until the new one is proven. Rolling back is renaming two
folders.
