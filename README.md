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

## Payouts and disputes

### Money out

A bank account is never saved on somebody's say-so. The account name comes back
from the bank through the gateway's resolution endpoint and is stored as the
bank gave it; `is_verified` records that the check actually happened, and an
unverified account is never paid.

A withdrawal reserves against the balance from the moment it is **requested**,
not when it is paid — otherwise a seller with ₦50,000 could file five ₦50,000
requests while an administrator was at lunch. The ledger entry is written when
the gateway accepts the transfer; if it later bounces, a reversal is placed
beside it and the original stays.

The double-spend guard is a real row lock (`FOR UPDATE`) over both the ledger
sum and the outstanding requests, taken inside the transaction that decides
whether a request is allowed. `tests/Driver/ConcurrentWithdrawalTest.php` proves
it by spawning six OS processes that race for one balance; with the lock
removed, four are accepted against a balance that covers two.

Two modes, set by `payout_mode`:

| Mode | How money leaves |
|---|---|
| `manual_request` | The seller asks, an administrator approves and sends. The default: while a platform is young, somebody should see every naira that leaves it. |
| `scheduled_auto` | `payouts:run` sweeps every balance over `minimum_withdrawal_amount` on `payout_schedule_day`. A day past the end of a short month lands on its last day. |

```bash
php artisan payouts:run            # scheduled daily; does nothing on other days
php artisan payouts:run --force    # ignore the mode and the schedule
```

### Disputes

A buyer may dispute within `dispute_window_days` of delivery being marked — or
at any time if it was never marked, because the complaint is that it has not
come. Raising one **freezes the money**: the escrow clock is cleared and the
settlement drivers refuse to release a disputed order. One live dispute per
sub-order, enforced by a unique index rather than by hope.

Buyer, seller and administrator share one thread. An administrator's notes to
file are the only thing withheld, and they are withheld from both sides — an
arbitrator taking one party into a private room is not arbitrating.

Every resolution obeys one law, asserted over the ledger on all four paths and
over 300 randomised partial refunds:

> what the seller keeps **+** what the platform keeps **+** what goes back to
> the buyer **=** what the buyer paid.

A partial refund is counted against the goods first and the delivery fee only
once the goods are exhausted, so the platform gives back its commission on
exactly what is being returned — refund two bags out of ten and it gives up the
commission on two bags. A refund that only concerns the delivery therefore
comes out of the seller, who is the one who delivered it late.

### Reading the books

**Admin → Money** carries the payout queue, the disputes queue, a searchable
ledger explorer, and a reconciliation page that puts what buyers were charged
next to what the ledger says happened to it. The two are built from different
tables by different code, so when they agree it means something.

**Seller → Money** is the same ledger from one seller's side: what is ready to
withdraw, what is still held, sales by month, and a filterable statement.

## Offers: one engine, two features

A buyer haggling over a listing and a seller answering a wanted ad are the same
transaction from opposite ends: somebody proposes a price and a quantity,
somebody else says yes, no, or how about this. So there is one `offers` table
with a polymorphic `offerable`, and one `OfferService` behind both. Writing it
twice would have meant two sets of expiry rules and eventually two different
answers to "what did we agree".

A counter-offer is a **new row** whose `parent_offer_id` is the offer it
answers; the answered one is marked `countered` and never rewritten. So
`$offer->chain()` reads the whole argument back in order, and `round()` says how
far in any link sits. Roles swap with every counter: whoever answers becomes the
one proposing.

### Haggling on a listing

Available on any product with `is_negotiable`. The seller answers in
**Seller → Offers**. On acceptance the buyer gets a private checkout at the
agreed price, good for `negotiated_checkout_hours` (default 48), and that much
stock is held back.

The reservation is its own counter on `products`, not a decrement of
`stock_quantity` — those mean different things, and a decrement would make a
reservation indistinguishable from a sale to the seller looking at their own
shelf. `availableStock()` is `stock_quantity − reserved_quantity`, and it is
what the cart, the catalogue and the product page all read.

Everything after the order exists is the ordinary Phase 3 path: same commission
split, same gateway, same webhook, same escrow. A negotiated order is a normal
order that happened to start with an argument.

### The wanted-ad board

```
/requests                 the board, public
/requests/new             post one
/requests/mine            your own
/requests/{slug}/manage   compare what you have been offered
```

Requests are moderated in **Admin → Buyer requests** before publication,
because an unmoderated board fills with phone numbers and scams faster than
anything else on a marketplace. The clock starts at approval, not submission: an
ad that sat in a queue for four days should not lose four days of its life.

Only an approved seller trading in the category may answer, and a seller
registered for a parent category answers anything beneath it — nobody registers
against every leaf of a tree this deep.

**The board shows how many offers a request has drawn and never what any of them
were.** A board where the best price so far is visible is a board where
everybody shaves a naira off it and nobody bids their real number. The buyer's
own comparison screen orders by **price each**, not by total: a seller offering
half the order has half the total, and badging that "cheapest" would send buyers
at the wrong offer for a reason that has nothing to do with price.

```bash
php artisan offers:sweep   # scheduled hourly
```

One command does four things in an order that matters: requests expire first, so
a seller is told the request closed rather than that their offer merely lapsed;
then buyers are warned three days out (once, stamped); then offers lapse; then
stock nobody came for is released.

### Notifications

Every state change goes out on two channels — a database row for the in-app list
at `/notifications`, and an email for the people who are not looking at the app,
which on a market stall is most of the time. All queued.

The mail half is an ordinary `BrandedMailable`, so notifications go through the
same branded layout, sender name and `{company}` expansion as everything else.
There is one mail path in this application and notifications are not an
exception to it.

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
