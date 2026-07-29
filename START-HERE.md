# START HERE — crmga CRM handover pack

Everything the development team needs to build the **multi-tenant SaaS CRM** that replaces the
Gunness & Associates SuiteCRM 8.8.0 system. Read this file first, then follow the order below.

**What is being built:** a Laravel 11 + Filament 3 CRM, **one database per customer company**, sold to
other immigration firms. It is a **metadata-driven engine** — per-tenant metadata generates the schema,
the screens, the permissions and the API, which is what makes **Studio** (each company customising its
own fields, layouts, relationships and modules) possible. A modern **REST API** with OpenAPI and signed
webhooks integrates WordPress, Meta/Instagram, WhatsApp and any other platform. First company live =
Gunness & Associates.

---

## 1. Read in this order

| # | File | Why | Who |
|---|---|---|---|
| 1 | `START-HERE.md` | This file | Everyone |
| 2 | `docs/crmga_CRM_Build_Plan.pdf` | The plan on 2 pages — timeline, roles, decisions | Everyone (share with stakeholders) |
| 3 | `CLAUDE.md` | Project memory: stack, golden rules, the 3 behaviours we must preserve | Everyone |
| 4 | `docs/PROJECT_PLAN.md` | Master plan: 8 phases, milestones, Definition of Done per phase | Everyone |
| 5 | `docs/TASK_BREAKDOWN.md` | **Your tasks** — every task assigned to Zain / Shahmeer / Shahab with estimates and dependencies | Everyone |
| 6 | `docs/ARCHITECTURE.md` | How each layer is built + **§5 open decisions** | Zain, Shahmeer |
| 7 | `docs/STUDIO_API_RBAC.md` | Deep design: Studio, REST API, per-tenant roles (+ appendix verified against the live schema) | Zain, Shahmeer |
| 8 | `docs/DATA_MODEL.md` | Target entities and how the 43 legacy modules consolidate | Zain |
| 9 | `docs/crmga_CRM_Functional_Screen_Spec.docx` | Every screen, what each page shows, what users can do (≈109 screens) | Shahmeer, Shahab |
| 10 | `docs/PHASE1_KICKOFF.md` | **Copy-paste Claude Code prompts to start building today** | Everyone |

`docs/reference/` is data, not reading: the parsed source schema, the field map, the roles list and the
raw DDL. Claude Code queries these while writing migrations.

---

## 2. Who does what

| Person | Role | Owns |
|---|---|---|
| **Zain** | Tech lead — database & core engine | All database work (schema, migrations, runtime DDL, ETL) and the hardest subsystems: metadata engine, `SchemaManager`, ACL enforcement, REST API core, security, performance, deployment |
| **Shahmeer** | Senior developer — Studio UI & integrations | Studio builder UIs, dynamic rendering, CRM screens, role-matrix UI, webhooks, social/WordPress integrations, tenant provisioning |
| **Shahab** | Product Manager + developer (~50% dev) | CI/tooling, seeders, simple entities and screens, DNC, Hot/Warm, OpenAPI & docs, tests, QA — **plus** the board, decision log, credentials, demos, UAT and go-live |

Full task list with IDs, day estimates and dependencies: **`docs/TASK_BREAKDOWN.md`**.

---

## 3. Before writing code — decisions the team needs from the owner

These are in `docs/ARCHITECTURE.md` §5 with a recommendation next to each. Shahab should chase them.

**Blocking (needed for Phase 1)**
- Timeline option — A: 3 devs / ~14 weeks · B: 4 devs / ~11–12 weeks · C: staged (9 weeks to go-live, Studio after). *Note the load analysis in `TASK_BREAKDOWN.md`: the full scope realistically needs ~16–17 weeks with three people.*
- Hosting and infrastructure + per-tenant backup plan.
- Domain and **wildcard DNS/TLS** (`*.crm.<domain>`) plus the central admin domain.
- **Studio governance default** — do new companies start `disabled`, `request-only` or `self-serve`, and with what limits.
- Confirm Filament UI (no separate SPA) and keeping the source `char(36)` UUID primary keys.

**Should decide by Phase 2–3**
- Build the thin legacy `/Api/V8/*` adapter (≈3 days) so the 133 existing n8n workflows keep running on day one, or rewrite them all up front. *(Recommended: build the adapter, migrate in waves.)*
- Which social platforms are must-have in v1 (recommended: Meta + WhatsApp + WordPress; others via the generic ingest endpoint).
- Whether any of Quotes/Invoices, report builder, Campaigns, Knowledge Base, Events or Projects are needed as built-ins.

**Provide later (per phase)**
- Phase 6: Meta app, WhatsApp, Vapi, Asterisk/PBX, SMS gateway and SMTP/IMAP credentials — into `.env`, never committed.
- Phase 7: the sanitized database dump, used **locally** (runbook: `PROJECT_PLAN.md` Appendix A).

---

## 4. Day 1 — how to start

```bash
git clone <repo>            # or: git clone crm-foundation.bundle crm
cd crm
claude                      # Claude Code picks up CLAUDE.md automatically
```

Then, in Claude Code:

> Read `docs/PROJECT_PLAN.md`, `docs/ARCHITECTURE.md` and `docs/STUDIO_API_RBAC.md`.
> We are starting **Phase 1**. Enter Plan Mode and propose the build.

Approve the plan, then work through `docs/PHASE1_KICKOFF.md` §1.1 → §1.7, **one pull request per task**.

**Working rules (every phase, every task)**
1. Start the phase in **Plan Mode**; get the plan approved before coding.
2. One entity or feature per pull request — small diffs.
3. Before every commit: `./vendor/bin/pint` → `./vendor/bin/phpstan analyse` → `./vendor/bin/pest`.
4. Run `/code-review` before merge. `/security-review` before go-live.
5. A phase is not finished until its **Definition of Done** in `PROJECT_PLAN.md` passes.
6. **Build the metadata engine before any hardcoded entity** — anything hardcoded first gets rewritten twice.

---

## 5. The three behaviours we must preserve

Learned the hard way on the existing system; all three are in `CLAUDE.md`.

1. **Datetimes are always `Y-m-d H:i:s` in UTC.** The old API silently blanked datetimes because it parsed them against the authenticated API user's own locale. Our API must be locale-independent.
2. **Incoming dropdown values must be canonicalised.** Meta lead-form answers arrive lowercased, underscored, inconsistently punctuated and carrying invisible Unicode marks. Canonicalise both the incoming value and each option key (lowercase, strip non-alphanumerics) before matching; unmatched values become null, never a wrong match.
3. **Dropdown labels capitalise only the first character** (`follow_up` → "Follow up"). Do not Title-Case them.

---

## 6. Security rules for this pack

- **There are no credentials anywhere in this package**, by design. All secrets go in `.env`, which is git-ignored.
- The original system-reference document (the blueprint) **contains live production passwords in its credentials appendix**. Do **not** hand that document to contractors or add it to the repository — remove that section first.
- Rotate the production credentials that have already circulated in documents and chat: server SSH, database, Asterisk AMI, n8n VPS, FTP, and the mailbox password.
- All work happens against a **copy** of production data. Never point a script or migration at the live database.
- The scripts in `scripts/` are **read-only by design** (SELECT/SHOW only, no writes, no DDL) and exclude password columns from their output.

---

## 7. What is in this pack

| Path | Contents |
|---|---|
| `START-HERE.md` | This guide |
| `CLAUDE.md` | Project memory for Claude Code |
| `README.md` | Repository front door and document index |
| `.gitignore` | Laravel-ready; also blocks committing database dumps |
| `docs/PROJECT_PLAN.md` | Master plan — 8 phases, 14 weeks, milestones, DoD, local ETL runbook |
| `docs/TASK_BREAKDOWN.md` | Task-by-task assignments per developer + load analysis |
| `docs/ARCHITECTURE.md` | Technical approach per layer, concern×phase map, open decisions |
| `docs/STUDIO_API_RBAC.md` | Studio, REST API and per-tenant RBAC design (+ live-schema verification) |
| `docs/DATA_MODEL.md` | Target entities, consolidation of the 43 legacy modules |
| `docs/PHASE1_KICKOFF.md` | Claude Code prompts for Phases 1–2 + per-entity checklist |
| `docs/crmga_CRM_Functional_Screen_Spec.docx` | Functional & screen specification (≈109 screens) |
| `docs/crmga_CRM_Build_Plan.pdf` | Team/stakeholder plan summary |
| `docs/reference/schema.json` | Parsed DDL of all 481 source tables (columns + indexes) |
| `docs/reference/crmga_full_schema.sql` | The raw source DDL (structure only, no data) |
| `docs/reference/field-map.json` | Source tables → target entities and columns |
| `docs/reference/roles.php` | The 29 ACL roles to seed per tenant |
| `docs/archive/` | Two earlier plan documents, clearly marked superseded — history only |
| `scripts/crm_db_schema_modules.sql` | Read-only SQL: schema + exact per-module record counts |
| `scripts/crm_readonly_audit.sh` | Read-only server audit (modules, email, schedulers, hooks, telephony) |
| `scripts/crm_api_audit.py` | Read-only audit via the existing V8 API (modules, counts, fields) |
| `crm-foundation.bundle` | Git bundle containing this repository with full commit history |

**To get the repository from the bundle:**
```bash
git clone crm-foundation.bundle crm
cd crm
git remote set-url origin https://github.com/Gunness-and-Associates/crm.git
git push -u origin claude/crm-repo-setup-d3arzu
```

---

## 8. What is not in scope for version 1

Self-service company sign-up and billing · a drag-and-drop report builder · Quotes/Invoices/Products,
Campaigns, Knowledge Base, Events and Projects as built-in modules (companies can build simple versions
in Studio) · field-level permissions · a client portal and native mobile app · rebuilding the telephony
and messaging providers in-house. Details in the screen specification, section 12.3.
