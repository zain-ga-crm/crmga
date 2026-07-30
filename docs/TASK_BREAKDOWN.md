# Task Breakdown — Zain (backend) · Shahmeer (frontend)

Working assignment sheet. Companion to `docs/PROJECT_PLAN.md`. Effort in **person-days**.
Task IDs: **Z** = Zain (backend) · **S** = Shahmeer (frontend).

**Revision 3 — 2026-07-29.** Two developers; multi-tenancy is the final phase (Phase 8); scope reduced to
fit 14 weeks (see `PROJECT_PLAN.md` §6).

| | Zain — Backend | Shahmeer — Frontend |
|---|---|---|
| **Owns** | Database and migrations, metadata engine, `SchemaManager` (runtime DDL), models and business logic, ACL enforcement, REST API, integrations, ETL, security, deployment, multi-tenancy conversion | The entire Filament interface: dynamic rendering from metadata, every screen, activity timeline, dashboards, DNC and Hot/Warm, role matrix UI, all Studio builders, settings, super-admin panel |
| **Capacity** | 14 weeks × 5 days = **70 days** | **70 days** |
| **Planned** | **64 days** | **63 days** |

**The contract between the two lanes.** Zain must land, in week 1 and then freeze: the `tenant_fields`
metadata shape, the **layout JSON definition**, and the option-list structure. Shahmeer builds everything
against that contract using seeded fixtures, so the frontend is never blocked waiting on backend work.

---

## Phase 1 — Foundation + metadata engine (Weeks 1–2)

### Zain — backend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **Z-1.1** | Scaffold + CI | Laravel 11 (PHP 8.3), Filament 3, spatie/permission, Passport + Sanctum, Horizon; Pint, PHPStan (max), Pest; GitHub Actions on every PR | 2 |
| **Z-1.2** | **Tenancy-ready skeleton** | `database/migrations/tenant/` as the home for all CRM migrations; `routes/central.php` stub; `Storage`-only file access; cache/queue key helper; **a Pest test that fails CI if a CRM migration lands outside the tenant folder or any table gains a `tenant_id` column** (rules 1, 2, 4 in `PROJECT_PLAN.md` §3) | 1.5 |
| **Z-1.3** | Users + authentication backend | Users table (with `is_admin`, status, `reports_to`, locale, timezone, telephony extension), password policy, TOTP 2FA with backup codes, password reset | 2 |
| **Z-1.4** | **Metadata registry** | Migrations and models for `tenant_modules`, `tenant_fields`, `tenant_option_lists`, `tenant_option_items`, `tenant_layouts`, `tenant_changes` — including the verified field flags (help, comments, audited, mass-update, duplicate-merge, reportable, importable, length) and typed extras (option list, related module, precision) | 3 |
| **Z-1.5** | Metadata contract + cache | `MetadataRepository` compiling metadata into a cached structure with version bumping; **the frozen layout-JSON contract documented and seeded with fixtures for Shahmeer** | 2 |
| **Z-1.6** | **`SchemaManager`** | Runtime DDL: validate (reserved names, type rules, limits) → plan → snapshot → apply in a transaction → log to `tenant_changes` → bump cache. Custom fields into `{table}_custom` sidecars. Restore-from-snapshot path. Always targets the current default connection | 4 |
| | | **Subtotal** | **14.5** |

### Shahmeer — frontend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **S-1.1** | Panel shell | Filament panel, theme, colours, typography, navigation structure, layout, notification area | 2 |
| **S-1.2** | Authentication screens | Login, 2FA challenge (authenticator + backup code), forgot password, reset password, forced first-login password change | 2 |
| **S-1.3** | **`FieldTypeRegistry`** | Every field type — text, long text, dropdown, multi-select, checkbox, integer, decimal, currency, date, date-time, email, phone, URL, relate, file, image — mapped to a Filament form component, a table column, a cast and validation rules; extensible registry | 3 |
| **S-1.4** | Design system | Reusable components: badge set (vertical, stage, hot/warm, DNC), phone cell with click-to-call, email cell, empty states, loading states, confirmation dialogs | 2 |
| | | **Subtotal** | **9** |

**DoD (M1):** a field added through the metadata layer creates a real column, is logged, and bumps the cache; the tenancy-ready CI guard passes; login with 2FA works; CI green.

---

## Phase 2 — Data model + ACL + first screens (Weeks 3–5)

### Zain — backend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **Z-2.1** | Contactable base + custom fields | Shared base columns from `field-map.json` (names, phones, addresses, `do_not_call`, consent fields, `assigned_user_id`, soft deletes, `char(36)` UUID keys) plus the `HasCustomFields` trait that reads the sidecar and derives casts, fillable and validation from metadata | 4 |
| **Z-2.2** | Polymorphic activities + audit | Meeting, Note, Document (+ revisions), Email (+ body), Call, Task — each morphing to any record; polymorphic audit log; `EmailAddress` morph with denormalised `primary_email`. Replaces 154 link tables and 79 audit tables | 5 |
| **Z-2.3** | **ACL engine** | `role`, `role_module_permissions` (view, list, edit, delete, import, export, mass-update × **All / Owner / None**), `role_user`; policies plus **global query scopes shared by the UI and the API**; user types (System Administrator, Regular User); permissions auto-registered per module; the 29 starter roles seeded | 4 |
| **Z-2.4** | Primary entities | **Company** (+ sidecar), **Lead** with `vertical` and `stage` — verticals include Study Permit and LMIA — and **Assessment** with CRS/FSW scores as typed columns plus a `scores` JSON | 4 |
| **Z-2.5** | Remaining entities | Student, Client, Affiliate, NewsletterSubscriber, SmsMessage, CallSummary — migrations, models, relationships, factories | 3 |
| **Z-2.6** | ETL transformers (early start) | First-pass transformers and a source-data profile, run against the sanitized dump **now** rather than in Phase 6, so mapping gaps surface early | 2 |
| | | **Subtotal** | **22** |

### Shahmeer — frontend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **S-2.1** | **`DynamicResource`** | The core of the frontend: builds table, form, detail view and filters for any module from `tenant_layouts` + `tenant_fields` through the `FieldTypeRegistry`; handles panels, tabs, column order and widths | 6 |
| **S-2.2** | Company screens | List with filters and columns, detail with panels, create and edit forms, bulk actions | 3 |
| **S-2.3** | Lead screens | List, detail and forms with **vertical-aware panels** (qualification fields shown per vertical), stage badges, quick actions (call, email, edit, convert) | 4 |
| **S-2.4** | List-page framework | Saved views, column chooser, filter panel, bulk-action bar, export — built once and reused by every module | 3 |
| | | **Subtotal** | **16** |

**DoD (M2):** migrations run clean; a Regular User with Owner access sees only their own records in the UI **and** the API; a System Administrator sees all; Company and Lead are fully usable.

---

## Phase 3 — Studio (Weeks 5–7)

### Zain — backend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **Z-3.1** | Field-type coverage in DDL | `SchemaManager` support for the full field-type range, safe type widening, guarded narrowing, soft-delete of fields with an impact check (which layouts, roles and integrations reference the field) | 3 |
| **Z-3.2** | Option lists + layout versioning | Persistence and validation for option lists and their items; layout versioning with publish and revert | 2 |
| **Z-3.3** | Change log + rollback | Every customisation recorded with actor, before and after, and the applied DDL; rollback execution; per-installation limits | 2 |
| | | **Subtotal** | **7** |

### Shahmeer — frontend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **S-3.1** | **Field Manager** | List every field on a module with its type and flags; add and edit fields of any type with per-type options (length, default, precision, validation, help text) and behaviour flags; **auto-generate `LBL_*` label keys** so labels can never break; delete with an impact warning | 4 |
| **S-3.2** | **Dropdown Editor** | Create and rename option lists; add, rename, reorder and remove items; edit stored value and displayed label independently; used-by warning before changing or deleting | 3 |
| **S-3.3** | **Layout Editor** | For each of the list, detail, edit and search views: choose which fields appear, their order, and which panel or tab they sit in; column widths for list views; preview before publishing; version history | 4 |
| **S-3.4** | Change history screen | Who changed what and when, with the applied change shown in plain language and a rollback action | 2 |
| | | **Subtotal** | **13** |

**DoD (M3):** an administrator adds a field, edits a dropdown and rearranges a layout — live immediately in the interface and the API with no deployment; every change is logged and reversible.

---

## Phase 4 — CRM complete (Weeks 7–9)

### Zain — backend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **Z-4.1** | Settings store | Per-company settings (SMTP, telephony, branding, business hours, enabled modules) in a `settings` table with encrypted secrets, and a runtime mailer built from them — **rule 3 of the tenancy-ready contract** | 2.5 |
| **Z-4.2** | Scheduled jobs + notifications | Horizon queues; the daily lead and student count reports; task and follow-up reminders; in-app notifications | 2 |
| **Z-4.3** | Dashboard data services | Aggregation queries behind each widget (hot and warm across verticals, pipeline by stage, calls to make, attention-needed), all permission-scoped and cached | 2.5 |
| **Z-4.4** | Performance pass | N+1 elimination on the dynamic rendering path, index review against real data volumes, metadata cache tuning | 2 |
| | | **Subtotal** | **9** |

### Shahmeer — frontend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **S-4.1** | Remaining module screens | Student, Client, Affiliate, Newsletter, SMS log, Call log — list, detail and form on the dynamic renderer | 4 |
| **S-4.2** | Assessment scorecard | Score summary, factor-by-factor breakdown, and a stepped entry form for CRS/FSW | 3 |
| **S-4.3** | **Activity timeline** | Unified chronological feed on every record (calls, SMS, emails, meetings, notes, documents, tasks, field changes) plus relation managers for each activity type | 4 |
| **S-4.4** | **Dashboard** | Widget framework and the v1 widget set: hot leads, warm leads, pipeline by stage, my tasks, today's meetings, calls to make, attention-needed, recent activity | 3 |
| **S-4.5** | **DNC + Hot/Warm** | Do-Not-Call flag, default exclusion from lists and the dedicated DNC view; hot and warm flags with their aggregate views | 2 |
| **S-4.6** | **Role matrix UI** | Modules down, actions across, access-level dropdown per cell, colour-coded, bulk row and column set; role CRUD; user management and role assignment | 4 |
| **S-4.7** | Settings + global search | Settings screens for company profile, branding, email, telephony and modules; global search across permitted modules | 3 |
| | | **Subtotal** | **23** |

**DoD (M4):** staff can run the business end to end; DNC and Hot/Warm work; an administrator manages users and roles from the interface.

---

## Phase 5 — REST API + integrations (Weeks 9–11)

### Zain — backend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **Z-5.1** | REST API foundation | `/api/v1` routing, response envelope, problem-details errors, ETag, idempotency keys, **UTC datetime boundary independent of the caller's locale** | 2.5 |
| **Z-5.2** | **Metadata-driven resources** | Index, show, store, update and destroy for every module from metadata, with filtering, sorting, sparse fields, includes and cursor pagination | 4 |
| **Z-5.3** | API authentication | OAuth2 client-credentials and personal access tokens with **scopes**, rate limits with the correct headers, request logging, client management | 3 |
| **Z-5.4** | ACL in the API | The Phase-2 policies and query scopes applied to every endpoint, with tests proving the API and UI agree exactly | 1.5 |
| **Z-5.5** | **Legacy `/Api/V8/*` adapter** | A thin compatibility layer over the new services so the **133 existing n8n workflows keep running with only a base-URL change** — this is what removes the workflow rewrite from the project | 3 |
| **Z-5.6** | **FieldMapper + intake** | Canonicalisation (lower-case, strip non-alphanumerics, both sides), invisible-Unicode phone cleaning, validation, dedupe by email and phone, owner assignment, events; **WordPress** endpoint, **Meta Lead Ads** webhook, and a generic signed `/ingest/{source}` for every other platform | 4 |
| **Z-5.7** | Click-to-call | Asterisk AMI originate from a queued job using the per-user extension and context, with call records created from the result | 2.5 |
| **Z-5.8** | Email sending | Per-company SMTP transport, send-from-record, delivery logging | 1.5 |
| | | **Subtotal** | **22** |

### Shahmeer — frontend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **S-5.1** | API management screens | API clients and personal tokens (create, scopes, secret shown once, revoke), request-activity view | 2.5 |
| **S-5.2** | Integration screens | Integration hub cards with status; WordPress and Meta configuration; the shared **field-mapping editor** (source field → CRM field, transform, default) with a test-payload runner | 3 |
| **S-5.3** | API documentation page | OpenAPI-driven documentation view with a try-it console | 1.5 |
| **S-5.4** | Communication UI | Compose email from a record with templates and merge fields; click-to-call control and call outcome capture; SMS send and thread view | 3 |
| | | **Subtotal** | **10** |

**DoD (M5):** an external application authenticates and performs CRUD; a real n8n workflow runs unchanged except for its base URL; a WordPress form and a Meta lead both land correctly in the CRM.

---

## Phase 6 — Data migration + UAT (Weeks 11–12 · ETL runs locally)

### Zain — backend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **Z-6.1** | ETL command | `crm:migrate-legacy` with a read-only legacy connection, per-entity transformers, batching, `--dry-run`, idempotent and resumable | 3 |
| **Z-6.2** | Data correctness | Email-address join to `primary_email`; all datetimes to UTC; dropdown and Meta value canonicalisation; dedupe of the duplicate legacy modules; Study and LMIA records mapped onto Lead verticals | 3 |
| **Z-6.3** | Studio metadata import | `fields_meta_data` and the list and detail view definitions imported into `tenant_fields` and `tenant_layouts`, so existing customisation becomes Studio metadata | 2 |
| **Z-6.4** | Reconciliation | Per-entity count report against the audited figures, with a discrepancy log | 1.5 |
| | | **Subtotal** | **9.5** |

### Shahmeer — frontend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **S-6.1** | Post-migration verification | Every migrated entity renders correctly; fix field, label and layout mismatches; verify the imported Studio metadata renders | 3 |
| **S-6.2** | UAT support | Run UAT sessions with staff, log findings, fix interface defects | 3 |
| | | **Subtotal** | **6** |

**DoD (M6):** counts reconcile with the audit; existing custom fields appear in Studio; UAT signed off. Runbook: `PROJECT_PLAN.md` Appendix A.

---

## Phase 7 — Hardening and go-live (Weeks 12–13)

### Zain — backend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **Z-7.1** | Security review | `/security-review` and remediation: authorisation on every endpoint, DDL safety, API scopes, encrypted secrets, audit coverage, dependency audit | 2.5 |
| **Z-7.2** | Performance + deployment | Octane or FrankenPHP worker mode, cache and queue tuning, deployment pipeline, health checks | 2 |
| **Z-7.3** | Backups + cutover | Backup and verified restore, export capability, and the **cutover runbook** — parallel run, switch, rollback plan | 2 |
| | | **Subtotal** | **6.5** |

### Shahmeer — frontend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **S-7.1** | Final polish | Empty states, error states, validation messages, accessibility and keyboard pass, responsive check on tablet and phone | 3 |
| **S-7.2** | User documentation | Staff user guide and an administrator guide covering roles and Studio | 2 |
| | | **Subtotal** | **5** |

**DoD (M7):** 🚀 **Gunness & Associates live on the new CRM (single tenant)**, with backups and a tested rollback.

---

## Phase 8 — Multi-tenancy conversion (Weeks 13–14)

### Zain — backend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **Z-8.1** | Tenancy installation | Install `stancl/tenancy`; create the **central database** (tenants, domains, platform super-admins) and the `Tenant` model; bootstrappers switching database, cache, queue and filesystem per request and per job | 2.5 |
| **Z-8.2** | Domain identification | Subdomain resolution with wildcard DNS and TLS; central versus tenant route separation activated; tenant-aware sessions and authentication | 2 |
| **Z-8.3** | **Promote the live database to tenant #1** | Register the existing live installation as the first tenant, pointing at its current database — **no data movement**; move `settings` to per-tenant scope; verify with a full regression run | 2 |
| **Z-8.4** | Provisioning | `tenant:create` — create database, run tenant migrations, seed roles and the first administrator, apply the default configuration; `tenants:migrate` for deployments; per-tenant backup and export | 2.5 |
| **Z-8.5** | Isolation proof | Pest tests proving one company cannot read another's data through the UI, the API or a queued job; and that a Studio field change affects only one company | 1.5 |
| | | **Subtotal** | **10.5** |

### Shahmeer — frontend
| ID | Task | Deliverable | Days |
|---|---|---|---|
| **S-8.1** | Super-admin panel | Central-domain panel with its own login: companies list (subdomain, users, records, database size, status), company detail, suspend and resume | 3 |
| **S-8.2** | Create-company wizard | Company name and subdomain, first administrator, modules enabled, and the default Studio governance mode; shows provisioning progress | 2 |
| **S-8.3** | Studio governance UI | Per company: Studio mode (disabled, request-only, self-service) and limits; the pending change-request queue with approve and reject | 2 |
| | | **Subtotal** | **7** |

**DoD (M8):** the live installation runs as tenant #1 with no data loss; a second company is created, reaches its own subdomain with an isolated database, and the isolation tests pass.

---

## Load summary

| Phase | Zain (backend) | Shahmeer (frontend) |
|---|---|---|
| 1 Foundation + metadata engine | 14.5 | 9 |
| 2 Data model + ACL + first screens | 22 | 16 |
| 3 Studio | 7 | 13 |
| 4 CRM complete | 9 | 23 |
| 5 REST API + integrations | 22 | 10 |
| 6 Data migration + UAT | 9.5 | 6 |
| 7 Hardening + go-live | 6.5 | 5 |
| 8 Multi-tenancy conversion | 10.5 | 7 |
| **Total** | **101** | **89** |
| **Capacity (14 weeks)** | **70** | **70** |

### ⚠️ This does not fit, and it is important to say so plainly
At **101 and 89 days against 70 each**, the plan is at **144% and 127%** of capacity. The scope reductions
in `PROJECT_PLAN.md` §6 were already applied to reach these numbers — this is what remains after cutting
the Module Builder, relationships, webhooks, native social connectors, the import wizard, the report builder
and the workflow rewrite.

Two developers in fourteen weeks can realistically deliver **about 70% of this**. The options, in the order
I would take them:

| Option | What it means | Result |
|---|---|---|
| **1. Ship in two releases** *(recommended)* | Weeks 1–14 deliver **Release 1**: the CRM, ACL, all screens, the REST API with the legacy adapter, data migration, go-live — **without Studio**. Studio (≈20 days) and multi-tenancy (≈17 days) become **Release 2**, roughly six further weeks. | Business live on time; both big subsystems built properly rather than rushed |
| **2. Keep Studio, defer tenancy further** | Studio ships in Release 1; the tenancy conversion moves to Release 2. | Live on time with customisation, SaaS a few weeks later |
| **3. Add the third developer back** | Restores roughly 60 days of capacity. | The full 14-week plan above becomes achievable |
| **4. Extend to ~19 weeks** | The same scope, honest dates. | Everything, later |

**My recommendation is option 1**, because it protects the go-live date, and because Studio and the tenancy
conversion are precisely the two pieces that suffer most from being rushed. If Studio is not negotiable,
option 2. Whichever you choose, the metadata engine (Z-1.4 to Z-1.6) stays in Phase 1 — without it, both
Studio and the dynamic API would have to be retrofitted at far greater cost.

## Cut order if the date comes under pressure mid-project
1. Layout Editor becomes a simple field-order editor (−2 days).
2. Assessment scorecard becomes read-only, scores calculated on import (−2 days).
3. Meta intake moves to the generic ingest endpoint (−2 days).
4. Studio moves entirely to Release 2 (−20 days) — the largest single lever.
