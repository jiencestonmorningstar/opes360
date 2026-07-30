# Running OPES360 on Windows at https://opes360.test

For getting the app onto your own machine with a real `.test` domain and HTTPS —
the same arrangement it was verified under, just on Windows.

---

## The one-command way

From an **Administrator PowerShell**, in the extracted release folder:

```powershell
.\scripts\windows\install.ps1
```

It does everything: checks PHP and its extensions, adds the hosts entry,
downloads Caddy, creates the database, writes `.env`, generates the app key,
migrates, seeds the demo business, builds the caches, writes the web-server
config, starts both processes, waits for the site to answer, and opens it.

Windows will ask once to trust Caddy's certificate authority. **Say yes** — the
service worker will not register on an untrusted origin, so declining leaves the
offline half of the product silently doing nothing.

```powershell
.\scripts\windows\stop.ps1     # stop
.\scripts\windows\start.ps1    # start again after a reboot
```

Neither process installs as a service, so they stop when the machine does.

You still need **PHP 8.2+** and **MySQL or MariaDB** on the machine first. If you
have neither, install [Laragon Full](https://laragon.org/download/) — it brings
both — then run the script from Laragon's terminal.

> The script was written against the arrangement verified on Linux — Caddy with
> its own CA in front of PHP — but PowerShell could not be executed in the
> environment it was authored in. Expect to fix a small thing on the first run;
> paste any error and it can be corrected.

---

## Or do it by hand: no domain at all

A `.test` domain needs a tool that creates it. Getting the app *running* needs
none — and `localhost` is treated as a secure context by browsers, so even the
PWA and the offline features work. Do this first; add the domain later if you
still want it.

You need **PHP 8.2+** and **MySQL or MariaDB**. If you have neither, install
[Laragon Full](https://laragon.org/download/) — it brings both — then use its
terminal for the commands below.

```powershell
# Extract the release zip anywhere, then in that folder:
copy .env.example .env
php artisan key:generate
```

Edit `.env` — only these lines matter:

```dotenv
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=opes360
DB_USERNAME=root
DB_PASSWORD=your-mysql-password
MAIL_MAILER=log
```

Create the database, then:

```powershell
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Open **http://localhost:8000** and sign in with
`john@opesware.com` / `password`.

That is the whole application, with the demo dataset. Nothing below is required
unless you specifically want the `opes360.test` hostname.

---

## Want the .test domain? Pick one of these three.

Herd is the shortest.

---

## Route A — Laravel Herd (recommended)

Herd gives every parked project a `.test` domain and a trusted certificate
automatically. No hosts file, no nginx config, no certificate warnings.

1. Install **[Laravel Herd for Windows](https://herd.laravel.com/windows)**.
   The free tier is enough. It bundles PHP and nginx.
2. Install **[MySQL](https://dev.mysql.com/downloads/installer/)** or use Herd
   Pro's built-in database. MariaDB works equally well — the suite passes on both.
3. Extract the release (or clone the repo) into Herd's parked directory,
   usually `C:\Users\<you>\Herd\`, as a folder named `opes360`.
   The folder name becomes the domain, so `opes360` gives `opes360.test`.
4. In Herd, click **Secure** on the site. That issues a trusted certificate and
   switches it to `https://opes360.test`.
5. Open a terminal in the project folder and follow
   [§4 Configure and set up](#4-configure-and-set-up) below.

---

## Route B — Laragon

Laragon creates `.test` hostnames and writes the Windows hosts file for you.

1. Install **[Laragon Full](https://laragon.org/download/)** — it includes PHP,
   nginx/Apache and MySQL.
2. Extract the release into `C:\laragon\www\opes360`.
3. Laragon → right-click tray icon → **Apache/Nginx → Reload**. The site appears
   at `http://opes360.test`.
4. For HTTPS: right-click tray icon → **Apache/Nginx → SSL → Create certificate
   for \*.test**, then reload. Laragon adds the CA to Windows' trust store.
5. Follow [§4](#4-configure-and-set-up).

> Laragon's default PHP may be older than 8.2. Check with `php -v` and switch via
> **Menu → PHP → Version** if needed — Laravel 12 will not boot on 8.1.

---

## Route C — WSL2 (mirrors the verified Linux setup exactly)

Choose this if you want the same nginx + php-fpm + MariaDB arrangement the
production deployment uses, rather than a Windows dev tool.

```bash
wsl --install -d Ubuntu-24.04        # in PowerShell, then reboot
```

Inside Ubuntu:

```bash
sudo apt update
sudo apt install -y nginx mariadb-server php8.3-fpm php8.3-mysql php8.3-mbstring \
    php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl unzip

sudo mkdir -p /var/www/opes360
# copy the release in, then use docs/DEPLOYMENT.md §3b
```

Then add the hosts entry **on Windows** (WSL shares the network stack), see §3.

---

## Requirements, whichever route

| | |
|---|---|
| PHP | **8.2 or newer** — Laravel 12 will not run on 8.1 |
| Extensions | `pdo_mysql`, `mbstring`, `gd`, `bcmath`, `zip`, `fileinfo`, `openssl`, `intl` |
| Database | MySQL 8 **or** MariaDB 10.6+ — the suite passes on both |

---

## 3. The hosts file (only if your tool did not do it)

Herd and Laragon handle this. Otherwise, open Notepad **as Administrator**, open
`C:\Windows\System32\drivers\etc\hosts`, and add:

```
127.0.0.1    opes360.test
```

Save. Then `ipconfig /flushdns` in an admin PowerShell.

`DNS_PROBE_FINISHED_NXDOMAIN` in the browser means this step is missing or was
saved to a copy — Notepad silently fails to write that file without admin.

---

## 4. Configure and set up

Create the database first (any MySQL client, or `mysql -u root -p`):

```sql
CREATE DATABASE opes360 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then, in the project folder:

```bash
copy .env.example .env

php artisan key:generate
```

Edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://opes360.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opes360
DB_USERNAME=root
DB_PASSWORD=your-mysql-password

# Local only: mail is written to storage/logs/laravel.log rather than sent.
MAIL_MAILER=log

# Herd, Laragon and nginx all terminate TLS in front of PHP. Without this,
# Laravel builds http:// URLs for an https site — the service worker will not
# register and printed QR codes point at a scheme the site does not answer on.
TRUSTED_PROXIES=*
```

```bash
php artisan migrate --seed
php artisan storage:link

php artisan opes:doctor
```

`opes:doctor` will flag mail as unconfigured — expected for a local run.
Everything else should pass.

---

## 5. Sign in

**https://opes360.test** → `john@opesware.com` / `password`

The seeder creates a demo business with the dataset from the designs, so every
figure on the dashboard is computed from real rows rather than hardcoded.

---

## 6. If you want the background jobs too

Only needed if you want to watch queued mail and lease expiry behave as they
will in production. Two terminals, left running:

```bash
php artisan queue:work
php artisan schedule:work
```

Without them the app works fine; queued emails simply sit in the `jobs` table.

---

## Troubleshooting

**`DNS_PROBE_FINISHED_NXDOMAIN`** — the hosts entry is missing. See §3. This is
the most common failure and has nothing to do with the app.

**Certificate warning** — your tool issued an untrusted certificate. In Herd,
click **Secure** on the site. In Laragon, use its SSL menu. The service worker
will not register until the certificate is trusted, so the offline features stay
dormant.

**`500` with a blank page** — check `storage/logs/laravel.log`. With
`APP_DEBUG=false` the page deliberately shows nothing useful; the log has it.

**Blank page, no log** — `storage/` or `bootstrap/cache/` is not writable.

**Assets missing / unstyled page** — `public/build` is absent. Release archives
include it; a git clone does not. Run `npm ci && npm run build`.
