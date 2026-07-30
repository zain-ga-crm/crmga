# Phase 1–2 Kickoff Kit (Claude Code)

Copy-paste prompts to run **inside Claude Code**, in order. One PR per task. Let Claude Code enter
**Plan Mode** first, approve the plan, then build. Run `pint` → `phpstan` → `pest` before each commit.

**Read first:** `CLAUDE.md` → `docs/PROJECT_PLAN.md` (especially **§3 tenancy-ready rules**) →
`docs/ARCHITECTURE.md` → `docs/STUDIO_API_RBAC.md` → `docs/DATA_MODEL.md`.

> **Two rules that shape everything in these phases:**
> 1. **Build the metadata engine before any hardcoded entity.** Schema, UI, permissions and API are all
>    generated from metadata. Hardcoding first means rewriting when Studio lands.
> 2. **Single database now, tenant database later.** Do **not** install `stancl/tenancy` yet and do **not**
>    add a `tenant_id` column to anything. Follow the tenancy-ready rules so the Phase 8 conversion is a
>    two-week job instead of a rewrite.

**Lanes:** **Z** = Zain (backend) · **S** = Shahmeer (frontend). Both can work in parallel from day one
once Z-1.4/Z-1.5 have landed the metadata contract.

---

## Phase 1 — Foundation + metadata engine (Weeks 1–2)

### Z-1.1 Scaffold and CI *(backend)*
> Scaffold a Laravel 11 (PHP 8.3) application at the repo root. Add and configure Filament v3,
> spatie/laravel-permission, Laravel Passport and Sanctum, and Horizon. Set up Pest, Pint and Larastan
> (level max). Add a GitHub Actions workflow running `pint --test`, `phpstan analyse` and `pest` on every
> pull request. Create `.env.example` with no secrets. **Do not install stancl/tenancy** — multi-tenancy is
> Phase 8. Follow CLAUDE.md. One PR; the app boots and `pest` passes.

### Z-1.2 Tenancy-ready skeleton and CI guard *(backend)*
> Set up the structure that lets multi-tenancy be added later without a rewrite, per `PROJECT_PLAN.md` §3:
> put all CRM migrations in `database/migrations/tenant/` and register that path; create an empty
> `routes/central.php` stub; add a `settings` table plus a `Settings` service for per-company configuration
> (nothing company-specific in `.env`); add one helper that all cache and queue keys go through; use only
> the `Storage` facade for files. Then write a Pest test that **fails CI** if a CRM migration appears
> outside the tenant folder, if any migration adds a `tenant_id` column, or if `routes/central.php` gains
> routes. Document the rules in the README.

### Z-1.3 Users and authentication backend *(backend)*
> Build the users table and authentication backend: name, username, email, `is_admin` (System
> Administrator), status, `reports_to`, locale, timezone, telephony extension and context, email signature.
> Add a password policy, TOTP two-factor authentication with backup codes, and password reset. Model the two
> user types (System Administrator, Regular User) as described in `STUDIO_API_RBAC.md` Part 3.

### Z-1.4 Metadata registry *(backend — the foundation of everything)*
> Create the metadata tables per `docs/STUDIO_API_RBAC.md` §1.2, with the corrections in its appendix:
> `tenant_modules`, `tenant_fields`, `tenant_option_lists`, `tenant_option_items`, `tenant_layouts`,
> `tenant_changes`. `tenant_fields` must carry the verified flags — help text, comments, audited,
> mass-updatable, duplicate-merge, reportable, importable, length — and typed extras instead of SuiteCRM's
> `ext1..ext4`: option list, related module, related display field, precision, default. Add Eloquent models
> and factories.

### Z-1.5 Metadata contract and cache *(backend — unblocks the frontend)*
> Build a `MetadataRepository` that compiles the metadata into a cached structure with a version that bumps
> on any change. Then **define and document the layout JSON contract** (how a view's panels, tabs, field
> order and column widths are represented) and seed a fixture set covering one module end to end. Treat this
> contract as frozen from here — the whole frontend builds against it.

### Z-1.6 SchemaManager — safe runtime DDL *(backend — the hardest piece)*
> Build a `SchemaManager` service that applies field changes as real DDL against **the current default
> connection** (never a hardcoded database name): validate the request (reserved names, type rules,
> configured limits) → plan the DDL → snapshot the affected table → apply inside a transaction with a lock →
> record the change and the applied DDL in `tenant_changes` → bump the metadata cache version. Custom fields
> go into a `{table}_custom` sidecar table, mirroring SuiteCRM's `_cstm` so existing fields import one-to-one
> later. Include a restore-from-snapshot path. Tests: adding a field creates the column; restore works;
> reserved names and limit breaches are rejected.

### S-1.1 Panel shell *(frontend)*
> Build the Filament panel shell: theme, colour palette, typography, navigation structure (grouped as in
> `ARCHITECTURE.md` §3.1), page layout and the notification area. No modules yet — just the frame.

### S-1.2 Authentication screens *(frontend)*
> Build the authentication screens: login, two-factor challenge (authenticator code or backup code), forgot
> password, reset password, and the forced password change on first login. Match the behaviour in
> `docs/crmga_CRM_Functional_Screen_Spec.docx` section 4.

### S-1.3 FieldTypeRegistry *(frontend — the bridge to the metadata engine)*
> Build a `FieldTypeRegistry` that maps every field type — text, long text, dropdown, multi-select,
> checkbox, integer, decimal, currency, date, date-time, email, phone, URL, relate, file, image — to a
> Filament form component, a table column, an Eloquent cast and a validation rule set. Make it extensible so
> a new type is one registration. Drive it from the metadata fixtures from Z-1.5.

### S-1.4 Design system *(frontend)*
> Build the reusable components every screen will use: the badge set (vertical, stage, hot, warm, do-not-call),
> a phone cell with a click-to-call action, an email cell that opens compose, empty states, loading states and
> confirmation dialogs. Keep them in one place so the interface stays consistent as modules are added.

**Phase 1 exit (M1):** a field added through the metadata layer creates a real column, is logged in
`tenant_changes`, and bumps the cache version; the tenancy-ready CI guard passes; login with 2FA works;
CI green.

---

## Phase 2 — Data model + ACL + first screens (Weeks 3–5)

### Z-2.1 Contactable base and custom fields *(backend)*
> Create the shared `Contactable` base — a trait plus the shared migration columns from
> `contactable_base` in `docs/reference/field-map.json`: names, phones, addresses, `do_not_call`, the consent
> fields, `assigned_user_id`, soft deletes, `char(36)` UUID primary keys. Then add a `HasCustomFields` trait
> that reads the `{table}_custom` sidecar and derives casts, fillable attributes and validation from
> `tenant_fields`, so any Studio field works transparently on any model.

### Z-2.2 Polymorphic activities and audit *(backend)*
> Add the activity models — Meeting, Note, Document with revisions, Email with body, Call, Task — each
> morphing to any record. Add a polymorphic audit log, and an `EmailAddress` morph with a denormalised
> `primary_email` on contactable models. Do **not** recreate the 154 SuiteCRM link tables or the 79 audit
> tables; that is the point of this task.

### Z-2.3 ACL engine *(backend)*
> Implement the permission model from `STUDIO_API_RBAC.md` Part 3: `role`, `role_module_permissions` (view,
> list, edit, delete, import, export, mass-update — each **All, Owner or None**) and `role_user`. Enforce it
> with policies **and global query scopes that the API will reuse unchanged**, so the interface and the API
> can never disagree. Auto-register permissions when a module is created, defaulting to deny. Seed the 29
> starter roles from `docs/reference/roles.php`. Store levels as named enums, not integers. Tests must cover
> Owner-level scoping in particular. The Group level and field-level permissions are out of scope.

### Z-2.4 Primary entities *(backend)*
> Build Company, Lead and Assessment from `docs/DATA_MODEL.md` and `field-map.json`: migration, model,
> factory, relationships and tests each. Lead carries `vertical` and `stage`, where `vertical` includes
> Business Immigration, Refugee, Spousal Sponsorship, Express Entry, Humanitarian, **Study Permit**, **LMIA**,
> PNP, USA, Canada Visa, In-Canada, Investor, Entrepreneur, Business Development and Resume — Study and LMIA
> are verticals in v1, not separate modules. Assessment stores the CRS and FSW key figures as typed columns
> plus the full factor set as JSON. Seed dropdown values into `tenant_option_lists` as well as PHP enums, and
> preserve the first-character-only label capitalisation.

### Z-2.5 Remaining entities *(backend)*
> Build Student, Client, Affiliate, NewsletterSubscriber, SmsMessage and CallSummary the same way, from the
> field map.

### Z-2.6 ETL transformers — early start *(backend)*
> Against a local copy of the sanitized dump, write first-pass transformers for the entities built so far and
> produce a source-data profile. The goal is to surface mapping gaps now, in week 5, rather than in Phase 6.

### S-2.1 DynamicResource *(frontend — the core component)*
> Build a `DynamicResource` that constructs a Filament table, form, detail view and filter set for any module
> from `tenant_layouts` and `tenant_fields` through the `FieldTypeRegistry`. It must honour panels, tabs,
> field order, column order and column widths from the layout JSON contract. Every screen in the product is
> built on this, so invest in it: tests against the metadata fixtures, and a fallback when a layout is absent.

### S-2.2 Company screens *(frontend)*
> Build the Company screens on the dynamic renderer: list with the columns and filters in the screen
> specification, detail with its panels, and create and edit forms, plus bulk actions.

### S-2.3 Lead screens *(frontend)*
> Build the Lead screens with **vertical-aware panels** — the qualification fields shown depend on the
> record's vertical, and irrelevant fields are hidden. Include stage and vertical badges, hot, warm and
> do-not-call indicators, and the quick actions (call, email, edit, convert).

### S-2.4 List-page framework *(frontend)*
> Build the list-page capabilities once so every module inherits them: saved views (named filter, column and
> sort combinations, private or shared), a column chooser, the filter panel, the bulk-action bar and export.

**Phase 2 exit (M2):** migrations run clean; a Regular User with Owner access sees only their own records in
the interface **and** through the API; a System Administrator sees everything; Company and Lead are fully
usable.

---

## Per-entity checklist
- [ ] Columns match `field-map.json`; sensible types (varchar → string, tinyint(1) → boolean, datetime → UTC).
- [ ] `char(36)` UUID primary key, source IDs preserved; `deleted` → soft deletes; user foreign keys in place.
- [ ] **Migration lives in `database/migrations/tenant/`**; **no `tenant_id` column**.
- [ ] Dropdowns exist as PHP enums **and** `tenant_option_lists` rows; label convention preserved.
- [ ] `HasCustomFields` wired and the `{table}_custom` sidecar created, so Studio can extend it.
- [ ] Relationships defined and tested (activity morphs, email addresses, company and affiliate links).
- [ ] Permissions auto-registered; **Owner-level scoping tested**.
- [ ] Factory, seeder and Pest tests for CRUD, one relationship and one access level.
- [ ] Renders through `DynamicResource`, and (from Phase 5) appears in the REST API.
- [ ] `pint`, `phpstan` and `pest` green; `/code-review` before merge.
