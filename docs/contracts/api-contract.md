# API Contract — REST v1 and the legacy V8 adapter

FROZEN CONTRACT. Concrete request and response shapes. Backend implements; integrators consume.

---

## PART 1 — REST API v1

Base: `/api/v1`. All requests and responses are `application/json`.

### 1.1 Authentication

**OAuth2 client credentials** (server-to-server; this is what n8n and partner systems use):
```http
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=<id>&client_secret=<secret>&scope=leads:read leads:write
```
```json
{ "token_type": "Bearer", "expires_in": 3600, "access_token": "eyJ0eXAi…" }
```

**Personal access token** (a user's own scripts): created in the UI, sent the same way.

All subsequent calls:
```http
Authorization: Bearer <access_token>
Accept: application/json
```

**Scopes.** `{module}:read`, `{module}:write`, `{module}:delete` per module, plus `metadata:read`,
`studio:write`, `users:read`. A token without the scope gets **403** with `code: "insufficient_scope"`.
A client also carries a **user identity** (its owner) — record-level ACL applies to API calls exactly as it
does in the interface.

### 1.2 Collection: index

```http
GET /api/v1/leads?filter[stage]=new&filter[created_at][gte]=2026-07-01&sort=-created_at&page[size]=25&fields[leads]=first_name,last_name,primary_email&include=assignee
```

Filter grammar:

| Form | Meaning |
|---|---|
| `filter[field]=value` | equals |
| `filter[field][eq|neq]=v` | equals / not equals |
| `filter[field][gt|gte|lt|lte]=v` | comparison (numbers, dates) |
| `filter[field][in]=a,b,c` | in list |
| `filter[field][like]=%text%` | contains (only on fields marked filterable) |
| `filter[field][null]=true|false` | is null / is not null |
| `q=amina` | free-text across the module's indexed identity fields |

`sort=field` ascending, `sort=-field` descending, comma-separated for multiple.
`page[size]` 1–200 (default 25), `page[number]` for offset paging, or `page[cursor]` for keyset paging on
large collections. Unknown filter fields → **422** naming the field; never silently ignored.

Response:
```json
{
  "data": [
    {
      "id": "9c8f1e4a-3b2d-4f77-9a11-77b0c2d5e601",
      "type": "leads",
      "attributes": {
        "first_name": "Amina",
        "last_name": "Khan",
        "primary_email": "amina@example.com",
        "phone_mobile": "+14165550134",
        "vertical": "Refugee",
        "stage": "new",
        "hot_lead": false,
        "warm_lead": true,
        "do_not_call": false,
        "assigned_user_id": "1f0a…",
        "created_at": "2026-06-12 14:03:11",
        "updated_at": "2026-07-20 09:15:00"
      },
      "relationships": {
        "assignee": { "data": { "id": "1f0a…", "type": "users" } }
      },
      "links": { "self": "/api/v1/leads/9c8f1e4a-…" }
    }
  ],
  "meta": { "total": 394, "count": 25, "page": 1, "pages": 16, "per_page": 25 },
  "links": {
    "self": "/api/v1/leads?page[number]=1",
    "next": "/api/v1/leads?page[number]=2",
    "prev": null
  }
}
```

**All datetimes are UTC in `Y-m-d H:i:s`.** No offsets, no `T`, no `Z`. Inbound datetimes are accepted in
that format or full ISO-8601 and normalised to UTC at the boundary; a value that cannot be parsed is a
**422 error**, never a silently blanked field.

### 1.3 Single record, create, update, delete

```http
GET    /api/v1/leads/{id}
POST   /api/v1/leads
PATCH  /api/v1/leads/{id}
DELETE /api/v1/leads/{id}          → soft delete, 204
```

Create and update bodies are flat attribute objects (not JSON:API wrapped):
```json
{ "first_name": "Amina", "last_name": "Khan", "vertical": "Refugee", "primary_email": "amina@example.com" }
```
Create returns **201** with the record and a `Location` header. `POST` accepts an
`Idempotency-Key` header; a repeat with the same key within 24 hours returns the original response
instead of creating a duplicate.

### 1.4 Metadata endpoints

```http
GET /api/v1/meta/modules                → modules the caller may see, with labels and counts
GET /api/v1/meta/modules/{module}/fields → every field: name, label, type, required, options, flags
GET /api/v1/meta/option-lists/{key}      → option list values and labels
```
These make the API self-describing, so an integration written today keeps working after an administrator
adds a field in Studio.

### 1.5 Errors — RFC 7807

```json
{
  "type": "https://crmga.local/errors/validation",
  "title": "The request could not be processed",
  "status": 422,
  "code": "validation_failed",
  "detail": "One or more fields are invalid.",
  "errors": {
    "primary_email": ["This is not a valid email address."],
    "vertical": ["The selected value is not in the allowed list."]
  },
  "trace_id": "01J9X…"
}
```

| Status | When | `code` |
|---|---|---|
| 400 | Malformed query or body | `bad_request` |
| 401 | Missing, expired or invalid token | `unauthenticated` |
| 403 | Authenticated but not permitted (role or scope) | `forbidden` / `insufficient_scope` |
| 404 | Record does not exist, **or exists but the caller's record access excludes it** | `not_found` |
| 409 | Version conflict on update (`If-Match` mismatch) | `conflict` |
| 410 | Endpoint removed (for example the SMS module, now out of scope) | `gone` |
| 422 | Validation failure | `validation_failed` |
| 429 | Rate limit exceeded | `rate_limited` |
| 500 | Unexpected — never leaks a stack trace | `server_error` |

Note on **404 versus 403**: when the caller's role limits them to Owner access and they request another
user's record, return **404**, not 403 — a 403 would confirm the record exists.

### 1.6 Rate limits and caching

Every response carries `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`. Default 600
requests per minute per client, configurable per client. `429` includes `Retry-After`.

`GET` responses carry `ETag`; a repeat with `If-None-Match` returns **304**. `PATCH` accepts `If-Match`
for optimistic concurrency and returns **409** on mismatch.

---

## PART 2 — Legacy V8 adapter (`/Api/V8/*`)

**Purpose:** the 133 existing n8n workflows keep running by changing only their base URL. Delete this
adapter once they have been migrated to `/api/v1`.

### 2.1 Shapes verified from the live workflows

These are taken from the actual HTTP nodes in the running n8n instance, so they must be matched exactly.

**Token** — form-encoded, not JSON:
```http
POST /public/Api/access_token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=<uuid>&client_secret=<secret>
```
```json
{ "access_token": "…", "token_type": "Bearer", "expires_in": 3600 }
```

**Read a module** — note the `vnd.api+json` headers and the bracketed parameters:
```http
GET /public/Api/V8/module/GA_HQ_Students?filter[status][eq]=TestCall&fields[GA_HQ_Students]=first_name,last_name,phone_mobile,email1,status,do_not_call,date_entered&page[size]=1000
Authorization: Bearer <token>
Content-Type: application/vnd.api+json
Accept: application/vnd.api+json
```
```json
{
  "data": [
    {
      "type": "GA_HQ_Students",
      "id": "3f2a…",
      "attributes": {
        "first_name": "Amina", "last_name": "Khan",
        "phone_mobile": "+14165550134", "email1": "amina@example.com",
        "status": "TestCall", "do_not_call": "0",
        "date_entered": "2026-06-12 14:03:11"
      }
    }
  ],
  "meta": { "total-pages": 1 }
}
```

**Update a record:**
```http
PATCH /public/Api/V8/module
Content-Type: application/vnd.api+json

{ "data": { "type": "GA_HQ_Students", "id": "3f2a…", "attributes": { "status": "follow_up_no_response" } } }
```

### 2.2 Compatibility rules the adapter must honour

1. **Booleans are returned as the strings `"0"` and `"1"`**, not JSON booleans — the workflows compare with `String(a.do_not_call||'0') === '1'`.
2. **Datetimes are returned as `Y-m-d H:i:s`** with a space, no timezone — workflow code does `Date.parse(String(x).replace(' ','T'))`.
3. **Accept a datetime on write only in `Y-m-d H:i:s`** (with seconds). Reject anything else with a clear error rather than silently discarding it — the silent discard was the original bug.
4. **`data` may be consumed as a JSON string** by some workflows (they defensively `JSON.parse` it). Always emit real JSON; the defensive branch is harmless.
5. **`page[size]` up to 1000** must work, because several workflows request exactly that.
6. **Unknown query parameters are ignored** rather than rejected, matching SuiteCRM's tolerance.
7. **Empty result is `{"data": []}`** with HTTP 200, never a 404.

### 2.3 Module name aliases

| Legacy module (as called by n8n) | Maps to |
|---|---|
| `GA_GALead` | `leads` — `vertical` derived from `category_c` |
| `GA_Imm_Biz` | `leads` with `vertical = BusinessImmigration` |
| `GA_Imm_can` | `leads` with `vertical = InCanada` |
| `GA_HQInvestor_` | `leads` with `vertical = Investor` |
| `GA_Study` | `leads` with `vertical = StudyPermit` |
| `GA_LMIA_MAIN`, `GA_LMIA_Course`, `GA_LMIAInquiry` | `leads` with `vertical = LMIA` |
| `GA_HQ_Students` | `students` |
| `GA_Companies` | `companies` |
| `GA_Assessment_Score`, `GA_Assessment_Request` | `assessments` |
| `GA_Clients`, `GA_ClientDevelopment2`, `GA_ClientDevelopment3` | `clients` |
| `GA_Affiliate` | `affiliates` |
| `GA_Newsletter_Subscriber` | `newsletter_subscribers` |
| `Emails`, `Notes`, `Meetings`, `Tasks`, `Documents`, `Calls` | the matching activity module |
| `dt_sms` | **410 Gone** with `"The SMS module is not part of this system."` |

When a legacy module maps to a Lead vertical, a **write** through the adapter must set that vertical, and a
**read** must filter to it — otherwise a workflow expecting only Business Immigration records would receive
every lead.

### 2.4 Field name aliases

| Legacy field | Maps to |
|---|---|
| `email1` | `primary_email` |
| `date_entered` / `date_modified` | `created_at` / `updated_at` |
| `deleted` | soft-delete state (`deleted_at is null` → `"0"`) |
| `category_c` | `vertical` |
| `lead_status_c` | `stage` |
| `hot_lead_c` / `warm_lead_c` | `hot_lead` / `warm_lead` |
| `whatsapp_number_c` | `whatsapp_number` |
| `call_status_c` | `call_status` |
| `call_attempts_c` | `call_attempts` |
| `last_call_outcome_c` | `last_call_outcome` |
| `last_call_summary_c` | `last_call_summary` |
| `last_contacted_at_c` | `last_contacted_at` |
| `own_business_bi_c`, `invest_in_canada`, `interested_in_pr`, `best_time_to_call_*_c`, `current_situation`, `afraid_to_return`, `refugee_claim_process`, `current_status_in_canada_h_c`, `seeking_a_humanitarian_pr_c`, `start_your_application_h_c` | the matching key inside `vertical_attributes` |
| any other `*_c` | strip the `_c` suffix, then look up in fields, then in `vertical_attributes` |

The alias map lives in one config file so it can be read, tested and eventually deleted in one place.

---

## PART 3 — Inbound integration endpoints

```http
POST /api/v1/ingest/wordpress      Header: X-Api-Key: <key>
POST /api/v1/ingest/meta           Meta Lead Ads webhook (hub verification + leadgen events)
POST /api/v1/ingest/{source}       Generic. Header: X-Signature: sha256=<hmac of the raw body>
```

Every inbound payload goes through the same pipeline:

```
receive → verify signature or key → log raw payload
        → map fields (per-source mapping)
        → CANONICALISE values
        → validate
        → dedupe (email, then phone)
        → create or update
        → assign owner (assignment rules)
        → fire events
        → respond 202 with the record id
```

**Canonicalisation — mandatory, this is a real bug we are designing out.** Incoming choice values arrive
lower-cased, spaces replaced with underscores, inconsistently punctuated, sometimes with a trailing period,
and phone numbers carry invisible Unicode direction marks.

```php
function canon(?string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower($s ?? ''));
}
// Match by comparing canon(incoming) against canon(option->value) AND canon(option->label).
// No match  =>  null. NEVER guess, never store the raw unmatched string in an enum field.
// Log every unmatched value with its source so the option list can be corrected.
```

Phone cleaning: strip `U+200E U+200F U+202A–U+202E U+2066–U+2069` and zero-width characters, then keep only
`+` and digits.

Respond **202 Accepted** quickly and finish the work in a queued job — Meta retries aggressively on slow
responses.
