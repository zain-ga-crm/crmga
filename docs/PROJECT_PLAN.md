# crmga SaaS CRM — Master Project Plan (9 weeks, 3 devs, Claude Code)

**Product:** A multi-tenant SaaS CRM (Laravel + Filament) sold to **other immigration firms**, rebuilt
from the Gunness & Associates SuiteCRM 8.8.0 system. **Each customer company = one tenant = its own
database.** First tenant live = Gunness & Associates; more companies onboarded after.

> **For Claude Code:** read `CLAUDE.md` first. Work **one phase at a time**, always start in **Plan
> Mode**, ship **one entity/feature per PR**, and run `pint` → `phpstan` → `pest` before every commit.
> Each task below has a **Definition of Done (DoD)** — don't mark it done until the DoD passes.
> Detailed starter prompts are in `docs/PHASE1_KICKOFF.md`; the schema truth is `docs/reference/`.

---

## 1. Locked decisions
- **SaaS, tenants = other companies.** Database-per-tenant (`stancl/tenancy`), full isolation, per-tenant settings (SMTP, telephony, branding, users/roles).
- **Keep n8n + telephony/SMS/Vapi external**; the CRM exposes a **SuiteCRM-V8-compatible API** so the 133 workflows keep working with only a base-URL change.
- **Consolidate** the 43 GA modules into ~11 clean entities (see `docs/DATA_MODEL.md`).
- **Activity modules in scope:** Meetings, Notes, Documents, Emails (+ Calls, Tasks) — polymorphic.
- **Migrate real data** from the sanitized dump (Phase 5).

**Defaults (change anytime, non-blocking):** Study & LMIA = `Lead` verticals; SMS = provider-agnostic adapter; self-serve signup + billing = fast-follow (not in the 9 weeks).

---

## 2. Team & responsibilities (3 developers + you)

| Role | Owner | Responsible for |
|---|---|---|
| **Dev A — Platform/Backend Lead** (most senior) | `[A]` | Architecture, DB-per-tenant + tenant provisioning, super-admin (landlord) panel, the V8-compatible API + OAuth2, CI/CD, performance, security, deployment & cutover |
| **Dev B — CRM App & UI** | `[B]` | Filament panels, all entity screens (CRUD), RBAC/roles, DNC + Hot/Warm, dashboards & notifications, per-tenant settings UI |
| **Dev C — Data & Integrations** | `[C]` | Migrations authoring, the ETL/data migration + reconciliation, n8n re-pointing, Asterisk/Vapi/SMS/email wiring, API contract tests |
| **Product Owner** | **You** | Field-mapping answers, priorities, UAT sign-off, providing the sanitized dump + provider credentials |

Pairing rule: A leads architecture-defining PRs; B and C build against A's foundations. Anyone can pick up an entity in Phase 2 (parallel).

---

## 3. Timeline at a glance (9 weeks)

| Wk | Phase | Primary focus | Owners | Milestone |
|---|---|---|---|---|
| 1 | 1 Foundation + tenancy | App, DB-per-tenant, auth, roles, CI, landlord skeleton | A lead, B, C | **M1** tenant create→login→roles |
| 2–3 | 2 Data model | Shared base, all entities, polymorphic activities, migrations | C lead, A, B | **M2** migrations clean + tests |
| 3–4 | 3 CRM UI | Filament screens, DNC, Hot/Warm, per-tenant settings | B lead, A | **M3** staff can operate CRM |
| 4–6 | 4 V8 API | OAuth2 + JSON:API + aliasing + contract tests | A lead, C | **M4** n8n works vs new API |
| 6–7 | 5 Data migration | ETL from sanitized dump + reconcile | C lead, A, B | **M5** data loaded + counts match |
| 7–8 | 6 Integrations | n8n re-point, telephony/Vapi/SMS/email | C lead, A, B | **M6** end-to-end lead lifecycle |
| 8–9 | 7 Hardening + go-live | Roles, security, perf, UAT, cutover | A lead, B, C | **M7** go-live (Gunness) |

Internal beta usable after **M4 (~wk6)**: new leads flow via n8n against the new CRM while later phases finish.

---

## 4. Phases in detail (tasks, owner, effort, DoD)

### Phase 1 — Foundation + tenancy (Week 1)
- `[A]` Scaffold Laravel 11 + Filament v3 + stancl/tenancy + spatie/permission + Passport + Horizon; central + tenant DBs; `tenant:create` (provision DB, migrate, seed); subdomain routing. **(3d)**
- `[A]` Super-admin "landlord" panel skeleton: list/create tenants (companies), per-tenant subdomain. **(1.5d)**
- `[B]` Filament auth panel + base theme; `config/roles.php` from `docs/reference/roles.php`; RolesSeeder per tenant; role gating. **(2d)**
- `[A]` CI (GitHub Actions: pint/phpstan/pest) + `.claude/settings.json` SessionStart hook + permission allow-list. **(1d)**
- `[C]` Stand up dev DB from the sanitized dump; profile data; refine `field-map.json`; extract enum option lists (`SELECT DISTINCT`). **(2d, needs the dump)**
- **DoD (M1):** create a tenant → log into its panel → role gating works; a Pest test proves tenant A can't read tenant B; CI green.

### Phase 2 — Core data model + migrations (Weeks 2–3)
- `[A]` Shared **Contactable** base (trait + migration) from `field-map.json contactable_base`; soft deletes; users FKs. **(1.5d)**
- `[A]` **Polymorphic activities**: Meeting, Note, Document(+revision), Email(+text, EmailAddress morph), Call, Task; polymorphic Audit log; `primary_email` denormalization. **(3d)**
- `[B]` **Company** + **Lead**(`vertical`,`stage`) entities: migration+model+factory+relationships; dropdowns as PHP enums (label convention preserved). **(3d)**
- `[C]` Remaining entities — Student, Assessment (88-field CRS/FSW → `scores` JSON + key columns), StudyLead, LmiaCase, Client, Affiliate, NewsletterSubscriber, SmsMessage — migration+model+factory each. **(5d)**
- **DoD (M2):** all core migrations run clean on a fresh tenant DB; relationship + factory tests green; schema matches `field-map.json`.

### Phase 3 — CRM UI + DNC + Hot/Warm (Weeks 3–4, overlaps P2)
- `[B]` Filament resources (list/detail/edit) for every entity; global search; role-gated. **(4d)**
- `[B]` DNC filter/toggle; Hot/Warm toggles + two dashboard widgets aggregating across verticals. **(2d)**
- `[A]` Per-tenant **settings** module (own SMTP, telephony creds, branding, enabled verticals) — SaaS essential. **(2.5d)**
- `[C]` ETL transformer skeleton (source→new mapping) using profiled data. **(2d)**
- **DoD (M3):** staff operate the CRM in a tenant; DNC + Hot/Warm work; each company can set its own SMTP/branding.

### Phase 4 — SuiteCRM-V8-compatible API + OAuth2 (Weeks 4–6)
- `[A]` Passport `client_credentials`; JSON:API `module/{m}`, `meta/modules`, `meta/fields`; pagination `meta` (record counts). **(3d)**
- `[A]` Server-side **datetime normalisation (UTC `Y-m-d H:i:s`)** + **Meta value canonicalisation**; module aliasing (`GA_*` ↔ entities). **(2d)**
- `[C]` Contract tests capturing SuiteCRM's real response shapes; validate a cloned real n8n workflow does CRUD. **(3d)**
- `[B]` Daily lead/student count notifications (Horizon jobs); per-tenant API-client management UI. **(2d)**
- **DoD (M4):** a real n8n workflow authenticates + CRUD against the new API; contract tests green.

### Phase 5 — Data migration / ETL (Weeks 6–7)
- `[C]` Full ETL from the sanitized dump → tenant DB: field mapping, dropdown/Meta cleanup, UTC datetimes, join `email_addresses`→`primary_email`, dedup legacy modules; **idempotent + `--dry-run`**. **(4d)**
- `[A]` Bulk-import performance + tenant seeding from data. **(1.5d)**
- `[B]` Verify migrated data renders in Filament; fix field/display mismatches. **(1.5d)**
- **DoD (M5):** staging tenant loaded; row counts reconcile to the audit (Company ≈ 21,014, Assessment_Score ≈ 8,147, Study ≈ 4,782…); spot-checks pass.

### Phase 6 — Integrations (Weeks 7–8)
- `[C]` Re-point n8n workflows to the new API (pilot 1–2 per family → bulk); per-tenant SMTP send + IMAP intake. **(4d)**
- `[A]` Click-to-call endpoint (Reverb/socket) + Vapi inbound/post-call webhooks; SMS adapter (provider-agnostic). **(3d)**
- `[B]` In-CRM activity timeline (calls/SMS/emails); per-tenant integration settings UI. **(2d)**
- **DoD (M6):** Meta lead → n8n → new CRM; follow-ups fire; Vapi post-call tags back; click-to-call works.

### Phase 7 — Hardening, roles, UAT, go-live (Weeks 8–9)
- `[A]` `/security-review`; performance (Octane/FrankenPHP); backups + per-tenant export; deployment + cutover runbook (parallel-run → switch). **(3d)**
- `[B]` Finalise the 29 roles + permission matrix; UAT fixes; user docs. **(2.5d)**
- `[C]` Reconciliation re-run; full n8n cutover; basic monitoring/alerts. **(2d)**
- **DoD (M7):** UAT sign-off; security review clean; backups + rollback tested; **first company (Gunness) live**; a second tenant can be onboarded by an admin.

---

## 5. SaaS specifics (because tenants = other companies)
- **In v1:** DB-per-tenant isolation, **admin-provisioned** tenants (super-admin creates a company → gets its own DB + subdomain), per-tenant settings (SMTP, telephony, branding, roles, enabled verticals), per-tenant backup/export.
- **Fast-follow (after wk9):** self-serve signup, subscription **billing (Stripe)**, plan/usage limits, tenant-facing onboarding wizard, per-tenant custom-field designer (Studio-like), native telephony/SMS rebuild, full email-template library migration, advanced reporting.

## 6. Risks & mitigations
- **API compatibility** with 133 workflows → contract-test first; buffer in Phase 4.
- **Data cleanup** (Meta values, duplicate modules, datetime) → handled in ETL with reconciliation; the audited counts are the acceptance bar.
- **Timeline** assumes 3 Laravel+Claude Code devs and the sanitized dump available by Phase 5; 2 devs → ~12–13 weeks.
- **PII/compliance** (immigration data, multi-company) → per-tenant isolation, encryption at rest, access logging, credential rotation.

## 7. How Claude Code executes this
1. Open the repo, read `CLAUDE.md` + this plan + `docs/DATA_MODEL.md` + `docs/reference/field-map.json`.
2. Take the current phase → **Plan Mode** → get approval → build the phase's tasks as **small PRs** in owner order.
3. Before each commit: `pint` → `phpstan` → `pest`. Before merge: `/code-review`. Before go-live: `/security-review`.
4. Only advance a phase when its **DoD** passes. Keep `CLAUDE.md` updated as decisions land.
