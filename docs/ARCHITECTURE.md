# crmga SaaS CRM — Technical Approach & Architecture

How every layer is actually built, mapped to the phases, plus the **open decisions to lock before we
start** (§5). Read with `PROJECT_PLAN.md` (timeline/ownership) and `DATA_MODEL.md` (entities).

## 1. Stack (definitive)

| Layer | Tech | Role / why |
|---|---|---|
| Runtime | **PHP 8.3, Laravel 11** | core framework |
| App server | **FrankenPHP** (worker mode) or Octane+Swoole; Caddy/Nginx front | 3–10× throughput vs FPM |
| Staff UI | **Filament v3** (Livewire + Alpine + Tailwind) | CRM screens without a separate SPA |
| Multi-tenancy | **stancl/tenancy v3** | database-per-tenant, auto connection switching |
| RBAC | **spatie/laravel-permission** | the 29 roles → roles+permissions |
| API auth | **Laravel Passport** (OAuth2 `client_credentials`) | SuiteCRM-V8-compatible API for n8n |
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

**Auth**
- Staff log in per tenant at their subdomain (`acme.crm.<domain>`); users live in the **tenant DB**; Filament session auth; **TOTP 2FA** (source had 2FA) + password policy.
- **Super-admin ("landlord")** = a separate Filament panel on the central domain, backed by the **central DB**, to create/manage companies (tenants).
- **API** = OAuth2 `client_credentials` via Passport; **each tenant gets its own client_id/secret** so every n8n workflow authenticates into the right tenant. Tokens are tenant-scoped.

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

**API (SuiteCRM-V8-compatible)**
- Routes `/{tenant}/Api/V8/module/{module}`, `/meta/modules`, `/meta/fields/{module}` (or per-subdomain). Controllers translate **JSON:API ↔ Eloquent**; a **module-aliasing** map (`GA_* ↔ new entities`); middleware does **datetime normalisation** + **Meta value canonicalisation**; pagination `meta` returns record counts. Contract tests pin SuiteCRM's real shapes.

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
- **Runtime field designer (Studio)** → v1 uses code-defined fields; per-tenant custom fields = fast-follow (§5).
- Modules not emphasised by the business: **Quotes/Invoices/Contracts/Products (aos_*)**, **Reports builder (aor_*)**, **Campaigns**, **Knowledge Base (aok_*)**, **Events (fp_*)**, **Projects (am_/project)**, **Google Maps (jjwg_*)**, **Surveys** → **out of v1 unless you flag them** (§5).
- 154 activity link tables + 79 audit tables → collapsed to polymorphic relations + one audit log.

## 5. OPEN DECISIONS TO LOCK BEFORE STARTING  ← work these first

**Blocking (needed for Phase 1 architecture):**
1. **Hosting / infra:** where does the SaaS run (your VPS? a cloud? Docker/K8s?), and the DB-per-tenant scaling + backup plan. *(Rec: Docker + FrankenPHP on a cloud VPS; nightly per-tenant `mysqldump`.)*
2. **Domain + DNS:** the tenant subdomain scheme + **wildcard DNS & TLS**, and the central/super-admin domain. *(Rec: `*.crm.<yourdomain>`; central `app.<yourdomain>`.)*
3. **Frontend = Filament (server-driven), no separate SPA** — confirm you're OK dropping SuiteCRM's Angular UI. *(Rec: yes — far faster to build, still modern.)*
4. **PK strategy = keep source char(36) UUIDs** (best for ETL integrity) — confirm. *(Rec: yes.)*

**Should-decide (shapes scope, needed by Phase 2–3):**
5. **Module scope:** are any of Quotes/Invoices/Products, Reports, Campaigns, Knowledge Base, Events, Projects needed in v1? *(Rec: all fast-follow; v1 = leads/companies/students/assessments/clients + activities.)*
6. **Per-tenant custom fields:** different firms will want their own fields. v1 shared schema vs a field-designer. *(Rec: v1 = shared + a JSON "extra fields" bag; full designer = fast-follow.)*
7. **Telephony model for SaaS:** one shared PBX vs **per-tenant PBX/AMI creds**. *(Rec: per-tenant creds in tenant settings; PBX stays external/customer-owned.)*
8. **Reporting in v1:** Filament widgets + CSV/Excel export only? *(Rec: yes; report builder = fast-follow.)*
9. **Email intake (IMAP) in v1** or fast-follow? *(Rec: sending in v1; inbound IMAP intake fast-follow unless critical.)*

**Provide-later (per phase, not blocking now):**
10. **SMS gateway** choice (Twilio/other) — needed Phase 6.
11. **Provider credentials** (Vapi, Asterisk AMI, SMTP/IMAP) in `.env` — Phase 6.
12. **Sanitized data dump** — used locally at Phase 5 (Appendix A).
13. **Compliance/region** for immigration PII (PIPEDA/GDPR) — confirm hosting region + retention.
14. **Billing/subscriptions** (Stripe) — fast-follow after go-live; confirm not in v1.
