# crmga — Multi-tenant CRM (Laravel rebuild)

A **multi-tenant SaaS CRM** (Laravel + Filament, **database-per-tenant**) **sold to other immigration
firms** — rebuilt from the Gunness & Associates SuiteCRM 8.8.0 system ("crmga"). It keeps the existing
n8n automation, Asterisk telephony, Vapi voice, and SMS working by exposing a
**SuiteCRM-V8-compatible API**, while replacing the CRM core with a clean, maintainable schema.
First tenant live = Gunness & Associates; more companies onboarded after.

> **Status:** planning + specs. The Laravel application is scaffolded from Phase 1 onward.
> **Secrets:** none in this repo, by design. Configure via `.env` (never commit it).

## Documentation (`/docs`)
| File | What |
|---|---|
| `docs/PROJECT_PLAN.md` | **Master plan — 14-week timeline, 8 phases, milestones (start here)** |
| `docs/TASK_BREAKDOWN.md` | **Task-by-task assignments per developer (Zain / Shahmeer / Shahab) + load analysis** |
| `docs/ARCHITECTURE.md` | **Technical approach per layer + concern×phase map + open decisions to lock first** |
| `docs/PLAN.md` | Architecture + full build plan |
| `docs/CLAUDE_CODE_BUILD_PLAN.md` | 8–9 week phased plan for building **with Claude Code** |
| `docs/DATA_MODEL.md` | Target data model (from the live 481-table DDL) |
| `docs/PHASE1_KICKOFF.md` | Copy-paste Claude Code prompts + per-entity checklist to start |
| `docs/reference/field-map.json` | Per-entity source tables → columns |
| `docs/reference/schema.json` | Full parsed source DDL (481 tables) |
| `docs/reference/roles.php` | The 29 ACL roles to seed |
| `CLAUDE.md` | Project memory for Claude Code (stack, rules, gotchas) |

## Getting started
1. Read `CLAUDE.md`, then `docs/DATA_MODEL.md`.
2. Follow `docs/PHASE1_KICKOFF.md` to scaffold Laravel + tenancy + RBAC.
3. Build in small PRs; tests + lint + static analysis must pass before merge.
