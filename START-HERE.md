# START HERE — crmga CRM handover pack

Everything the development team needs. **Revision 3 — 2026-07-29.**

**What is being built:** a **Laravel 11 + Filament 3** CRM replacing the heavily-customised SuiteCRM 8.8.0
system. It is a **metadata-driven engine** — metadata generates the schema, the screens, the permissions and
the API — which is what makes **Studio** (customising fields, dropdowns and layouts with no deployment)
possible. A modern **REST API** covers WordPress, Meta and any other platform, and a thin legacy adapter
keeps the **133 existing n8n workflows running** with only a base-URL change.

**Delivery order — read this carefully, it changed:**
```
Weeks 1–13   Build, migrate, go live   →  SINGLE TENANT (Gunness & Associates)
Weeks 13–14  Convert to multi-tenant   →  SaaS-ready, second company onboardable
```
Multi-tenancy is the **final phase**. One database is built now, designed so that **the same database becomes
tenant #1** on conversion — which is only cheap if the ten **tenancy-ready rules** are followed from day one
(`docs/PROJECT_PLAN.md` §3, enforced by a CI test).

**Team:** **Zain — backend** · **Shahmeer — frontend**. The Product Owner covers product decisions and UAT.

---

## 1. Read in this order

| # | File | Why | Who |
|---|---|---|---|
| 1 | `START-HERE.md` | This file | Both |
| 2 | `docs/crmga_CRM_Build_Plan.pdf` | The plan at a glance — timeline, lanes, decisions | Both + stakeholders |
| 3 | `CLAUDE.md` | Project memory: stack, golden rules, the behaviours we must preserve | Both |
| 4 | `docs/PROJECT_PLAN.md` | The plan: 8 phases, milestones, **§3 tenancy-ready rules**, **§6 what was cut** | Both |
| 5 | `docs/TASK_BREAKDOWN.md` | **Your tasks** — every task by ID with estimates and dependencies, plus the honest load position | Both |
| 6 | `docs/ARCHITECTURE.md` | How each layer is built, plus **§5 open decisions** | Zain, then Shahmeer |
| 7 | `docs/STUDIO_API_RBAC.md` | Studio, REST API and permissions design (read the revision-3 scope note first) | Zain, Shahmeer |
| 8 | `docs/DATA_MODEL.md` | Entities and how the 43 legacy modules consolidate | Zain |
| 9 | `docs/crmga_CRM_Functional_Screen_Spec.docx` | Every screen, what each page shows and does | Shahmeer |
| 9b | `docs/crmga_Frontend_Design_Spec.docx` | **How every screen is built — design tokens, layout shells, component library, wireframes, fields per screen** | Shahmeer (primary) |
| 10 | `docs/PHASE1_KICKOFF.md` | **Copy-paste Claude Code prompts — start here on day one** | Both |

`docs/reference/` is data, not reading: the parsed source schema, the field map, the roles list and the raw
DDL. Claude Code queries these while writing migrations.

---

## 2. Who does what

| | **Zain — Backend** | **Shahmeer — Frontend** |
|---|---|---|
| **Owns** | Database and migrations · the metadata engine and `SchemaManager` (runtime DDL) · models and business logic · ACL enforcement · REST API and the legacy adapter · integrations · ETL/data migration · security, performance, deployment · the Phase 8 multi-tenancy conversion | The entire Filament interface · dynamic rendering from metadata · every module's screens · activity timeline · dashboards · DNC and Hot/Warm · the role matrix UI · all Studio builder screens · settings screens · the Phase 8 super-admin panel |
| **Capacity** | 70 days | 70 days |

**The contract between the lanes.** In week 1 Zain lands and then **freezes** the metadata shape, the
**layout JSON contract** and the option-list structure, with seeded fixtures. Shahmeer builds everything
against those fixtures, so the frontend is never waiting on backend work.

---

## 3. Read this before you start: the plan does not fit, and that is a decision to make

The 14-week date is fixed and scope has already been cut hard (`PROJECT_PLAN.md` §6 — Module Builder,
relationships, webhooks, native social connectors, the import wizard, the report builder and the workflow
rewrite are all out). Even so, the remaining work is **101 days for Zain and 89 for Shahmeer against 70 each**.

Two developers in fourteen weeks realistically deliver about 70% of it. The options, in the order recommended
in `TASK_BREAKDOWN.md`:

1. **Ship in two releases (recommended).** Weeks 1–14 deliver the CRM, permissions, all screens, the REST API
   with the legacy adapter, data migration and go-live — **without Studio**. Studio and the tenancy conversion
   become Release 2, roughly six further weeks.
2. **Keep Studio in Release 1, move the tenancy conversion to Release 2.**
3. **Add the third developer back** — the full 14-week plan becomes achievable.
4. **Extend to about 19 weeks** for the same scope with honest dates.

Whichever is chosen, the **metadata engine stays in Phase 1** — Studio and the dynamic API both depend on it,
and retrofitting it later costs far more than building it now.

---

## 4. Decisions needed from the Product Owner

**Before Phase 1**
- Which of the four options in §3.
- Hosting and backups.
- Confirm Filament (no separate single-page application) and keeping the source `char(36)` UUID primary keys.
- Confirm the reduced v1 scope in `PROJECT_PLAN.md` §6.

**Before Phase 8 (multi-tenancy)**
- The domain and **wildcard DNS/TLS** for company subdomains, plus the central admin domain.
- The default Studio governance mode for new companies (disabled, request-only or self-service) and its limits.

**Per phase**
- Field-mapping answers (Phases 2–3) · WordPress and Meta credentials (Phase 5) · the sanitized dump, used
  **locally** (Phase 6) · UAT sign-off and credential rotation (Phase 7).

---

## 5. Day one

```bash
git clone <repo>            # or: git clone crm-foundation.bundle crm
cd crm
claude                      # Claude Code loads CLAUDE.md automatically
```

Then, in Claude Code:

> Read `docs/PROJECT_PLAN.md` (including §3 tenancy-ready rules), `docs/ARCHITECTURE.md` and
> `docs/STUDIO_API_RBAC.md`. We are starting **Phase 1**. Enter Plan Mode and propose the build.

Approve the plan, then work through `docs/PHASE1_KICKOFF.md`, **one pull request per task**.

**Working rules**
1. Start each phase in **Plan Mode**; get the plan approved before coding.
2. One feature per pull request.
3. Before every commit: `./vendor/bin/pint` → `./vendor/bin/phpstan analyse` → `./vendor/bin/pest`.
4. `/code-review` before merge; `/security-review` in Phase 7.
5. A phase is not done until its **Definition of Done** passes.
6. **Build the metadata engine before any hardcoded entity.**
7. **Do not install `stancl/tenancy` before Phase 8, and never add a `tenant_id` column.**

---

## 6. The three behaviours we must preserve

1. **Datetimes are always `Y-m-d H:i:s` in UTC.** The old API silently blanked datetimes because it parsed
   them against the authenticated API user's own locale. Our API must be locale-independent.
2. **Incoming dropdown values must be canonicalised.** Meta lead-form answers arrive lower-cased,
   underscored, inconsistently punctuated and carrying invisible Unicode marks. Canonicalise both the incoming
   value and each option key (lower-case, strip non-alphanumerics) before matching; unmatched values become
   null, never a wrong match.
3. **Dropdown labels capitalise only the first character** (`follow_up` → "Follow up"). Never Title-Case them.

---

## 7. Security rules for this pack

- **No credentials anywhere in this package**, by design. Secrets live in `.env`, which is git-ignored.
- The original system-reference document (the blueprint) **contains live production passwords**. Do not give
  that document to contractors or add it to the repository — remove its credentials appendix first.
- Rotate the production credentials that have circulated: server SSH, database, Asterisk AMI, the n8n VPS,
  FTP and the mailbox password.
- All work happens against a **copy** of production data. Never point a script or migration at the live
  database.
- The scripts in `scripts/` are read-only by design (SELECT and SHOW only) and exclude password columns.

---

## 8. What is in this pack

| Path | Contents |
|---|---|
| `START-HERE.md` | This guide |
| `CLAUDE.md` | Project memory for Claude Code |
| `README.md` | Repository front door and document index |
| `.gitignore` | Laravel-ready; also blocks committing database dumps |
| `docs/PROJECT_PLAN.md` | The plan — 8 phases, tenancy last, tenancy-ready rules, scope cuts, local ETL runbook |
| `docs/TASK_BREAKDOWN.md` | Task-by-task assignments for Zain and Shahmeer, with the load analysis |
| `docs/ARCHITECTURE.md` | Technical approach per layer, concern-by-phase map, open decisions |
| `docs/STUDIO_API_RBAC.md` | Studio, REST API and permissions design, verified against the live schema |
| `docs/DATA_MODEL.md` | Target entities and the consolidation of the 43 legacy modules |
| `docs/PHASE1_KICKOFF.md` | Claude Code prompts for Phases 1–2 and the per-entity checklist |
| `docs/crmga_CRM_Functional_Screen_Spec.docx` | Functional and screen specification |
| `docs/crmga_Frontend_Design_Spec.docx` | Frontend design specification — tokens, shells, components, wireframes |
| `docs/crmga_CRM_Build_Plan.pdf` | Plan summary for the team and stakeholders |
| `docs/reference/schema.json` | Parsed DDL of all 481 source tables |
| `docs/reference/crmga_full_schema.sql` | The raw source DDL (structure only, no data) |
| `docs/reference/field-map.json` | Source tables mapped to target entities and columns |
| `docs/reference/roles.php` | The 29 ACL roles to seed |
| `docs/archive/` | Superseded earlier plans — history only, do not build from them |
| `scripts/` | Read-only audit scripts for the source system |
| `crm-foundation.bundle` | Git bundle of this repository with full history |

**To get the repository from the bundle:**
```bash
git clone crm-foundation.bundle crm
cd crm
git remote set-url origin https://github.com/Gunness-and-Associates/crm.git
git push -u origin claude/crm-repo-setup-d3arzu
```
