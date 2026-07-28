# Phase 1–2 Kickoff Kit (Claude Code)

Copy-paste prompts to run **inside Claude Code**, in order. One PR per task. Always let Claude Code enter
**Plan Mode** first, approve the plan, then build. Run `pint` → `phpstan` → `pest` before each commit.

**Read first:** `CLAUDE.md` → `docs/ARCHITECTURE.md` → `docs/STUDIO_API_RBAC.md` → `docs/DATA_MODEL.md`.

> **The rule:** build the **metadata engine before any entity**. Schema, UI, permissions and API are all
> generated from per-tenant metadata. Hardcoding entities first means rewriting them when Studio lands.

---

## Phase 1 — Foundation + tenancy + metadata core (Weeks 1–2)

### 1.1 Scaffold the app
> Scaffold a Laravel 11 (PHP 8.3) app at the repo root. Add and configure: Filament v3, stancl/tenancy,
> spatie/laravel-permission, Laravel Passport + Sanctum, Horizon. Set up Pest, Pint, Larastan. Add a GitHub
> Actions workflow running `pint --test`, `phpstan analyse`, `pest` on every PR. Create `.env.example` (no
> secrets). Follow CLAUDE.md. Single PR; `pest` passes and the app boots.

### 1.2 Database-per-tenant
> Using stancl/tenancy, set up database-per-tenant: a central DB for the tenant registry + a `Tenant` model,
> and per-tenant databases for all CRM data. Add subdomain identification, central vs tenant route files, and
> `php artisan tenant:create {name}` that provisions the tenant DB, runs tenant migrations, and seeds roles.
> Write a Pest test proving tenant A cannot read tenant B's data.

### 1.3 Metadata registry (the engine's foundation)
> Create the per-tenant metadata tables per `docs/STUDIO_API_RBAC.md` §1.2: `tenant_modules`,
> `tenant_fields`, `tenant_option_lists`, `tenant_option_items`, `tenant_layouts`, `tenant_relationships`,
> `tenant_studio_changes`. Add Eloquent models + a `MetadataRepository` that compiles a tenant's metadata
> into a cached structure keyed `tenant:{id}:metadata:v{n}`, with a version bump that invalidates it.

### 1.4 SchemaManager (safe runtime DDL)
> Build a `SchemaManager` service that applies field/relationship/module changes as real DDL against the
> **current tenant's** database only: validate (reserved names, type rules, per-tenant limits) → plan the
> DDL → snapshot the affected table → apply inside a transaction/lock → record in `tenant_studio_changes`
> (including the DDL log) → bump the metadata cache version. Custom fields go into a `{table}_custom`
> sidecar table. Support add/modify/soft-delete field, and rollback from a recorded change. Tests: adding a
> field creates the column in that tenant only; rollback restores; limits and reserved names are rejected.

### 1.5 Super-admin (landlord) panel + Studio governance
> Add a second Filament panel on the central domain, authenticating **platform Super Admins** against the
> central DB. Features: list/create/suspend companies (tenants); per-tenant **Studio governance** —
> mode (`disabled` | `request-only` | `self-serve`) and limits (max custom fields per module, max custom
> modules, allowed field types, may create relationships/modules); and a **change-request queue** showing
> pending Studio requests with a **DDL preview** and approve/reject actions.

### 1.6 Tenant auth + FieldTypeRegistry
> Build the tenant Filament auth panel (login, TOTP 2FA, password policy) with users in the tenant DB. Then
> create a `FieldTypeRegistry` mapping each field type (text, textarea, enum, multienum, bool, int, decimal,
> currency, date, datetime, email, phone, url, relate, file/image) to a Filament form component, a table
> column, an Eloquent cast, and validation rules.

### 1.7 CI + conventions
> Finalise CI (pint, phpstan max, pest + coverage). Add `.claude/settings.json` with a SessionStart hook
> running `composer install` + tenant migrations, and a permission allow-list for composer/artisan/pest/pint.

**DoD (M1):** create a tenant → log in → add a field through the metadata API → the column appears in that
tenant's DB only, is audited, and the metadata cache version bumps; tenant-isolation test green; CI green.

---

## Phase 2 — Core data model + ACL engine (Weeks 3–5)

### 2.1 Contactable base + custom-field trait
> Create a `Contactable` base (trait + shared migration columns) from `contactable_base` in
> `docs/reference/field-map.json` (names, phones, addresses, `do_not_call`, consent fields,
> `assigned_user_id`, soft deletes). Add a `HasCustomFields` trait that loads the `{table}_custom` sidecar,
> and derives casts/fillable/validation from `tenant_fields` metadata.

### 2.2 Polymorphic activities
> Add polymorphic activities: Meeting, Note, Document (+DocumentRevision), Email (+EmailText, EmailAddress),
> Call, Task — each `morphTo` a subject (any CRM record, including Studio-created modules). Add a
> polymorphic Audit log. Do NOT recreate the 154 SuiteCRM `_c` link tables. Denormalize `primary_email`.

### 2.3 ACL engine (per tenant)
> Implement the ACL model in `docs/STUDIO_API_RBAC.md` Part 3: `role`, `role_module_permissions`
> (view/list/edit/delete/import/export/mass_update, each `All|Owner|Group|None`), `role_field_permissions`,
> `role_user`. Enforce with Policies + **global query scopes used by BOTH the UI and the API**, and
> field-level filtering in forms/serializers. Add user types: platform Super Admin, tenant System
> Administrator (bypasses ACL within its tenant), Regular User. Auto-register permissions when Studio
> creates a module (default deny). Tests for every access level, especially **Owner**.

### 2.4 Core entities
> Build one entity per PR from `docs/DATA_MODEL.md` + `field-map.json`: Company, Lead (`vertical` enum +
> `stage`), Student, Assessment (88-field CRS/FSW → `scores` JSON + key typed columns), StudyLead, LmiaCase,
> Client, Affiliate, NewsletterSubscriber, SmsMessage. Migration + model + factory + relationships + tests.
> Dropdowns as PHP enums **and** seeded into `tenant_option_lists` so Studio can edit them; preserve the
> first-char-capitalised label convention.

### 2.5 Starter roles + first dynamic rendering
> Seed each new tenant with starter roles derived from `docs/reference/roles.php` (the 29 source roles).
> Then render one module's list + edit view entirely from metadata via a `DynamicResource` to prove the
> engine end-to-end.

**DoD (M2):** migrations clean on a fresh tenant DB; a Regular User with *Owner* access sees only their own
records **in both UI and API**; System Administrator sees all; relationship/factory tests green.

---

## Per-entity checklist (use for every entity)
- [ ] Columns match `field-map.json`; types sensible (varchar→string, tinyint(1)→boolean, datetime→UTC).
- [ ] UUID PK (`char(36)`, keep source IDs); `deleted` → soft deletes; `assigned_user_id`/`created_by` FKs.
- [ ] Dropdowns → PHP enums **and** `tenant_option_lists` rows (Studio-editable), label convention preserved.
- [ ] `HasCustomFields` wired (sidecar table exists) so Studio can extend it.
- [ ] Relationships (activity morphs, email addresses, company/affiliate links) defined + tested.
- [ ] Permissions auto-registered for the module; **Owner-level scope tested**.
- [ ] Factory + seeder; Pest tests for CRUD + one relationship + one ACL level.
- [ ] Appears correctly via `DynamicResource` and (from Phase 5) the REST API + OpenAPI.
- [ ] `pint` + `phpstan` + `pest` green; `/code-review` before merge.
