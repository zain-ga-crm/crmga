# crmga SaaS CRM — Technical Approach & Architecture

How every layer is actually built, mapped to the phases, plus the **open decisions to lock before we
start** (§5). Read with `PROJECT_PLAN.md` (timeline/ownership) and `DATA_MODEL.md` (entities).

> **Revised 2026-07-28.** The app is now a **metadata-driven engine**: per-tenant metadata generates the
> schema, UI, permissions and API. This enables **Studio per tenant**, a **modern RESTful API**
> (the SuiteCRM-V8-compatible API is dropped), and **per-tenant roles/ACL**.
> Full design of those three subsystems: **`docs/STUDIO_API_RBAC.md`**.

## 1. Stack (definitive)

| Layer | Tech | Role / why |
|---|---|---|
| Runtime | **PHP 8.3, Laravel 11** | core framework |
| App server | **FrankenPHP** (worker mode) or Octane+Swoole; Caddy/Nginx front | 3–10× throughput vs FPM |
| Staff UI | **Filament v3** (Livewire + Alpine + Tailwind) | CRM screens without a separate SPA |
| Multi-tenancy | **stancl/tenancy v3** | database-per-tenant, auto connection switching |
| **Metadata engine** | **custom** (metadata registry + `SchemaManager` + `FieldTypeRegistry` + `DynamicResource`) | powers **Studio**, dynamic UI, dynamic API — the core of the app |
| RBAC | **spatie/laravel-permission** + custom **ACL matrix** (module × action × access level) | per-tenant roles; access levels All/Owner/Group/None |
| API auth | **Laravel Passport** (OAuth2 `client_credentials`) + **Sanctum** (PATs) + API keys, with scopes | server-to-server, per-user, and simple integrations |
| API docs | **OpenAPI 3.1** (Scramble/L5-Swagger) + Swagger UI, generated **per tenant** | lets any external stack integrate without custom work |
| Webhooks | custom dispatcher (**HMAC-SHA256** signing, retries/backoff, delivery log) | outbound events to WordPress/n8n/Zapier/anything |
| Integrations | Meta Graph, WhatsApp Cloud, LinkedIn/TikTok/Google lead forms, **WordPress plugin** | inbound lead capture + two-way sync |
| DB | **MySQL 8 / MariaDB**, InnoDB, utf8mb4 | matches source; per-tenant DBs |
| Cache/queue/session | **Redis** | tenant-prefixed |
| Jobs/scheduler | **Horizon** + Laravel scheduler | replaces SuiteCRM schedulers |
| Realtime | **Laravel Reverb** (WebSockets) | click-to-call + live updates |
| Validation/DTO | **spatie/laravel-data**, Form Requests, PHP enums | dropdowns as enums |
| Audit | **owen-it/laravel-auditing** (polymorphic) | replaces 79 `_audit` tables |
| Mail intake | **webklex/php-imap** | per-tenant IMAP polling |
| Tests / quality | **Pest**, **Larastan/PHPStan (max)**, **Pint** | CI gates |
| CI/CD | **GitHub Actions** | lint → analyse → test → deploy |

## 2. How each concern is carried out (real mechanics)

**Auth & user types**
- Staff log in per tenant at their subdomain (`acme.crm.<domain>`); users live in the **tenant DB**; Filament session auth; **TOTP 2FA** (source had 2FA) + password policy.
- **Super Admin (platform)** = a separate Filament panel on the central domain, backed by the **central DB**: manages companies, **Studio governance per tenant**, plans, support. **System Administrator (per tenant)** = full admin inside their company (users, roles, Studio, settings). **Regular User** = access strictly per roles. Optional **API/portal principal** for integrations.
- **API** = OAuth2 `client_credentials` (Passport) + **Personal Access Tokens** (Sanctum) + **API keys**, all **tenant-scoped with scopes** (`leads:read`, `leads:write`, …), per-tenant rate limits and request logs.

**Studio (per-tenant customisation)** — see `STUDIO_API_RBAC.md` Part 1
- Per-tenant **metadata registry** (modules, fields, option lists, layouts, relationships, change log) drives everything.
- **SchemaManager** applies runtime DDL safely: validate → plan → snapshot → apply → audit → bump metadata cache. Custom fields land in a **`{table}_custom` sidecar** (mirrors SuiteCRM `_cstm`, so existing fields import 1:1) — real, indexable columns.
- **Why safe:** database-per-tenant means a DDL change touches exactly one company; never the platform.
- **Dynamic UI:** `DynamicResource` builds Filament form/table/infolist/filters from metadata; `FieldTypeRegistry` maps field type → component + cast + validation; compiled metadata cached per tenant with version bumps.
- **Governance (super admin, per tenant):** mode `disabled | request-only | self-serve`, limits (max fields/modules, allowed types), **change-request queue with DDL preview + approve/reject**, audit + **rollback**, and **blueprints** to push a standard config to new tenants.

**Multi-tenancy (DB-per-tenant)**
- **Central DB:** tenants, domains, super-admins, plans/settings. **Tenant DB:** all CRM data (§ DATA_MODEL).
- Identify tenant by **subdomain** (wildcard DNS + wildcard TLS). stancl bootstrappers switch DB, cache, filesystem, queue, and Redis prefix automatically per request/job.
- Provisioning: `php artisan tenant:create <company>` → creates DB, runs tenant migrations, seeds roles + first admin. Per-tenant settings (SMTP, telephony, branding, enabled verticals) stored in the tenant DB, secrets **encrypted** at rest.

**Frontend**
- **Filament v3** panels (server-driven Livewire — no Angular). Per entity: list/table (filters incl. **DNC toggle**, bulk actions), infolist (detail), form (create/edit), **relation managers** for the activity timeline (meetings/notes/docs/emails/calls/tasks).
- Dashboard **widgets** for Hot/Warm (aggregate across verticals), daily counts. **Global search**. Per-tenant **theme/branding** (logo, colors).
- **Click-to-call** = a Filament row/action that triggers the telephony flow (see Integrations).
- Public/marketing + self-serve signup = Blade+Tailwind (fast-follow).

**Backend**
- Eloquent models + **Policies** (authorization) + **Actions/Services** (business logic) + Form Requests / `laravel-data` (validation).
- **Enums** for every dropdown (label convention preserved); JSON casts for `vertical_attributes` and the 88-field assessment `scores`.
- **Polymorphic** activities + email addresses (`morphTo`/`morphedByMany`).
- **Events → Jobs (Horizon)** for async work (notifications, webhooks, calling, IMAP).

**Database**
- **char(36) UUID PKs** kept from the source (preserves FK integrity through ETL); new rows use UUIDv7. Soft deletes (`deleted` → `deleted_at`). **All datetimes UTC** (`Y-m-d H:i:s`).
- Shared base columns via a trait/macro; per-entity migrations under `database/migrations/tenant/`. Carry over the useful source indexes (email, phone, assigned_user, status, vertical) + add FKs where clean.

**API (modern RESTful — replaces the V8-compatible API)** — see `STUDIO_API_RBAC.md` Part 2
- Versioned `/api/v1/{resource}`, proper verbs, JSON envelope (`data`/`meta`/`links`/`errors`), RFC-7807 errors, ETag, idempotency keys, cursor pagination, `429` + rate-limit headers.
- **Endpoints are metadata-driven** — every module, including ones a tenant creates in Studio, automatically gets REST endpoints, validation and docs.
- Query language: `?filter[status]=new&filter[created_at][gte]=…&sort=-created_at&include=activities&fields[lead]=…&page[size]=50`.
- **OpenAPI 3.1 generated per tenant** (reflects their Studio fields) + Swagger UI → any external stack integrates without bespoke work.
- **Outbound webhooks:** tenant-configured subscriptions (`lead.created`, `call.completed`, `{custom_module}.updated`, …), **HMAC-signed**, retried with backoff, delivery log + replay.
- **Inbound:** WordPress plugin/form mappers, Meta Lead Ads + Instagram, WhatsApp Cloud, LinkedIn/TikTok/Google lead forms, and a generic signed `/api/v1/ingest/{source}` — all through one **FieldMapper** (canonicalise → validate → dedupe → create/update → assign → events), keeping the **Meta value canonicalisation** rules.
- **ACL is enforced identically in API and UI** (same policies + query scopes).
- ⚠️ **Consequence:** the 133 n8n workflows are **rewritten** against this API (Phase 6). A thin `/Api/V8/*` legacy adapter is available as cheap transition insurance — see `STUDIO_API_RBAC.md` §2.4.

**Jobs / scheduling**
- Horizon + Redis. Laravel scheduler runs: daily lead/student count notifications, IMAP polling, any follow-up cadences that move in-app. (n8n still owns most cadences.)

**Integrations (kept external, wired in)**
- **n8n:** unchanged; workflows re-point base URL to the new API per tenant.
- **Telephony (Asterisk AMI):** click-to-call → queued job issues an **AMI Originate** to the tenant's PBX (creds in encrypted tenant settings); call results land via **Vapi/AMI webhooks** → create `Call`/`CallSummary`, update the lead. (Browser→PBX socket.io model can be kept if preferred.)
- **Vapi:** inbound/outbound via webhooks (post-call handler controller). Stays orchestrated in n8n for v1.
- **SMS (`dt_sms`):** provider-agnostic `SmsMessage` + a driver interface (Twilio/etc.); inbound webhook logs messages, morphed to the lead.
- **Email:** per-tenant SMTP transport built at runtime from tenant settings; IMAP intake job → `Email` records; `EmailTemplate` models.

**Security / compliance**
- Per-tenant DB isolation; encrypted secrets; API rate-limiting; audit log; consent fields already in the base (`lawful_basis`); **PIPEDA/GDPR** posture (retention, access logging, region); credential rotation; least-privilege DB users.

**Observability / ops**
- JSON logs, Horizon dashboard, health checks, optional Sentry/Flare. Per-tenant DB backups; deploy runs `tenants:migrate`.

## 3. Concern × phase (where each is built)

| Concern | P1 | P2 | P3 | P4 | P5 | P6 | P7 |
|---|---|---|---|---|---|---|---|
| Stack/scaffold/CI | ● |  |  |  |  |  | ○ |
| Multi-tenancy | ● | ○ | ○ |  | ○ |  | ○ |
| Auth + 2FA | ● |  | ○ | ● (API) |  |  | ○ |
| RBAC (29 roles) | ○ |  | ● |  |  |  | ● |
| Database/migrations |  | ● | ○ |  | ○ |  |  |
| Backend models/logic |  | ● | ● | ● | ● | ○ | ○ |
| Frontend (Filament) |  | ○ | ● |  | ○ | ○ | ○ |
| V8 API |  |  |  | ● |  | ○ |  |
| Data migration (ETL) |  |  |  |  | ● |  | ○ |
| Integrations (n8n/tel/SMS/email) |  |  | ○ |  |  | ● | ○ |
| Security/perf/backup | ○ |  |  | ○ |  |  | ● |

● = primary  ○ = partial/supporting

## 4. What we deliberately DON'T carry over from SuiteCRM (v1)
- The **Angular SPA** and the classic-PHP dashlet engine → replaced by Filament.
- The **SuiteCRM-V8 JSON:API** → replaced by the modern REST API (optional thin adapter as a bridge).
- Modules not emphasised by the business: **Quotes/Invoices/Contracts/Products (aos_*)**, **Reports builder (aor_*)**, **Campaigns**, **Knowledge Base (aok_*)**, **Events (fp_*)**, **Projects (am_/project)**, **Google Maps (jjwg_*)**, **Surveys** → **out of v1 unless you flag them** (§5). *(Note: tenants can build simple versions of these themselves via Studio's Module Builder.)*
- 154 activity link tables + 79 audit tables → collapsed to polymorphic relations + one audit log.

**Now explicitly IN v1** (revised): **Studio** (fields, dropdowns, layouts, relationships, custom modules — per tenant, super-admin governed) · **per-tenant roles/ACL with access levels + user types** · **RESTful API + webhooks + social/WordPress integrations**.

## 5. OPEN DECISIONS TO LOCK BEFORE STARTING  ← work these first

**Blocking (needed for Phase 1 architecture):**
1. **Hosting / infra:** where does the SaaS run (your VPS? a cloud? Docker/K8s?), and the DB-per-tenant scaling + backup plan. *(Rec: Docker + FrankenPHP on a cloud VPS; nightly per-tenant `mysqldump`.)*
2. **Domain + DNS:** the tenant subdomain scheme + **wildcard DNS & TLS**, and the central/super-admin domain. *(Rec: `*.crm.<yourdomain>`; central `app.<yourdomain>`.)*
3. **Frontend = Filament (server-driven), no separate SPA** — confirm you're OK dropping SuiteCRM's Angular UI. *(Rec: yes — far faster to build, still modern.)*
4. **PK strategy = keep source char(36) UUIDs** (best for ETL integrity) — confirm. *(Rec: yes.)*

**RESOLVED by the 2026-07-28 revision:** ~~per-tenant custom fields~~ → **Studio is in v1** · ~~V8-compatible API~~ → **modern REST API** · **per-tenant roles/ACL + user types in v1**.

**Should-decide (shapes scope, needed by Phase 2–3):**
5. **Module scope:** are any of Quotes/Invoices/Products, Reports, Campaigns, Knowledge Base, Events, Projects needed **as built-ins** in v1? *(Rec: no — tenants can build simple versions in Studio; a real report builder is fast-follow.)*
6. **Studio governance default:** which mode do new tenants start in — `disabled` / `request-only` / `self-serve` — and what limits (max custom fields per module, max custom modules, may they create relationships/modules)? *(Rec: `request-only` for new tenants, `self-serve` with limits once trusted.)*
7. **Telephony model for SaaS:** one shared PBX vs **per-tenant PBX/AMI creds**. *(Rec: per-tenant creds in tenant settings; PBX stays external/customer-owned.)*
8. **Reporting in v1:** Filament widgets + CSV/Excel export only? *(Rec: yes; report builder = fast-follow.)*
9. **Email intake (IMAP) in v1** or fast-follow? *(Rec: sending in v1; inbound IMAP intake fast-follow unless critical.)*
10. **Legacy adapter for the 133 n8n workflows** — build the thin `/Api/V8/*` bridge as insurance (≈3–4 days) or rewrite all workflows big-bang? *(Rec: build the bridge, migrate in waves, then delete it.)*
11. **Which social platforms are must-have in v1** — Meta/Instagram, WhatsApp, LinkedIn, TikTok, Google? *(Rec: Meta + WhatsApp + WordPress in v1; others via the generic ingest endpoint, then native later.)*
12. **Timeline option** — A: 3 devs / ~14 wks (full) · B: 4 devs / ~11–12 wks · C: staged, 9 wks to go-live + Studio in wks 10–15. *(Rec: A, or B if you want it faster.)*

**Provide-later (per phase, not blocking now):**
10. **SMS gateway** choice (Twilio/other) — needed Phase 6.
11. **Provider credentials** (Vapi, Asterisk AMI, SMTP/IMAP) in `.env` — Phase 6.
12. **Sanitized data dump** — used locally at Phase 5 (Appendix A).
13. **Compliance/region** for immigration PII (PIPEDA/GDPR) — confirm hosting region + retention.
14. **Billing/subscriptions** (Stripe) — fast-follow after go-live; confirm not in v1.
