# CLAUDE.md — VMS-backend-laravel

Guidance for Claude Code when working in this repository. Container-level context (the legacy system,
the hybrid multi-yard program, Azure) lives in `../CLAUDE.md` (`D:\INIT-ASL\VMS\CLAUDE.md`), and the
suite-wide conventions in `D:\INIT-ASL\CLAUDE.md`. **Owner decisions for the hybrid program are
authoritative in `D:\INIT-ASL\docs\decisions\vms-hybrid-decisions.md`.**

## What this repo is

The VMS gate API: Laravel 13 · PHP 8.4 · PostgreSQL 18, auth by **Sanctum + spatie-permission** (Bearer
tokens, not Passport). It runs offline on one Windows PC per yard; the React SPA
(`VMS-web-react`) is built into `public/` and served by the SPA fallback route.

| Path | What |
|---|---|
| `tools/windows/` | Windows server package: Apache/PHP config, `setup-ca.ps1`, `build-release.ps1`, `deploy.ps1`, backup task, `export-vms-old.ps1`, cutover rehearsal and SLA audit, `INSTALL-GUIDE.md`. Moved into the repo 2026-09-28 (B-2). |
| `docs/cutover-runbook.md` | Night-cutover runbook (needs revising for two targets, ASL and CIS — PE-8). |
| `.github/workflows/quality.yml` | Audit, Pint, tests on SQLite **and** on PostgreSQL 18 (job `postgres`). |
| `.github/workflows/deploy.yml` | Former Azure deploy of the gate — **disabled 2026-09-28** (step 3); kept in the tree until the on-prem workflow replaces it. |

## Git

**Never `git add`, `commit`, `push` or `rm`** — the owner does git. Show `git status --short` before handing off.
`deploy.yml` (Azure) was disabled on 2026-09-28 and must not be re-enabled: `as-api-vms-bt-apps` now belongs to
the Cloud Hub (decision B-3).

## How to write code here

- **Nothing may need the internet at runtime** — no CDN, no external font, no outbound API, no
  cloud-only service. Mail is `log`, the queue is `sync`, and files stay on local disk.
- **Trusted proxies come from `TRUSTED_PROXIES`** (`config/trustedproxy.php`): empty on the LAN
  server, `*` on Azure. In Laravel 13.30 `*` trusts every address (`0.0.0.0/0`, `::/0`), so
  `$request->ip()` is the leftmost `X-Forwarded-For` entry. The LAN server is unaffected, because
  Apache sees the counter PCs directly.
- **Visits are open while `checked_out_at IS NULL`** — use `Visit::open()`, never
  `whereNull('time_out')`. `time_out` is still written for older readers but cannot express a
  checkout on a later day.
- **Identity numbers are stored normalized** (`App\Support\IdentityNumber`: upper case, no spaces
  or dashes). Eloquent writes normalize through the model mutator; query-builder bulk writes must
  call `IdentityNumber::normalize()` themselves. Hybrid tables use exactly the same rule (BE-1).
- **`visitor_no` below 1,000,000 is reserved for VMS-old's `VisitorID`**; the application numbers
  new visitors from `config('vms.visitor_no_floor')` upward.
- **Create visitors and visits inside a database transaction.** `visitor_no` and `ticket_number`
  are allocated under a PostgreSQL advisory lock (`App\Support\SequenceLock`) that lasts until
  commit. `SequenceLock` throws outside a transaction only on PostgreSQL, and never inside a Feature
  test, which `RefreshDatabase` already wraps in a transaction, so no test catches a missing
  transaction: give each new creating path a `pgsql` test that runs outside that wrapper. Retry a
  failed insert only when `App\Support\UniqueViolation::isRetryable()` says so: a lost numbering
  race, never an identity collision.
- **Take the ticket lock before any read that decides a check-in write.** `VisitController::store`
  calls `SequenceLock::acquire('ticket_number')` right after `firstOrCreate` and before it checks open
  visits and card status. Keep the lock order `visitor_no` before `ticket_number`; the opposite order
  makes check-ins queue behind a visitor import and can deadlock two new-visitor check-ins.
- **Ticket numbers restart every month** (`App\Support\TicketNumber`, `YY/MM/NNNNN`).
- **A 500 response never carries the exception's message**, which can hold SQL with visitor data:
  a `catch` calls `report($e)` and answers a fixed message.
- **Visits and visitors leave the API through resources** (`VisitResource`, `VisitorSummaryResource`,
  and `VisitorProfileResource` for the profile screen only), never as raw models; `checked_out_at`
  goes out as local time (`Y-m-d H:i:s`), not UTC.
- **Code that creates visitors and visits runs through `App\Support\NumberingRetry::run()`**.
- **Master lists paginate and filter on the server** (`/locations`, `/staffs`, `/cards`, `/visitors`;
  `per_page` up to 100); boolean filters take `1`, `0`, `true` or `false`. **Card status is set only by
  check-in and check-out**; a card in use cannot be deleted.
- **A place's code comes from its name when the place is created** (`Location::codeFromName()`) and
  never changes; a vessel's code is its IMO number, required, unique and correctable.
- **Roles and permissions live on the `web` guard** (`Role::$guard_name`), even though requests authenticate
  through Sanctum — spatie would otherwise create a role on the request's `sanctum` guard and fail to attach
  the seeded permissions (500). The three `Role::BUILT_IN` roles are refused by `PUT`/`DELETE /api/roles`
  (422, decision FE-3-ROLE) because the seeder re-syncs them.
- **Usernames are stored upper-cased** (`App\Support\Username`); `POST /api/login` is rate limited
  (`throttle:login`: 5 a minute per login and address, 20 per address).
- **Health checks must touch the database** (`/api/health-db`), never just `/up`. No `throttle` in
  front of it (`CACHE_STORE=database` would turn a dead database into a 500), and it is exempt from
  maintenance mode because `deploy.ps1` probes it between `artisan down` and `artisan up`.
- **Migrations stay additive** until the hybrid roadmap retires the Azure deployment.
- **SQL must run on PostgreSQL, not just SQLite.** Tests default to SQLite in memory, which forgives
  things PostgreSQL rejects: a double-quoted unknown identifier becomes a string literal, `substr()`
  accepts a `date`. `vms:import-verify` shipped broken on PostgreSQL that way until 2026-09-28. Use
  driver-aware expressions (`DB::connection()->getDriverName()`), and run the PostgreSQL suite.

## Tests

- SQLite (default): `php artisan test`.
- PostgreSQL 18: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=vms_test DB_USERNAME=postgres
  DB_PASSWORD=<local> php artisan test`. Tests that need PostgreSQL call `requirePostgres()` and carry
  `->group('pgsql')`. CI runs both (`quality.yml`).
- The Windows package is checked by `tests/Feature/Deployment/*`: string checks plus a PowerShell
  **parse** of every `tools/windows/*.ps1` through `Tests\Support\PowerShell` (`pwsh`, else
  `powershell`). `REQUIRE_POWERSHELL=1` (set in CI) turns a missing PowerShell into a failure instead
  of a skip. Nothing in the suite executes the scripts.
- CI has no `.env`; `quality.yml` generates a throwaway `APP_KEY` per run. Without it the SPA route
  tests throw `MissingAppKeyException`.

## Traps

- Partial-update `PUT` endpoints have shipped blanking bugs across this ecosystem. Use
  `array_key_exists`, not `isset` — an explicit `null` means "reset to default" and must stay
  distinguishable from "not sent".
- **A failed login logs the identifier only when it names an existing account**
  (`AuthController::login`). People type their password into the login field by mistake, and
  `activity_logs` must never keep that text.
- **Non-GET requests to unknown paths answer 405, not 404.** The SPA's `Route::fallback` is
  GET-only but matches every URI, so Laravel reports the method as not allowed.
- **Hybrid mirror tables (roadmap step 5, 2026-09-28)**: `pre_registrations`, `global_blacklists`,
  `global_blacklist_outbox`, `sync_state_logs` (migrations `2026_09_28_00000{1-4}`) hold copies only —
  no `yard_companies`, no secret, no `version` column (PE-2, PE-3). Look global entries up with
  `GlobalBlacklist::activeForNumber()`, which matches the number across document types (SEC-4); only a
  `BlacklistReasonCategory` goes to the hub, `local_note` never does (SEC-6d). The 2026-09-26 draft these
  replace is archived outside the repo in `../.superpowers/sdd/archive/phase1-2026-09-26/`.
- **`tests/Fixtures/identity-number-vectors.json` is shared with the cloud hub** (BE-1): the hub repo keeps
  a byte-identical copy; change both together.
- Legacy VMS-old column names lie: `VehicleNo` = location IMO code, `Note` = vehicle plate,
  `VisitorAddress` = nationality; the legacy blacklist is `VisitorType = 5`.
