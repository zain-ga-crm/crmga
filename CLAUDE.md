# CLAUDE.md — crmga CRM (Laravel rebuild)

## What this is
A **multi-tenant SaaS CRM** (Laravel + Filament) **sold to other immigration firms**, rebuilt from the
Gunness & Associates SuiteCRM 8.8.0 system ("crmga"). **Each customer company = one tenant = its own
database.** First tenant live = Gunness & Associates. This repo currently holds the **plan + specs** in
`/docs`; the Laravel app gets scaffolded starting in Phase 1.
- **Master plan (timelines + who-does-what):** `docs/PROJECT_PLAN.md`
- **Start here to build:** `docs/PHASE1_KICKOFF.md`

## Stack (pin these)
- PHP 8.3, Laravel 11
- **Filament v3** — staff CRM UI
- **stancl/tenancy** — database-per-tenant (central DB = tenant registry/auth; **tenant DB = all CRM data**)
- **spatie/laravel-permission** — RBAC (29 roles, see `docs/reference/roles.php`)
- **Laravel Passport** — OAuth2 `client_credentials` for the V8-compatible API
- **Horizon + Redis** — queues & scheduled jobs (replace SuiteCRM schedulers)
- MySQL/MariaDB; FrankenPHP or Octane for performance
- Tests **Pest** · Format **Pint** · Static analysis **Larastan/PHPStan**

## Golden rules
- **DB-per-tenant.** Tenant CRM data lives in the tenant DB — never mix tenants. Anything not central is tenant-scoped.
- **Never commit secrets.** `.env` only (`.env.example` is committed). No live credentials in this repo, ever — the source system's credentials are deliberately excluded.
- **Small PRs** — one entity/feature per PR. Start each phase in **Plan Mode**; run **`/code-review`** before merge, **`/security-review`** before go-live.
- **Guardrail before every commit:** `./vendor/bin/pint` → `./vendor/bin/phpstan analyse` → `./vendor/bin/pest`.
- Work against a **copy** of prod data (the sanitized dump), never the live DB.

## SuiteCRM behaviours we MUST preserve (learned the hard way)
1. **Datetimes → always `Y-m-d H:i:s` in UTC.** The old V8 API silently blanked mismatched datetimes; our V8-compatible API must normalise every inbound datetime to this.
2. **Meta-Ads value canonicalisation** — incoming dropdown values are lowercased/underscored/punctuated and carry invisible Unicode marks. Canonicalise both the value and each enum key (`strtolower` + strip non-alphanumerics) before matching; unmatched → `null`.
3. **Label convention** — dropdown labels capitalise only the first character (`follow_up` → "Follow up"). Do NOT Title-Case.

## Data model (read before writing migrations)
- `docs/DATA_MODEL.md` — target entities + how the 43 GA modules consolidate.
- `docs/reference/field-map.json` — per-entity source tables + columns (from the live DDL).
- `docs/reference/schema.json` — full parsed DDL of all 481 tables (columns + indexes).
- A shared **Contactable base** (first_name/last_name, phones, addresses, `do_not_call`, consent fields) is reused by every lead entity.
- **Activities are polymorphic** (Meeting/Note/Document/Email/Call/Task morph to any record) — do NOT recreate the 154 SuiteCRM `_c` link tables.
- **Email** = related `EmailAddress` (morph) + a denormalized `primary_email`; it is not a base column.

## Integrations — kept EXTERNAL for v1 (we integrate, not rebuild)
- **n8n** (133 workflows): stays running. The app exposes a **SuiteCRM-V8-compatible JSON:API** (`/Api/V8/module/{m}`, `/meta/modules`, `/meta/fields`) + OAuth2 `client_credentials`, so each workflow only re-points its base URL.
- **Asterisk** click-to-call, **Vapi** voice, **dt_sms** SMS, **email** (SMTP/IMAP) — integrate via API + webhooks.

## Commands (once the app is scaffolded)
- Install: `composer install && npm install`
- Create tenant: `php artisan tenant:create <name>` · Migrate tenants: `php artisan tenants:migrate`
- Test / format / analyse: `./vendor/bin/pest` · `./vendor/bin/pint` · `./vendor/bin/phpstan analyse`

## Where to start
`docs/PHASE1_KICKOFF.md` — copy-paste Claude Code prompts + the per-entity checklist for Phases 1–2.
