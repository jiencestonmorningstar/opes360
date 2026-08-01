# Deploying OPES360

Written for a single application server plus a managed MySQL instance, which is
the right shape until the product has enough load to need more.

**Deploying to cPanel shared hosting instead?** Use
[`DEPLOY-NAMECHEAP.md`](DEPLOY-NAMECHEAP.md) — no Docker, no daemons, and the
queue runs from cron.

The suite passes on **MariaDB 10.11 as well as MySQL 8**, so either is fine.

---

## 1. What has to exist before the first deploy

| Requirement | Why it is not optional |
|---|---|
| MySQL 8.4, `utf8mb4` | The suite is verified against MySQL in strict mode. SQLite is for tests only. |
| PHP 8.4 with `pdo_mysql`, `mbstring`, `gd`, `bcmath`, `zip` | `gd` renders the logo previews; `bcmath` keeps money arithmetic exact. |
| A mail transport (SES, Postmark, Resend…) | Without it, password reset links go to a log file and locked-out users stay locked out. |
| SPF and DKIM on the sending domain | Mail that lands in spam is indistinguishable from mail that was never sent. |
| HTTPS with a valid certificate | The session cookie, the sync API and the service worker all require it. A PWA will not install over plain HTTP. |
| A queue worker | Account mail is queued. No worker means no mail, with no error anywhere. |
| A cron entry | Number leases expire on a schedule; without it, gaps in the invoice sequence go unexplained. |

Docker covers everything in this table except TLS and the mail account — see §3.

---

## 2. Environment

Copy `.env.example` and set, at minimum:

```dotenv
APP_ENV=production
APP_DEBUG=false            # leaks stack traces, including credentials
APP_URL=https://…          # https, or sessions and the sync API break

DB_CONNECTION=mysql
DB_HOST=…
DB_DATABASE=…
DB_USERNAME=…
DB_PASSWORD=…

MAIL_MAILER=smtp           # never `log` in production
MAIL_FROM_ADDRESS=…        # on a domain you control, with SPF + DKIM

QUEUE_CONNECTION=database  # or redis
SESSION_DRIVER=database
CACHE_STORE=database       # or redis
```

`APP_KEY` must be generated **once** and then never changed. Encrypted tax IDs
and 2FA secrets are unreadable without the key that wrote them; rotating it
silently destroys them.

---

## 2b. Subscription billing (MTN Mobile Money / Orange Money)

Company owners pay for their plan from `/settings/billing`. Both providers are
optional — leave a provider's credentials blank and the billing page simply
hides it, so a fresh install works with neither configured.

**MTN Mobile Money** (Collections API — momodeveloper.mtn.com):
1. Register a product subscription for "Collections" and note its
   `Ocp-Apim-Subscription-Key`.
2. Provision an API user and API key against that key (the sandbox self-signup
   flow, or MTN's onboarding for a live merchant).
3. Set `MTN_MOMO_SUBSCRIPTION_KEY`, `MTN_MOMO_API_USER`, `MTN_MOMO_API_KEY`.
   Leave `MTN_MOMO_ENVIRONMENT=sandbox` until MTN approves the merchant for a
   specific operator target (e.g. `mtncameroon`), then switch it and
   `MTN_MOMO_BASE_URL` to the production values MTN gives you.
4. In the developer portal, register this app's callback host so MTN's async
   payment result reaches `/webhooks/mtn-momo`. The webhook is never trusted on
   its own — it only tells the app which payment to re-check — so a missed or
   delayed registration degrades to polling (the billing page checks status
   itself) rather than losing a payment.

**Orange Money** (Web Payment API — developer.orange.com/apis/om-webpay):
1. Create an Orange Developer application to get `ORANGE_MONEY_CLIENT_ID` /
   `ORANGE_MONEY_CLIENT_SECRET`.
2. Get `ORANGE_MONEY_MERCHANT_KEY` from your Orange Money merchant account
   (separate from the developer application above).
3. Set `ORANGE_MONEY_COUNTRY` (`cm` for Cameroon) and `ORANGE_MONEY_LANG`.
4. No manual webhook registration is needed — `notif_url`, `return_url` and
   `cancel_url` are sent with every payment request and point back at this
   app automatically.

Neither provider signs its webhook calls, so `SubscriptionWebhookController`
treats a callback purely as a "check this payment" trigger and always asks
the provider directly (with our own API credentials) before changing a
payment's status or activating a plan.

---

## 3. Deploy with Docker (recommended)

The repository ships a production image and a single-node compose file. Set the
values in `.env` first — the containers refuse to start without `APP_KEY`,
`APP_URL`, `DB_PASSWORD` and `DB_ROOT_PASSWORD`, rather than booting into a
broken state:

```bash
cp .env.example .env
docker compose run --rm app php artisan key:generate --show   # paste into .env
docker compose up -d --build
docker compose exec app php artisan opes:doctor --mail=you@yourdomain.com
```

That brings up four services: the web container (nginx + php-fpm), a queue
worker, the scheduler, and MySQL 8.4. They are separate on purpose — a web
container that also runs cron cannot be restarted independently, and the queue
is what delivers password resets, so its health has to be visible on its own.

Put a TLS-terminating proxy in front on port `8080`. `APP_URL` must be the
`https://` address: Laravel builds every QR code and service-worker URL from it,
so a mismatch prints codes that resolve to nothing.

> **Not yet verified end to end.** The image and compose file are written and
> statically checked, but no Docker daemon was available in the environment they
> were authored in, so `docker compose up` has not been run. Expect to iterate on
> the first build.

---

## 3b. Deploy without Docker

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Then check the result before letting anyone in:

```bash
php artisan opes:doctor --mail=you@yourdomain.com
```

It exits non-zero on anything blocking. It catches the failures that look fine
in a browser and surface at the worst possible moment — mail still pointed at
the log file, a queue with no worker, debug mode left on, a missing storage
link.

---

## 4. Long-running processes

**Queue worker.** Under supervisor, systemd, or whatever the host provides:

```
php artisan queue:work --tries=3 --max-time=3600
```

Restart it on every deploy (`php artisan queue:restart`), or workers keep
running the previous release's code.

**Scheduler.** One cron entry, which Laravel fans out internally:

```
* * * * * cd /path/to/opes360 && php artisan schedule:run >> /dev/null 2>&1
```

See `routes/console.php` for what it runs and why.

---

## 5. Backups

Not automated here — it depends on the host — but the requirements are specific.

**What must be backed up:**
- The MySQL database.
- `storage/app/public` (logos, avatars, artisan photos).
- `APP_KEY`, stored somewhere other than the server it decrypts.

**What matters more than the backup:** a *rehearsed restore*. Restore into a
scratch database and run:

```bash
php artisan opes:doctor
php artisan tinker --execute="dump(App\Models\Document::withoutGlobalScopes()->count());"
```

A backup nobody has restored is a hypothesis, not a safeguard. Rehearse before
launch and after any schema change large enough to worry you.

**Retention.** Financial records are the point of this product. Daily backups
with 30-day retention is a floor, not a target, and some jurisdictions require
invoice data to be retrievable for years.

---

## 6. After deploying

- Confirm the PWA installs on a real phone, both iOS and Android.
- Confirm a password reset email arrives at an external address.
- Confirm an invoice issues, prints, and verifies through its public QR page.
- Take one device offline, issue an invoice, bring it back, and confirm it
  syncs with the number it printed.

That last one is the whole product working end to end. If it passes, the
deployment is sound.

---

## 7. Known operational limits

- **No error tracking wired up.** Sentry or equivalent should be added before a
  real launch; today a 500 is only visible in `storage/logs`.
- **No uptime monitoring or log aggregation.**
- **Single-server assumptions.** The `database` cache and queue drivers are fine
  for one node. Multiple app servers need Redis and shared session storage.
- **`storage/app/public` is local disk.** Moving to S3 needs
  `FILESYSTEM_DISK=s3` and a migration of existing files.
- **Reminders, but no dunning.** Owners are emailed a week before
  `plan_renews_at` and again once it passes (`opes:remind-plan-renewals`,
  scheduled daily). Nothing downgrades or suspends a lapsed plan — that stays
  a deliberate choice: a paid plan remains active until a platform admin or a
  new payment changes it.
