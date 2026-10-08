# AGENTS.md — Aishii POS

Aishii POS: the store POS of the Aishii family (https://aishiierp.com). A public,
MIT-licensed fork of `aryadwiputra/point-of-sales` (Point of Sales by Arya Dwi
Putra). Laravel 13 + Inertia 3.0 + React 19.

## Important: This Repo

**Remote:** `https://github.com/okyzazake7-cell/point-of-sales` (fork).
**Upstream:** `https://github.com/aryadwiputra/point-of-sales` — keep the
original copyright in `LICENSE`, and send generic fixes upstream.

**Aishii-specific rules (wave AS, `docs/permintaan-3okt.md` in the Aishii repo):**
- The brand lives in ONE place per world: `config/brand.php` (PHP/Blade) and
  `resources/js/Utils/brand.js` (React). Never type the product name in a page;
  `tests/Feature/BrandTest.php` fails when the two twins drift.
- Colors come from tokens (`tailwind.config.js` + `resources/css/design-tokens.css`):
  `primary` = the Aishii palette (#752e8e), `accent` = violet. Do not hard-code
  `indigo-*`/hex colors in pages — swap tokens instead, so upstream merges stay small.
- Mobile-friendliness is MEASURED: `node scripts/audit-ponsel.mjs` (server on
  :8000 with `php artisan seed:demo --force`) must report
  `0 halaman×lebar meluber` at 390 and 320 px before every PR touching UI.
- Interface language defaults to Indonesian; `SetLocale` must not read
  `Accept-Language`. English stays available as an explicit choice.
- `Permissions-Policy` keeps `camera=(self)` and `usb=(self)`: the camera barcode
  scanner and WebUSB receipt printing depend on them.
- Aishii POS is sold as a service at `https://pos.aishiierp.com` in multi-store
  mode (decision AS5; design: document 24 in the Aishii repo). See "Multi-store
  mode" below. Hosting: **Google Cloud Run in Tokyo + TiDB Cloud Starter in
  Tokyo + Cloudflare R2** (option E, owner decision 5 Oct; design: document 25
  in the Aishii repo; owner steps in its `docs/langkah-pemilik-aishii-pos.md`).
  Technical reference: `docs/cloud-run.md` — `Dockerfile`, `docker/`,
  `cloudflare/proksi.js` (the Worker in front of `*.run.app`), `pos:siapkan`,
  `POST /_jadwal`. The VPS runbook `docs/deploy-pos-aishiierp.md` is DEFERRED.
- Upstream CI runs SQLite only. Before a PR that touches queries or
  migrations, run the suite on MySQL too: TiDB v8.5.3 is 479/514 (5 Oct,
  after stage 1c; MariaDB 10.11 was 462/493 at stage 1a) — every failure is
  in `tests/Feature/BanyakToko`, whose harness is SQLite-only. A page makes
  84–159 queries, so production keeps the database in the same city as
  Cloud Run.
- Uploaded files go through the `public` disk ONLY (`App\Support\BerkasPublik`
  for URLs and PDF data URIs). `POS_BERKAS=r2` points that disk at R2; R2
  rejects ACL `public-read`, so the disk stays `visibility: private`.

**Branch structure:**
- `main` — production. Protected. PR only from `development`.
- `development` — integration branch. Feature branches merge here via PR.
- `release/*` — release candidates. Created from `development`, merged to `main` + tagged.
- `revamp-frontend` — legacy UI overhaul branch (inactive).
- `feature/*` — individual feature work. Branch from `development`, PR to `development`.
- `fix/*` — hotfixes. Branch from `main`, PR to `main` + `development`.

**Tags follow semver:** `v1.0.0`, `v2.1.0`, etc.

## Stack

- **Backend**: Laravel 13 (composer.json requires PHP ^8.3; CI tests on PHP 8.4)
- **Frontend**: Inertia.js 3.0 + React 19, Vite 5
- **CI**: `.github/workflows/deploy.yml` uses PHP 8.4 + Node 22 for build (its VPS deploy job is gated off; production builds from `Dockerfile` in Cloud Build — PHP 8.4 + Apache, Node 22 for Vite)
- **Styling**: Tailwind CSS 3 (custom theme in `tailwind.config.js`)
- **Auth/RBAC**: Spatie Laravel Permission + Laravel Breeze
- **REST API**: Sanctum token-based at `/api/v1`; Scramble docs at `/docs/api`, spec at `/docs/api.json`; protect with `SCRAMBLE_DOCS_TOKEN`
- **DB**: MySQL (default); SQLite in-memory for tests
- **i18n**: react-i18next; locales in `resources/js/i18n/locales`; `SetLocale` middleware on web group
- **Payment gateways**: Midtrans, Xendit (webhooks in `routes/api.php`)
- **WhatsApp**: whatsapp-web.js via separate Node service (`whatsapp-service/`, port 3001)

## CI / Deploy

- **CI validates and tests** — `.github/workflows/deploy.yml` validates Composer, runs `npm run build`, and executes `php artisan test --compact` with PHP 8.4, Node 22, and SQLite. Run `php artisan test` locally before every PR.
- **(VPS fallback, deferred) Push to `main` deploys to `pos.aishiierp.com` only when the repository variable `POS_DEPLOY` is `aktif`** (secrets `VPS_HOST`/`VPS_USER`/`VPS_SSH_KEY`). Until the owner sets it, `main` only runs CI. Never push directly to `main`.
- The deploy refuses a server whose `.env` lacks `POS_MULTI_TOKO=true`, then runs `pusat:migrasi --force` → `pusat:wilayah` → `toko:migrasi --force` (never plain `migrate`) and ends with `bash scripts/uji-asap-pos.sh $POS_URL`, which checks things only a ready multi-store server answers (`/daftar`, `/api/harga`, `X-Toko` on the API, CORS for Aishii's `/pos`).
- npm is the package manager of record (`package-lock.json` committed, `bun.lock` gitignored). CI/deploy run `npm ci`. Don't switch to bun/yarn lockfiles.

## Developer Commands

```bash
# Initial setup
cp .env.example .env
composer install && PUPPETEER_SKIP_DOWNLOAD=true npm install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
# After the server starts, open the root URL; first install automatically redirects to /setup.

# Dev — runs server, queue, logs (pail), and vite in one command
composer run dev     # equivalent to `php artisan dev` (Laravel 13 DevCommand)

# Testing
php artisan test                     # all
php artisan test --filter=FooTest    # one class
php artisan test --filter=test_name  # one method

# WhatsApp Service (separate terminal)
cd whatsapp-service
npm install && npm start             # port 3001

# PM2 for production
pm2 start whatsapp-service/server.js --name wa-service

# Artisan commands
php artisan inventory:reconcile           # report global vs pivot stock mismatch
  php artisan inventory:reconcile --fix     # align global stock to pivot sum
  php artisan outlet:audit                  # read-only outlet/warehouse data audit
  php artisan outlet:audit --strict         # read-only audit with rollout failure status
  php artisan outlet:legacy-audit           # classify warehouse-less records; no mutation
php artisan reorder:generate              # generate draft PO from low-stock products (daily 02:00)
php artisan crm:sync-segments             # refresh auto segment memberships (daily 01:00)
php artisan crm:generate-reminders       # queue campaign reminder messages (daily 01:15)
php artisan scramble:cache               # warm Scramble OpenAPI cache
php artisan scramble:clear               # invalidate Scramble OpenAPI cache
php artisan seed:demo                    # regenerate full demo dataset (truncates demo tables; --force skips confirm)
php artisan db:seed --class=DemoSeeder --force # explicit full demo dataset (never use on production)

# Formatting
vendor/bin/pint

# Production build
PUPPETEER_SKIP_DOWNLOAD=true npm run build   # CI/deploy skip Puppeteer's Chromium download
```

Production must trigger `php artisan schedule:run` every minute for the scheduled CRM and reorder commands.

## Architecture

- **Controllers**: `app/Http/Controllers/Apps/` — per-module web controllers (~35)
- **API Controllers**: `app/Http/Controllers/Api/` — REST API (Sanctum token auth)
- **Services**: `app/Services/` — ~22 services: AuditLog, BatchService, CashierShiftService, DineOrderService, GoodsReceivingService, LoyaltyService, PaymentGatewayManager, PricingService, PriceListService, PurchaseOrderService, ReorderService, StockMutationService, StockTransferService, UnitConversionService, WhatsAppService, etc.
- **Layouts**: `POSLayout.jsx` (POS), `DashboardLayout.jsx` (admin + profile), `GuestLayout.jsx` (reset password, shared receipt), `PublicLayout.jsx` (public marketing pages); `AuthenticatedLayout.jsx` is no longer used
- **Routes**: `routes/web.php` (~50+ dashboard routes), `routes/api.php` (webhooks + REST API), `routes/auth.php` (Breeze)
- **Inertia shared props**: `HandleInertiaRequests.php` — auth, permissions, notifications (low stock, receivables, payables aging), active shift, store profile, appVersion

## Middleware

| Alias | Class | Applied to |
|-------|-------|------------|
| `permission` | Spatie PermissionMiddleware | Every dashboard route |
| `step_up` | EnsureRecentPasswordConfirmation | Sensitive create/update/delete: roles, users, payment settings, bank accounts, payment confirm |
| `active_shift` | EnsureActiveCashierShift | All POS transaction actions (cart CRUD, hold/resume, checkout) |
| `bot.guard` | EnsureBotGuard | Login/register/forgot-password (honeypot + timer) |
| `registration.enabled` | EnsurePublicRegistrationEnabled | Register route (default: off) |
| `abilities` | CheckAbilities (Sanctum) | API master-data resources (`{module}-*`) and `/pos/*` (`pos-access`); `/auth/*` is auth-only |

## Seeder Chain & First-Install Setup

`DatabaseSeeder` runs only system-essential seeders with permission cache reset before & after:

```
PermissionSeeder → RoleSeeder → PaymentSettingSeeder → DineInSettingsSeeder
```

After seeding, a default `PUSAT` warehouse is created and existing product stock is migrated to the `product_warehouse` pivot.

**No default users.** Admin account, store profile, business type, categories, and main warehouse are created via the first-install setup wizard at `/setup`. Open the root URL after migration; it automatically redirects to `/setup` while `Setting::app_setup_completed` is false. The `setup.notinstalled` middleware redirects to login once setup is done.

**Demo data is opt-in, not part of `DatabaseSeeder`:** run `php artisan db:seed --class=DemoSeeder --force` (or `php artisan seed:demo --force`) for the complete demo dataset. It creates demo outlets `MAL`, `TKB`, and `PUT`; `PUSAT` remains a central non-sales warehouse. Demo accounts (password `password`): `arya@gmail.com` (super-admin, all outlets), `manager@gmail.com` (manager role, MAL+TKB), `cashier@gmail.com` (cashier, MAL). Never run the demo seeder on production. Full dataset details: `docs/demo-data.md`.

**Email verification is disabled** — `User` no longer implements `MustVerifyEmail`, dashboard routes carry no `verified` middleware, and the verification routes/controllers/pages are removed. `markEmailAsVerified()` is still available via the retained trait (used by seeders and tests).

## Multi-store mode (Aishii POS, `POS_MULTI_TOKO=true`)

`false` (default) is exactly upstream: one install, one store. `true` turns
`DB_*` into the CENTRAL database (stores, directory of emails, invoices,
sessions, cache, Indonesian regions) and gives every store its own database
`POS_AWALAN_DB` + store number, created at `/daftar`. Upstream's 44 modules are
untouched — isolation comes from the connection, not from a `toko_id` column.

- **The store is resolved per request**: session (login looks the email up in
  the central directory), path `/t/{toko}` for public customer links, header
  `X-Toko` for the API (missing/unknown → 400). `App\Penyewaan\Penyewaan::masuk()`
  switches the default connection, Spatie's permission cache key, and
  `URL::defaults`; middleware `terminate()` switches back.
- **Plain `migrate` / `db:seed` without `--database` are refused** (they would
  write store tables into the central DB). Use `pusat:migrasi`, `pusat:wilayah`
  (regions — upstream's `laravolt:indonesia:seed` is refused for the same
  reason, AS16), `toko:migrasi`, and `toko:jalankan "<command>"` (the scheduler
  wraps per-store commands with it). The guard is a `CommandStarting` listener,
  which does NOT fire under PHPUnit; a `MigrationsStarted` guard covers tests.
- **Subscription lock** (`KunciLangganan`): an unpaid or expired store can READ
  everything but every non-GET is refused, except the narrow `RUTE_BOLEH`
  whitelist (paying, logging in/out, own account). A new write route that must
  work while locked is added there on purpose, never by loosening the method rule.
  Offline sales recorded before expiry are still accepted; later ones come back
  `held`, in the original order (the cashier matches results by index).
- **Invoices never self-pick a unique code.** Codes come from the shared invoice
  book in Aishii's Supabase (`BukuTagihanAishii`, migration `20261153` there);
  when it is unreachable the invoice is a ROUND amount confirmed manually.
- **Offline cashier queue syncs over the session** (`transactions.sync-offline`);
  upstream sent it to the token API, which always answered 401. IndexedDB and
  the service-worker cache are per store (`pos-offline-t<id>`).
- **"Masuk dengan akun Aishii" (AU1, document 27 in the Aishii repo)**: the
  store owner signs in with the one Aishii account (Supabase OAuth 2.1 Server;
  POS is a CONFIDENTIAL OIDC client — `config/akun_aishii.php`,
  `App\AkunAishii\KlienAishii`, `MasukAishiiController`). Off while
  `AISHII_OIDC_ID_KLIEN`/`AISHII_OIDC_RAHASIA_KLIEN` are empty. The central
  directory row carries `aishii_sub`; a linked account can NOT sign in (web or
  API) or confirm `step_up` with a POS password (decision D2) — step-up goes
  through `{AISHII_URL}/masuk-ulang` and the callback checks `auth_time`.
  Cashiers stay local password accounts (D1b). New stores at `/daftar` are
  born from an Aishii identity (email from the verified token, random password).
  ID tokens are verified ES256-only against the JWKS, never with a shared
  secret; tests use a generated key (`tests/Feature/BanyakToko/MasukAishiiTest.php`).
  The token's `name` claim is the EMAIL for every Aishii account that signed
  up with email (Aishii stores no name). Never offer it as a person's name:
  the owner's name becomes the cashier name printed on every receipt
  ("Kasir: …"). `DaftarController::namaLayak` drops email-shaped names (AV9).
- **Service admin with the Aishii account (AU5, owner's decision 7 Oct —
  reverses D4)**: while AU1 is on, `/pengelola/masuk` shows only "Masuk dengan
  akun Aishii" (`/auth/aishii/pengelola`) and the password POST is refused for
  everyone. WHO is an admin stays POS's own list — rows in `pengelola`, born
  from `POS_PENGELOLA_SUREL` via `pengelola:buat --akun-aishii` (create-only,
  random unknown password; never overwrites a row, it runs on every boot) —
  never Aishii Bazar's `admin_platform`. First sign-in locks the row to the
  token `sub` through a VERIFIED email; afterwards `sub` decides. Both doors
  share the ONE registered callback `/auth/aishii/kembali`; the session
  `bekal.tujuan` picks the door, so `/kembali` has no `guest` middleware (the
  check moved into the controller). Emergency door: empty the OIDC secret and
  set `POS_PENGELOLA_SANDI` — the password path works again.
- **Store sign-up is STEP-WISE (AU6, document 28 in the Aishii repo).** One
  registration = 1,313 SQL statements incl. 427 DDL (measured 7 Oct): 2.3 s on
  MariaDB, 26 s on local TiDB, and in production on TiDB Serverless the single
  request never finished (Cloudflare cuts unanswered requests at 100 s; Cloud
  Run throttles CPU afterwards). `POST /daftar` now only creates the `toko` row
  (`PendaftaranToko::mulai`); the progress page `/daftar/menyiapkan` calls
  `POST /daftar/lanjut` repeatedly, each call working at most
  `penyewaan.anggaran_langkah_detik` (20 s) — `buat`, ONE migration file at a
  time (`PenyediaBasisData::migrasiSatuBerkas`, the same Migrator as
  `migrate`), `tanam` (seeder in ONE transaction), `setup` (SetupService,
  then `siap`, then login). One lock per owner email (cache) serialises tabs
  and resubmissions. A failed step marks the store `gagal`; "Ulangi dari
  awal" drops and recreates its DB (never for `siap` stores —
  `email_pemilik` does not follow email changes). Resubmitting `/daftar` for
  an unfinished store restarts THAT store, never a second one. `daftarkan()`
  (all at once) stays for tests and `toko:buat`. The session keeps the form
  with the password ENCRYPTED (SetupService hashes it itself). Log line
  "Toko siap" carries the real duration. Commands that loop over stores use
  `PilihToko` (only `siap`), so unfinished stores never block `pos:siapkan`.
  Playwright cannot intercept requests the POS service worker handles —
  browser tests that route `/daftar/lanjut` need `serviceWorkers: 'block'`.
- **Unnamed `throttle:N,M` share ONE counter** per visitor IP (guest) or per
  user id (`ThrottleRequests::resolveRequestSignature`, no route in the key).
  Measured 7 Oct: three Aishii round trips used up `/daftar`'s 5 per 10 min →
  429. The `auth/aishii` group therefore uses its own prefix
  (`throttle:20,1,aishii`); give any new limiter a prefix too.
- **Tests**: extend `Tests\BanyakTokoTestCase` (sets the env BEFORE the app
  boots, since `/t/{toko}` routes are shaped at boot). Proof scripts:
  `scripts/uji-luring-per-toko.mjs` (offline queue, real Chromium) and
  `BANYAK_TOKO=1 node scripts/audit-ponsel.mjs`.

## Inventory Model

`product_warehouse.stock` is the operational source of truth. `products.stock` is maintained as a global aggregate — both must be updated in the same DB transaction for every mutation. Always prefer locking `product_warehouse` rows with `lockForUpdate()` before decrementing. Use `inventory:reconcile --fix` to align global stock after data repairs.

**Initial stock of a new product** goes to the request's `warehouse_id`;
without it, to the warehouse of the store's ONLY selling outlet (when the user
may use it), else to the first active `main` warehouse. The cashier searches
the open shift's warehouse and shifts open only at selling outlets, so in a
one-outlet store — what Aishii POS sign-up creates: a non-selling PUSAT plus
"Toko Utama" — stock parked in PUSAT never reached the cashier (AV3, 8 Oct).
Stores with several selling outlets keep the central warehouse + transfers.

## Critical Gotchas

1. **Permission cache stale after seed** — logout + login again. Seeder resets cache but session still holds old permissions.
2. **Webhooks need public APP_URL** — Midtrans/Xendit won't work with localhost.
3. **Product images need storage:link** — `php artisan storage:link` or images won't render.
4. **Missing migrations cause 500 on new modules** — run `php artisan migrate` for newer modules (purchase orders, goods receiving, supplier returns, stock opname, dine-in, etc.).
5. **Tests force SQLite in-memory** — `phpunit.xml` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Don't assume MySQL features. **Set `tax_rate=0` on test Product::create** to avoid PPN changing grand_total.
6. **Dev servers unified** — `composer run dev` starts server + queue + pail + vite together (`php artisan dev`). WA service stays separate.
7. **WhatsApp service separate** — `whatsapp-service/` needs `npm start` in another terminal + `WA_SERVICE_URL` in .env.
8. **CRM campaign auto-send** — requires `wa_enabled=true` + connected device in Settings > WhatsApp.
9. **Version bump on release** — update `APP_VERSION` in `.env` + `.env.example` when tagging.
10. **Concurrency patterns** — all stock mutations (checkout, transfer, receiving, payment) are wrapped in `DB::transaction` with `lockForUpdate()` on affected rows. Never skip the transaction or lock.
11. **Dine-in online payment is disabled** — `payment_option=pay_online` returns 422. Only `pay_at_counter` is accepted.

## Release Process

1. `development` accumulates features → branch `release/X.Y.Z`
2. QA/fix on `release/X.Y.Z` → merge to `main`
3. Tag: `git tag -a vX.Y.Z -m "vX.Y.Z"` on `main`
4. Merge `release/X.Y.Z` back to `development`
5. GitHub Release created from tag

## Frontend

- **Icons**: `@tabler/icons-react`
- **Alerts/confirm**: `react-hot-toast` + `sweetalert2`
- **Charts**: `chart.js`
- **Routing**: Ziggy `route()` helper available
- **Offline mode**: `resources/js/Utils/offlineDb.js` (IndexedDB via `idb`) queues transactions when offline, flushes on reconnect; idempotent via `client_uuid` — server price wins
- **ESC/POS printing**: `resources/js/Utils/escpos.js` (WebUSB, Chromium-only; fallback `window.print()`)
- **Tailwind tokens**: `primary` (Aishii purple, #752e8e scale), `accent` (violet), `success` (emerald), `warning` (amber), `danger` (rose)
- **i18n**: Indonesian (`id.json`) and English (`en.json`) in `resources/js/i18n/locales`

## Docs

- Modules: `docs/features/`
- Architecture: `docs/architecture-overview.md`
- Config: `docs/configuration.md`
- Feature index: `docs/feature-index.md`
- Manual QA checklist (QRIS, ESC/POS, offline sync): `docs/testing-manual.md`

## Test Conventions

- Use `RefreshDatabase` trait on every test class
- Seed: `PermissionSeeder → RoleSeeder → UserSeeder` before every test
- Admin: `arya@gmail.com` (super-admin, all permissions); manager: `manager@gmail.com`; cashier: `cashier@gmail.com`
- **Always call `markEmailAsVerified()`** before `actingAs()` for HTTP controller tests (email verification is disabled, but the trait method remains available)
- `PUSAT` warehouse: `type='main'`, `is_active=true`, `sort_order=0`
- Product needs: `image`, `barcode`, `sku`, `title`, `description`, `category_id`, `buy_price`, `sell_price`, `stock`, `tax_rate=0`
- Attach warehouse stock: `$warehouse->products()->attach($product->id, ['stock' => N])` or `$product->warehouses()->attach($warehouse->id, ['stock' => N])`
- Open shift: `app(CashierShiftService::class)->openShift($cashier, $cashier, $openingCash, null, $warehouse->id)`
- **PHPUnit 12: no `$faker` property** — use `static int $seq = 0` counters or `uniqid()` for unique values
- **Tests that mimic a form must send what the form sends.** Inertia posts JSON
  when no file is attached, empty arrays included; `post()` form-encodes and
  DROPS empty arrays, which is how `components: []` failing every plain
  product without an image went unseen (AV2). Use
  `json('POST', $uri, $data, ['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'])`.
- **A sale needs a customer only when it is pay-later** — server, cashier
  button, and receipt ("Umum") agree since AV4. A new store has no customers.
- For API tests: `Sanctum::actingAs($user, ['*'])` — explicit abilities required; TransientToken does not bypass `abilities` middleware

## API Ability System

Master-data API routes (`/api/v1/products`, `/customers`, `/categories`, `/warehouses`, `/suppliers`) enforce Sanctum abilities matching Spatie permission names:

| Route | Ability |
|-------|---------|
| index, show | `{module}-access` |
| store | `{module}-create` |
| update | `{module}-edit` (products/customers/categories) or `{module}-update` (warehouses) |
| destroy | `{module}-delete` |
| suppliers (all verbs) | `suppliers-access` |
| `/api/v1/pos/*` (all verbs) | `pos-access` |

`/api/v1/auth/*` is auth-only (no abilities). Token abilities are stamped at login from Spatie permissions + `'user:read'`. Public registration creates a token with only `'user:read'` (so it cannot reach `/pos/*`). The `pos-access` permission is granted to `cashier`, `manager`, and `super-admin` roles by the seeders. Existing mobile tokens must re-login after this change to receive `pos-access`.

API tests must use `Sanctum::actingAs($user, ['*'])` or real tokens via `$user->createToken('test', $abilities)->plainTextToken`.

## Route Naming Gotchas

- Price list sidebar link: `price-lists.index` (NOT `settings.price-lists.index`)
- Profile URL: `/dashboard/profile` (NOT `/apps/profile`)
- Public invoice: `/share/transactions/{invoice}?token={access_token}`
