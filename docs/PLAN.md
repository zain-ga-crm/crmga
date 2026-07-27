# crmga → Laravel Rebuild — Review & Build Plan

**Source:** "crmga SuiteCRM — Complete System Reference" (SuiteCRM 8.8.0 audit, 2026‑07‑27).
**Goal:** Rebuild the CRM in **Laravel**, **multi‑tenant**, covering modules, fields, schema/migrations, APIs, workflows, integrations, and roles.
**Note:** This document contains **no credentials**. Live secrets stay in your private appendix only.

---

## PART A — Review: what the current system actually is

**Platform.** SuiteCRM **8.8.0** (Angular SPA over legacy SuiteCRM‑7 PHP core), single‑tenant cPanel box, PHP 8.2, MySQL. Live DB **641 MB / 481 tables**. Heavily customised well beyond stock.

**The business.** Gunness & Associates — Canadian immigration consultancy (LMIA, study permits, business/investor immigration, refugee/humanitarian, spousal sponsorship) **+ HQ Learning Hub** course sales. Multiple brands/domains (immigrationmatters.info, skillworkforce.com, hqlearninghub.com).

**Where the data actually lives (this drives the whole rebuild).** Stock Leads/Contacts/Accounts are **effectively unused** (8 / 4 / 1 rows). All real data is in **35 custom `GA_*` modules**, and it's concentrated:

| Tier | Modules (active rows) |
|---|---|
| Heavy | GA_Companies **21,014**, GA_Assessment_Score **8,147**, GA_Study **4,782**, GA_LMIA_Course **4,320**, GA_Newsletter_Subscriber **2,023** |
| Medium | GA_Imm_can 785, GA_Applicant 560, GA_HQ_Students 548, GA_USA 535, GA_Assessment_Request 484, GA_BD1 404, GA_GALead 394, GA_ExpressEntryRequests 290, GA_Clients 265 |
| Light | GA_StudyPermitRequests 115, GA_Imm_Biz 112, GA_ClientDevelopment2 53, GA_Affiliate 49, GA_LMIAInquiry 40, GA_canadaVisa 39, GA_HQInvestor_ 15 |
| Trace (≤9) | GA_Refugee 9, GA_GunnessAssociates 8, GA_Entrepreneur 7, GA_ClientDevelopment3 7, GA_Associates 3, GA_Resumes 1, GA_New_PNP_Form 1, GA_ImmCan3 1, GA_BD2 1 |

**Core working set.** 7 "Person‑type" modules carry the DNC + Hot/Warm features and are the operational heart: **GA_GALead, GA_Imm_Biz, GA_HQ_Students, GA_HQInvestor_, GA_Companies, GA_GunnessAssociates, GA_Imm_can**. All extend a base Person class (`first_name/last_name/email1/phone_mobile` inherited).

**Custom fields.** Studio custom fields across 22 modules; densest: GA_GALead (13), GA_Companies (11), GA_Imm_Biz (10), GA_LMIA_MAIN (5). Dropdown values + a deliberate label convention (first‑char‑capitalised only — must be preserved, not "fixed" to Title Case).

**Lead pipeline.** Meta Instant Forms → Google Sheet → **n8n** → SuiteCRM **V8 JSON:API**. Known integration bug: Meta returns lowercased/underscored/punctuated values + invisible Unicode marks → naive pass‑through saves blank. Fixed with a `canon()` normaliser in n8n. **The rebuild's importer/API must reproduce this normalisation.**

**Email.** 212 active templates (of 220); **70 outbound SMTP accounts** (67 active, per‑user), **45 inbound IMAP mailboxes** (993/TLS). Multiple mail hosts.

**Telephony.** **Asterisk** click‑to‑call via `AsteriskIntegration` plugin — NOT a `tel:` link; a JS `onAsteriskClick()` emits a socket.io `ClickToCall` to a PBX (AMI). Separate **`dt_sms`** SMS module (own tables, ~21 logs). Plus **Vapi** voice‑AI (outbound lead caller + inbound handler) driven from n8n.

**DNC.** `do_not_call` toggle on the 7 core modules; list views hide/show via a cookie; backend WHERE‑clause switch.

**Hot/Warm.** `hot_lead_c` / `warm_lead_c` booleans on the 7 modules; two Home dashlets do a **UNION‑ALL across all 7 `_cstm` tables**. Legacy classic‑dashlet dashboard (Angular Home is disabled).

**Automation.** **133 n8n workflows** on a Hostinger VPS: Meta intake per vertical, 2h/24h/48h follow‑up cadences, AI reply/auto‑responders, welcome/confirmation flows, status routers, Vapi voice, SMS, KB/chat agents, form captures. All auth via V8 OAuth2 `client_credentials`.

**Users/roles.** 76 users (~45 active), **29 ACL roles** mirroring verticals/functions, only 2 (barely‑used) security groups. Role‑based ACL is the real access model.

**Gotchas that must carry into the rebuild** (from Section 15):
1. **V8 API silently blanks datetime writes** unless sent as `YYYY-MM-DD HH:MM:SS` (UTC, with seconds) — SuiteCRM parses against the API user's locale and drops mismatches. Our new API must accept ISO‑8601 and store canonically.
2. Label/translation‑key handling, OPcache/caching quirks — SuiteCRM‑specific pain we **design out** in Laravel.

**Known gaps in the audit (I'll close these in Phase 0):**
- **Full column‑level schema + indexes** for the 35 modules was **not** exported — only Studio custom fields + inherited Person fields. Exact migrations need `DESCRIBE`/`SHOW INDEX` per table (the read‑only scripts I gave you produce this).
- `dt_sms` internals not deeply documented.

---

## PART B — Decisions that shape the build (my recommendation in **bold**; tell me if you disagree)

1. **Module fidelity → Consolidate.** Rebuild the 35 `GA_*` modules into a clean core: a unified **Lead** with a `vertical` + `stage` and per‑vertical field groups, plus first‑class **Company, Student, Assessment, Client, Affiliate, NewsletterSubscriber, CallSummary, SmsMessage**. The ≤9‑row/duplicate modules fold in as verticals/records, not tables. Rationale: many `GA_*` are near‑duplicates; a clean model is faster to build, far better for multi‑tenancy, and easier to maintain. (Exact field‑level mapping finalised in Phase 0.)
2. **Tenancy → database‑per‑tenant via `stancl/tenancy`.** Central DB for tenant registry/auth/billing; one DB per tenant for CRM data. Best isolation for immigration PII, easy per‑tenant backup/export, and the smoothest "build‑now/scale‑later" path. *Confirm who the tenants are — other firms (SaaS) vs your own brands — since it changes onboarding/billing, not the core architecture.*
3. **Automations → keep n8n, expose a SuiteCRM‑V8‑compatible API.** The new CRM serves a JSON:API (`/Api/V8/module/{m}`, `meta/modules`, `meta/fields`) with OAuth2 `client_credentials`, matching SuiteCRM's contract closely enough that the **133 workflows keep working with only a base‑URL/field re‑point**. This is the single biggest scope‑saver. Telephony/Vapi/SMS remain external and integrate via API + webhooks. (Full native re‑implementation is a later, optional phase.)
4. **Data → migrate all, with cleanup.** ETL the ~45k business records into the new schema, applying the Meta‑value canonicalisation and datetime normalisation, with row‑count reconciliation. Trace/duplicate modules can be merged or archived during ETL.

---

## PART C — Target architecture

```
Laravel 11 (PHP 8.3)  ·  Filament v3 (staff CRM UI)  ·  stancl/tenancy (DB‑per‑tenant)
spatie/laravel-permission (roles/ACL)  ·  MySQL/MariaDB  ·  Redis (cache/queue)
Laravel Horizon (queues = "schedulers")  ·  FrankenPHP/Octane (performance)
API: Laravel + JSON:API layer, OAuth2 client_credentials (Passport)  — SuiteCRM‑V8‑compatible
Realtime: Reverb (click‑to‑call events)  ·  Mail: per‑tenant SMTP/IMAP accounts
Integrations: n8n (unchanged) · Asterisk AMI · Vapi webhooks · dt_sms equivalent
```

**Target data model (proposed consolidation — refined in Phase 0):**

| New entity | Absorbs | Notes |
|---|---|---|
| `Company` | GA_Companies | Recruiter/employer directory (21k) — first‑class, heaviest |
| `Lead` (+ `vertical`, `stage`) | GA_GALead, GA_Imm_Biz, GA_Imm_can, GA_USA, GA_canadaVisa, GA_ExpressEntryRequests, GA_StudyPermitRequests, GA_New_PNP_Form, GA_Refugee, GA_Entrepreneur, GA_BD1/2, GA_Resumes, GA_HQInvestor_, GA_GunnessAssociates | Per‑vertical field groups; DNC + Hot/Warm live here |
| `Student` | GA_HQ_Students | HQ Learning Hub (distinct lifecycle + Vapi calling) |
| `Assessment` (+ score) | GA_Assessment_Request, GA_Assessment_Score | Intake + scoring (~8.6k) |
| `LmiaCase` | GA_LMIA_MAIN, GA_LMIA_Course, GA_LMIAInquiry | LMIA pipeline |
| `StudyLead` | GA_Study | Or a Lead vertical — decide in Phase 0 |
| `Client` | GA_Clients, GA_ClientDevelopment2/3, GA_Imm_Client | Converted‑client lifecycle |
| `Affiliate` | GA_Affiliate | Referral program |
| `NewsletterSubscriber` | GA_Newsletter_Subscriber | 2k, simple |
| `CallSummary` | GA_Call_Summaries | Telephony logs |
| `SmsMessage` | dt_sms | SMS logs |
| RBAC | 29 ACL roles → spatie roles/permissions | Vertical + function based |

---

## PART D — Phased plan, team & timeline

### Team (recommended: 3)
- **Backend/architecture lead** — tenancy, schema/migrations, V8‑compatible API, OAuth2, performance, security.
- **Full‑stack Laravel/Filament dev** — modules, Filament CRUD, RBAC, DNC/Hot‑Warm, dashboards, notifications.
- **Integrations/data engineer** — ETL migration, n8n re‑point, Asterisk/Vapi/SMS/email wiring, reconciliation.
- (You = product owner / domain SME for field mapping & UAT. Optional part‑time QA.)

A **2‑person** team works if the integrations engineer's scope is split across the other two — it mainly stretches the calendar.

### Phases

| Ph | Deliverable | Key work | Est (3 ppl) |
|---|---|---|---|
| 0 | **Discovery & schema lock** | Full `DESCRIBE`/`SHOW INDEX` on all tables; finalise module consolidation + field map; tenancy model; data profiling | 1.5 wk |
| 1 | **Foundation** | Laravel + Filament + `stancl/tenancy` skeleton; central/tenant DBs; auth; spatie RBAC; CI/CD; base layout | 2.5 wk |
| 2 | **Core modules & fields** | Migrations + Eloquent models + relationships for all entities; Filament resources (list/detail/edit); enums/dropdowns + label convention; **DNC** + **Hot/Warm** features | 5 wk |
| 3 | **V8‑compatible API** | OAuth2 `client_credentials`; JSON:API `module`/`meta/modules`/`meta/fields`; pagination `meta` (record counts); datetime + Meta‑value normalisation server‑side | 3.5 wk |
| 4 | **Data migration** | ETL crmga_crm_prod → new schema; dropdown/Meta cleanup; UTC datetime; row‑count reconciliation; dry‑run + cutover runbook | 3.5 wk |
| 5 | **Integrations** | Re‑point 133 n8n workflows to new API; Asterisk AMI click‑to‑call (Reverb); Vapi inbound/outbound webhooks; `dt_sms` equivalent; per‑tenant SMTP send + IMAP intake | 4 wk |
| 6 | **Dashboards, roles, UAT, go‑live** | Hot/Warm aggregate dashboards; daily lead/student count notifications; finalise 29 roles; security review; perf/backup; UAT + cutover | 3.5 wk |

### Timeline
- **3‑person team:** **~4.5–5.5 months** (phases 2–4 partly parallel).
- **2‑person team:** **~6.5–8 months.**
- **Usable internal beta earlier:** after Phases 0–3 + top‑5 module migration ≈ **11–13 weeks** — new leads can flow via n8n against the new CRM while the rest is built.

---

## PART E — Immediate next steps
1. **Close the schema gap (Phase 0 input):** run the read‑only `crm_readonly_audit.sh` / `crm_db_schema_modules.sql` on the box and send the output — gives exact columns/indexes/enums for precise migrations.
2. **Confirm the 4 decisions in Part B** (consolidate vs 1:1, who the tenants are, keep‑n8n‑API, migrate‑all).
3. On your go‑ahead I scaffold the Laravel + Filament + `stancl/tenancy` skeleton in the `crm` repo (branch `claude/crm-repo-setup-d3arzu`) and stand up Phase 1.

## Risks / watch‑list
- Field‑level mapping accuracy depends on the Phase‑0 schema export (currently the one real unknown).
- V8‑API compatibility must be close enough for 133 workflows — budget buffer in Phase 3 for edge cases (filters, relationships, the datetime quirk).
- PII/data‑protection: per‑tenant isolation, encryption at rest, audit logging, and credential rotation (the blueprint's secrets should be rotated).
