# Task Breakdown — per developer, task by task

Companion to `docs/PROJECT_PLAN.md` (phases/milestones). This is the **working assignment sheet**.
Effort is in **person-days**. Task IDs: **Z** = Zain · **SM** = Shahmeer · **SB** = Shahab.

## Team & how work is split

| Who | Role | Gets |
|---|---|---|
| **Zain** | **Tech Lead — database & core engine** | All **database** work (schema, migrations, DDL, ETL) and the **hardest** subsystems: metadata engine, SchemaManager, ACL enforcement, REST API core, security/performance |
| **Shahmeer** | **Senior developer — Studio UI & integrations** | Moderately complex: Studio builder UIs, dynamic rendering, CRM screens, webhooks, social/platform integrations, tenant provisioning command |
| **Shahab** | **Product Manager + junior developer (~50% dev)** | Straightforward, well-bounded dev: config/CI, seeders, simple entities & screens, DNC/Hot-Warm, OpenAPI/docs, tests, QA — **plus all PM duties** |

**Rule of engagement:** Zain builds the *engine and the pattern* first; Shahmeer and Shahab then build
*on* that pattern. Nobody builds on top of an engine piece that isn't merged — dependencies are listed per task.

---

## Phase 1 — Foundation + tenancy + metadata core (Weeks 1–2)

### Zain
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **Z-1.1** | App skeleton + conventions | Laravel 11 (PHP 8.3) + Filament v3, stancl/tenancy, spatie/permission, Passport, Sanctum, Horizon installed & configured; folder/namespace conventions; boots clean | 2 | — |
| **Z-1.2** | Central + tenant database layer | Two connections; stancl bootstrappers switching DB/cache/queue/filesystem per request & job; subdomain identification; wildcard-domain routing | 3 | Z-1.1 |
| **Z-1.3** | Metadata registry (schema) | Migrations + models for `tenant_modules`, `tenant_fields`, `tenant_option_lists`, `tenant_option_items`, `tenant_layouts`, `tenant_relationships`, `tenant_studio_changes` — per `STUDIO_API_RBAC.md` §1.2 **incl. the verified field flags** (help, comments, massupdate, duplicate_merge, reportable, importable, len) | 3 | Z-1.2 |
| **Z-1.4** | MetadataRepository + cache | Compiles a tenant's metadata into a cached structure `tenant:{id}:metadata:v{n}`; version bump invalidates; unit tests | 2 | Z-1.3 |
| **Z-1.5** | **SchemaManager (runtime DDL)** | validate (reserved names, type rules, per-tenant limits) → plan DDL → **snapshot** → apply in transaction/lock → write `tenant_studio_changes` + DDL log → bump cache. `{table}_custom` sidecars. **Rollback from a recorded change.** Tests: column created in that tenant only; rollback restores; limits/reserved names rejected | 5 | Z-1.4 |
| | | **Zain subtotal** | **15** | |

### Shahmeer
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SM-1.1** | Tenant provisioning command | `php artisan tenant:create {company}` → create DB, run tenant migrations, seed roles + first System Administrator; `tenants:migrate`; idempotent; tested | 2 | Z-1.2 |
| **SM-1.2** | Tenant auth panel | Filament login per subdomain, users in tenant DB, **TOTP 2FA** + backup codes, password policy, forgot-password | 3 | SM-1.1 |
| **SM-1.3** | **FieldTypeRegistry** | Every field type (text, textarea, enum, multienum, bool, int, decimal, currency, date, datetime, email, phone, url, relate, file/image) → Filament form component + table column + Eloquent cast + validation rules; extensible | 3 | Z-1.3 |
| **SM-1.4** | Super-admin (landlord) panel | Central-domain Filament panel authenticating platform Super Admins; list/create/suspend companies; tenant detail page | 3 | Z-1.2 |
| | | **Shahmeer subtotal** | **11** | |

### Shahab
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SB-1.1** | Tooling + CI | `.env.example` (no secrets), Pint config, PHPStan/Larastan (max), Pest setup, GitHub Actions running lint → analyse → test on every PR | 2 | Z-1.1 |
| **SB-1.2** | Claude Code project config | `.claude/settings.json` with SessionStart hook (composer install + tenant migrations) and a permission allow-list for composer/artisan/pest/pint | 1 | SB-1.1 |
| **SB-1.3** | Option-list seed data | Extract every dropdown's distinct values from the source data → seeders for `tenant_option_lists`/`items`, **first-char-capitalised labels preserved** | 2 | Z-1.3 |
| **SB-PM-1** | Project setup (PM) | Task board from this document, decision log, phase-1 kickoff, DoD tracking, weekly status | 2 | — |
| | | **Shahab subtotal** | **7** (5 dev + 2 PM) | |

**Phase 1 exit (M1):** create a tenant → log in → add a field via the metadata layer → column appears **in that tenant only**, audited, cache bumped; tenant-isolation test green; CI green.

---

## Phase 2 — Core data model + ACL engine (Weeks 3–5)

### Zain
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **Z-2.1** | Contactable base | Trait + shared migration columns from `field-map.json contactable_base` (names, phones, addresses, `do_not_call`, consent/`lawful_basis`, `assigned_user_id`, soft deletes, UUID PK char(36)) | 2 | Z-1.5 |
| **Z-2.2** | `HasCustomFields` trait | Loads `{table}_custom` sidecar; derives casts/fillable/validation from `tenant_fields`; transparent read/write; tests | 3 | Z-2.1 |
| **Z-2.3** | Polymorphic activities + audit | Meeting, Note, Document (+DocumentRevision), Email (+EmailText), Call, Task — each `morphTo` any record; `EmailAddress` morph + denormalized `primary_email`; polymorphic Audit log. **Replaces 154 link + 79 audit tables** | 5 | Z-2.1 |
| **Z-2.4** | **ACL engine** | `role`, `role_module_permissions` (view/list/edit/delete/import/export/mass_update × **All\|Owner\|Group\|None\|not_set**), `role_user`; Policies + **global query scopes used by BOTH UI and API**; auto-register permissions when a module is created (default deny); Group level via security-group tables; tests for **every** access level | 6 | Z-2.1 |
| **Z-2.5** | Complex entity migrations | **Company** (+sidecar), **Lead** (`vertical` enum + `stage`, vertical attribute groups), **Assessment** (88-field CRS/FSW → `scores` JSON + key typed columns) — migrations, models, relationships, factories | 4 | Z-2.2 |
| | | **Zain subtotal** | **20** | |

### Shahmeer
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SM-2.1** | Mid-tier entities | Student, StudyLead, LmiaCase, Client — migrations + models + relationships + factories, on the Contactable base | 4 | Z-2.2 |
| **SM-2.2** | **DynamicResource v1** | A Filament resource that builds `table()` + `form()` from `tenant_layouts` + `tenant_fields` via FieldTypeRegistry; proves the engine end-to-end on one module | 5 | SM-1.3, Z-1.4 |
| **SM-2.3** | Enum/dropdown system | PHP enums for known dropdowns **and** the same values seeded into `tenant_option_lists` so Studio can edit them; label-convention helper; `LBL_*` key generation | 3 | SB-1.3 |
| | | **Shahmeer subtotal** | **12** | |

### Shahab
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SB-2.1** | Simple entities | Affiliate, NewsletterSubscriber, SmsMessage, CallSummary — migrations + models + factories (straight from the field-map) | 3 | Z-2.2 |
| **SB-2.2** | Factories + demo seeders | Realistic factories for every entity + a demo-tenant seeder for testing | 3 | SB-2.1 |
| **SB-2.3** | Role seeder | Seed the **29 starter roles** from `docs/reference/roles.php` into each new tenant with a sensible default matrix | 2 | Z-2.4 |
| **SB-PM-2** | PM | Field-mapping questions to PO, DoD tracking, phase review | 2 | — |
| | | **Shahab subtotal** | **10** (8 dev + 2 PM) | |

**Phase 2 exit (M2):** migrations clean on a fresh tenant DB; a Regular User with **Owner** access sees only their own records **in both UI and API**; System Administrator sees all; relationship/factory tests green.

---

## Phase 3 — Studio, per tenant (Weeks 5–8)

### Zain
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **Z-3.1** | Relationship Manager (backend) | 1-M / M-M / 1-1 via SchemaManager: FK or pivot creation, `join_table` + both keys, **`role_column` + `role_column_value`**, `reverse`; both-sides relation wiring; tests | 4 | Z-1.5 |
| **Z-3.2** | Module Builder (backend) | Create a tenant module: table + `_custom` sidecar + metadata rows + activities morphs + **auto-registered permissions** + API registration; delete/soft-delete path | 4 | Z-3.1, Z-2.4 |
| **Z-3.3** | Governance backend | Change-request workflow (`requested → approved → applied → rolled_back`), **human-readable DDL preview**, approve/reject, per-tenant limits enforcement, audit trail, rollback execution | 4 | Z-1.5 |
| | | **Zain subtotal** | **12** | |

### Shahmeer
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SM-3.1** | **Field Manager UI** | Add/edit/delete fields, all types, per module; validation, default, required, length, and the verified flags (audited, massupdate, duplicate_merge, reportable, importable, help); **auto-generates `LBL_*` label keys** | 5 | Z-1.5, SM-1.3 |
| **SM-3.2** | **Dropdown Editor UI** | Per-tenant option lists: create/rename, add/remove/reorder items, key⇄label, used-by warning before delete | 3 | SM-2.3 |
| **SM-3.3** | **Layout Editor UI** | Drag-and-drop for **list / detail / edit / search** views: field placement, panels/tabs, column order & width; versioned layout JSON; live preview | 6 | SM-2.2 |
| **SM-3.4** | Relationship + Module Builder UI | Wizards on Zain's backends, with DDL preview shown before submit | 3 | Z-3.1, Z-3.2 |
| **SM-3.5** | Blueprints (export/import) | Export a tenant's metadata as a versioned JSON blueprint; import into another tenant | 2 | Z-3.3 |
| | | **Shahmeer subtotal** | **19** | |

### Shahab
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SB-3.1** | Governance UI (super admin) | Per-tenant Studio mode (`disabled`/`request-only`/`self-serve`) + limits form; pending-request queue list with approve/reject actions | 3 | Z-3.3 |
| **SB-3.2** | Studio test scenarios | Feature tests + a manual test script covering add field → reorder layout → create relationship → create module → rollback | 3 | SM-3.4 |
| **SB-3.3** | Studio user guide | Short guide for tenant System Administrators (how to add a field, edit a dropdown, change a layout) | 2 | SM-3.3 |
| **SB-PM-3** | PM | Studio scope control (this is the phase that grows), demo to PO, decision log | 3 | — |
| | | **Shahab subtotal** | **11** (8 dev + 3 PM) | |

**Phase 3 exit (M3):** a tenant admin adds a field, edits a dropdown, reorders a layout, creates a relationship and a new module — live immediately in UI **and** API, no deploy; super admin can gate/approve/roll back; other tenants unaffected.

---

## Phase 4 — CRM UI + DNC + Hot/Warm + settings (Weeks 7–9)

### Shahmeer *(phase lead)*
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SM-4.1** | Company + Lead screens | Full Filament resources on the dynamic renderer: list with filters, detail, create/edit, vertical-aware field groups, bulk actions | 4 | SM-2.2, Z-2.5 |
| **SM-4.2** | Activity timeline | Relation managers for meetings/notes/documents/emails/calls/tasks on every record; unified timeline view | 4 | Z-2.3 |
| **SM-4.3** | **Role matrix UI** | SuiteCRM-style editor: modules down, actions across, access-level dropdown per cell; role CRUD; assign users; tenant user management | 4 | Z-2.4 |
| | | **Shahmeer subtotal** | **12** | |

### Zain
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **Z-4.1** | Per-tenant settings | Settings store (SMTP, telephony/PBX, branding, enabled modules/verticals) with **encrypted secrets**; runtime mailer/telephony config built per tenant | 3 | Z-1.2 |
| **Z-4.2** | Performance pass | Metadata cache tuning, N+1 elimination on dynamic resources, index review on the new schema | 2 | SM-4.1 |
| | | **Zain subtotal** | **5** | |

### Shahab
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SB-4.1** | Simple entity screens | Affiliate, NewsletterSubscriber, SmsMessage, CallSummary, Student resources | 3 | SM-2.2 |
| **SB-4.2** | **DNC feature** | `do_not_call` toggle on records + list-view filter/toggle ("Do Not Call list" view), applied across all lead entities | 2 | SB-4.1 |
| **SB-4.3** | **Hot / Warm feature** | `hot_lead`/`warm_lead` toggles + two dashboard widgets aggregating across all verticals (name → record link, email, phone with click-to-call, module) | 3 | SB-4.1 |
| **SB-4.4** | Navigation + global search | Menu grouping, per-tenant enabled modules, global search config | 1 | SB-4.1 |
| **SB-PM-4** | PM | UAT-prep checklist, stakeholder demo, priorities | 2 | — |
| | | **Shahab subtotal** | **11** (9 dev + 2 PM) | |

**Phase 4 exit (M4):** staff operate the CRM per tenant; DNC + Hot/Warm work; a tenant admin creates users/roles; each company has its own SMTP/branding. **Internal beta.**

---

## Phase 5 — RESTful API + webhooks (Weeks 9–11)

### Zain *(phase lead)*
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **Z-5.1** | API foundation | `/api/v1` routing, response envelope (`data`/`meta`/`links`/`errors`), RFC-7807 errors, versioning, ETag/`If-Modified-Since`, **idempotency keys**, **locale-independent UTC datetime boundary** | 3 | Z-1.2 |
| **Z-5.2** | **Metadata-driven resources** | Generic controllers giving every module — **including Studio-created ones** — index/show/store/update/destroy, with `filter[…]`, `sort`, `include`, sparse `fields[…]`, cursor pagination | 5 | Z-5.1, Z-3.2 |
| **Z-5.3** | **API auth & limits** | OAuth2 `client_credentials` (Passport) + Personal Access Tokens (Sanctum) + API keys; **scopes**; per-tenant rate limits + `429` headers; request logging; client model mirroring `oauth2clients` (owner user, grant type, TTL, confidential) | 4 | Z-5.1 |
| **Z-5.4** | ACL in the API | Reuse the Phase-2 policies + query scopes so API access levels match the UI exactly; tests per level | 2 | Z-5.2, Z-2.4 |
| | | **Zain subtotal** | **14** | |

### Shahmeer
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SM-5.1** | **Outbound webhooks** | Tenant subscriptions per event (`lead.created`, `call.completed`, `{custom_module}.updated`, …), **HMAC-SHA256 signing** + timestamp, retries with exponential backoff, delivery log, manual replay | 5 | Z-5.1 |
| **SM-5.2** | API & webhook management UI | Per-tenant screens: create/revoke API clients & tokens, choose scopes, manage webhook subscriptions, view delivery log | 3 | SM-5.1, Z-5.3 |
| | | **Shahmeer subtotal** | **8** | |

### Shahab
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SB-5.1** | **OpenAPI 3.1 + Swagger UI** | Spec generated **per tenant** (reflects their Studio fields) + hosted Swagger UI | 3 | Z-5.2 |
| **SB-5.2** | Postman collection + examples | Ready-to-run collection, auth walkthrough, integration examples for partners | 2 | SB-5.1 |
| **SB-5.3** | API tests | Contract/feature tests for CRUD, filtering, pagination, scopes, rate limits (patterns from Zain) | 3 | Z-5.4 |
| **SB-PM-5** | PM | Integration-partner comms, credentials gathering for Phase 6 | 2 | — |
| | | **Shahab subtotal** | **10** (8 dev + 2 PM) | |

**Phase 5 exit (M5):** an external app authenticates, CRUDs a **Studio-created** module, and receives a signed webhook; OpenAPI matches reality; ACL identical to UI.

---

## Phase 6 — Integrations (Weeks 11–13)

### Shahmeer *(phase lead)*
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SM-6.1** | **Shared FieldMapper** | Per-source, per-tenant mapping pipeline: **Meta value canonicalisation** (`strtolower` + strip non-alphanumerics, both sides), invisible-Unicode phone cleaning, validation, **dedupe by email/phone**, owner assignment, event firing | 4 | Z-5.2 |
| **SM-6.2** | Meta + Instagram + WhatsApp | Graph API **Lead Ads** `leadgen` webhook → fetch → map; Instagram lead forms; **WhatsApp Cloud API** inbound messages + template sends threaded onto the lead | 5 | SM-6.1 |
| **SM-6.3** | Other platforms | LinkedIn / TikTok / Google lead forms **via the generic signed `/ingest/{source}` endpoint** (native connectors post-launch) | 1 | SM-6.1 |
| | | **Shahmeer subtotal** | **10** | |

### Zain
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **Z-6.1** | **Telephony / click-to-call** | Reverb + queued **Asterisk AMI** originate; **per-user extension/context** (as the source stores on `users`) + per-tenant PBX credentials; call events → `Call` records | 4 | Z-4.1 |
| **Z-6.2** | Vapi + SMS | Vapi inbound/post-call webhook handlers (classify, summarise, tag back to the lead); provider-agnostic **SMS adapter** + inbound webhook → `SmsMessage` | 3 | SM-5.1 |
| **Z-6.3** | **Legacy V8 adapter** *(bridge)* | Thin `/Api/V8/*` endpoints mapping onto the new services so the **133 existing n8n workflows keep running on day one** — only their base URL changes. Deleted after the post-launch rewrite | 3 | Z-5.2 |
| | | **Zain subtotal** | **10** | |

### Shahab
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SB-6.1** | **WordPress integration** | WP plugin: settings page (API key + field mapping), hooks for Gravity Forms / CF7 / WPForms / Elementor → `POST /api/v1/leads`; install docs | 4 | SB-5.2 |
| **SB-6.2** | Email sending | Per-tenant SMTP send path, email templates, test-send tool | 2 | Z-4.1 |
| **SB-6.3** | n8n pilot re-point | Re-point **one workflow per family** (intake, follow-up, AI reply, Vapi, SMS) to the adapter/new API and verify end-to-end; document the pattern for the post-launch bulk migration | 3 | Z-6.3 |
| **SB-PM-6** | PM | Credential collection (Meta app, WhatsApp, Vapi, PBX, SMS), integration sign-off | 3 | — |
| | | **Shahab subtotal** | **12** (9 dev + 3 PM) | |

**Phase 6 exit (M6):** a Meta/WordPress lead lands via the API into the right tenant, follow-ups fire, a Vapi call tags back, click-to-call works, SMS/email logged.

---

## Phase 7 — Data migration / ETL (Weeks 12–13 · runs LOCALLY)

### Zain *(phase lead — all database work)*
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **Z-7.1** | ETL framework | Read-only `legacy` connection to the local `crmga_source` DB; `crm:migrate-legacy` command with `--dry-run`, batching, resumable + **idempotent**; transformer per entity from `field-map.json` | 4 | Z-2.5 |
| **Z-7.2** | Data correctness | `email_addr_bean_rel` → `email_addresses` join → `primary_email`; **all datetimes UTC `Y-m-d H:i:s`**; dropdown/Meta value cleanup; dedupe the legacy duplicate modules (`ga_immcan1/2/3`, `hamid_*`, `ga_client_development1`) | 3 | Z-7.1 |
| **Z-7.3** | **Import existing customisation into Studio** | `fields_meta_data` + list/detail view defs → `tenant_fields` / `tenant_layouts` (their current custom fields and layouts become Studio metadata) | 3 | Z-7.1, Z-1.3 |
| **Z-7.4** | Reconciliation | Per-entity count report vs the audited numbers (Company ≈ 21,014, Assessment_Score ≈ 8,147, Study ≈ 4,782 …); discrepancy log | 2 | Z-7.2 |
| | | **Zain subtotal** | **12** | |

### Shahmeer
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SM-7.1** | Post-migration UI verification | Every migrated entity renders correctly; fix field/display/layout mismatches; verify imported Studio metadata renders | 3 | Z-7.3 |

### Shahab
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SB-7.1** | Data QA | Spot-check records against the source, verify counts, log and triage discrepancies | 2 | Z-7.4 |
| **SB-PM-7** | PM | Migration sign-off, cutover scheduling with the PO | 2 | — |

**Phase 7 exit (M7):** staging tenant loaded; counts reconcile; existing custom fields visible in Studio. **Runbook: `PROJECT_PLAN.md` Appendix A.**

---

## Phase 8 — Hardening, UAT, go-live (Weeks 13–14)

### Zain
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **Z-8.1** | Security review + fixes | `/security-review`; verify **tenant isolation**, **Studio DDL safety**, **API scopes/authz**, encrypted secrets, audit logging; remediate | 3 | all |
| **Z-8.2** | Performance + deployment | Octane/FrankenPHP worker mode, cache/queue tuning; deployment pipeline running `tenants:migrate`; **cutover runbook + rollback** (parallel-run → switch) | 3 | Z-8.1 |
| | | **Zain subtotal** | **6** | |

### Shahmeer
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SM-8.1** | Roles finalisation + UAT fixes | Final permission matrix for all 29 roles; fix UAT findings | 3 | SM-4.3 |
| **SM-8.2** | Backups + monitoring | Per-tenant backup & export, health checks, error alerting (Sentry/Flare), Horizon monitoring | 2 | Z-8.2 |
| | | **Shahmeer subtotal** | **5** | |

### Shahab
| ID | Task | Deliverable | Days | Depends |
|---|---|---|---|---|
| **SB-8.1** | UAT coordination | Test scripts per role, run UAT with staff, log/triage findings, sign-off pack | 3 | SM-8.1 |
| **SB-8.2** | Documentation | Admin guide, user guide, Studio guide, release notes | 2 | — |
| **SB-PM-8** | Go-live PM | Go-live checklist, comms, training session, post-launch backlog | 3 | — |
| | | **Shahab subtotal** | **8** (5 dev + 3 PM) | |

**Phase 8 exit (M8):** UAT sign-off; security review clean; backups + rollback tested; **first company LIVE**; a second company onboardable from a blueprint.

---

## Load summary

| Phase | Zain | Shahmeer | Shahab (dev + PM) |
|---|---|---|---|
| 1 Foundation + metadata | 15 | 11 | 5 + 2 |
| 2 Data model + ACL | 20 | 12 | 8 + 2 |
| 3 Studio | 12 | 19 | 8 + 3 |
| 4 CRM UI | 5 | 12 | 9 + 2 |
| 5 REST API | 14 | 8 | 8 + 2 |
| 6 Integrations | 10 | 10 | 9 + 3 |
| 7 Data migration | 12 | 3 | 2 + 2 |
| 8 Hardening + go-live | 6 | 5 | 5 + 3 |
| **Total (person-days)** | **94** | **80** | **54 + 19** |

**Capacity in 14 weeks** (5 days/week): Zain **70** · Shahmeer **70** · Shahab **~35 dev** (the rest is PM).

### ⚠️ The honest read: this scope needs ~17 weeks with this team
Concentrating all complex + database work on one person makes **Zain the critical path at ~134% load**;
Shahmeer is at ~114% and Shahab's dev list exceeds his non-PM time. Pick one:

| Fix | Effect |
|---|---|
| **1. Extend to ~17 weeks** (recommended, no scope loss) | Everything above, comfortably; Zain ≈ 85 available vs 94 needed — still tight, combine with #2 |
| **2. Shahmeer pairs on engine work** (Z-1.5 SchemaManager, Z-2.4 ACL) and owns one of them after Zain sets the pattern | Moves ~8–10 days off Zain; also removes the single-point-of-failure risk |
| **3. Add a 4th developer** | Hits ~14 weeks; new dev takes Shahab's dev list so Shahab is PM-only |
| **4. Cut scope** — defer Module Builder (Z-3.2 + part of SM-3.4), native social connectors, and the WordPress plugin to post-launch | Saves ~12–14 days; keeps 14 weeks with fields/dropdowns/layouts/relationships Studio only |

**My recommendation:** **#1 + #2** — plan **16–17 weeks**, and have Shahmeer pair with Zain on the
SchemaManager and ACL engine so the two hardest pieces aren't single-threaded. If the 14-week date is
fixed, take **#3** (a 4th dev) or **#4** (defer Module Builder + WordPress plugin).

## Shahab's PM responsibilities (in addition to his dev tasks — ~19 days total)
- Maintain the task board from this document; track each phase's **DoD** and don't let a phase be
  declared done early.
- Own the **decision log** (`ARCHITECTURE.md` §5 open items) and chase the PO for answers **before** they block a phase.
- **Guard Studio scope** in Phase 3 — that's the phase most likely to expand.
- Collect credentials ahead of Phase 6 (Meta app, WhatsApp, Vapi, PBX/AMI, SMS gateway, SMTP/IMAP).
- Run demos at each milestone; coordinate UAT, training, go-live comms and the post-launch backlog.
- Weekly status: progress vs plan, blockers, risks, next week's tasks.
