# crmga → Laravel — Target Data Model v1 (from live DDL, 481 tables)

Derived from `crmga_full_schema.sql` (exact DDL). This is the Phase‑2 build spec: entities, the
shared base, how the 43 GA modules consolidate, and how 481 tables become ~30–40.

**Revision 3:** the system is built **single-tenant first**; every table below lives in the one CRM
database, whose migrations sit in `database/migrations/tenant/` and which **becomes tenant #1's database**
when multi-tenancy is added in Phase 8. **No table gets a `tenant_id` column** — isolation will be by
database. Also: **Study Permit and LMIA are Lead verticals in v1**, not separate entities (this reduces both
the entity count and the ETL surface); their fields live in the Lead vertical attribute groups.

## 1. The shared "Contactable" base (identical across all GA lead modules)
Every GA person/lead table repeats these columns — model once as a trait/base migration:
```
id (uuid/char36 PK), created_at, updated_at, deleted_at (soft delete = SuiteCRM `deleted`)
assigned_user_id → users, created_by, modified_user_id
salutation, first_name, last_name, title, department, description
do_not_call (bool)                      ← DNC feature
phone_home, phone_mobile, phone_work, phone_other, phone_fax
primary_address_{street,city,state,postalcode,country}
alt_address_{street,city,state,postalcode,country}
assistant, assistant_phone
lawful_basis, date_reviewed, lawful_basis_source   ← consent tracking (keep for PIPEDA/GDPR)
```
Plus, on the 7 core modules (in `_cstm`): `hot_lead` (bool), `warm_lead` (bool).
**Email is NOT a column** — it lives in shared `email_addresses` + `email_addr_bean_rel`. Model as an `EmailAddress` morph relation and **denormalize a `primary_email`** for search/list.

## 2. Core entities (v1) and their source mapping

| New entity | Source table(s) | Rows | Notes |
|---|---|---|---|
| **Company** | `ga_companies` (+`_cstm`) | 21,014 | Employer/recruiter directory. Base + rating, lmia, jobpostlink, jobtitle, employees, contact_person_*, company_type, company_contact_status, industry, website; cstm: status, email1, contact_person_email, pnp_program, resume_submitted, hot/warm |
| **Lead** (`vertical`, `stage`) | `ga_galead`, `ga_imm_biz`, `ga_imm_can`, `ga_usa`, `ga_canadavisa`, `ga_expressentryrequests`, `ga_studypermitrequests`, `ga_new_pnp_form`/`ga_pnp`, `ga_refugee_book`, `ga_entrepreneur`, `ga_bd1/2`, `ga_resumes`, `ga_hqinvestor_`, `ga_gunnessassociates`, `ga_associates`, `ga_applicant`, `ga_immcan1/2/3`, `ga_inland`, `ga_client_development1` | ~3.5k | Shared base + `vertical` enum + `stage`; vertical‑specific dropdowns (own_business_bi, invest_in_canada, current_status_in_canada, seeking_a_humanitarian_pr, best_time_to_call*, etc.) as typed columns or `vertical_attributes` JSON; DNC + hot/warm |
| **Student** | `ga_hq_students` (+`_cstm`) | 548 | HQ Learning Hub; base + get_started, status, how_hear, hot/warm; Vapi calling target |
| **Assessment** | `ga_assessment_request` + `ga_assessment_score` | 484 / 8,147 | Express‑Entry **CRS/FSW calculator** — 88 scoring fields → `scores` JSON + key typed columns (crs_score, fsw_score, marital_status, education, language tests, spouse_*) |
| ~~StudyLead~~ → **Lead** `vertical=StudyPermit` | `ga_study` (+`_cstm`) | 4,782 | **Revision 3: folded into Lead**; study-specific fields become that vertical's attribute group |
| ~~LmiaCase~~ → **Lead** `vertical=LMIA` | `ga_lmia_main` (+`_cstm`), `ga_lmia_course`, `ga_lmiainquiry`, `lmia_affiliate` | ~4.4k | **Revision 3: folded into Lead**; employer link and case fields become that vertical's attribute group |
| **Client** | `ga_clients` (+`_cstm`), `ga_clientdevelopment2/3`, `ga_imm_client` | ~325 | Post‑conversion client lifecycle |
| **Affiliate** | `ga_affiliate` (+`_cstm`) | 49 | Referral partners (commission, status, whatsapp) |
| **NewsletterSubscriber** | `ga_newsletter_subscriber` | 2,023 | Simple subscriber list |
| **CallSummary** | `ga_call_summaries` | — | Call‑log records → merge into **Call** activity |
| **SmsMessage** | `dt_sms` (+ link tables) | 21 | direction, source/destination_number, status, media; morph to Lead/Contact |

## 3. Activities — polymorphic (replaces 154 link tables + 20 stock tables + 79 audit)
```
Meeting, Note, Document (+DocumentRevision), Email (+EmailText, EmailAddress), Call, Task
   → each morphs to any CRM record via (subject_type, subject_id)   [Laravel morphMany]
Audit  → one polymorphic activity/audit log (replaces every *_audit table)
EmailAddress → morphedByMany (replaces email_addresses + email_addr_bean_rel)
```
This is where the table count collapses. In SuiteCRM these are `ga_X_meetings_c`, `ga_X_notes_c`, … (one per module per activity type). In Laravel it's a single relation each.

## 4. Users / roles / security (from `users`, `acl_*`, `securitygroups*`)
- `User` (76 rows; is_admin flag) → Filament users.
- **29 ACL roles** → `spatie/laravel-permission` roles; `acl_roles_actions` (module × action = view/edit/delete/list/import/export) → permissions. Seed from a `config/roles.php` derived from `acl_roles` + `acl_actions`.
- Security groups minimal (2) → optional `Team` scoping, deferred.

## 5. Support / config entities
`EmailTemplate` (212), `OutboundAccount` (70), `InboundMailbox` (45), `TelephonyServer` (from `asteriskintegration_servers`: server_ip, ami_username/secret/port, recordinglink), `NotificationLog` (`ga_lead/student_notification_log`), `Scheduler` → **Laravel scheduled jobs / Horizon** (not a table).

## 6. Enums / dropdowns
Values live in the app's language files + the stored `varchar(100)` data (e.g. `category_c` = Refugee/SpousalSponsorship/BusinessImmigration/ExpressEntry/Humanitarian; `lead_status_c`, `call_status_c`, `last_call_outcome_c` from blueprint §6). Extract per column from the sanitized data (`SELECT DISTINCT`) during Phase 0/5 and codify as PHP enums/config. **Preserve the label convention** (first char capitalised only).

## 7. Table-count reduction
```
481 live tables →  ~30–40 tenant tables
  −154 activity link tables   → polymorphic morphs
  −79  *_audit               → 1 audit log
  −43  GA module tables       → ~8 consolidated entities (+vertical)
  −20  stock activity tables  → 6 activity models
  −misc system/sales/reports  → keep only what v1 needs (Meetings/Notes/Documents/Emails confirmed in)
```

## 8. Migration notes (for ETL, Phase 5)
- Join `email_addr_bean_rel`→`email_addresses` to recover each record's email(s); set `primary_email`.
- Person base is identical → one reusable transformer; map module → `vertical`.
- `do_not_call` on base table; `hot/warm` from `_cstm`; datetime → **UTC `Y-m-d H:i:s`**.
- Dedup the legacy modules (`ga_immcan1/2/3`, `hamid_*`, `ga_client_development1`) into their target entity or archive.
- Reconcile counts to blueprint §4 (Company ≈ 21,014, Assessment_Score ≈ 8,147, Study ≈ 4,782, …).

## 9. Resolved vs still-open
**Resolved by you:** DB‑per‑tenant ✔ · Meetings/Notes/Documents/Emails in scope ✔ · full schema delivered ✔.
**Still to confirm:** Study & LMIA as own entities vs Lead verticals · which enum option lists to freeze · SMS gateway (dt_sms provider) · Asterisk stays external (assumed) · how much history in v1 vs fast‑follow.
