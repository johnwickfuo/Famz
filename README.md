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

## The academy

Courses are written by the company. There is deliberately no instructor role,
no `instructor_id` "for later" and no revenue share — third-party authoring is a
different product with different payouts, moderation and liability, and leaving
the door ajar for it would be an open door dressed up as foresight.

### Course material never leaves the server

Uploaded lesson files live on a private disk at `storage/app/course-content`,
configured in `config/filesystems.php` with **no `url` key at all**. That is not
decoration: `Storage::url()` on that disk throws, so any code that tried to turn
a lesson into a link fails loudly at the point of the mistake instead of quietly
publishing a guessable path.

The only route to the bytes is `GET /academy/content/{lesson}`, and it checks
four independent things rather than inferring any of them from the last:

1. the signature is ours and is inside its five minutes (`signed` middleware),
2. somebody is signed in,
3. the link was minted **for that somebody** — forwarding it does not transfer it,
4. they still hold an active enrolment, or the lesson is an open preview.

The fourth is the one that earns its keep. A signature proves we made the URL,
not that whoever is holding it may still use it: somebody refunded an hour ago
has a perfectly valid signature.

Handouts are stamped at serve time with the reader's own name and email along
the footer of every page, responses carry `no-store` and are always `inline`,
never an attachment, and there is no download route anywhere in the application
— a test asserts that no route name contains both "lesson" and "download".

None of this is DRM, and it is not meant to be. Somebody determined will
photograph the screen. The watermark is the answer to that, and the goal here is
to stop the casual "save as, send to the group chat".

The player renders PDFs with PDF.js onto a canvas rather than handing them to
the browser's built-in viewer, which comes with download and print buttons we
have no way to remove. `pdfjs-dist` is pinned to an **exact** version: later
releases call `Map.prototype.getOrInsertComputed`, which no Android WebView in
the field has yet, and the reader would load a document and then fail on the
first page — on exactly the phones most of these students are holding.

### Buying a course

Checkout reuses the Phase 3 payment layer whole — same gateways, same callback,
same webhook, same "the order moves only on a verified webhook" rule. What is
not reused is the split: **a course order has no sub-orders**, so there is no
seller, no commission and no escrow, and the whole amount is the platform's the
moment it clears. That falls out of the schema rather than being special-cased
in the payment code, which is why `PaymentProcessor` needs no branch for it: it
loops over sub-orders, and a course order has none.

One purchase, lifetime access, all sales final. The wording is printed on the
checkout page in full, the box is not pre-ticked, and the **text they agreed to**
is stored on the enrolment along with the time and the IP — an argument six
months later is about what they were shown, not about what the page says by then.

### Certificates

Issued only when every lesson is finished **and** the final quiz is passed. A
course whose quiz is marked not required needs only the lessons.

The issuing company's name, short name, RC number, logo path and signature block
are **copied onto the certificate at issue time**, not looked up when the PDF
renders. A student downloading theirs again in two years gets the document they
were given, not one bearing whatever the company has since renamed itself, and
the public check page at `/verify/{code}` says what it said on the day. The logo
is stored as a path rather than a URL so it survives a change of domain.

Marking happens on the server from the stored options. The quiz page carries
option text and ids and nothing else — no scores, no correctness, no "passed"
flag — because anything the browser is trusted to work out is a certificate
anybody can mint with the console open.

### Seeing it with something in it

```bash
php artisan db:seed --class=DemoAcademySeeder
```

Three published courses with modules, lessons, a quiz and **real generated PDF
handouts** written onto the private disk, so the reader, the watermark and the
five-minute link can be exercised rather than taken on trust.

## Mentorship

Mentors join **by invitation only**. There is no public signup form, no link in
any navigation, and the registration page is disallowed in `robots.txt` and
carries a `noindex` header. None of that is the lock: the lock is that
`/mentors/join/{token}` needs a token that exists, is unspent and is unexpired,
checked on the GET and again on the POST under a row lock — so two people racing
the same forwarded link cannot both come out the other side as mentors.

A test asserts the invariant against the source rather than against a list of
routes: exactly one file in the whole application may create a `MentorProfile`,
and it is the one that spends an invitation.

Being invited and being listed are separate decisions. Registration creates a
pending profile; an administrator approves it, and refuses to approve a mentor
with no packages — nothing to hire is a dead end in front of every client who
finds them.

### Matching, and why it cannot depend on the AI

The client writes what is wrong in their own words. That text is mapped onto
**specialisation tags**, and the tags are what the ranking runs on — keeping the
paragraph and the tags apart is what stops a shortlist depending on how well
somebody writes.

The AI layer answers exactly one question, from a **closed vocabulary**, with a
six-second timeout, no retries, and no code path that throws. Anything it names
that is not a real tag is discarded. With no `GEMINI_API_KEY` set, the container
binds `NullTagResolver`, which always declines — so the keyword fallback is what
runs in development, in the tests, and on any deployment where nobody has signed
up to Google. **The fallback is the path that must never break, so it is the
path that runs by default rather than only in an emergency.** The fallback is
not a stub: it scores every tag against the words in the request using the
taxonomy's own keyword lists, which is why the seeder writes the phrases a
farmer would actually type ("my chicks are dying", not "poultry husbandry").

Ranking weighs tag overlap far above rating and completed engagements, and a new
mentor scores half marks rather than zero. Weighting reputation heavily is how a
marketplace ends up with five mentors taking all the work and nobody else ever
getting a first engagement.

A mentor with **no** overlap is left out entirely rather than ranked last: a crop
agronomist shown to somebody whose chicks are dying makes the whole shortlist
untrustworthy. When nothing could be inferred at all there is nothing to be
irrelevant to, so everybody stays — and the page says which of the two happened.

Every run is stored in `mentorship_matches` with the brief, the tags, the
resolver that produced them and the ranked result, so the ranking can be tuned
later against what was actually shown.

### Contact details are the product

A client can read a mentor's whole profile, compare every package and see what
other clients said, and still have no way to reach them. That is the business
model, so it is enforced structurally rather than by a flag:

- `MentorProfile::publicCard()` — which every listing, shortlist and profile page
  is built from — **has no contact field in it at all**. Not hidden, absent.
- `contactFor()` takes the *engagement* rather than a boolean, checks it belongs
  to this mentor, and asks `EngagementStatus::revealsContact()`. A caller cannot
  pass `true` and be believed.
- There is no `contact_unlocked` column that could disagree with the payment.

The exchange is symmetric: the client gets the mentor's WhatsApp at the same
moment the mentor gets the client's phone. Neither side gives up more than the
other.

### The money

Payment reuses the Phase 3 layer whole — same gateways, same callback, same
webhook. A mentorship order has no sub-orders, so none of the marketplace
settlement runs for it.

Every mentorship ledger entry hangs off an **invoice**, and a one-off engagement
gets an invoice too. That collapses "paid once" and "paid monthly" into a single
path — money arrives against an invoice, is held against it, is released against
it — so a mentor is paid per confirmed period rather than upfront for a term the
client may abandon in month three.

Both the mentor's share and the platform's commission are **held**. Until the
work is confirmed the platform has not earned its cut either, and showing it as
revenue on day one would overstate what the business can spend.

    request  → engagement + invoice, both unpaid, nothing revealed
    pay      → contact revealed both ways; mentor share and commission HELD
    finish   → mentor marks it done; the client has 7 days
    release  → the client confirms, or silence confirms it

Auto-confirmation exists so a mentor is not left unpaid because somebody stopped
reading their email. Raising a dispute takes the engagement out of that sweep, so
a client who complains on day six does not have the work confirmed out from under
them on day seven.

### Disputes

Mentorship disputes route into the Phase 4 system rather than beside it: one
thread, one set of statuses, one admin queue, one conservation law —

    mentor keeps + platform keeps + client gets back = client paid

— asserted on every resolution path and across a spread of partial refunds.
Either party may raise one here, unlike the marketplace: a mentor whose client
vanished after three months of advice has a complaint worth hearing.

### Reviews

Only a client whose engagement **finished** may write one, one per engagement
enforced by a unique index, and nothing is visible or counts toward a rating
until an administrator approves it. That costs immediacy and buys the ability to
take down a review written in a temper, which on a market where one bad rating
can end a small practice is a trade worth making. The mentor sees it while it
waits, so nobody learns their rating dropped by noticing the number on their own
profile.

### Seeing it with something in it

```bash
php artisan db:seed --class=DemoMentorSeeder
```

Six approved mentors with real packages across poultry, feed, fish, livestock,
crops and farm business.

## Consultations

A consultation is with **the company**, not with a listed professional. The
client does not choose a person, and there is no directory to browse: they
describe a problem, somebody rings them back, and the company decides internally
who takes it. That is the whole difference from mentorship, and it is why a
consultation has no assignee column.

### Four fields

Name, phone, email, tier. Everything else on the booking form is optional and
marked as such, and the form says so out loud. A farmer standing in a house
losing ten birds a day will not fill in a farm profile, and a form that makes
them is a form that loses the booking — the rest can be asked on the call.

Guests can book without an account. When somebody later registers with the same
email their bookings are attached to the new account, so the history does not
disappear behind a signup.

Photographs are compressed in the browser before upload — a vet will ask for
pictures either way, and a farmer on patchy data should not spend their bundle
sending a 6MB phone photo.

### The promise, and the working-hours clock

Two tiers, both read from settings rather than written into the page:

| Tier | Promise | Clock |
|---|---|---|
| `standard` | within 48 hours | wall-clock |
| `urgent` | within 6 **working** hours, at a premium | working hours only |

`response_due_at` is computed at submission and **stored**, not derived. Changing
the standard window next month must not retroactively make last week's bookings
late — or, worse, on time.

Urgent counts working hours because that is the honest way to promise six of
them: somebody who books at nine at night is not owed a call at three in the
morning, and `ResponseClock` rolls the clock forward to the next open moment
instead. Working hours and days are themselves settings.

### Book first, pay later

There is **no price list**. The flow is deliberate:

1. The client books. Nothing is charged, and the form says nothing is charged.
2. Somebody rings them and stamps `first_responded_at`, which stops the clock.
3. After the conversation an administrator enters a price — a number a person
   decided, not a rate card. That emails a payment link and shows it on the
   client's dashboard.
4. The client pays through the Phase 3 gateway layer. The **full amount goes to
   the platform**: no commission split and no escrow hold, the same shape the
   academy uses, because there is no third party to hold money back from.
5. The work happens, a report is written, and the client can read it and save it
   as a PDF.

The company cannot quote sensibly before it knows what is wrong, and a farmer
with dying birds will not stop to agree a price. Both facts point the same way.

### Reports are drafts until somebody says otherwise

A report is invisible to the client until it is published, and publishing is a
separate deliberate action rather than a checkbox on the form. An administrator
working through findings over two days must not have half of it appear in
somebody's dashboard: a client who reads "what we found:" with nothing after it
has been told something untrue about the work.

The client's page has no flag for this to get wrong — the server sends only
published reports, so a draft is simply absent.

### The queue

`/admin/consultations` is sorted by `response_due_at` ascending, which is the
line that turns a list into a queue. Late rows are **tinted**, not merely badged,
because a colour is read before a word is. The navigation badge counts only late
work: a badge counting everything open is a number people stop reading after the
first week, and one that is usually zero is a number they act on.

Record contact sits outside the action menu as a button, because stopping the
clock is the thing an administrator does most often and it should not be two
clicks behind a chevron.

### Follow-up

A threaded conversation, open while the work is live and for
`consultation_followup_days` (default 30) after it finishes. The client's page
only invites them to write when the thread is actually open; otherwise it points
them at the email, rather than at a box that is not there.

### Seeing it with something in it

Book one at `/consult` and work it from `/admin/consultations`.

## Roles

One `users` table. A user may hold any number of roles at once — they are
additive capabilities, not a hierarchy.

| Role | Panel | What it can do |
|---|---|---|
| `admin` | `/admin` | Settings, branding, users, moderation |
| `seller` | `/seller` | Listings and orders |
| `mentor` | `/mentor` | Mentorship engagements and packages |
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
