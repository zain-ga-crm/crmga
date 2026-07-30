# crmga CRM — Master Project Plan (14 weeks · 2 developers)

**Revision 3 — 2026-07-29.** Two changes from revision 2:
1. **Multi-tenancy moves to the end.** The CRM is built and goes live **single-tenant** for
   Gunness & Associates, then is converted to multi-tenant SaaS as the final phase.
2. **Two developers.** **Zain — backend** · **Shahmeer — frontend**. (No third developer, no dedicated
   PM; the Product Owner covers product decisions and UAT sign-off.)

Timeline is unchanged at **14 weeks**, so **scope was reduced to fit** — see §6 for exactly what moved out.

> **For Claude Code:** read `CLAUDE.md`, then `docs/ARCHITECTURE.md`, `docs/STUDIO_API_RBAC.md`,
> `docs/DATA_MODEL.md`. Work one phase at a time, start each phase in **Plan Mode**, one feature per PR,
> run `pint` → `phpstan` → `pest` before every commit. Do not advance a phase until its **DoD** passes.
> Per-task assignments: **`docs/TASK_BREAKDOWN.md`**.

---

## 1. What is being built

A **Laravel 11 + Filament 3** CRM replacing the heavily-customised SuiteCRM 8.8.0 system, built as a
**metadata-driven engine** so each company can customise its own fields, dropdowns and layouts
(**Studio**), with a modern **REST API** for WordPress, Meta and any other platform.

**Delivery order (new):**
```
Weeks 1–12   Build + migrate + go live  →  SINGLE TENANT (Gunness & Associates)
Weeks 13–14  Convert to multi-tenant SaaS  →  second company onboardable
```

**One database now, tenant database later.** The single CRM database we build is designed from day one to
*become* tenant #1's database. That is why tenancy can be deferred cheaply — but only if the ten
tenancy-ready rules in §3 are followed without exception.

---

## 2. The team

| Who | Lane | Owns |
|---|---|---|
| **Zain** | **Backend** | Database and migrations, the metadata engine + `SchemaManager` (runtime DDL), models and business logic, ACL enforcement, REST API, integrations, ETL/data migration, security, performance, deployment, and the final multi-tenancy conversion |
| **Shahmeer** | **Frontend** | The whole Filament interface: dynamic rendering from metadata, every module's screens, activity timeline, dashboards, DNC and Hot/Warm, the role matrix UI, all Studio builder screens, settings screens, and the super-admin panel in the final phase |
| **Product Owner** | Product | Field-mapping answers, the open decisions in `ARCHITECTURE.md` §5, priorities, UAT sign-off, credentials, the sanitized dump |

**Interface between the lanes.** Zain owns the metadata contract (`tenant_fields`, `tenant_layouts`,
option lists) and the API; Shahmeer consumes them. Zain must land the metadata registry and the
`FieldTypeRegistry` contract early in Phase 1 so Shahmeer is never blocked. Agree the JSON shape of a
layout definition in week 1 and treat it as a fixed contract.

---

## 3. Tenancy-ready rules — non-negotiable from day one

The entire deferral depends on these. Any violation converts a 2-week tenancy phase into a rewrite.

1. **All CRM migrations live in `database/migrations/tenant/`** from day one, even though only one database exists. Nothing CRM-related goes in the default migrations folder.
2. **No `tenant_id` column, ever.** Isolation is by database, not by row. Adding a tenant column now would be wasted work and a permanent tax.
3. **Per-company configuration lives in a `settings` table**, never in `.env` — SMTP, telephony, branding, business hours, enabled modules. On conversion these become per-tenant automatically.
4. **Routes are split now:** `routes/web.php` is the tenant application; `routes/central.php` exists as an empty stub for the future landlord application.
5. **All file access goes through the `Storage` facade** with a configurable disk and root. No absolute paths anywhere.
6. **Cache and queue keys go through one helper**, so a tenant prefix can be injected later in a single place.
7. **`SchemaManager` always targets "the current default connection"** — trivially the single database now, the tenant database later. Never a hardcoded database name.
8. **Users, roles and permissions live in the CRM database** (which becomes the tenant database). The future central database will hold only tenants, domains and platform super-admins.
9. **Seeders are idempotent and per-database**, so they can be run per tenant on provisioning.
10. **No query assumes a global ID space** beyond the current database, and no cross-company reporting is built.

A Pest test asserting rules 1, 2 and 4 should exist from Phase 1 so a violation fails CI.

---

## 4. Timeline (14 weeks)

| Wk | Phase | Focus | Milestone |
|---|---|---|---|
| 1–2 | **1 Foundation + metadata engine** | App, auth, metadata registry, `SchemaManager`, dynamic-rendering contract | **M1** a field added in metadata appears as a real column |
| 3–5 | **2 Data model + ACL + first screens** | Contactable base, activities, ACL, core entities, first working screens | **M2** Owner-level access enforced; core records usable |
| 5–7 | **3 Studio** | Field Manager, Dropdown Editor, Layout Editor (+ change log) | **M3** a user customises fields and layouts with no deploy |
| 7–9 | **4 CRM complete** | All module screens, activity timeline, dashboards, DNC, Hot/Warm, roles UI, settings | **M4** staff can run the business in the new CRM |
| 9–11 | **5 REST API + integrations** | REST API, OAuth2/tokens, legacy adapter, WordPress + Meta intake, click-to-call | **M5** external systems read and write; n8n keeps working |
| 11–12 | **6 Data migration + UAT** | ETL from the sanitized dump, reconciliation, UAT | **M6** real data in, counts reconciled, UAT signed off |
| 12–13 | **7 Hardening + GO LIVE** | Security, performance, backups, cutover | **M7 🚀 Gunness live on the new CRM (single tenant)** |
| 13–14 | **8 Multi-tenancy conversion** | Central DB, tenant identification, provisioning, super-admin panel | **M8** SaaS-ready; a second company can be onboarded |

**Note on M7:** the business is live on the new CRM at the end of week 13 — before tenancy. Phase 8 then
turns that live installation into tenant #1 of a SaaS platform without moving its data.

---

## 5. Phases in detail

### Phase 1 — Foundation + metadata engine (Weeks 1–2)
**Backend (Zain):** Laravel 11 + Filament 3 + spatie/permission + Passport/Sanctum + Horizon scaffold with
CI (Pint, PHPStan, Pest); the **tenancy-ready folder structure and CI guard** (§3); users, authentication
and 2FA backend; the **metadata registry** (`tenant_modules`, `tenant_fields`, `tenant_option_lists`,
`tenant_option_items`, `tenant_layouts`, `tenant_changes`) with the verified field flags; a cached
`MetadataRepository` with version bumping; and the **`SchemaManager`** that applies field changes as real
DDL — validate, plan, snapshot, apply, log, bump cache — into `{table}_custom` sidecar tables.

**Frontend (Shahmeer):** Filament panel shell, theme and navigation; authentication screens (login, 2FA,
forgot/reset, first-login); and the **`FieldTypeRegistry`** mapping every field type to a form component,
a table column, a cast and validation rules.

**DoD (M1):** a field added through the metadata layer creates a real column, is logged, and the cache
version bumps; the tenancy-ready CI guard passes; login with 2FA works; CI green.

### Phase 2 — Data model + ACL + first screens (Weeks 3–5)
**Backend:** the shared **Contactable** base and `HasCustomFields` trait (sidecar-aware); **polymorphic
activities** (Meeting, Note, Document, Email, Call, Task) plus an audit log and the `EmailAddress` morph
with a denormalised `primary_email`; the **ACL engine** — module × action with access levels
**All / Owner / None**, policies and **global query scopes shared by the UI and the API**, plus user types
(System Administrator, Regular User) and auto-registered permissions per module; and the entity set:
**Company**, **Lead** (with `vertical` covering Business Immigration, Refugee, Spousal, Express Entry,
Humanitarian, **Study Permit**, **LMIA**, PNP, USA, Investor and the rest), **Student**, **Assessment**
(CRS/FSW scores), **Client**, **Affiliate**, **NewsletterSubscriber**, plus SMS and call logs.

**Frontend:** the **`DynamicResource`** that builds tables, forms, detail views and filters from metadata —
the single most important frontend component, since every screen is built on it; then Company and Lead
screens (list, detail, form) including vertical-aware panels.

**DoD (M2):** migrations run clean; a Regular User with Owner access sees only their own records in the UI
**and** the API; a System Administrator sees all; Company and Lead are fully usable.

### Phase 3 — Studio (Weeks 5–7)
**Backend:** hardening `SchemaManager` for the full field-type range, safe type changes, soft-delete of
fields with impact checks, option-list persistence, layout versioning, and the change log with rollback.

**Frontend:** **Field Manager** (add/edit/delete fields of every supported type with all behaviour flags,
auto-generating `LBL_*` label keys); **Dropdown Editor** (create lists, add/rename/reorder items, value
versus label, used-by warning); **Layout Editor** (choose which fields appear, in what order and in which
panel, for the list, detail, edit and search views, with versioning and preview).

**DoD (M3):** an administrator adds a field, edits a dropdown and rearranges a layout, and the change is
live immediately in the interface and the API with no deployment; every change is logged and reversible.

### Phase 4 — CRM complete (Weeks 7–9)
**Backend:** per-company settings store with encrypted secrets; notification and daily-count jobs on
Horizon; performance pass on the dynamic rendering path (N+1 elimination, index review, cache tuning).

**Frontend:** screens for the remaining modules (Student, Assessment scorecard, Client, Affiliate,
Newsletter, SMS and call logs); the **activity timeline** and relation managers on every record; the
**dashboard** widgets (Hot leads, Warm leads, pipeline by stage, my tasks, today's meetings, calls to make,
attention-needed); the **DNC** filter and list; **Hot/Warm** flags; the **role matrix UI** and user
management; settings screens; global search; and export.

**DoD (M4):** staff can run the business end to end in the new CRM; DNC and Hot/Warm work; an administrator
manages users and roles from the interface.

### Phase 5 — REST API + integrations (Weeks 9–11)
**Backend:** versioned **`/api/v1`** with a consistent envelope, problem-details errors, ETag, idempotency
keys, filtering, sorting, sparse fields, includes and cursor pagination — **generated from metadata** so
every module is covered; **OAuth2 client-credentials plus personal access tokens with scopes**, rate limits
and request logging; a **thin legacy `/Api/V8/*` adapter** so the **133 existing n8n workflows keep running
by changing only their base URL**; the shared **FieldMapper** (canonicalisation, validation, dedupe,
assignment, events); **WordPress** and **Meta Lead Ads** intake plus a generic signed ingest endpoint for
every other platform; and **click-to-call** via Asterisk with per-user extensions.

**Frontend:** API client and token management screens, integration configuration and field-mapping screens,
and the OpenAPI documentation page.

**DoD (M5):** an external application authenticates and performs CRUD through the REST API; a real n8n
workflow runs unchanged except for its base URL; a WordPress form and a Meta lead both land correctly.

### Phase 6 — Data migration + UAT (Weeks 11–12 · ETL runs locally)
**Backend:** the `crm:migrate-legacy` command — read-only legacy connection, per-entity transformers from
`field-map.json`, `--dry-run`, idempotent and resumable; correctness work (email-address join to
`primary_email`, UTC datetimes, dropdown canonicalisation, dedupe of the duplicate legacy modules); import
of the existing `fields_meta_data` and view definitions **into Studio metadata**; and the reconciliation
report against the audited counts.

**Frontend:** verification that every migrated entity renders correctly, fixing field and layout mismatches.

**Both:** UAT with real staff, triage and fix.

**DoD (M6):** counts reconcile (Company ≈ 21,014, Assessment scores ≈ 8,147, Study ≈ 4,782 …), existing
custom fields appear in Studio, UAT signed off. Runbook: **Appendix A**.

### Phase 7 — Hardening and go-live (Weeks 12–13)
**Backend:** `/security-review` and remediation (authorisation, DDL safety, API scopes, encrypted secrets,
audit logging); performance with Octane or FrankenPHP; backups and restore rehearsal; deployment pipeline;
and the **cutover runbook** — run both systems in parallel, then switch.

**Frontend:** final UAT fixes, empty and error states, accessibility pass, user documentation.

**DoD (M7):** 🚀 **Gunness & Associates is live on the new CRM**, single-tenant, with backups and a tested
rollback.

### Phase 8 — Multi-tenancy conversion (Weeks 13–14)
**Backend (Zain):** install `stancl/tenancy`; create the **central database** (tenants, domains, platform
super-admins) and the `Tenant` model; **subdomain identification** with wildcard DNS and TLS; bootstrappers
switching database, cache, queue and filesystem per request and per job; **promote the existing live
database to be tenant #1** — no data movement; `tenant:create` provisioning (create database, run tenant
migrations, seed roles and the first administrator); move the `settings` rows to per-tenant scope; per-tenant
backup and export.

**Frontend (Shahmeer):** the **platform super-admin panel** on the central domain — companies list,
create-company wizard, company detail, Studio governance settings per company (mode and limits), and the
change-request queue.

**DoD (M8):** the live installation runs as tenant #1 with no data loss; a second company can be created,
reaches its own subdomain, has its own isolated database, and a Pest test proves one company cannot read
another's data.

---

## 6. What was cut to fit 14 weeks with two developers

Three developers needed roughly 228 person-days. Two developers over 14 weeks have **140**, of which about
**120 are realistically plannable** after review, meetings and defect work. The following moved out of
version 1. **None of it is lost — it is a post-launch backlog**, and the metadata engine is built so each
item drops in without rework.

| Deferred | Why it is safe to defer |
|---|---|
| **Studio Module Builder** (tenant-created modules) | The heaviest Studio feature. Fields, dropdowns and layouts deliver most of the value; the engine already supports adding it later. |
| **Studio Relationship Manager** | The relationships the business needs already exist in the shipped data model. |
| **ACL "Group" access level** and field-level permissions | The source system has only two security groups and no field-ACL table at all, so neither is in use today. |
| **Outbound webhooks** | n8n continues to work through the legacy adapter and can poll the REST API; signed webhooks become the first post-launch feature. |
| **Native WhatsApp, LinkedIn, TikTok and Google connectors** | All are handled in v1 by the generic signed ingest endpoint plus existing n8n workflows. |
| **Vapi and SMS native handling** | Both keep working exactly as today through n8n and the legacy adapter. |
| **Import wizard** | Export ships; imports run through the API or a console command until the wizard is built. |
| **Email template editor and IMAP intake** | Sending works from the CRM; template management and inbound mail parsing come later. |
| **Report builder** | Dashboard widgets plus CSV and Excel export ship instead. |
| **Rewriting the 133 n8n workflows** | The legacy adapter keeps them running; rewriting them onto the clean API happens in waves after launch. |
| **Self-service signup and billing** | Companies are created by you in the super-admin panel. |

### Honest load position
Even after these cuts the plan is **about 105% of nominal capacity for both developers** — there is no
slack. If anything slips, cut in this pre-agreed order:
1. Layout Editor drops to a simple field-order editor (saves ~2 days).
2. Assessment scorecard becomes read-only, with scores calculated on import (~2 days).
3. Meta Lead Ads intake moves to the generic ingest endpoint (~2 days).
4. **Studio moves entirely to post-launch** (~20 days) — the last resort, but it is the single biggest lever.

---

## 7. Risks

| Risk | Mitigation |
|---|---|
| **Tenancy retrofit costs more than two weeks** | The ten rules in §3, enforced by a CI test from Phase 1. Database-per-tenant is the cheapest model to retrofit; the live database is promoted rather than migrated. |
| **Two-developer dependency chain** — the frontend is blocked without the metadata contract | Zain lands the metadata registry and the layout-JSON contract in week 1 and treats it as frozen. Shahmeer works against a seeded fixture, not a moving target. |
| **No dedicated PM or QA** | The Product Owner owns decisions and UAT; both developers write their own tests; `/code-review` before every merge is mandatory, not optional. |
| **Single point of failure on the backend** | Shahmeer pairs with Zain on `SchemaManager` and the ACL engine so neither is understood by only one person. |
| **Data migration surprises** | Dry-run early — the ETL transformers start in Phase 2, not Phase 6, so mapping gaps surface with ten weeks to spare. |
| **Scope creep in Studio** | Studio is fixed at fields, dropdowns and layouts for v1. Module Builder and relationships are explicitly out. |

---

## 8. Environments
- **Cloud / web Claude Code** (no access to your machines or the live server): everything needing only the schema and specs — Phases 1 to 5, 7 and 8.
- **Local Claude Code / developer machines**: anything needing the sanitized dump or provider credentials — **Phase 6 (ETL)** and Phase 5 integration testing. The dump stays local.

## 9. What we need from the Product Owner
**Before Phase 1:** hosting and backups · confirm Filament (no separate SPA) · confirm keeping the source
`char(36)` UUID primary keys · confirm this reduced v1 scope (§6).
**Before Phase 8:** the domain and **wildcard DNS/TLS** for company subdomains, plus the central admin
domain, and the default Studio governance mode for new companies.
**Per phase:** field-mapping answers (2–3) · WordPress and Meta credentials (5) · the sanitized dump,
locally (6) · UAT sign-off and credential rotation (7).

---

## Appendix A — Phase 6: run the data migration locally

**Why local:** the dump and database live on a developer machine; the cloud session cannot reach them.

1. Load the source dump into a local read-only database:
   ```bash
   mysql -e "CREATE DATABASE crmga_source CHARACTER SET utf8mb4;"
   gunzip -c crmga_sanitized.sql.gz | mysql crmga_source
   ```
2. Add a read-only **`legacy`** connection in `config/database.php` pointing at `crmga_source`.
3. Prepare the target: `php artisan migrate --path=database/migrations/tenant`
4. Import the existing customisation into Studio metadata:
   `php artisan crm:import-studio-metadata`
5. Dry run and reconcile: `php artisan crm:migrate-legacy --dry-run`
   Compare the printed per-entity counts with the audited figures and fix mappings until they agree.
6. Run for real (idempotent, resumable): `php artisan crm:migrate-legacy`
7. Spot-check in the interface: email addresses, dropdown values, and dates in UTC.

**Safety:** always work on a copy — never point the ETL at live production. The dump is sanitized but may
retain personal data in free-text fields; keep it on the developer machine and delete it when finished.
