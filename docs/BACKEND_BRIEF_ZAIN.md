# Backend Brief — Zain

**Everything the backend needs, in one document.** Written to be given to Claude Code: it is
self-contained, states the rules as rules, and ends with a copy-paste prompt per task.

> **How to use this with Claude Code**
> 1. `cd` into the repo and run `claude`. It loads `CLAUDE.md` automatically.
> 2. Say: *"Read `docs/BACKEND_BRIEF_ZAIN.md` in full, plus `docs/contracts/*`. I am the backend
>    developer. We are starting task **Z-1.1**. Enter Plan Mode and propose the implementation."*
> 3. Approve the plan, let it build, then run the guardrail: `pint` → `phpstan` → `pest`.
> 4. One task = one pull request. Never start a task whose dependencies are unmerged.
> 5. When a rule in §2 conflicts with anything Claude Code proposes, the rule wins. Say so.

**Companion contracts (read these — they are frozen and machine-readable):**
`docs/contracts/layout.schema.json` · `docs/contracts/field-types.json` · `docs/contracts/api-contract.md`
**Reference data:** `docs/reference/schema.json` (parsed source DDL, 481 tables) ·
`docs/reference/field-map.json` (source → target mapping) · `docs/reference/roles.php` (29 roles) ·
`docs/reference/crmga_full_schema.sql` (raw DDL)

---

## 1. What you own

| You own | You do not own |
|---|---|
| Database schema and all migrations | Any Filament screen, form or table (Shahmeer) |
| The metadata engine: registry, repository, cache | The Studio builder user interfaces |
| `SchemaManager` — safe runtime DDL | Dashboard widget presentation |
| Models, relationships, business logic, events | Design tokens, components, layout |
| The ACL engine and its query scopes | |
| REST API v1 and the legacy V8 adapter | |
| Inbound integrations and the field mapper | |
| Queues, scheduled jobs, notifications backend | |
| Settings store and encryption | |
| ETL / data migration | |
| Security, performance, backups, deployment | |
| The Phase 8 multi-tenancy conversion | |

**Your obligation to the frontend:** in week 1, land and then **freeze** the metadata shape, the layout JSON
contract and the option-list structure, and seed fixtures for one complete module (`leads`) so Shahmeer can
build against real data on day two. If you later need to change a frozen contract, it is a conversation, not
a commit.

---

## 2. Non-negotiable rules

Twelve rules. Every one of them exists because breaking it costs weeks later.

1. **Single database now; tenancy in Phase 8.** Do not install `stancl/tenancy` before Phase 8.
2. **Every CRM migration lives in `database/migrations/tenant/`.** Nothing CRM-related in the default folder.
3. **Never create a `tenant_id` column.** Isolation will be by database. A CI test must fail if one appears.
4. **Per-company configuration lives in the `settings` table, never in `.env`.** SMTP, branding, business hours, feature flags, integration keys.
5. **Metadata first.** Build the registry and `SchemaManager` before any entity. No screen, endpoint or validation rule may hardcode a field list or an option list.
6. **All datetimes UTC, `Y-m-d H:i:s`,** stored and on the wire. Parsing must never depend on the authenticated principal's locale — that was the original silent-data-loss bug.
7. **Canonicalise inbound choice values** (`strtolower` then strip non-alphanumerics, both sides) before matching; no match means `null`, never a guess. Strip invisible Unicode from phone numbers.
8. **Dropdown labels capitalise only the first character** (`follow_up` → "Follow up"). Never Title-Case.
9. **No MySQL `ENUM` columns.** Dropdowns are `varchar(100)` plus an option list, so an administrator can add a value without DDL.
10. **All DDL goes through `SchemaManager`.** No raw `ALTER` anywhere else, ever. Identifiers are whitelist-validated then backtick-quoted (§6.4).
11. **The same query scopes serve the interface and the API.** One implementation, so the two can never disagree about who sees what.
12. **No Asterisk, no SMS.** No dialer, no telephony settings, no SMS module, tables or endpoints. `Call` records remain, written through the API by external systems.

---

## 3. Environment

| Item | Value |
|---|---|
| PHP | 8.3, extensions: `mysqli`/`pdo_mysql`, `mbstring`, `intl`, `openssl`, `curl`, `zip`, `gd`, `redis`, `bcmath`, `opcache` |
| Laravel | 11.x |
| Database | MySQL 8.0 (or MariaDB 10.11+), InnoDB, `utf8mb4` / `utf8mb4_unicode_ci` |
| Cache, queue, session | Redis 7 |
| Queue worker | Horizon |
| App server | PHP-FPM in development; FrankenPHP or Octane for production |
| Packages | `filament/filament ^3`, `spatie/laravel-permission ^6`, `laravel/passport ^12`, `laravel/sanctum ^4`, `laravel/horizon ^5`, `owen-it/laravel-auditing ^13`, `spatie/laravel-data ^4`, `spatie/laravel-settings` (or a hand-rolled settings store), `pestphp/pest ^3`, `larastan/larastan ^2`, `laravel/pint ^1` |
| Deliberately absent | `stancl/tenancy` (Phase 8 only), any telephony or SMS package |

`.env` keys you will need: `APP_KEY`, `APP_URL`, `DB_*`, `REDIS_*`, `QUEUE_CONNECTION=redis`,
`CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `MAIL_MAILER` (bootstrap only — real mail settings live in
`settings`), `PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY`, `HORIZON_PREFIX`.
**Nothing company-specific belongs in `.env`.**

---

## 4. Database conventions

Apply these to every table without exception.

| Concern | Rule |
|---|---|
| Primary key | `char(36)` UUID, **not** auto-increment. Source IDs are preserved through the ETL so foreign keys survive. New records use UUIDv7 (time-ordered, better index locality). |
| Timestamps | `created_at`, `updated_at` (`datetime`, UTC). Plus `created_by`, `modified_by` (`char(36)` → users) on every CRM entity. |
| Soft delete | `deleted_at datetime null`. The source `deleted tinyint(1)` maps to `deleted_at` during ETL. |
| Ownership | `assigned_user_id char(36) null` → `users.id`, **indexed on every CRM entity** — the Owner access level filters on it constantly. |
| Booleans | `tinyint(1) not null default 0`. Never nullable. |
| Money | `decimal(18,4)`. Never float. |
| Enums | `varchar(100)` + option list. Never a MySQL `ENUM`. |
| JSON | `json` columns for `vertical_attributes` and assessment `scores` only. Anything filtered or sorted gets a real column. |
| Text | `varchar(255)` by default; `text` when genuinely long. Do not default everything to `text`. |
| Nullability | Nullable unless the business genuinely requires a value. Required-ness is expressed in metadata and validation, not only in the column. |
| Naming | `snake_case`, plural tables (`leads`, `companies`), singular columns. Sidecars are `{table}_custom`. Pivots are `{singular}_{singular}` alphabetically. |
| Foreign keys | Named `fk_{table}_{column}`. `restrict` on delete for user references; `cascade` only for genuinely owned children (document revisions, option items). |
| Indexes | Named `idx_{table}_{columns}`; unique `uq_{table}_{columns}`. **Required on every entity:** `assigned_user_id`, `deleted_at`, `created_at`, `primary_email`, `phone_mobile`, and the module's status or stage field. `leads` additionally needs a composite `(vertical, stage, assigned_user_id)`. |
| Charset | `utf8mb4` throughout — the source contains names and free text in many scripts. |
| Column count | Keep base tables under ~60 columns; custom fields go in the sidecar. |

**Sanity check against real volumes before declaring an index set done:** companies ≈ 21,000,
assessment scores ≈ 8,150, study ≈ 4,780, LMIA course ≈ 4,320, newsletter ≈ 2,020, in-Canada ≈ 785,
applicants ≈ 560, students ≈ 548, USA ≈ 535, leads ≈ 394.

---

## 5. Metadata engine

### 5.1 Tables (all in `database/migrations/tenant/`)

```
modules            id char(36) pk, key varchar(60) unique, label varchar(120), label_plural varchar(120),
                   table_name varchar(64), base_type enum-as-varchar(contactable|generic),
                   icon varchar(60), is_custom bool, enabled bool, menu_group varchar(60),
                   order int, created_at, updated_at, deleted_at

fields             id char(36) pk, module_id fk, name varchar(60), label varchar(160),
                   label_key varchar(160)            -- generated LBL_* key, never user-entered
                   type varchar(30),                  -- see contracts/field-types.json
                   storage varchar(10),               -- 'base' | 'custom' (sidecar) | 'json'
                   length int null, precision int null, scale int null,
                   option_list_id fk null, related_module_id fk null,
                   related_display_field varchar(60) null,
                   default_value varchar(255) null, validation json null,
                   required bool, audited bool, mass_update bool, duplicate_merge bool,
                   reportable bool, importable bool, filterable bool, sortable bool,
                   help varchar(500) null, comments varchar(500) null,
                   is_system bool,                    -- system fields cannot be edited or deleted
                   is_custom bool, order int,
                   created_at, updated_at, deleted_at
                   unique (module_id, name)

option_lists       id char(36) pk, key varchar(60) unique, label varchar(120), is_system bool
option_items       id char(36) pk, option_list_id fk, value varchar(100), label varchar(160),
                   order int, is_active bool
                   unique (option_list_id, value)

layouts            id char(36) pk, module_id fk, view varchar(10), definition json,
                   version int, is_published bool, published_at datetime null, created_by
                   index (module_id, view, is_published)

changes            id char(36) pk, actor_id char(36), kind varchar(40),
                   target_module varchar(60) null, target_field varchar(60) null,
                   payload json,                      -- the request that was made
                   ddl_log text null,                 -- the exact statements executed
                   snapshot_path varchar(500) null,   -- where the pre-change dump lives
                   status varchar(20),                -- requested|approved|applied|rejected|rolled_back
                   reviewer_id char(36) null, review_note varchar(500) null,
                   applied_at datetime null, created_at
```

**Naming note:** these are `modules`, `fields`, … not `tenant_modules`. They live in the database that
becomes tenant #1, so the prefix would be redundant and would have to be renamed later.

### 5.2 `MetadataRepository`

```php
interface MetadataRepository {
    public function modules(): Collection;                 // enabled, ordered, permission-filtered
    public function module(string $key): ModuleMeta;
    public function fields(string $moduleKey): Collection; // ordered, with option values resolved
    public function field(string $moduleKey, string $name): FieldMeta;
    public function layout(string $moduleKey, string $view): LayoutMeta;   // published version
    public function optionList(string $key): Collection;
    public function version(): int;                        // cache version
    public function bumpVersion(): void;                   // called by SchemaManager after any change
}
```

Cache key: `crm:meta:v{version}:{artefact}`. Compile once per version, cache forever, invalidate by
bumping the version — never by clearing keys individually. One `MetadataRepository` call per request at
most: hydrate everything the request needs in a single compiled structure.

**Performance target: metadata resolution adds under 5ms to a request.** If it does not, the compiled
structure is wrong, not the cache.

---

## 6. `SchemaManager` — safe runtime DDL

The single most dangerous class in the system. Treat it accordingly.

### 6.1 Public surface

```php
final class SchemaManager {
    public function plan(FieldChangeRequest $r): ChangePlan;      // no side effects; returns SQL + warnings
    public function apply(ChangePlan $p, string $actorId): ChangeResult;
    public function rollback(string $changeId, string $actorId): ChangeResult;
    public function createSidecar(string $table): void;           // {table}_custom
}
```

### 6.2 Algorithm for `apply`

```
1  Re-validate the plan (never trust a plan that was built earlier).
2  Acquire a named lock  crm:schema  (timeout 10s). Concurrent DDL is rejected, not queued.
3  Snapshot: mysqldump the affected table(s) — structure and data — to the configured snapshot disk.
   Record the path. If the snapshot fails, ABORT.
4  Execute the DDL statements in order. Each statement is logged before execution.
   (DDL is not transactional in MySQL — this is exactly why step 3 is mandatory.)
5  Write the metadata rows (fields / option_items / layouts) inside a database transaction.
6  Write the `changes` row: status=applied, ddl_log, snapshot_path, applied_at.
7  bumpVersion() so the compiled metadata cache is invalidated.
8  Release the lock. Return the result.
On any failure after step 4: mark the change failed, keep the snapshot, and surface a precise error.
Do not attempt an automatic partial rollback of DDL — a human decides, using the snapshot.
```

### 6.3 Validation — reject before planning

- Field name matches `^[a-z][a-z0-9_]{1,58}$` and is not in the reserved list (`contracts/field-types.json`).
- Name is unique within the module, including soft-deleted fields (avoids column collisions).
- Type exists in the field-type contract; the type's required options are present.
- Length, precision and scale within the contract's limits.
- Per-module field ceiling not exceeded (default 150 columns per sidecar; configurable).
- The target module exists and is not a system-locked module.
- The field is not a system field (`is_system = true` → reject any change).
- Type change is allowed by the matrix in the contract; a lossy change requires an explicit
  `confirm_lossy: true` in the request.
- **A request to add a column named `tenant_id` is rejected unconditionally.**

### 6.4 SQL injection — the rule that matters most

DDL cannot use bound parameters, so identifier safety is entirely our responsibility.

```php
// The ONLY way an identifier may reach a DDL string:
private function ident(string $raw): string {
    if (!preg_match('/^[a-z][a-z0-9_]{1,58}$/', $raw)) {
        throw new UnsafeIdentifier($raw);          // fail loudly; never sanitise-and-continue
    }
    return '`' . $raw . '`';                        // backtick-quote after validating
}
```
- Column types are built from the **contract's** template, with numeric parameters cast to `int` — never
  interpolated from user input as a string.
- Default values are applied with a bound parameter in a follow-up `UPDATE`, not embedded in the DDL.
- A Pest test must attempt injection through the field name, type, length and default, and assert that each
  attempt throws.

### 6.5 Deletion

Soft-delete the metadata row first and hide the field everywhere; keep the column. Report which layouts,
roles and integrations reference it before confirming. Hard-drop the column only via an explicit
administrative action after a retention period, always with a snapshot.

---

## 7. Data model

Follow `docs/DATA_MODEL.md` and `docs/reference/field-map.json`. Points that need stating precisely:

### 7.1 The `Contactable` base

Used by `leads`, `companies` (partially), `students`, `clients`, `affiliates`,
`newsletter_subscribers`. Columns: `salutation, first_name, last_name, title, department, description,
do_not_call, phone_home, phone_mobile, phone_work, phone_other, phone_fax, whatsapp_number,
primary_address_{street,city,state,postalcode,country}, alt_address_{…}, lawful_basis, date_reviewed,
lawful_basis_source, primary_email, assigned_user_id`, plus the standard audit columns.

Implement as a trait plus a shared migration macro so there is exactly one definition.

### 7.2 Email addresses

Email is **not** simply a column. The source keeps a shared `email_addresses` table joined through
`email_addr_bean_rel`. Model an `EmailAddress` with a polymorphic relation, **and** denormalise
`primary_email` onto the record for list display, search and deduplication. Keep the two in sync in one
place (a model observer), not at every call site.

### 7.3 Activities are polymorphic

`Meeting, Note, Document (+DocumentRevision), Email (+EmailBody), Call, Task` each `morphTo` a subject.
One `Audit` model, polymorphic. **Do not recreate the source's 154 link tables or 79 audit tables** — that
is the single largest simplification in the whole project.

### 7.4 Verticals

`leads.vertical` is a `varchar(100)` backed by an option list, values: `BusinessImmigration, Refugee,
SpousalSponsorship, ExpressEntry, Humanitarian, StudyPermit, LMIA, PNP, USA, CanadaVisa, InCanada,
Investor, Entrepreneur, BusinessDevelopment, Resume, General`.
Vertical-specific answers live in `vertical_attributes json`. Promote a key to a real column only when it
must be filtered or sorted — and then do it through `SchemaManager`, not a hand-written migration.

---

## 8. ACL engine

### 8.1 Tables

```
roles                      id, name unique, description, is_system, created_at, updated_at
role_module_permissions    id, role_id fk, module_key varchar(60),
                           view, list, edit, delete, import, export, mass_update
                           -- each varchar(10): 'all' | 'owner' | 'none' | 'not_set'
                           unique (role_id, module_key)
role_user                  role_id, user_id      (composite pk)
```

Store **named levels**, never the source system's integers. If the ETL meets an integer, map it there and
throw on anything unmapped rather than defaulting to a permissive value.

### 8.2 Resolution

```
effective(user, module, action):
    if user.is_admin            -> 'all'          // System Administrator bypasses ACL within the company
    levels = roles(user).map(r => r.permission(module, action)).reject('not_set')
    if levels empty             -> 'none'         // deny by default
    return most permissive of levels              // all > owner > none
```
Multiple roles are **additive** — the most permissive wins. That matches the source system and is what
administrators expect.

### 8.3 Enforcement — three layers, one implementation

1. **Policy** per model: `viewAny, view, create, update, delete, import, export, massUpdate` — each asks
   `effective()` and, for `owner`, compares `assigned_user_id` with the actor.
2. **Global scope** `AppliesRecordAccess` on every CRM model: `none` → `whereRaw('1=0')`, `owner` →
   `where('assigned_user_id', $user->id)`, `all` → no constraint. **This scope is what the API uses too** —
   do not write a second filter for the API.
3. **Attribute filtering** at the serialisation boundary for fields a role may not read (the hook exists;
   field-level ACL itself is deferred).

### 8.4 Dynamic registration

When a module is created, insert a `role_module_permissions` row for **every** existing role with all
actions `none`. New capability is never granted implicitly.

### 8.5 Seeding

Seed the 29 roles from `docs/reference/roles.php`. The ETL later reads the source `acl_roles`,
`acl_actions` (`category` = module, `name` = action) and `acl_roles_actions.access_override` to set real
levels; see `docs/STUDIO_API_RBAC.md` appendix A2 for the exact source shape and the SQL that reveals the
integers actually used.

### 8.6 Tests that must exist

- Owner-level user sees only their own records: in the UI query, in the API, and in a queued job.
- Requesting another user's record by id returns **404**, not 403.
- Admin bypass works but is scoped to the company.
- A new module is invisible to every role until explicitly granted.
- Two roles combine to the most permissive level.

---

## 9. REST API and the legacy adapter

Implemented exactly as `docs/contracts/api-contract.md` specifies. Implementation notes:

- **One generic controller** driven by metadata, not a controller per module. A module added in Studio gets
  its endpoints with no code.
- Whitelist filterable and sortable fields from metadata; an unknown field is a **422** naming the field.
- Apply the ACL global scope in the base query — never as an afterthought on the result.
- Datetime normalisation lives in **one** middleware at the boundary, applied to input and output.
- Rate limiting per client id, not per IP address (n8n calls from one host).
- Log every request: client, route, status, duration, and the trace id returned in errors.
- The **legacy adapter** is a thin translation layer over the same services: never duplicated business
  logic. Its module and field alias maps live in one config file so it can be deleted in one commit.
- Write the **contract tests first** from the verified shapes in the contract document — booleans as
  `"0"`/`"1"`, space-separated datetimes, `page[size]=1000`, `{"data": []}` on empty.

---

## 10. Integrations backend

| Piece | Requirement |
|---|---|
| `FieldMapper` | One pipeline for every inbound source: map → canonicalise → validate → dedupe → create or update → assign → events. Per-source mapping stored in `settings`, editable in the interface. |
| Canonicalisation | As §2 rule 7 and the contract. Log every unmatched value with its source, field and raw value so option lists can be corrected. |
| Deduplication | Match on `primary_email`, then on normalised `phone_mobile`. Configurable per module: warn or merge. Always record which rule matched. |
| WordPress | `POST /api/v1/ingest/wordpress` with `X-Api-Key`. Accept the payload shapes of Gravity Forms, Contact Form 7, WPForms and Elementor. |
| Meta Lead Ads | Webhook verification (`hub.challenge`), signature check, then fetch the lead by id from the Graph API and map it. Respond within 5 seconds — queue the work. |
| Generic ingest | `POST /api/v1/ingest/{source}` with an HMAC-SHA256 signature over the raw body; compare with `hash_equals`. |
| Email sending | Build the mailer transport at runtime from `settings`; never from `.env`. Log every send with its result. |
| Email templates | Stored records with merge fields resolved from **current** metadata, so a template keeps working after a field is renamed. |
| Out of scope | Asterisk, SMS, outbound webhooks, WhatsApp, LinkedIn, TikTok, Google connectors. |

---

## 11. Queues, jobs and scheduling

| Queue | Purpose | Retry policy |
|---|---|---|
| `default` | User-triggered background work (export, bulk update) | 3 tries, backoff 10s/60s/300s |
| `integrations` | Inbound payload processing, outbound calls to providers | 5 tries, exponential to 1 hour |
| `mail` | Sending | 3 tries |
| `maintenance` | Snapshots, reindex, cleanup | 1 try, no retry |

Scheduled tasks (replacing the source system's schedulers):

| Task | Schedule | Notes |
|---|---|---|
| Daily lead count notification | `7 10 * * *` company time zone | Guarded by a log table so it can never send twice for one day |
| Daily student count notification | `12 10 * * *` | Same guard |
| Follow-up and task reminders | every 15 minutes | Respects business hours from `settings` |
| Metadata cache warm | on deploy | |
| Snapshot cleanup | daily 03:00 | Honours the retention setting |
| Failed-job alert | hourly | Notifies administrators when failures exceed a threshold |

Every job must be **idempotent** — assume it will run twice.

---

## 12. Settings store

```
settings   id, key varchar(120) unique, value text null, type varchar(20),
           group varchar(60), is_encrypted bool, updated_by, updated_at
```
- `type` ∈ `string|int|bool|json|encrypted`. Encrypted values use Laravel's `Crypt`; they are **write-only**
  through the API and interface and are never returned, only replaced.
- Access through a typed facade with a per-request cache: `Settings::get('mail.smtp.host')`.
- Groups mirror the administration catalogue: `system`, `branding`, `locale`, `business_hours`, `search`,
  `notifications`, `modules`, `assignment`, `duplicates`, `mail.outbound`, `mail.inbound`, `google`,
  `studio`, `api`.
- Ship a seeder defining **every** key with its default, so the catalogue is identical for every company
  from day one (this is what makes "the same settings for all tenants" true by construction).

---

## 13. ETL / data migration

Runs **locally** against a copy. Never against production. Runbook: `PROJECT_PLAN.md` Appendix A.

```bash
php artisan crm:migrate-legacy --dry-run          # reports counts, writes nothing
php artisan crm:migrate-legacy --only=companies   # one entity
php artisan crm:migrate-legacy                    # idempotent, resumable
php artisan crm:import-studio-metadata            # fields_meta_data + view defs -> metadata tables
php artisan crm:reconcile                         # counts vs the audited targets
```

**Load order (foreign keys demand it):** users → option lists and items → companies → leads → students →
assessments → clients → affiliates → newsletter subscribers → activities → email addresses → audit.

**Rules**
- Preserve source `id` values. Idempotency key is the source id; re-running updates rather than duplicating.
- `deleted = 1` → set `deleted_at` from `date_modified`.
- Datetimes: the source stores UTC as `Y-m-d H:i:s`; carry across unchanged, and reject anything unparseable
  into an error report rather than writing null silently.
- Recover email addresses:
  ```sql
  SELECT b.bean_id, e.email_address, b.primary_address
  FROM email_addr_bean_rel b
  JOIN email_addresses e ON e.id = b.email_address_id
  WHERE b.deleted = 0 AND e.deleted = 0 AND b.bean_module = :module;
  ```
  Set `primary_email` from `primary_address = 1`; keep the rest as `EmailAddress` records.
- Join each `*_cstm` sidecar on `id_c = id` to pick up custom fields; map `*_c` names by stripping the suffix.
- Map source module → target entity and vertical per `field-map.json` and the alias table in the API contract.
- Deduplicate the legacy duplicate modules (`ga_immcan1/2/3`, `hamid_*`, `ga_client_development1`,
  `ga_bd2`) into their target, preferring the most recently modified row and logging every merge.
- Canonicalise every dropdown value on the way in; write unmatched values to an error report.
- Batch 500 rows, wrap each batch in a transaction, log progress, and support `--from-id` to resume.

**Reconciliation targets (the acceptance bar).** Active-record counts from the audited source:
companies 21,014 · assessment scores 8,147 · study 4,782 · LMIA course 4,320 · newsletter 2,023 ·
in-Canada 785 · applicant 560 · students 548 · USA 535 · assessment requests 484 · BD1 404 · leads 394 ·
express entry 290 · clients 265 · study permit requests 115 · business immigration 112.
The reconcile command prints target, loaded, and difference per entity, and exits non-zero on any mismatch.

---

## 14. Multi-tenancy conversion (Phase 8)

Only after go-live. Steps, in order:

1. Install `stancl/tenancy`. Create the **central** database: `tenants`, `domains`, `platform_users`.
2. Add the `Tenant` model and register bootstrappers for database, cache, queue and filesystem.
3. Enable subdomain identification; activate `routes/central.php`; make sessions and authentication tenant-aware.
4. **Promote the live database to tenant #1** — insert a tenant row whose connection points at the existing database. No data is moved.
5. Move `settings` rows into tenant scope (they already live in the tenant database, so this is a verification step, not a migration).
6. Add `tenant:create` — create database, run `migrations/tenant`, seed roles, seed the settings catalogue, create the first System Administrator.
7. Add `tenants:migrate` to the deployment pipeline.
8. Per-tenant backup and export commands.
9. **Isolation tests:** tenant A cannot read tenant B through a model, the API, or a queued job; a `SchemaManager` change in A leaves B untouched.

If rules 1–4 of §2 were followed, this is roughly ten days of work. If they were not, it is a rewrite —
which is why the CI guard exists from Phase 1.

---

## 15. Security requirements

- Passwords: bcrypt (Laravel default), policy from `settings`, no password ever logged.
- 2FA: TOTP with encrypted secret and hashed backup codes.
- Secrets: `Crypt` at rest, never returned by an API or rendered in a form.
- Mass assignment: explicit `$fillable` derived from metadata; never `$guarded = []`.
- Every API route is authenticated and authorised. A route with no policy check fails code review.
- **DDL identifier whitelisting** as §6.4 — with tests that attempt injection.
- Rate limits on authentication, password reset and inbound ingest endpoints.
- Audit: every create, update and delete of a CRM record, every Studio change, every permission change.
- No secret, token or personal datum in application logs. Scrub known keys in the log formatter.
- Signature verification with `hash_equals` — never `==`.
- File uploads: validate MIME and extension, store outside the web root via `Storage`, never trust the client filename.
- `/security-review` before go-live, and remediate before launch, not after.

---

## 16. Performance requirements

| Target | Measure |
|---|---|
| List page (25 rows, filtered) | under 300ms server time at production volumes |
| Detail page | under 200ms |
| API index | under 250ms |
| Metadata resolution | under 5ms added per request |
| Companies list (21,000 rows) | no full table scan; verify with `EXPLAIN` |
| Queries per page | under 25; no N+1 (assert with a query-count test on the heaviest pages) |
| Import | at least 1,000 records per minute |

Rules: eager-load relationships used by a layout; never `SELECT *` when a layout names its columns; paginate
everything; cache aggregate widget queries for 60 seconds; keep the metadata cache compiled.

---

## 17. Testing requirements

- **Pest**, feature tests over unit tests where behaviour crosses layers.
- Every entity: factory, CRUD test, one relationship test, one ACL-level test.
- `SchemaManager`: add, modify, soft-delete, rollback, every validation rejection, and the injection attempts.
- ACL: the five tests in §8.6.
- API: contract tests for the envelope, filters, pagination, scopes, rate limits, error format — and for the
  legacy adapter's verified quirks.
- **Tenancy guard test** (CI-blocking): fails if a CRM migration is outside `migrations/tenant/`, if any
  migration adds `tenant_id`, or if `routes/central.php` gains routes before Phase 8.
- ETL: transformer unit tests plus a reconciliation test against a fixture subset.
- Static analysis: PHPStan at max, zero baseline entries added without a written reason.

---

## 18. Task list, order and prompts

Dependencies are strict — do not start a task whose dependencies are unmerged. Estimates from
`docs/TASK_BREAKDOWN.md`.

| ID | Task | Days | Depends on |
|---|---|---|---|
| Z-1.1 | Scaffold and CI | 2 | — |
| Z-1.2 | Tenancy-ready skeleton and CI guard | 1.5 | Z-1.1 |
| Z-1.3 | Users and authentication backend | 2 | Z-1.1 |
| Z-1.4 | Metadata registry | 3 | Z-1.2 |
| Z-1.5 | Metadata contract, repository and cache | 2 | Z-1.4 |
| Z-1.6 | `SchemaManager` | 4 | Z-1.5 |
| Z-2.1 | Contactable base and `HasCustomFields` | 4 | Z-1.6 |
| Z-2.2 | Polymorphic activities and audit | 5 | Z-2.1 |
| Z-2.3 | ACL engine | 4 | Z-1.3, Z-2.1 |
| Z-2.4 | Company, Lead, Assessment | 4 | Z-2.1 |
| Z-2.5 | Student, Client, Affiliate, Newsletter, SMS log, Call summary | 3 | Z-2.4 |
| Z-2.6 | ETL transformers, early pass | 2 | Z-2.4 |
| Z-3.1 | Full field-type DDL coverage and safe type changes | 3 | Z-1.6 |
| Z-3.2 | Option lists and layout versioning | 2 | Z-1.5 |
| Z-3.3 | Change log and rollback | 2 | Z-1.6 |
| Z-4.1 | Settings store with encryption | 2.5 | Z-1.2 |
| Z-4.2 | Scheduled jobs and notifications | 2 | Z-2.4 |
| Z-4.3 | Dashboard data services | 2.5 | Z-2.3 |
| Z-4.4 | Performance pass | 2 | Z-2.4 |
| Z-5.1 | API foundation | 2.5 | Z-2.3 |
| Z-5.2 | Metadata-driven resources | 4 | Z-5.1 |
| Z-5.3 | API authentication, scopes, rate limits | 3 | Z-5.1 |
| Z-5.4 | ACL in the API | 1.5 | Z-5.2, Z-2.3 |
| Z-5.5 | Legacy `/Api/V8/*` adapter | 3 | Z-5.2 |
| Z-5.6 | `FieldMapper`, WordPress, Meta, generic ingest | 4 | Z-5.2 |
| Z-5.8 | Email sending | 1.5 | Z-4.1 |
| Z-6.1 | ETL command | 3 | Z-2.6 |
| Z-6.2 | Data correctness | 3 | Z-6.1 |
| Z-6.3 | Studio metadata import | 2 | Z-6.1 |
| Z-6.4 | Reconciliation | 1.5 | Z-6.2 |
| Z-7.1 | Security review and remediation | 2.5 | all |
| Z-7.2 | Performance and deployment | 2 | Z-7.1 |
| Z-7.3 | Backups and cutover runbook | 2 | Z-7.2 |
| Z-8.1–8.5 | Multi-tenancy conversion | 10.5 | go-live |

*(Z-5.7 click-to-call and Z-6.x SMS from earlier revisions are deleted — telephony and SMS are out of scope.)*

### Copy-paste prompts

**Z-1.1**
> Scaffold a Laravel 11 application (PHP 8.3) at the repo root with Filament 3, spatie/laravel-permission,
> Passport, Sanctum and Horizon. Configure Pest, Pint and Larastan at max. Add a GitHub Actions workflow
> running pint, phpstan and pest on every pull request. Create `.env.example` with no secrets and nothing
> company-specific. **Do not install stancl/tenancy.** Follow `docs/BACKEND_BRIEF_ZAIN.md` §3 and §4.

**Z-1.2**
> Implement the tenancy-ready skeleton from `docs/BACKEND_BRIEF_ZAIN.md` §2 rules 1–4: register
> `database/migrations/tenant/` as the path for all CRM migrations; add an empty `routes/central.php`;
> create the `settings` table and typed `Settings` facade per §12; add one cache/queue key helper; enforce
> `Storage`-only file access. Then write the CI guard test from §17 that fails if a CRM migration is outside
> the tenant folder, if any migration adds a `tenant_id` column, or if `routes/central.php` has routes.

**Z-1.4 and Z-1.5**
> Create the metadata tables exactly as `docs/BACKEND_BRIEF_ZAIN.md` §5.1 defines, with models and
> factories. Then implement `MetadataRepository` per §5.2 with the compiled-and-versioned cache. Finally
> seed a complete fixture for the `leads` module — fields, option lists, and published list, detail, edit and
> search layouts that validate against `docs/contracts/layout.schema.json` — so the frontend can build
> against real metadata. Add a test asserting every seeded layout validates against that schema.

**Z-1.6**
> Implement `SchemaManager` exactly as `docs/BACKEND_BRIEF_ZAIN.md` §6 specifies: the `plan`/`apply`/
> `rollback`/`createSidecar` surface, the eight-step apply algorithm including the named lock and the
> mandatory snapshot, the full validation list, and the identifier whitelisting in §6.4. Column types come
> from `docs/contracts/field-types.json` — do not invent type mappings. Tests must include adding a field,
> a lossy change requiring confirmation, rollback from a snapshot, every rejection case, and injection
> attempts through the field name, type, length and default.

**Z-2.3**
> Implement the ACL engine per `docs/BACKEND_BRIEF_ZAIN.md` §8: the tables, the resolution algorithm with
> most-permissive-wins and deny-by-default, policies for all eight actions, and the
> `AppliesRecordAccess` global scope that the API will reuse unchanged. Store named levels, never integers.
> Seed the 29 roles from `docs/reference/roles.php`. Include all five tests from §8.6, especially that
> another user's record returns 404 rather than 403 under Owner access.

**Z-5.1 to Z-5.4**
> Implement REST API v1 exactly as `docs/contracts/api-contract.md` Part 1 specifies. Use one generic
> metadata-driven controller, not a controller per module. Whitelist filterable and sortable fields from
> metadata and return 422 naming an unknown field. Put datetime normalisation in a single boundary
> middleware. Apply the existing `AppliesRecordAccess` scope in the base query — do not write a second
> filter. Add OAuth2 client credentials and personal access tokens with scopes, per-client rate limits and
> request logging. Write the contract tests first.

**Z-5.5**
> Implement the legacy `/Api/V8/*` adapter exactly as `docs/contracts/api-contract.md` Part 2 specifies. It
> must be a thin translation layer over the existing services with no duplicated business logic, and it must
> reproduce the verified quirks: booleans as the strings "0" and "1", datetimes as `Y-m-d H:i:s` with a
> space, `page[size]` up to 1000, `{"data": []}` with HTTP 200 on empty, and tolerance of unknown query
> parameters. Put the module and field alias maps in one config file. Write contract tests from the recorded
> shapes, and reject a write datetime that is not `Y-m-d H:i:s` with a clear error rather than discarding it.

**Z-6.1 to Z-6.4** *(run locally, against a copy)*
> Build `crm:migrate-legacy` per `docs/BACKEND_BRIEF_ZAIN.md` §13: read-only `legacy` connection, per-entity
> transformers from `docs/reference/field-map.json`, the documented load order, `--dry-run`, `--only`,
> `--from-id`, batches of 500 in transactions, and idempotency keyed on the source id. Implement the email
> address recovery query, the `*_cstm` join, deduplication of the legacy duplicate modules, and dropdown
> canonicalisation with an error report for unmatched values. Then `crm:reconcile`, printing target, loaded
> and difference per entity against the §13 figures and exiting non-zero on mismatch.

---

## 19. Definition of done — every backend task

- [ ] Migration in `database/migrations/tenant/`; no `tenant_id`; conventions in §4 followed.
- [ ] Nothing hardcoded that belongs in metadata (no field lists, no option values).
- [ ] All DDL through `SchemaManager`.
- [ ] Datetimes UTC `Y-m-d H:i:s` at every boundary.
- [ ] Policy and query scope in place; permission tested at the Owner level.
- [ ] Factory plus tests: happy path, one failure path, one permission case.
- [ ] `pint` clean, `phpstan` at max clean, `pest` green.
- [ ] No secret in code, config or log output.
- [ ] Query count and timing checked against §16 on the heaviest path touched.
- [ ] Frozen contracts unchanged — or changed by agreement with Shahmeer and the version bumped.
- [ ] `/code-review` run before merge.

---

## 20. Do NOT do these

1. Install `stancl/tenancy` before Phase 8.
2. Add a `tenant_id` column to anything.
3. Put a company-specific value in `.env`.
4. Write a raw `ALTER TABLE` outside `SchemaManager`.
5. Interpolate an unvalidated identifier into DDL.
6. Use a MySQL `ENUM`, or hardcode dropdown values in PHP.
7. Create a controller, validator or serialiser per module instead of driving it from metadata.
8. Write a second permission filter for the API.
9. Parse a datetime using the authenticated user's locale.
10. Store a raw unmatched inbound choice value in an enum field.
11. Title-Case a dropdown label.
12. Recreate the source system's per-module activity link tables or audit tables.
13. Add anything Asterisk, telephony or SMS related.
14. Point any script or migration at the live production database.
15. Add a PHPStan baseline entry to make an error disappear without a written reason.

---

## 21. Open questions — get answers before they block you

| # | Question | Blocks | Default if no answer |
|---|---|---|---|
| 1 | Hosting, database version and backup destination | Z-1.1, Z-7.3 | MySQL 8, Docker, nightly dump to object storage |
| 2 | Confirm `char(36)` UUID primary keys preserved from the source | Z-2.1 | Yes — preserve |
| 3 | Which fields identify a duplicate per module | Z-5.6 | Email, then normalised mobile |
| 4 | Assignment rule for inbound leads (round-robin or by vertical) | Z-5.6 | Round-robin across active sales users |
| 5 | Do we keep the source `reports_to` hierarchy for a future manager access level | Z-1.3 | Keep the column, no behaviour yet |
| 6 | Retention period before a soft-deleted record can be hard-deleted | Z-2.1 | 90 days |
| 7 | Snapshot retention for `SchemaManager` | Z-1.6 | 30 days |
| 8 | Are personal access tokens allowed, or OAuth clients only | Z-5.3 | Both |
| 9 | Which option lists must be frozen as system lists (not editable) | Z-3.2 | `vertical`, `stage` |
| 10 | Business hours and holiday calendar values | Z-4.2 | Mon–Fri 09:00–17:00, company time zone |

---

## 22. What the Product Owner must give you

| When | What |
|---|---|
| Before Z-1.1 | Repository access; hosting and database for development and staging; the answers to §21 questions 1 and 2 |
| Before Z-2.4 | Field-mapping answers as they arise; confirmation of the vertical list in §7.4 |
| Before Z-3.2 | Which option lists are frozen; the current dropdown values for verticals, stages and call outcomes |
| Before Z-4.1 | Branding assets (logo, colour) and business hours; the notification recipient list |
| Before Z-5.6 | WordPress site details and an API key destination; the Meta app id, secret and page or form ids |
| Before Z-5.8 | SMTP host, port, encryption, username and password for the sending account (to be entered into settings, never committed) |
| Before Z-6.1 | **`crmga_sanitized.sql.gz`** — the sanitized database copy, used only on your local machine |
| Before Z-7.1 | Approval to rotate the credentials that have circulated in documents |
| Before Z-8.1 | The domain plus **wildcard DNS and TLS** for company subdomains, and the central admin domain |

---

## 23. Where to look when you need something

| Need | File |
|---|---|
| Exact source column types and indexes | `docs/reference/schema.json`, `docs/reference/crmga_full_schema.sql` |
| Which source tables feed which entity | `docs/reference/field-map.json` |
| Entity design and consolidation reasoning | `docs/DATA_MODEL.md` |
| Studio, API and ACL design detail plus live-schema verification | `docs/STUDIO_API_RBAC.md` |
| Layout JSON shape | `docs/contracts/layout.schema.json` |
| Field type to column, cast and validation | `docs/contracts/field-types.json` |
| API request and response shapes | `docs/contracts/api-contract.md` |
| Phases, milestones, definition of done per phase | `docs/PROJECT_PLAN.md` |
| Your task list with estimates | `docs/TASK_BREAKDOWN.md` |
| What each screen needs from the backend | `docs/crmga_Frontend_Design_Spec.docx` |
| Project rules Claude Code always loads | `CLAUDE.md` |
