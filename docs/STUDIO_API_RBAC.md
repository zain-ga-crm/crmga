# Studio · RESTful API · Per-Tenant RBAC — Design

Three subsystems added/changed by the 2026-07-28 revision:
1. **Studio per tenant** — runtime fields, layouts, relationships, custom modules; controlled by super admin.
2. **Modern RESTful API** — integrates with social platforms, WordPress, any stack (replaces the SuiteCRM-V8-compatible API).
3. **Per-tenant roles & ACL** — SuiteCRM-style module × action matrix + user types.

> **The architectural consequence:** all three are **metadata-driven**. The CRM is no longer a set of
> hardcoded models — it is an **engine** that reads per-tenant metadata and produces the schema, the UI,
> the permissions, and the API. This must be built **up front** (Phase 1–2), because retrofitting Studio
> onto hardcoded models later means rewriting the app.

---

## PART 1 — Studio (per-tenant customisation engine)

### 1.1 What it must do (SuiteCRM parity)
| Studio capability | Scope |
|---|---|
| **Field Manager** | add/edit/delete fields per module: text, textarea, dropdown (enum), multiselect, checkbox, integer, decimal, currency, date, datetime, email, phone, url, relate (link to another module), image/file. With label, default, required, max length, validation, audited, reportable. |
| **Dropdown Editor** | per-tenant option lists (key ⇄ label), ordering, i18n. **Preserve the label convention** (first char capitalised only). |
| **Layout Editor** | drag-and-drop per module for **List view**, **Detail view**, **Edit view**, **Search/filter**, plus column order/width and panels/tabs. |
| **Relationship Manager** | one-to-many, many-to-many, one-to-one between any two modules (stock or custom) → generates FK or pivot + both sides' relation UI. |
| **Module Builder** | tenant-defined **new modules** (their own entity, e.g. "Visa Applications") with the full base (assigned user, audit, activities, permissions, API). |

### 1.2 Metadata model (lives in EACH tenant DB)
```
tenant_modules        id, key, label, table_name, is_custom, base_type(person|company|generic),
                      icon, enabled, order
tenant_fields         id, module_id, name, type, label_key, storage(column|json), options_list_id,
                      required, default, validation json, audited, filterable, sortable,
                      max_length, precision, related_module_id, is_custom, order, deleted_at
tenant_option_lists   id, key, label            tenant_option_items  id, list_id, value, label, order
tenant_layouts        id, module_id, view(list|detail|edit|search), definition json, version
tenant_relationships  id, name, lhs_module_id, rhs_module_id, type(1-M|M-M|1-1), pivot_table,
                      lhs_label, rhs_label
tenant_studio_changes id, actor_id, kind, payload json, status(requested|approved|applied|rolled_back),
                      applied_at, ddl_log text        ← audit + approval trail
```

### 1.3 Field storage — **managed sidecar DDL** (recommended)
Each module has a real table plus a **`{table}_custom` sidecar** (mirrors SuiteCRM's `_cstm`, so their
existing custom fields map 1:1 during migration). Studio changes go through a **SchemaManager** service:

```
Studio request → validate → plan DDL → snapshot (dump affected table)
              → apply ALTER inside a transaction/lock → record in tenant_studio_changes
              → bump metadata cache version → rebuild dynamic resources
```
**Why real columns, not pure JSON:** indexable, sortable, fast filtering on 21k+ records, and honest SQL
for the ETL and reporting. **Why it's safe here:** with **database-per-tenant**, runtime DDL touches
exactly one tenant — the blast radius is a single company, never the whole platform. (Fields flagged
non-filterable may be stored in a `custom` JSON column to avoid column sprawl; MySQL 8 generated columns
+ functional indexes are the escape hatch if a JSON field later needs indexing.)

**Guardrails:** field-count ceiling per module (configurable per tenant), reserved-name blocklist,
type-change rules (widen freely; narrowing requires confirmation + backup), destructive ops
(delete field/module) = soft-delete first, hard-delete after a retention window, always with a snapshot.

### 1.4 Dynamic rendering (how the UI stays generic)
- A **`DynamicResource`** Filament resource builds `form()`, `table()`, `infolist()`, and filters **from
  `tenant_layouts` + `tenant_fields`** at runtime; a `FieldTypeRegistry` maps each field type to a
  Filament component + Eloquent cast + validation rule.
- Models: core entities are real classes using a `HasCustomFields` trait (loads sidecar + casts +
  fillable from metadata); Studio-created modules use a single `DynamicModel` bound to its table.
- **Caching:** compiled metadata cached per tenant (`tenant:{id}:metadata:v{n}`); any Studio change bumps
  `v` → instant, safe invalidation. No app deploy needed for a tenant's change.

### 1.5 Super-admin governance (your control per tenant)
On the central/landlord panel, per tenant:
- **Studio mode:** `disabled` · `request-only` (tenant submits, you approve) · `self-serve` (within limits).
- **Limits:** max custom fields per module, max custom modules, allowed field types, may-create-relationships y/n.
- **Change queue:** tenant requests appear for review → **preview the exact DDL** → approve/reject with a note → applied and logged.
- **Blueprints:** save a tenant's configuration as a template and **push it to other tenants** (e.g. a standard "Immigration Firm" package) — how you onboard new companies in minutes.
- **Full audit + rollback** per change (`tenant_studio_changes` + snapshots).

### 1.6 Migration bonus
Their live `fields_meta_data` (Studio custom fields) + `listviewdefs`/`detailviewdefs` **import directly
into `tenant_fields` / `tenant_layouts`** — the existing customisation becomes Studio metadata on day one.

---

## PART 2 — RESTful API + integrations (replaces V8-compatible API)

### 2.1 Design
- **Versioned REST:** `/api/v1/{resource}` — `GET` (index/show), `POST`, `PATCH`, `DELETE`; JSON in/out.
- **Resources are metadata-driven:** every module (stock *and* Studio-created) automatically gets
  endpoints, validation, and docs — a tenant adds a module in Studio and its API exists immediately.
- **Query language:** `?filter[status]=new&filter[created_at][gte]=...&sort=-created_at&page[size]=50&include=activities&fields[lead]=first_name,email`.
- **Standards:** consistent envelope (`data`, `meta`, `links`, `errors`), RFC-7807 problem details, ETag/
  `If-Modified-Since`, idempotency keys on `POST`, cursor pagination for large sets, `429` + rate-limit headers.
- **Auth (all tenant-scoped):** OAuth2 **client_credentials** (server-to-server, Passport) · **Personal
  Access Tokens** (Sanctum, per user) · **API keys** for simple integrations — all with **scopes**
  (`leads:read`, `leads:write`, `studio:read`, …) and per-tenant rate limits + request logs.
- **OpenAPI 3.1 spec generated per tenant** (it reflects their Studio fields) + Swagger UI → this is what
  makes "any other stack application" integrate without custom work.

### 2.2 Outbound webhooks (the real integration engine)
Tenant-configurable subscriptions: `lead.created`, `lead.updated`, `lead.stage_changed`, `call.completed`,
`sms.received`, `email.received`, `{custom_module}.created`, … Delivered as signed JSON
(**HMAC-SHA256** `X-Signature` + timestamp), with **retries + exponential backoff**, a delivery log, and
replay. This is how any external system (n8n, Zapier, Make, a WordPress site, a custom app) reacts to CRM events.

### 2.3 Inbound integrations
| Source | How |
|---|---|
| **WordPress** | A small **WP plugin** (+ works with Gravity Forms / CF7 / WPForms / Elementor): maps form fields → CRM fields, posts to `/api/v1/leads` with an API key; optional two-way sync of post/user data. Plus a generic form endpoint for any site. |
| **Meta (Facebook / Instagram)** | Graph API **Lead Ads**: subscribe to the `leadgen` webhook → fetch the lead → map to CRM (keeping the **Meta value canonicalisation** rules). Also page messages if wanted. |
| **WhatsApp** | WhatsApp Business Cloud API: inbound messages + template sends, threaded onto the lead. |
| **LinkedIn / TikTok / Google** | Lead Gen Form webhooks / API pulls, same mapper pipeline. |
| **Generic** | `POST /api/v1/ingest/{source}` — signed, mappable payload for any platform not listed. |
| **n8n / Zapier / Make** | Consume the REST API + webhooks (OpenAPI makes nodes trivial). |

A shared **FieldMapper** (per source, per tenant) does canonicalisation → validation → dedupe (email/phone)
→ create/update → assign owner → fire events. One pipeline, many sources.

### 2.4 ⚠️ Consequence of dropping V8 compatibility
The **133 existing n8n workflows** call SuiteCRM's V8 JSON:API. With the V8-compatible layer removed they
must be **rewritten**, not just re-pointed (different URLs, payload shape, auth, field names) —
**+2–3 weeks** of the timeline, and a big-bang switchover risk.

**Recommendation (insurance, cheap):** build the modern REST API as the product, and add a **thin legacy
adapter** (`/Api/V8/*` → maps to the new services; ~3–4 days) purely as a **transition bridge** so the 133
workflows keep running on day one. Migrate them to the clean REST API in waves, then delete the adapter.
Your call — the plan currently assumes **full rewrite** as you asked, with the adapter listed as an option.

---

## PART 3 — Per-tenant roles & ACL (SuiteCRM-style)

### 3.1 User types
| Type | Scope |
|---|---|
| **Super Admin (platform)** | Central/landlord panel. Manages tenants, Studio governance, plans, cross-tenant support. Not a CRM user. |
| **System Administrator (per tenant)** | Full admin **inside their company**: users, roles, Studio (per your governance), settings, email/telephony config. |
| **Regular User** | CRM access strictly per their roles. |
| *(optional)* **Portal/API user** | Non-UI principal for integrations, scoped tokens only. |

### 3.2 ACL model — module × action × access level (per tenant)
```
role                      id, name, description
role_module_permissions   role_id, module_key,
                          view, list, edit, delete, import, export, mass_update   ← each:
                                                                       All | Owner | Group | None
role_field_permissions    role_id, module_key, field_name, access(read_write|read_only|hidden)
role_user                 role_id, user_id
```
- **Access levels** are the SuiteCRM behaviour that matters: **Owner** = only records where
  `assigned_user_id = me`; **Group** = my team/security group; **All**; **None**.
- Enforced in three layers: **Policies** (can I do this action?), **global query scopes** (which records do
  I see — applied to UI *and* API identically), and **field-level** filtering in forms/serializers.
- **Dynamic:** when Studio creates a module, permissions for it are auto-registered for every action, and
  every role gets an explicit default (deny) — no orphaned access.
- **UI:** a SuiteCRM-like **role matrix editor** (modules down, actions across, dropdown per cell) in the
  tenant's admin area; roles are **per tenant** (stored in the tenant DB) so every company defines its own.
- Implementation: `spatie/laravel-permission` for the role/permission plumbing (tenant-scoped because it
  lives in the tenant DB) **plus** our `role_module_permissions` matrix for access levels, which spatie
  alone doesn't model. Seed each new tenant with a starter set derived from the 29 existing roles.

---

## PART 4 — What this changes in the build

**New/changed stack items:** metadata engine + `SchemaManager` (runtime DDL), `DynamicResource`/
`FieldTypeRegistry` (dynamic Filament), OpenAPI generator (`scramble` or `l5-swagger`), webhook dispatcher
(signed + retried), platform SDK/HTTP clients (Meta Graph, WhatsApp Cloud, LinkedIn, Google), a WordPress
plugin (separate small repo), `spatie/laravel-permission` + custom ACL matrix.

**Timeline:** the original 9 weeks assumed *no Studio* and the *V8-compat API as a shortcut*. Adding a
per-tenant Studio (a metadata engine — the single largest subsystem in SuiteCRM), dynamic ACL, a full REST
API + platform integrations, and rewriting 133 workflows lands realistically at **~14 weeks with 3 devs**:

| Option | Team | Duration | Notes |
|---|---|---|---|
| **A — Full scope (recommended)** | 3 devs | **~14 weeks** | Everything above, built in the right order |
| **B — Compress** | **4 devs** | ~11–12 weeks | Studio and API run in parallel tracks |
| **C — Staged** | 3 devs | 9 wks to first go-live, Studio in wks 10–15 | Ship CRM + REST API + roles first; Studio after. **The metadata engine must still be built in Phase 2** so Studio drops in without a rewrite. |

**Non-negotiable sequencing:** metadata engine (P1–P2) → dynamic ACL (P2) → Studio UI (P3) →
dynamic API from the same metadata (P5). Anything hardcoded before the engine exists gets rewritten twice.

---

## Appendix — Verified against the live SuiteCRM 8.8 schema (2026-07-28)

`docs.suitecrm.com` is blocked by this environment's egress policy, so these were verified against the
**authoritative source for this install**: the real DDL of `crmga_crm_prod` (`docs/reference/schema.json`).
Corrections below are already folded into the design.

### A1. Studio field metadata — `fields_meta_data` (21 columns)
```
id, name, vname, comments, help, custom_module, type, len, required, default_value,
date_modified, deleted, audited, massupdate, duplicate_merge, reportable, importable, ext1..ext4
```
**Flags we were missing → add to `tenant_fields`:** `help` (tooltip), `comments`, `massupdate`
(mass-update allowed), `duplicate_merge` (participates in dedupe/merge), `reportable`, `importable`, `len`.

**`ext1..ext4` are SuiteCRM's type-specific extras** — we model them as explicit typed columns instead:
`options_list_id` (enum/multienum ← ext1) · `related_module_id` + `related_display_field`
(relate ← ext2/ext3) · `precision` (decimal) · `default_value`. Cleaner and self-documenting.

**`vname` is a language key, not a label.** Studio stores `LBL_*` and resolves it through the module's
language files — this is exactly their documented gotcha (raw text in `vname` renders blank in the
Angular "Choose Columns" picker, and Studio then bakes the broken string into `listviewdefs.php`).
**Our Field Manager must auto-generate a `label_key` (`LBL_…`) and store the human label separately**, so
this class of bug cannot occur.

### A2. ACL — the real model (correcting our sketch)
```
acl_actions        id, name(action), category(= MODULE), acltype, aclaccess(int = default level)
acl_roles_actions  role_id, action_id, access_override(int = this role's level)
acl_roles          id, name, description
acl_roles_users    role_id, user_id
```
- Permissions are **(category = module) × (name = action)** rows in `acl_actions`; a role attaches with an
  **integer `access_override`**. Actions: `access, view, list, edit, delete, import, export, massupdate`
  (+ admin/developer levels for admins).
- Access levels in the UI are **All · Owner · Group · Not Set (inherit default) · None**.
  ⚠️ The exact integer constants live in `modules/ACLActions/actiondefs.php`; rather than assume them,
  read the values actually used in this install:
  ```sql
  SELECT DISTINCT category, name, acltype, aclaccess FROM acl_actions ORDER BY category, name;
  SELECT DISTINCT access_override, COUNT(*) FROM acl_roles_actions GROUP BY access_override;
  ```
  Our `role_module_permissions` stores a **named enum** (`all|owner|group|none|not_set`) and maps to/from
  those integers only in the ETL — no magic numbers in the app.
- **Field-level ACL is NOT in use here:** there is **no `acl_fields` table** among the 481 (matches the
  blueprint's note). → **Scope saving: field-level ACL moves to fast-follow**, keep the hook in the model.
- **Security groups ("Group" level)** are real and modelled by
  `securitygroups`, `securitygroups_users`, `securitygroups_records` (record ↔ group ↔ module),
  `securitygroups_acl_roles` (roles granted via a group), `securitygroups_default`, plus a
  `noninheritable` flag. Only 2 groups exist in their install → implement `Group` level, low priority.

### A3. User types — confirmed by `users` (48 columns)
`is_admin` (= **System Administrator**) · `is_group` (group user) · `portal_only` (portal user) · `status`
· **2FA is real**: `factor_auth`, `factor_auth_interface`, `totp_secret`, `is_totp_enabled`, `backup_codes`
· **`reports_to_id`** = reporting hierarchy (enables an optional "manager sees their reports' records" level).

**Telephony correction:** the `users` table carries **per-user Asterisk settings** —
`asteriskintegration_server_ip`, `asteriskintegration_extension`, `asteriskintegration_context`,
`asteriskintegration_show_notification`. So click-to-call needs **per-user extension/context**, not only
per-tenant PBX credentials. Added to the telephony design.

### A4. Relationships — enrich `tenant_relationships`
The real `relationships` table stores: `relationship_name, lhs_module, lhs_table, lhs_key, rhs_module,
rhs_table, rhs_key, join_table, join_key_lhs, join_key_rhs, relationship_type,
relationship_role_column, relationship_role_column_value, reverse`.
→ Add **`role_column` + `role_column_value`** (role-based / self-referencing relations) and **`reverse`**
to our metadata, and keep the explicit `join_table` + both join keys for M-M.

### A5. API / OAuth — `oauth2clients` / `oauth2tokens`
`oauth2clients`: `secret, redirect_url, is_confidential, allowed_grant_type, duration_value,
duration_amount, duration_unit, assigned_user_id`.
→ **API clients are owned by a user** (`assigned_user_id`) — which is precisely why the V8 datetime bug
depended on `api_user`'s own date-format/timezone preferences. Two consequences, both adopted:
1. Our REST API is **locale-independent** — parsing/formatting never depends on the authenticated
   principal's preferences (always UTC `Y-m-d H:i:s` on the wire boundary).
2. Our API-client model mirrors these fields (owner user for attribution + ACL, grant type, configurable
   token TTL, confidential flag, redirect URL).

### A6. Legacy storage to ignore
`custom_fields` (`bean_id, set_num, field0..field9`) is the **pre-`_cstm` SugarCRM custom-field store** —
deprecated. The live custom data is in the `*_cstm` sidecars. ETL reads `*_cstm`; check `custom_fields`
for stray rows once and otherwise ignore it.

### A7. Sources
Official docs pages identified (currently unreachable from this environment — read them locally if you
want the prose): [Studio](https://docs.suitecrm.com/admin/administration-panel/studio/) ·
[Field Types (8.x developer/metadata)](https://docs.suitecrm.com/8.x/developer/metadata/fields/field-types/) ·
[Roles and Security Groups](https://docs.suitecrm.com/admin/administration-panel/roles-and-security-groups/) ·
[Security Suite (Groups)](https://docs.suitecrm.com/user/security-suite-groups/) ·
[Vardefs](https://docs.suitecrm.com/developer/vardefs/) ·
[Administrator Guide 8.x](https://docs.suitecrm.com/8.x/admin/).
Confirmed from those pages via search: Studio supports dropdown, multi-select dropdown, relate/link-to-module
and related-bean field types, and dropdown fields reference a named dropdown list; ACL access options are
**All / Owner / Not Set / None** (+ Group via SecuritySuite), with levels defined in
`modules/ACLActions/actiondefs.php`.
