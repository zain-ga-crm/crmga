# Phase 1–2 Kickoff Kit (Claude Code)

Copy-paste prompts to run **inside Claude Code**, in order. One PR per task. Always let Claude Code
enter **Plan Mode** first, approve the plan, then build. Run `pint`/`phpstan`/`pest` before each commit.

---

## Phase 1 — Foundation + tenancy + RBAC

### 1.1 Scaffold the app
> Scaffold a Laravel 11 (PHP 8.3) app at the repo root. Add and configure: Filament v3, stancl/tenancy,
> spatie/laravel-permission, Laravel Passport, Horizon. Set up Pest, Pint, and Larastan. Add a GitHub
> Actions workflow that runs pint --test, phpstan analyse, and pest on every PR. Create `.env.example`
> (no secrets). Follow CLAUDE.md. Keep it a single PR; make sure `pest` passes and the app boots.

### 1.2 Database-per-tenant
> Using stancl/tenancy, set up database-per-tenant: a central DB for the tenant registry + a `Tenant`
> model, and per-tenant databases for all CRM data. Add subdomain identification, central vs tenant
> route files, and an artisan command `tenant:create {name}` that provisions the tenant DB, runs tenant
> migrations, and seeds roles. Write a Pest test proving tenant A cannot read tenant B's data.

### 1.3 RBAC + roles
> Add spatie/laravel-permission. Create `config/roles.php` from `docs/reference/roles.php` and a
> `RolesSeeder` that seeds those roles per tenant. Wire Filament auth to roles. Add a permission matrix
> (module × view/list/edit/delete/import/export) and gate Filament resources on it. Tests for role gating.

### 1.4 CI + conventions
> Finalise CI: pint (format check), phpstan (level max), pest with coverage. Add a SessionStart hook in
> `.claude/settings.json` that runs `composer install` and tenant migrations so web sessions are ready.
> Add a permissions allow-list for composer/php artisan/pest/pint to reduce prompts.

**Phase 1 done when:** create a tenant → log into its Filament panel → role gating works; tenant-isolation test green; CI green on a PR.

---

## Phase 2 — Core data model + migrations

Read `docs/DATA_MODEL.md` + `docs/reference/field-map.json` first. Build **one entity per PR** (use a
subagent per entity to parallelize). Order: Company → Lead → Student → Assessment → StudyLead → LmiaCase
→ Client → Affiliate → NewsletterSubscriber → SmsMessage → Activities.

### 2.1 Shared base + first entity
> Create a `Contactable` base (trait + shared migration columns) from the `contactable_base` in
> `docs/reference/field-map.json` (first_name, last_name, phones, addresses, do_not_call, consent fields,
> assigned_user_id, soft deletes). Then build the **Company** entity: migration + Eloquent model +
> factory + relationships, mapping `ga_companies` (+ `_cstm`) columns per the field-map. Add a Pest test.

### 2.2 Lead (consolidated)
> Build the **Lead** entity with a `vertical` enum (BusinessImmigration, Refugee, SpousalSponsorship,
> ExpressEntry, Humanitarian, StudyPermit, PNP, USA, CanadaVisa, InCanada, Investor, Entrepreneur, BD,
> Resume) and a `stage`. Use the Contactable base; put vertical-specific dropdowns as typed columns where
> shared, else a `vertical_attributes` JSON. Map the source tables listed under `Lead` in the field-map.
> Model dropdowns as PHP enums, preserving the first-char-capitalised label convention.

### 2.3 Remaining entities (repeat the pattern)
> Build {Student|Assessment|StudyLead|LmiaCase|Client|Affiliate|NewsletterSubscriber|SmsMessage} the same
> way, from the field-map. Assessment carries the 88-field CRS/FSW calculator — store the scores in a
> `scores` JSON plus key typed columns (crs_score, fsw_score, marital_status, education).

### 2.4 Polymorphic activities
> Add polymorphic activities: Meeting, Note, Document (+DocumentRevision), Email (+EmailText,
> EmailAddress), Call, Task — each `morphTo` a subject (any CRM record). Add a polymorphic `Audit` log.
> Do NOT recreate the 154 SuiteCRM `_c` link tables. Denormalize `primary_email` on contactable entities.

### 2.5 DNC + Hot/Warm
> Implement the DNC feature (a `do_not_call` filter/toggle on list views) and Hot/Warm (`hot_lead`,
> `warm_lead` booleans + two Filament dashboard widgets aggregating across all lead verticals).

**Phase 2 done when:** all core migrations run clean on a fresh tenant DB; relationship + factory tests
green; the schema matches the field-map; DNC + Hot/Warm work in Filament.

---

## Per-entity migration checklist (use for every entity)
- [ ] Columns match `field-map.json` (base + module extras + `_cstm`), types sensible (varchar→string, tinyint(1)→boolean, datetime→UTC).
- [ ] `id` UUID PK; `deleted` → soft deletes; `assigned_user_id`/`created_by` → users FK.
- [ ] Dropdowns → PHP enums (label convention preserved).
- [ ] Relationships (activities morphs, email addresses, affiliate/company links) defined + tested.
- [ ] Factory + seeder; Pest test for CRUD + a relationship.
- [ ] Filament resource (list/detail/edit) gated by role.
- [ ] `pint` + `phpstan` + `pest` green; `/code-review` before merge.
