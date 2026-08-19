# agriplatform

An agricultural marketplace and training platform for Nigerian farmers — poultry
farmers, feed sellers, equipment dealers and farm workers.

> **`agriplatform` is an internal codename.** The client's company name has not
> been chosen. It is **never** hardcoded: the administrator types it into
> **Settings → Company & branding** and it propagates to the site, emails, PDFs
> and certificates with no code change and no redeploy. `APP_NAME` in `.env` is a
> placeholder used only as a last-resort fallback.

## Stack

| | |
|---|---|
| Framework | Laravel 13 (PHP 8.3+) |
| Front end | Inertia.js 3 + Vue 3 (Composition API) + Tailwind CSS 4 |
| Admin | Filament 5 — three panels: `/admin`, `/seller`, `/mentor` |
| Auth | Laravel Breeze (Inertia + Vue) |
| Roles | spatie/laravel-permission |
| Database | MySQL |
| Cache / queue / session | Redis |
| Mail | Resend, Postmark, Brevo or Mailgun in production; Mailpit locally |
| Tests | Pest |

## Getting started

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Point DB_* at a MySQL database and REDIS_* at a Redis server, then:
php artisan migrate --seed
php artisan storage:link

npm run build      # or: npm run dev
php artisan serve
```

Set `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD` before seeding to create the
first administrator. Without them the seeder skips that step rather than
creating an account with a guessable password.

For local mail, run Mailpit and leave `MAIL_MAILER=mailpit`:

```bash
docker run -d -p 1025:1025 -p 8025:8025 axllent/mailpit   # UI at :8025
```

## Where the company name lives

`App\Services\Branding\BrandingService` is the **only** way any part of the
application learns the company's name, logo or contact details. It is wired in
three ways:

1. **Inertia** — `App\Http\Middleware\HandleInertiaRequests` shares a `branding`
   prop with every page; Vue reads it through `useBranding()`.
2. **Blade** — `App\Providers\BrandingServiceProvider` registers a view composer
   so email, PDF and certificate templates get the same `$branding` array.
3. **Authored content** — `branded($text)` (and the `@branded` Blade directive)
   expands `{company}` and `{company_short}` in copy an administrator typed.

Writing a branding setting fires `App\Events\SettingsChanged`, which drops the
branding cache immediately, so a rename is live on the next request.

`tests/Feature/NoHardcodedBrandTest.php` fails the build if a brand-shaped
literal appears in `app/`, `resources/` or `config/`.

## Selling and the catalogue

Anyone with an account can apply to sell at `/sell` — a four-step form covering
the business, its location, an ID document and the categories they intend to
trade in. CAC registration is optional on purpose: most traders in this market
are not registered, and requiring it would shut them out.

Applications land `pending` and grant no role. An administrator approves,
rejects with a reason, or asks for more information from **Admin → Sellers**;
approval is what grants the `seller` role and opens `/seller`.

A new seller's listings queue for review. After **three** approved listings the
rest go live straight away; an administrator can override that per seller in
either direction. Ownership of a listing is enforced twice — by a query scope on
the seller panel's resource and by `ProductPolicy` — so neither a forgotten scope
nor a forgotten policy check can expose another seller's records.

Money is stored in **kobo** as integers everywhere; `App\Support\Money` is the
only place it becomes something a person reads.

Live animals and perishable goods must carry a handling or delivery note. That
is enforced on the model, not just in the form, so an admin edit, an import or a
seeder is bound by it too — and both flags are surfaced on the catalogue card,
not only on the product page.

Catalogue search uses a MySQL `FULLTEXT` index in boolean mode, with prefix
matching so "feed" finds "feeder". On SQLite it degrades to a `LIKE` scan; the
tests in `tests/Driver` cover the production path against a real MySQL and skip
when none is reachable.

```bash
php artisan db:seed --class=DemoCatalogueSeeder   # eight sellers, ~50 listings
```

## Buying: cart, payment and the ledger

A guest's cart lives in the session and a signed-in buyer's lives in the
database; signing in merges the two by **adding** quantities rather than
replacing them. Prices are resolved again at checkout, and a price that has
moved is shown to the buyer before they pay, never for the first time on the
receipt.

One payment, one `orders` row. It splits into a `sub_orders` row per seller,
each carrying its own commission snapshot and delivery choice, and each
`order_items` row snapshots the product name, unit and price as they were at
purchase — a historical order never reads the live product row.

Delivery is per seller: `seller_arranged` at a rate the seller sets per state,
or `buyer_pickup`, where the seller's address appears only once payment has
cleared. `quote_required` is in the schema and behind the
`delivery_quotes_enabled` flag until the negotiation flow exists.

### The rule that matters

**An order moves on the webhook and only on the webhook.** The gateway callback
the buyer returns to is a public URL, so it grants nothing — it polls
`/checkout/{order}/status` and reports what the server actually knows. Every
webhook is signature-checked, made idempotent by a unique index on
`(gateway, event_id)` plus a row lock on the order, and logged raw to
`payment_webhooks` whether or not it was accepted.

```
POST /webhooks/payments/paystack
POST /webhooks/payments/flutterwave
```

Paystack counts in kobo, Flutterwave in Naira. That conversion lives inside the
gateway classes and nowhere else. Which gateway is live is the
`active_payment_gateway` setting, not configuration; a gateway with no keys is
never offered to a buyer.

### Balances are never stored

There is no balance column anywhere. `wallet_transactions` is the record and
`WalletService` sums it on demand, so there is no second number that can
disagree with the entries. A reversal is written **beside** the entry it
corrects, leaving the original intact — rewriting a released entry would destroy
the evidence that the money was ever released.

Settlement is a driver, chosen by the `settlement_driver` setting:

| Driver | Payout state on payment | Released when |
|---|---|---|
| `escrow` | `held` | the buyer confirms receipt, or `escrow_auto_release_days` (default 7) after the seller marks delivery, with no dispute |
| `instant` | `released` | immediately on a verified payment |

Commission is a separate ledger entry to the platform account under both.

```bash
php artisan escrow:release   # scheduled hourly; releases windows that have run out
```

Commission is split on integers: the platform's share is rounded once, and the
seller's is what remains after subtraction, so the two always add back to the
subtotal exactly. `tests/Unit/CommissionTest.php` proves that over 20,000
random sales.

## Roles

One `users` table. A user may hold any number of roles at once — they are
additive capabilities, not a hierarchy.

| Role | Panel | What it can do |
|---|---|---|
| `admin` | `/admin` | Settings, branding, users, moderation |
| `seller` | `/seller` | Listings and orders |
| `mentor` | `/mentor` | Consultations and training |
| `worker` | — | Finds farm work, holds certificates |
| `employer` | — | Posts farm jobs |

Registration creates a plain user with **no** role and a `pending` status.

## Design

The palette, type, scale and the signature element are documented in
[`docs/design-system.md`](docs/design-system.md), together with the critique that
produced them. Fonts are self-hosted and regenerated by
[`scripts/build-fonts.sh`](scripts/build-fonts.sh).

## Tests

```bash
php artisan test
```
