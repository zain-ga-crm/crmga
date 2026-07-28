# crmga SaaS CRM — Master Project Plan (14 weeks, 3 devs, Claude Code)

**Product:** A multi-tenant SaaS CRM (Laravel + Filament) sold to **other immigration firms**, rebuilt
from the Gunness & Associates SuiteCRM 8.8.0 system. **Each customer company = one tenant = its own
database.** First tenant live = Gunness & Associates; more companies onboarded after.

**Revised 2026-07-28** — now includes a **per-tenant Studio** (runtime fields/layouts/relationships/
modules, governed by super admin), a **modern RESTful API** with social/WordPress/any-stack integrations
(replacing the SuiteCRM-V8-compatible API), and **per-tenant roles/ACL** with user types.
Design detail: **`docs/STUDIO_API_RBAC.md`**.

> **For Claude Code:** read `CLAUDE.md`, then `docs/ARCHITECTURE.md` + `docs/STUDIO_API_RBAC.md` +
> `docs/DATA_MODEL.md`. Work **one phase at a time**, always start in **Plan Mode**, ship **one
> feature/entity per PR**, run `pint` → `phpstan` → `pest` before every commit, `/code-review` before
> merge. Don't advance a phase until its **Definition of Done (DoD)** passes.

---

## 1. Locked decisions
- **SaaS, tenants = other companies.** Database-per-tenant (`stancl/tenancy`), full isolation, per-tenant settings.
- **Metadata-driven engine.** Schema, UI, permissions and API are generated from **per-tenant metadata** — this is what makes Studio possible. Built in Phases 1–2, before any hardcoding.
- **Studio per tenant**, with **super-admin governance** (disabled / request-only / self-serve + limits, approval queue, audit, rollback, blueprints).
- **Modern RESTful API** (versioned, OpenAPI, OAuth2 + tokens + API keys, scopes) + **signed outbound webhooks**; integrations for **WordPress, Meta/Instagram, WhatsApp, LinkedIn/TikTok/Google**, and a generic ingest endpoint for anything else.
- **Per-tenant roles/ACL:** module × action × access level (All/Owner/Group/None) + field-level ACL; **user types** = Super Admin (platform), System Administrator (per tenant), Regular User.
- **Activity modules in scope:** Meetings, Notes, Documents, Emails (+ Calls, Tasks) — polymorphic.
- **Migrate real data** from the sanitized dump (Phase 7, run locally).
- **No SuiteCRM-V8-compatible API** (your decision) → the **133 n8n workflows are rewritten** against the new REST API in Phase 6. *Optional cheap insurance: a thin legacy adapter as a transition bridge — see `STUDIO_API_RBAC.md` §2.4.*

---

## 2. Team & responsibilities (3 developers + you)

| Role | Owner | Responsible for |
|---|---|---|
| **Dev A — Platform / Metadata & API Lead** (most senior) | `[A]` | Architecture; DB-per-tenant + provisioning; **metadata engine + SchemaManager (runtime DDL)**; super-admin/landlord panel + Studio governance; **RESTful API + OpenAPI + webhooks**; CI/CD; performance; security; deployment & cutover |
| **Dev B — Studio & CRM UI** | `[B]` | **Studio UI** (field/dropdown/layout/relationship/module builders); **dynamic Filament rendering** from metadata; all CRM screens; **role-matrix UI**; DNC + Hot/Warm; dashboards; per-tenant settings |
| **Dev C — Data, ACL & Integrations** | `[C]` | Core migrations; **ACL engine** (matrix, policies, query scopes); **ETL/data migration** + reconciliation; **WordPress + social integrations**; n8n rewrite; Asterisk/Vapi/SMS/email; API contract tests |
| **Product Owner** | **You** | Field-mapping answers; Studio governance policy; priorities; UAT sign-off; sanitized dump (locally, P7); provider credentials (P6) |

---

## 3. Timeline at a glance (14 weeks)

| Wk | Phase | Focus | Owners | Milestone |
|---|---|---|---|---|
| 1–2 | 1 Foundation + tenancy + metadata core | App, DB-per-tenant, auth, super-admin, **metadata registry + SchemaManager** | A lead, B, C | **M1** tenant + metadata engine live |
| 3–5 | 2 Core data model + ACL engine | Entities, activities, **per-tenant roles/ACL + user types** | C lead, A, B | **M2** schema + ACL enforced |
| 5–8 | 3 **Studio** (per tenant) | Field/dropdown/layout/relationship/module builders, dynamic rendering, governance | B lead, A | **M3** a tenant customises itself |
| 7–9 | 4 CRM UI + DNC + Hot/Warm + settings | All screens on the dynamic renderer, role matrix UI, tenant settings | B lead, A, C | **M4** staff operate the CRM |
| 9–11 | 5 **RESTful API** + webhooks | v1 REST (incl. custom modules), auth/scopes, OpenAPI, signed webhooks | A lead, C | **M5** API + docs + webhooks live |
| 11–13 | 6 Integrations | WordPress, Meta/WhatsApp/LinkedIn/TikTok/Google, **n8n rewrite**, telephony/SMS/email | C lead, A, B | **M6** end-to-end lead lifecycle |
| 12–13 | 7 Data migration (LOCAL) | ETL + **import existing custom fields into Studio** + reconcile | C lead, B | **M7** data migrated + counts match |
| 13–14 | 8 Hardening, UAT, go-live | Security, perf, backups, UAT, cutover | A lead, B, C | **M8** first company LIVE |

Internal beta usable after **M4 (~wk 9)**. Second tenant onboarding demo-able after **M3**.

---

## 4. Phases in detail (tasks · owner · DoD)

### Phase 1 — Foundation + tenancy + metadata core (Weeks 1–2)
- `[A]` Scaffold Laravel 11 + Filament v3 + stancl/tenancy + spatie/permission + Passport/Sanctum + Horizon; central + tenant DBs; `tenant:create`; subdomain routing; CI (pint/phpstan/pest). **(4d)**
- `[A]` **Metadata registry** (`tenant_modules`, `tenant_fields`, `tenant_option_lists`, `tenant_layouts`, `tenant_relationships`, `tenant_studio_changes`) + **SchemaManager**: plan→snapshot→apply DDL→audit→cache-bump. **(4d)**
- `[A]` Super-admin (landlord) panel: create/manage companies, per-tenant **Studio governance flags + limits**. **(2d)**
- `[B]` Tenant auth panel (+2FA), base theme, **FieldTypeRegistry** skeleton (type → Filament component/cast/validation). **(3d)**
- `[C]` Load sanitized dump locally; profile data; refine `field-map.json`; extract enum option lists. **(2d)**
- **DoD (M1):** create a tenant → log in → add a field via the metadata API → column appears in that tenant's DB only, audited, cache-bumped; tenant-isolation test green; CI green.

### Phase 2 — Core data model + ACL engine (Weeks 3–5)
- `[A]` Shared **Contactable** base + `HasCustomFields` trait (sidecar `_custom` tables) + **polymorphic activities** (Meeting/Note/Document/Email/Call/Task) + audit log + `EmailAddress` morph/`primary_email`. **(4d)**
- `[C]` **ACL engine:** `role_module_permissions` matrix (view/list/edit/delete/import/export/mass_update × All/Owner/Group/None), field-level ACL, Policies + **global query scopes shared by UI and API**, dynamic permission registration for new modules, user types (Super Admin / System Administrator / Regular). **(5d)**
- `[C]` Core entity migrations/models/factories: Company, Lead(`vertical`,`stage`), Student, Assessment (88-field CRS/FSW), StudyLead, LmiaCase, Client, Affiliate, NewsletterSubscriber, SmsMessage. **(5d)**
- `[B]` Seed the 29 starter roles per tenant; first dynamic list/form rendering from metadata. **(3d)**
- **DoD (M2):** migrations clean on a fresh tenant DB; a Regular User with *Owner*-level access sees only their records **in both UI and API**; System Administrator sees all; tests cover each access level.

### Phase 3 — Studio, per tenant (Weeks 5–8)
- `[B]` **Field Manager** (all types incl. relate) + **Dropdown Editor** (label convention preserved). **(5d)**
- `[B]` **Layout Editor** — drag-and-drop for list/detail/edit/search; versioned layout JSON. **(5d)**
- `[B]` **Dynamic rendering** hardening: `DynamicResource` builds form/table/infolist/filters from metadata; metadata cache per tenant. **(4d)**
- `[A]` **Relationship Manager** (1-M / M-M / 1-1 → FK or pivot via SchemaManager) + **Module Builder** (tenant-defined modules with base, activities, permissions, API). **(5d)**
- `[A]` **Governance:** change-request queue with **DDL preview**, approve/reject, audit, **rollback**, per-tenant limits, **blueprints** (save a config → push to other tenants). **(3d)**
- **DoD (M3):** a tenant admin adds a field, a dropdown, reorders a layout, creates a relationship and a new module — visible immediately in UI **and** API, with no deploy; super admin can gate/approve/roll back; another tenant is unaffected.

### Phase 4 — CRM UI + DNC + Hot/Warm + settings (Weeks 7–9)
- `[B]` Screens for every core entity on the dynamic renderer; activity timeline relation managers; global search. **(5d)**
- `[B]` **DNC** filter/toggle; **Hot/Warm** toggles + dashboard widgets across verticals; daily count notifications (Horizon). **(3d)**
- `[B]` **Role matrix UI** (SuiteCRM-like editor) + user management for tenant admins. **(3d)**
- `[A]` Per-tenant settings (SMTP, telephony, branding, enabled verticals/modules), secrets encrypted. **(3d)**
- **DoD (M4):** staff operate the CRM per tenant; DNC + Hot/Warm work; a tenant admin creates users/roles; each company has its own SMTP/branding.

### Phase 5 — RESTful API + webhooks (Weeks 9–11)
- `[A]` **v1 REST** for every module **including Studio-created ones** (metadata-driven): filtering/sorting/sparse fields/includes, cursor pagination, problem-details errors, ETag, idempotency. **(5d)**
- `[A]` **Auth & limits:** OAuth2 client_credentials + PATs + API keys, **scopes**, per-tenant rate limits, request logs; **OpenAPI 3.1 per tenant** + Swagger UI. **(4d)**
- `[A]` **Outbound webhooks:** subscriptions, HMAC signing, retries/backoff, delivery log + replay. **(3d)**
- `[C]` API contract tests + a reference integration (Postman/n8n collection) proving the flows. **(3d)**
- **DoD (M5):** external app authenticates, CRUDs a **custom** module created in Studio, receives a signed webhook; OpenAPI docs match reality; ACL access levels enforced identically to the UI.

### Phase 6 — Integrations (Weeks 11–13)
- `[C]` **WordPress**: plugin + form mappers (Gravity/CF7/WPForms/Elementor) → `/api/v1/leads`. **(3d)**
- `[C]` **Meta Lead Ads + Instagram + WhatsApp Cloud API** (leadgen webhook → fetch → map, keeping **Meta value canonicalisation**); **LinkedIn/TikTok/Google** lead forms; generic `/ingest/{source}`; shared **FieldMapper** + dedupe. **(5d)**
- `[C]` **Rewrite the 133 n8n workflows** against the new REST API, in waves per family, with pilots first. **(5d)**
- `[A]` Click-to-call (Reverb + Asterisk AMI, per-tenant creds) + **Vapi** webhooks; **SMS** provider-agnostic adapter; per-tenant SMTP send (+ IMAP intake if in scope). **(4d)**
- `[B]` In-CRM activity timeline for calls/SMS/emails; per-tenant integration settings UI. **(3d)**
- **DoD (M6):** a Meta/WordPress lead lands via the API, follow-ups fire, a Vapi call tags back, click-to-call works, SMS/email logged — all inside the correct tenant.

### Phase 7 — Data migration / ETL (Weeks 12–13, runs LOCALLY)
- `[C]` ETL sanitized dump → tenant DB: mapping, cleanup, UTC datetimes, `primary_email` join, dedupe legacy modules; **idempotent + `--dry-run` + reconciliation report**. **(4d)**
- `[C]` **Import `fields_meta_data` + view defs → Studio metadata** (their existing custom fields/layouts become tenant metadata). **(2d)**
- `[B]` Verify migrated data renders; fix field/display mismatches. **(2d)**
- **DoD (M7):** staging tenant loaded; counts reconcile to the audit (Company ≈ 21,014, Assessment_Score ≈ 8,147, Study ≈ 4,782 …); existing custom fields appear in Studio. **Runbook: Appendix A.**

### Phase 8 — Hardening, UAT, go-live (Weeks 13–14)
- `[A]` `/security-review` (incl. **tenant isolation, Studio DDL safety, API scope/authz**); performance (Octane/FrankenPHP, metadata cache); backups + per-tenant export; deploy + **cutover runbook** (parallel-run → switch). **(4d)**
- `[B]` Finalise roles/permission matrix; UAT fixes; admin + user docs (incl. a Studio guide for tenant admins). **(3d)**
- `[C]` Reconciliation re-run; full n8n cutover; monitoring/alerts. **(3d)**
- **DoD (M8):** UAT sign-off; security review clean; backups + rollback tested; **first company LIVE**; a second company onboardable from a blueprint.

---

## 5. Timeline options
| Option | Team | Duration | Notes |
|---|---|---|---|
| **A — Full scope (this plan)** | 3 devs | **~14 weeks** | Everything, built in the correct order |
| **B — Compress** | **4 devs** | ~11–12 weeks | Studio (B+1) and API (A) run as parallel tracks |
| **C — Staged** | 3 devs | 9 wks to go-live, Studio wks 10–15 | Ship CRM + REST API + roles first, Studio after. **Metadata engine still built in P1–P2.** |

**Fast-follow (post go-live):** self-serve signup + **Stripe billing**, plan/usage limits, report builder, native telephony/SMS rebuild, email-template library migration, tenant-facing onboarding wizard, mobile app.

## 6. Risks & mitigations
- **Studio scope** (biggest new risk) → build the engine first (P1–P2), ship Studio in slices (fields → dropdowns → layouts → relationships → modules); hard limits + approval queue + snapshots/rollback.
- **Runtime DDL** → SchemaManager only; snapshot before every change; DB-per-tenant limits blast radius to one company; full audit.
- **133 n8n workflows rewritten** → pilot one per family, migrate in waves, keep the old CRM running in parallel; *consider the thin legacy adapter as insurance*.
- **Dynamic everything vs performance** → compiled metadata cache per tenant, real indexed columns for filterable fields, query-scope tests.
- **Timeline** assumes 3 Laravel+Claude Code devs, decisions locked, dump available by P7. 2 devs → ~19–21 weeks.
- **PII/compliance** → per-tenant isolation, encrypted secrets, audit log, access logging, retention, credential rotation.

## 7. How Claude Code executes this
1. Read `CLAUDE.md` → `docs/ARCHITECTURE.md` → `docs/STUDIO_API_RBAC.md` → `docs/DATA_MODEL.md` → `docs/reference/*`.
2. Current phase → **Plan Mode** → approval → build as **small PRs** in owner order.
3. `pint` → `phpstan` → `pest` before each commit; `/code-review` before merge; `/security-review` in P8.
4. Advance only when the phase **DoD** passes. Keep `CLAUDE.md` current as decisions land.

---

## 8. Environments — where each phase runs (cloud vs local)
- **Cloud / web Claude Code** (isolated container — **no access to your PC or live server**): everything needing only schema + specs — Phases 1–6, 8.
- **Local Claude Code / dev machines** (your computers, local DB/services): anything needing the **sanitized dump**, a **prod-copy DB**, or **provider credentials** — **Phase 7 (ETL)** and Phase 6 integration testing. The dump stays local and never passes through the cloud session.

## 9. What we exactly need to do (execution checklist)
**Setup (once):**
- [ ] Push the repo foundation to `Gunness-and-Associates/crm` — or link GitHub so Claude Code can push directly.
- [ ] Assign 3 developers to lanes **A / B / C** (§2).
- [ ] Stand up dev + staging (PHP 8.3, MySQL 8/MariaDB, Redis) with wildcard DNS/TLS.

**Decisions to lock before Phase 1** (see `ARCHITECTURE.md` §5):
- [ ] Hosting/infra + backup plan · [ ] domain + wildcard DNS/TLS · [ ] confirm Filament UI · [ ] keep source UUID PKs
- [ ] **Studio governance policy** — default mode per tenant (disabled / request-only / self-serve) + limits
- [ ] **Legacy adapter yes/no** (insurance for the 133 workflows) · [ ] which social platforms are **must-have in v1** vs later

**Inputs by phase:** field-mapping answers (P2–P3) · Studio limits (P3) · **social/WordPress API credentials (P6)** · SMS gateway choice (P6) · Vapi/Asterisk/SMTP creds (P6) · **sanitized dump, locally (P7)** · UAT sign-off + credential rotation (P8).

---

## Appendix A — Phase 7: run the data migration LOCALLY (Dev C)
**Why local:** the dump + database live on your machine; the cloud session can't (and shouldn't) touch them.

**Prereqs:** PHP 8.3, Composer, MySQL/MariaDB, repo with P1–P2 done, Claude Code (`claude`), and `crmga_sanitized.sql.gz`.

1. **Load the source dump into a local read-only DB:**
   ```bash
   mysql -e "CREATE DATABASE crmga_source CHARACTER SET utf8mb4;"
   gunzip -c crmga_sanitized.sql.gz | mysql crmga_source
   ```
2. Add a read-only **`legacy`** DB connection (`.env` → `config/database.php`) pointing at `crmga_source`.
3. **Create + migrate the target tenant:**
   ```bash
   php artisan tenant:create gunness
   php artisan tenants:migrate --tenant=gunness
   ```
4. **Import existing customisation into Studio metadata:**
   ```bash
   php artisan crm:import-studio-metadata --tenant=gunness   # fields_meta_data + view defs
   ```
5. **Dry-run the ETL** (reads legacy, maps, reports counts, writes nothing):
   ```bash
   php artisan crm:migrate-legacy --tenant=gunness --dry-run
   ```
6. **Reconcile** the printed per-entity counts against the audited targets; fix mappings until they line up.
7. **Run for real** (idempotent): `php artisan crm:migrate-legacy --tenant=gunness`
8. **Spot-check** in the UI: emails (from the `email_addresses` join), dropdown values, dates (UTC `Y-m-d H:i:s`).

**Driving it with local Claude Code:** `cd` into the repo, run `claude`, then: *"Build/extend `crm:migrate-legacy` per `docs/DATA_MODEL.md` + `docs/reference/field-map.json`; source = the `legacy` connection; run `--dry-run` and reconcile to the audited counts."*

**Safety:** work on a copy — never point the ETL at live production. The dump is sanitized but may hold residual PII in untagged free-text; keep it local and delete it when done.
