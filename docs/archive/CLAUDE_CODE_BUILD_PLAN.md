> ## ⚠️ SUPERSEDED — DO NOT BUILD FROM THIS DOCUMENT
> This was the earlier 9-week plan. It assumed a SuiteCRM-V8-compatible API and treated
> Studio as a later addition. Both changed. The current plan is **`docs/PROJECT_PLAN.md`**
> (14 weeks, Studio in v1, modern REST API) with tasks in **`docs/TASK_BREAKDOWN.md`**.
> Kept only for history.

# crmga → Laravel — Claude Code Build Plan (8–9 week v1)

Build the multi-tenant Laravel CRM **with Claude Code + your dev team**, targeting a
**production-usable v1 in 8–9 weeks**, then a fast-follow. Companion to `PLAN.md`. No credentials in this file.

---

## Reality check (read first)
8–9 weeks is aggressive. It works **only** if we scope v1 to the operational core and **keep n8n + telephony external** (the CRM exposes a SuiteCRM-V8-compatible API so the **133 workflows keep working with just a base-URL re-point**). We do **not** rebuild workflows/telephony natively in v1.

**In scope for the 8–9 week v1**
- Multi-tenant Laravel + Filament CRM (DB-per-tenant, launched with the one tenant).
- Core entities (consolidated), DNC + Hot/Warm, roles.
- **SuiteCRM-V8-compatible API + OAuth2** → n8n keeps running.
- ETL of the **high-value modules** (Companies 21k, GALead, Imm_Biz, HQ_Students, Study, Assessment, LMIA, Newsletter, Clients).
- Click-to-call + Vapi + SMS + email **wired via API/webhooks** (external systems unchanged).
- Cutover (parallel-run → switch).

**Deferred to fast-follow (weeks 10+)** — *not* in the 8–9 weeks
- Native re-implementation of telephony/SMS in Laravel (stay external for v1).
- Migrating all 212 email templates; the ≤9-row / duplicate legacy modules; full historical long-tail.
- Stock activity modules (Cases/Meetings/Calls/Tasks/Notes/Documents) unless flagged required.
- SaaS billing/self-serve onboarding; advanced reporting.

**Conditions to actually hit 8–9 weeks**
- **3 devs** fluent in Laravel + Claude Code (2 devs → ~11–13 wks, not 8–9).
- The **4 decisions locked now** + **schema export delivered by day 2–3**.
- You available for fast field-mapping answers + UAT.
- Scope held (no mid-flight "also rebuild all workflows/telephony").

---

## How we run it in Claude Code (every phase)
- **One phase = one milestone; one module/feature = one PR.** Small diffs = better Claude Code output + faster review.
- **Plan Mode to start each phase** (Claude Code proposes → you approve → it builds).
- **Guardrail before every commit:** `pint` → `phpstan` → `pest`. `/code-review` before merge; `/security-review` in Phase 7.
- **`CLAUDE.md` is the source of truth** (stack, the SuiteCRM gotchas, commands, conventions). Keep it updated.
- **Parallelize with subagents** — scaffold independent entities/resources concurrently.
- **Never commit secrets** (`.env.example` only). **Work against a COPY of prod**, never live.
- **MCP:** n8n (connected) for workflow re-pointing; **authorize the GitHub connector** for PR/CI; read-only MySQL MCP on the prod copy for ETL.

**Gotchas Claude Code must honor (put in CLAUDE.md):** V8 datetime → always send `YYYY-MM-DD HH:MM:SS` UTC; Meta-Ads value **canonicalisation**; label convention = first char capitalised only.

---

## Phases

| Phase | Weeks | Ships |
|---|---|---|
| 1 Foundation + tenancy | 1 | Bootstrapped app, CI, tenancy, auth, RBAC scaffold |
| 2 Core data model + migrations | 2 | All core entities as migrations/models/tests |
| 3 CRM UI (Filament) + DNC + Hot/Warm | 3 | Staff can operate the CRM per tenant |
| 4 V8-compatible API + OAuth2 | 4–5 | n8n can talk to the new CRM |
| 5 Data migration (core modules) | 5–6 | Real data loaded + reconciled |
| 6 Integrations (n8n re-point, tel/Vapi/SMS/email hooks) | 6–7 | End-to-end lead lifecycle on new CRM |
| 7 Hardening, roles, UAT, cutover | 8–9 | Go-live |

### Phase 1 — Foundation + tenancy (Week 1)
- **Build:** Laravel 11 (PHP 8.3) + Filament v3 + `stancl/tenancy` + `spatie/permission` + Passport + Horizon; `CLAUDE.md`; `.claude/settings.json` + SessionStart hook; GitHub Actions CI (pint/phpstan/pest); central + tenant DBs; `tenant:create`; subdomain identification; Filament auth; roles seeded from `config/roles.php`.
- **Claude Code:** `/init` → refine `CLAUDE.md`; plan-mode the tenancy topology (central vs tenant tables) — highest-leverage design step.
- **Done:** create tenant → log in → role gating; tenant-isolation test green; CI green.
- **Parallel (you):** deliver the schema export + start field-map answers.

### Phase 2 — Core data model + migrations (Week 2)
- **Build:** migrations/models/factories/relationships for `Company`, `Lead`(`vertical`+`stage`), `Student`, `Assessment`, `LmiaCase`, `Client`, `Affiliate`, `NewsletterSubscriber`, `CallSummary`, `SmsMessage`; enums/dropdowns config; `do_not_call`, `hot_lead`, `warm_lead`.
- **Claude Code:** feed `docs/field-map.yml` + the schema export; **one entity per PR**, a **subagent per entity** to parallelize.
- **Done:** migrations clean on a fresh tenant DB; relationship/factory tests green; matches field-map. *(Hard dep: schema export.)*

### Phase 3 — CRM UI + DNC + Hot/Warm (Week 3)
- **Build:** Filament resources (list/detail/edit) per entity; **DNC** filter/toggle; **Hot/Warm** toggles + 2 dashboard widgets aggregating across verticals; global search; role gating.
- **Claude Code:** one resource per PR; give it the DNC + Hot/Warm behaviour notes from the blueprint.
- **Done:** staff CRUD leads/companies/students in a tenant; DNC + Hot/Warm work; permission-gated.

### Phase 4 — SuiteCRM-V8-compatible API + OAuth2 (Weeks 4–5)
- **Build:** Passport `client_credentials`; JSON:API `GET/POST/PATCH /Api/V8/module/{m}`, `/meta/modules`, `/meta/fields/{m}`; pagination `meta` (record counts); **datetime + Meta canonicalisation server-side**; module aliasing (`GA_*` ↔ new entities) so workflows barely change.
- **Claude Code:** **contract tests first** (capture SuiteCRM's real shapes via the n8n MCP), then build to pass; plan-mode the aliasing layer.
- **Done:** a cloned real n8n workflow authenticates + does CRUD against the new API; contract tests green. **← the linchpin that keeps your 133 workflows alive.**

### Phase 5 — Data migration, core modules (Weeks 5–6, overlaps P4)
- **Build:** idempotent `--dry-run` ETL from the prod copy → new schema for the **high-value modules**; dropdown/Meta cleanup + UTC datetime; reconciliation report vs the audited counts.
- **Claude Code:** artisan command reading the read-only MySQL MCP (source) → tenant DB; iterate until counts reconcile.
- **Done:** staging tenant loaded; core counts reconcile (Companies ≈ 21,014, etc.); spot-checks pass. *(Trace/duplicate modules deferred.)*

### Phase 6 — Integrations (Weeks 6–7)
- **Build:** re-point n8n workflows to the new API (pilot 1–2 per family via the **n8n MCP**, then batch); **click-to-call** event endpoint; **Vapi** inbound/post-call webhooks; **SMS** log intake; **email** send via per-tenant SMTP. Telephony/SMS/Vapi stay external.
- **Done:** Meta lead → n8n → new CRM; follow-ups fire; Vapi post-call tags back; click-to-call works end-to-end.

### Phase 7 — Hardening, roles, UAT, cutover (Weeks 8–9)
- **Build:** daily lead/student count notifications (Horizon); finalize the 29 roles; `/security-review`; Octane/perf, backups, per-tenant export; **parallel-run → cutover runbook**; UAT.
- **Done:** UAT sign-off; security review clean; backups/rollback tested; **go-live**.

---

## NEEDED FROM YOU (bullets)
- **Lock the 4 decisions:** consolidate modules · who the tenants are · keep-n8n-with-compatible-API · migrate core-data-first.
- **Schema export by day 2–3:** run `crm_db_schema_modules.sql` (or `crm_readonly_audit.sh`) → send output. **Only hard blocker for Phase 2.**
- **Sanitised prod DB copy** (`mysqldump`) for dev/ETL.
- **3 Laravel devs** on Claude Code (or accept ~11–13 wks with 2).
- **Staging/host** for the new app (or "use existing infra").
- **Authorize the GitHub connector** for PRs/CI; confirm `crm` is the repo.
- **Provider creds in `.env`** (never committed): Vapi, Asterisk/PBX (AMI), SMS gateway, mail (SMTP/IMAP).
- **Tenant/domain scheme** (subdomains, which brands) + branding.
- **Roles:** keep all 29 or rationalise; matrix or let us derive.
- **You available** for daily field-mapping answers + UAT sign-off.
- **Approve rotating** the credentials that appeared in the shared doc.

## UNCLEAR / TO CONFIRM (bullets)
- **Tenancy purpose** — SaaS to other firms vs your own brands vs future-proof (affects onboarding/billing only).
- **Which verticals stay first-class** vs fold into `Lead` (Student, Study, Assessment, LMIA).
- **Stock activity modules** — need Cases/Meetings/Calls/Tasks/Notes/Documents in v1? (Call_Summaries link to them today.)
- **`dt_sms` internals + SMS gateway** (Twilio/other?) — undocumented.
- **Vapi scope** — stay in n8n (assumed) vs native.
- **Asterisk** — keep external PBX/plugin behaviour (assumed) vs native; who owns the PBX.
- **Duplicate legacy modules** (BD1/BD2, ClientDevelopment2/3, ImmCan1/2/3) — merge or archive?
- **How much history** must land in v1 vs fast-follow (full 45k vs core modules only).
- **Cutover** — parallel-run window; acceptable downtime.
- **Compliance** — PIPEDA/GDPR for immigration PII (retention, access logging, data residency).
