# Deployment

Target: a Contabo VPS running HestiaCP. Everything below assumes a staging
subdomain first — **the production domain is not ready**, and nothing in the
application hardcodes a domain: `APP_URL` in `.env` is the only place one
appears, and every generated link, webhook URL and email is built from it.

---

## 1. The server

### PHP 8.3

HestiaCP installs several PHP versions side by side. Set the web template for
the domain to 8.3, then check the extensions:

```bash
php8.3 -m | grep -E '^(bcmath|ctype|curl|dom|fileinfo|gd|intl|mbstring|openssl|pcntl|pdo_mysql|redis|xml|zip)$'
```

All thirteen must be present.

- **gd** — every uploaded image is decoded and re-encoded through it. Without
  gd, uploads fail closed rather than being stored unchecked, which is the
  right failure but a confusing one.
- **bcmath** — money is integer kobo, but commission splits use it.
- **pcntl** — queue workers use it to handle the restart signal. Without it,
  `queue:restart` does nothing and workers keep running old code after a
  deploy.
- **redis** — the phpredis extension. Predis works but is measurably slower
  under the session load this platform puts on it.

```bash
apt install php8.3-{bcmath,curl,gd,intl,mbstring,mysql,redis,xml,zip}
```

Then in `php.ini`:

```ini
memory_limit = 256M
; Large enough for a phone photograph, which is what a consultation booking
; and a seller's product shots actually are.
upload_max_filesize = 12M
post_max_size = 16M
max_execution_time = 60
```

### MySQL

```sql
CREATE DATABASE agriplatform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'agriplatform'@'localhost' IDENTIFIED BY '<generated>';
GRANT ALL PRIVILEGES ON agriplatform.* TO 'agriplatform'@'localhost';
FLUSH PRIVILEGES;
```

`utf8mb4` is not optional. Nigerian names carry diacritics, and `utf8` (which
in MySQL means three bytes) mangles them.

### Redis

```bash
apt install redis-server
```

In `/etc/redis/redis.conf`:

```
maxmemory 512mb
maxmemory-policy allkeys-lru
```

`allkeys-lru` rather than `noeviction`: this Redis holds sessions, cache **and**
the queue. Under `noeviction`, a full instance starts refusing writes, and the
first thing to fail is a queued payment notification.

### Node

Only needed to build assets:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install nodejs
```

---

## 2. First deploy

```bash
su - agriplatform
cd ~/web/<domain>
git clone <repo> .
cp .env.example .env
```

Fill in `.env` — every variable is documented in the file itself. The ones with
no sensible default and no way to guess:

`APP_KEY` (`php artisan key:generate`), `APP_URL`, `DB_*`, `MAIL_*`,
`SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD`, the gateway keys, `BACKUP_DISK`.

Set `APP_ENV=production` and `APP_DEBUG=false`. **`APP_DEBUG=true` in
production prints stack traces containing database credentials to anybody who
triggers an error.**

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --seed --force
php artisan storage:link
php artisan config:cache route:cache view:cache event:cache
```

### Permissions

```bash
chown -R agriplatform:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

The web server writes to both. Everything else should not be writable by it:

```bash
find app config routes resources -type d -exec chmod 755 {} \;
find app config routes resources -type f -exec chmod 644 {} \;
```

`storage/app/public` ships an `.htaccess` that refuses to execute anything.
Confirm it survived the deploy — it is symlinked into the document root, so a
missed upload path there is the difference between a photograph and remote code
execution:

```bash
cat public/storage/.htaccess   # must contain: SetHandler none
```

### Subsequent deploys

```bash
./deploy/deploy.sh
```

---

## 3. Queue workers

Copy `deploy/supervisor/agriplatform-worker.conf` to
`/etc/supervisor/conf.d/`, adjust the paths and the user, then:

```bash
supervisorctl reread && supervisorctl update
supervisorctl status
```

Two pools, deliberately:

| Pool | Queue | Why |
|---|---|---|
| `agriplatform-default` | `default` | Payment webhooks, settlement, everything that moves money |
| `agriplatform-mail` | `mail` | Every outbound email |

They are separate so a mail provider that hangs for thirty seconds cannot put a
payment confirmation behind however much mail is in front of it. `stopwaitsecs`
is an hour on the default pool — a settlement job must never be killed
mid-transaction — and two minutes on mail, where the worst case is one message
resent.

## 4. Scheduler

```bash
crontab -u agriplatform deploy/cron/agriplatform
crontab -u agriplatform -l
```

One entry, every minute. Laravel decides what actually runs.

Confirm the schedule is live the next morning:

```bash
php artisan schedule:list
```

If `ledger:reconcile` is not in that list, the most important safety net on the
platform is not running.

---

## 5. TLS

HestiaCP issues Let's Encrypt certificates from the web interface: **Web →
domain → Edit → SSL Support → Let's Encrypt**. Enable **Force HTTPS**.

Renewal is automatic. Verify it actually is, rather than assuming — an expired
certificate takes the whole site down including the webhooks, and the failure
mode is silent until the day it happens:

```bash
certbot renew --dry-run
```

Then confirm the application agrees, since Laravel builds absolute URLs from
`APP_URL` rather than from the request:

```bash
grep APP_URL .env      # must be https://
```

A mismatch means the gateway callback URL the application generates is http,
the gateway refuses it, and checkout fails with no error on our side.

---

## 6. Gateway webhooks

**The single most consequential step on this page.** An order does not move to
paid when the buyer finishes paying. It moves when the webhook lands. Without
these registered, buyers pay and nothing happens.

Register in each dashboard:

| Gateway | URL |
|---|---|
| Paystack | `https://<domain>/webhooks/payments/paystack` |
| Flutterwave | `https://<domain>/webhooks/payments/flutterwave` |

Paystack signs the body with your secret key. Flutterwave instead sends a shared
secret in a header — set the same value there and in `FLUTTERWAVE_WEBHOOK_HASH`.

Verify with a real test payment on staging, then check:

```bash
php artisan tinker --execute="dump(App\Models\PaymentWebhook::latest()->first()?->only(['gateway','event_type','signature_valid','outcome']));"
```

`signature_valid` false means the secret does not match. `outcome` of
`unmatched` means the webhook arrived but referenced no order — which the
nightly reconciliation will also report, by design.

---

## 7. Mail deliverability

Transactional mail from an unverified domain goes to spam. On this platform
that means a seller never hears they have an order, and a buyer never gets the
receipt for money they have already spent. Treat this as part of the deploy,
not as follow-up.

### Verify the domain

In the provider's dashboard (Resend, Postmark, Brevo or Mailgun), add the
sending domain and publish the DNS records it gives you. Then publish all
three of:

**SPF** — one record only. Two SPF records is the same as none:

```
v=spf1 include:<provider's include> ~all
```

**DKIM** — the provider gives you a selector and a public key:

```
<selector>._domainkey    TXT    v=DKIM1; k=rsa; p=<key>
```

**DMARC** — start at `none` so you get reports without losing mail, and only
tighten to `quarantine` once a fortnight of reports is clean:

```
_dmarc    TXT    v=DMARC1; p=none; rua=mailto:dmarc@<domain>; fo=1
```

### From and Reply-To

Set `MAIL_FROM_ADDRESS` to an address **at the verified domain**. Leave
`MAIL_FROM_NAME` unset — the from-name is resolved at send time from the
`company_name` setting, so it follows a rename like everything else.

Reply-To is set to the `company_email` setting. Make sure it is an address
somebody reads: people reply to transactional mail constantly, and a reply that
bounces is a customer who thinks they were ignored.

### Test it for real

Send to a Gmail, a Yahoo and an Outlook address, then open the raw headers on
each. All three must say pass:

```
Authentication-Results: spf=pass ... dkim=pass ... dmarc=pass
```

`php artisan mail:test <address>` sends one of each transactional template.

### Every template, end to end on staging

`php artisan mail:test <address>` sends all of them at once, synchronously, and
reports each one. `--list` shows the keys; `--template=<key>` sends one.

Twenty-one transactional templates plus the announcement:

| Area | Templates |
|---|---|
| Account | `welcome`, `account-activated`, plus Laravel's own verification and password-reset mail |
| Selling | `seller-approved`, `seller-more-info`, `seller-rejected` |
| Orders | `order-paid`, `seller-order-received`, `order-shipped` |
| Money | `withdrawal-paid`, `dispute-raised` |
| Offers | `offer-received`, `offer-countered`, `offer-accepted`, `offer-rejected`, `buyer-request-reviewed`, `buyer-request-expiring` |
| Academy | `course-purchased`, `certificate-issued` |
| Mentorship | `mentor-invitation`, `engagement-confirmed` |
| Consultations | `consultation-booked`, `consultation-quoted`, `consultation-report` |
| Quotations | `quotation-study-fee-due`, `quotation-sent`, `quotation-expired` |

Sending is not the same as arriving, so walk the actual flows on staging too
and confirm each message renders with the branding and its links resolve
against the staging domain.

Four are time-critical and their subject lines say so without being opened —
check these read correctly, since a person deciding whether to open a message
reads only the subject:

- `dispute-raised` — *Action needed: dispute opened on SO-…*
- `withdrawal-paid` — *Paid: ₦87,500 sent to your bank account*
- `consultation-booked` (urgent) — *URGENT consultation CON-… — we reply within 6 working hours*
- `order-paid` — *Payment received — order OR-…*

### Bounces and complaints

Register the provider's bounce and complaint webhook. A hard bounce must flag
the address on the user record; without it the queue retries a dead address
forever, and the provider's reputation score — shared across every message the
platform sends — falls for everybody.

---

## 8. Monitoring

| What | How |
|---|---|
| Uptime | Point a monitor at `https://<domain>/health` every minute. It answers 503 when the database, cache, storage or queue is down |
| Errors | Set `SENTRY_LARAVEL_DSN`. With no DSN the integration is inert, so staging can report and a laptop cannot |
| The books | `ledger:reconcile` runs at 02:30 and emails every administrator on a discrepancy. **Read those emails** |
| Backups | `backup:monitor` at 08:00 notices a backup that stopped silently; `backup:verify-restore` on Mondays proves the newest one actually restores |

### Restore drill

Do this once before go-live, and once a quarter after. A backup nobody has
restored is a backup nobody knows the shape of:

```bash
php artisan backup:verify-restore --keep
```

`--keep` leaves the scratch database behind so you can look inside it.

---

## Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` is the https domain
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `php artisan schedule:list` shows `ledger:reconcile`
- [ ] `supervisorctl status` shows both worker pools running
- [ ] Both gateway webhook URLs registered, and a real test payment moved an order to paid
- [ ] SPF, DKIM and DMARC all pass at Gmail, Yahoo and Outlook
- [ ] Bounce and complaint webhooks registered
- [ ] A backup has been taken **and restored**
- [ ] `curl https://<domain>/health` returns 200
- [ ] The company name and logo are set in `/admin → Settings`, and the header, an email and a PDF all show them
