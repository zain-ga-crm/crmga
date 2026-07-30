# CLAUDE.md — crmga CRM (Laravel rebuild)

## What this is
A CRM (Laravel + Filament) rebuilt from the Gunness & Associates SuiteCRM 8.8.0 system ("crmga"), which
will later be sold to other immigration firms as a multi-tenant SaaS.

**Build order (important):** the CRM is built and goes live **single-tenant** for Gunness & Associates
(Phases 1–7), then converted to **multi-tenant, database-per-tenant** SaaS in the **final phase (8)**.
So: **one database today; that same database becomes tenant #1 later.** This repo currently holds the
**plan + specs** in `/docs`; the Laravel app is scaffolded from Phase 1.

**Team:** **Zain — backend** · **Shahmeer — frontend**.
- **Master plan (timelines + phases):** `docs/PROJECT_PLAN.md`
- **Task-by-task assignments per developer:** `docs/TASK_BREAKDOWN.md`
- **Technical approach / architecture + open decisions:** `docs/ARCHITECTURE.md`
- **Studio · REST API · per-tenant RBAC design:** `docs/STUDIO_API_RBAC.md`
- **Start here to build:** `docs/PHASE1_KICKOFF.md`

## The core architectural rule
This app is a **metadata-driven engine**, not a set of hardcoded models. Per-tenant **metadata**
(`tenant_modules`, `tenant_fields`, `tenant_option_lists`, `tenant_layouts`, `tenant_relationships`)
generates the **schema** (via `SchemaManager` runtime DDL into `{table}_custom` sidecars), the **UI**
(`DynamicResource` + `FieldTypeRegistry` build Filament forms/tables), the **permissions** (auto-registered
per module), and the **REST API** (endpoints + OpenAPI per tenant). Build the engine before any entity —
anything hardcoded first gets rewritten twice.

## Stack (pin these)
- PHP 8.3, Laravel 11
- **Filament v3** — staff CRM UI
- **stancl/tenancy** — database-per-tenant, **added in Phase 8** (not installed before then)
- **spatie/laravel-permission** — RBAC (29 roles, see `docs/reference/roles.php`)
- **Laravel Passport** (OAuth2 `client_credentials`) + **Sanctum** (PATs) + API keys — for the REST API
- **Horizon + Redis** — queues & scheduled jobs (replace SuiteCRM schedulers)
- MySQL/MariaDB; FrankenPHP or Octane for performance
- Tests **Pest** · Format **Pint** · Static analysis **Larastan/PHPStan**

## Golden rules
- **Tenancy-ready from day one, single-tenant today.** All ten rules in `docs/PROJECT_PLAN.md` §3 are mandatory. The three that CI enforces:
  1. **Every CRM migration goes in `database/migrations/tenant/`** — never the default folder.
  2. **Never add a `tenant_id` column.** Isolation will be by database, not by row.
  3. **Per-company config lives in a `settings` table, never in `.env`** (SMTP, telephony, branding, business hours).
  Also: file access only via `Storage`; cache/queue keys via the shared helper; `SchemaManager` always targets the current default connection; `routes/central.php` stays a stub until Phase 8.
- **Never commit secrets.** `.env` only (`.env.example` is committed). No live credentials in this repo, ever — the source system's credentials are deliberately excluded.
- **Small PRs** — one entity/feature per PR. Start each phase in **Plan Mode**; run **`/code-review`** before merge, **`/security-review`** before go-live.
- **Guardrail before every commit:** `./vendor/bin/pint` → `./vendor/bin/phpstan analyse` → `./vendor/bin/pest`.
- Work against a **copy** of prod data (the sanitized dump), never the live DB.

## SuiteCRM behaviours we MUST preserve (learned the hard way)
1. **Datetimes → always `Y-m-d H:i:s` in UTC.** The old V8 API silently blanked mismatched datetimes because it parsed against the *authenticated API user's* locale. Our REST API must be **locale-independent**: normalise every inbound datetime to UTC at the boundary, never using the principal's preferences.
2. **Meta-Ads value canonicalisation** — incoming dropdown values are lowercased/underscored/punctuated and carry invisible Unicode marks. Canonicalise both the value and each enum key (`strtolower` + strip non-alphanumerics) before matching; unmatched → `null`.
3. **Label convention** — dropdown labels capitalise only the first character (`follow_up` → "Follow up"). Do NOT Title-Case.

## Data model (read before writing migrations)
- `docs/DATA_MODEL.md` — target entities + how the 43 GA modules consolidate.
- `docs/reference/field-map.json` — per-entity source tables + columns (from the live DDL).
- `docs/reference/schema.json` — full parsed DDL of all 481 tables (columns + indexes).
- A shared **Contactable base** (first_name/last_name, phones, addresses, `do_not_call`, consent fields) is reused by every lead entity.
- **Activities are polymorphic** (Meeting/Note/Document/Email/Call/Task morph to any record) — do NOT recreate the 154 SuiteCRM `_c` link tables.
- **Email** = related `EmailAddress` (morph) + a denormalized `primary_email`; it is not a base column.

## API & integrations
- **Modern RESTful API** `/api/v1/*` — versioned, metadata-driven (custom modules get endpoints automatically), OAuth2 + PATs + API keys with **scopes**, **OpenAPI 3.1 per tenant**, signed **outbound webhooks**. The REST API is the product; a **thin `/Api/V8/*` adapter ships in v1** purely as a bridge for the existing 133 n8n workflows, to be deleted after they are migrated. **Outbound webhooks are deferred** to post-launch.
- **Inbound integrations:** WordPress (plugin + form mappers), Meta Lead Ads/Instagram, WhatsApp Cloud, LinkedIn/TikTok/Google lead forms, generic signed `/ingest/{source}` — all via one **FieldMapper** (canonicalise → validate → dedupe → assign → events).
- **n8n** (133 workflows): kept running via a **thin `/Api/V8/*` legacy adapter** (in v1) so only their base URL changes. Rewriting them onto the clean REST API is post-launch work.
- **Asterisk** click-to-call (per-tenant AMI creds), **Vapi** voice, **SMS** (provider-agnostic adapter), **email** (per-tenant SMTP/IMAP) — integrate via API + webhooks.

## Roles & ACL (per tenant)
- **User types:** Super Admin (platform, central panel) · System Administrator (per tenant) · Regular User · optional API principal.
- **ACL matrix:** module × action (view/list/edit/delete/import/export/mass_update) × **access level `All | Owner | None`** (Group level and field-level ACL are deferred — the source system uses neither). Enforced by Policies + **global query scopes shared by UI and API**. Roles live in the **tenant DB** — every company defines its own. New Studio modules auto-register permissions (default deny).

## Commands (once the app is scaffolded)
- Install: `composer install && npm install`
- Create tenant: `php artisan tenant:create <name>` · Migrate tenants: `php artisan tenants:migrate`
- Test / format / analyse: `./vendor/bin/pest` · `./vendor/bin/pint` · `./vendor/bin/phpstan analyse`

## Where to start
`docs/PHASE1_KICKOFF.md` — copy-paste Claude Code prompts + the per-entity checklist for Phases 1–2.
