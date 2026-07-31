# crmga — CRM (Laravel rebuild, multi-tenant in its final phase)

A CRM (Laravel + Filament) rebuilt from the Gunness & Associates SuiteCRM 8.8.0 system ("crmga"), which
becomes a **multi-tenant SaaS** (database-per-tenant) in its final phase.
**Built and launched single-tenant first**, then converted for other immigration firms. It keeps the existing
n8n automation, Asterisk telephony, Vapi voice and SMS working — a thin legacy adapter means the 133 existing
n8n workflows only change their base URL — while replacing the CRM core with a clean, maintainable schema.
First live: Gunness & Associates (single tenant); other companies onboarded after the Phase 8 conversion.

> **Status:** planning + specs. The Laravel application is scaffolded from Phase 1 onward.
> **Secrets:** none in this repo, by design. Configure via `.env` (never commit it).

## Documentation (`/docs`)
| File | What |
|---|---|
| `START-HERE.md` | **Handover guide — read this first** |
| `docs/PROJECT_PLAN.md` | **Master plan — 14 weeks, 8 phases, tenancy last (start here)** |
| `docs/TASK_BREAKDOWN.md` | **Task-by-task assignments — Zain (backend) / Shahmeer (frontend) + load analysis** |
| `docs/STUDIO_API_RBAC.md` | Studio · REST API · RBAC design (+ live-schema verification) |
| `docs/ARCHITECTURE.md` | **Technical approach per layer + concern×phase map + open decisions to lock first** |
| `docs/DATA_MODEL.md` | Target data model (from the live 481-table DDL) |
| `docs/BACKEND_BRIEF_ZAIN.md` | **Backend brief — the complete backend spec + copy-paste Claude Code prompts (Zain)** |
| `docs/contracts/` | **Frozen contracts: layout JSON schema, field-type map, API contract** |
| `docs/PHASE1_KICKOFF.md` | Copy-paste Claude Code prompts + per-entity checklist to start |
| `docs/crmga_Frontend_Design_Spec.docx` | **Frontend design spec — tokens, shells, components, wireframes, every screen's fields** |
| `docs/crmga_CRM_Functional_Screen_Spec.docx` | **Functional & screen specification — every screen, what it shows, what it does** |
| `docs/reference/field-map.json` | Per-entity source tables → columns |
| `docs/reference/schema.json` | Full parsed source DDL (481 tables) |
| `docs/reference/roles.php` | The 29 ACL roles to seed |
| `docs/reference/crmga_full_schema.sql` | Raw source DDL (structure only, no data) |
| `scripts/` | Read-only audit scripts for the source system |
| `docs/archive/` | Superseded earlier plans (history only) |
| `CLAUDE.md` | Project memory for Claude Code (stack, rules, gotchas) |

## Getting started
1. Read `START-HERE.md`, then `CLAUDE.md`.
2. Follow `docs/PROJECT_PLAN.md` (phases) and `docs/TASK_BREAKDOWN.md` (your tasks).
3. Start building with `docs/PHASE1_KICKOFF.md`.
3. Build in small PRs; tests + lint + static analysis must pass before merge.
