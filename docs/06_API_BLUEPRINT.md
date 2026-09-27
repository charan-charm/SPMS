# 06 — API Blueprint

**Status:** Phase 6 consolidated — React frontend boundary updated  
**Scope:** PHP JSON REST API contracts and semantics  
**API version:** `/api/v1`

## 1. Purpose

The SPMS API is the controlled boundary between the React frontend/browser and backend application capabilities.

```text
React Frontend
↓
Central API Client
↓
HTTP Request
↓
PHP Router
↓
Controller
↓
Application / Domain Service
↓
Repository / Integration
↓
Authoritative Result
↓
JSON Response
↓
Central API Client
↓
React UI
```

The frontend never becomes authoritative for business truth.

## 2. Frontend Technology

Approved frontend:

- React
- Vite
- Tailwind CSS
- Motion
- shadcn/ui where appropriate
- Lucide Icons
- Recharts/approved charting library
- React Router
- native `fetch()` through a centralized API client

Node.js may be used only as frontend development/build tooling required by the React/Vite ecosystem. Node.js/Express is not the SPMS backend.

## 3. API Versioning

Version APIs from the beginning:

```text
/api/v1/...
```

Potential domain resources:

```text
/api/v1/auth
/api/v1/households
/api/v1/solar
/api/v1/bills
/api/v1/environment
/api/v1/forecasts
/api/v1/energy
/api/v1/intelligence
/api/v1/recommendations
/api/v1/analytics
/api/v1/reports
/api/v1/notifications
/api/v1/preferences
```

Exact endpoint inventory is finalized during implementation.

## 4. HTTP Methods

Use explicit HTTP semantics:

```text
GET
POST
PUT
PATCH
DELETE
```

GET is read-oriented. POST creates resources or performs explicitly defined actions. PUT/PATCH update meaningful state. DELETE performs controlled deletion according to lifecycle rules.

## 5. Authentication and Authorization

Protected APIs require authenticated server-side session context.

Authentication establishes identity/session. Authorization establishes permitted scope.

Every protected operation validates, as appropriate:

- authentication
- account state
- role/capability
- household/resource ownership
- lifecycle state
- operation scope

The React application cannot grant itself access.

## 6. React API Client

All normal API communication should go through a centralized client.

The client handles common transport behavior such as:

- base API path
- request construction
- JSON parsing
- response envelope handling
- standardized error mapping
- correlation/request reference handling
- authentication/session response handling
- CSRF handling where required

Feature components should not independently invent API conventions.

## 7. Standard Success Envelope

```json
{
  "success": true,
  "data": {},
  "meta": {}
}
```

## 8. Standard Error Envelope

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Please correct the highlighted fields.",
    "context": {}
  },
  "meta": {}
}
```

Do not expose stack traces, SQL, credentials, provider secrets, private paths, or internal security details.

## 9. HTTP Status Semantics

```text
2xx → success
4xx → request/auth/scope/validation problem
5xx → server/infrastructure problem
```

Do not return HTTP 200 for every outcome.

## 10. Semantic Identifiers

APIs expose stable semantic identifiers. UI labels are contextual/localized presentation and are not authoritative API semantics.

## 11. Data Nature

Where relevant:

```text
ACTUAL
PREDICTED
ESTIMATED
SIMULATED
```

The frontend must not infer nature from styling alone.

## 12. Data Status

```text
FRESH
STALE
DEGRADED
UNAVAILABLE
PROCESSING
ERROR
```

Nature and status remain separate.

## 13. Measurement Context

Where relevant:

```text
MEASURED
INFERRED
PREDICTED
ESTIMATED
POTENTIAL
SIMULATED
```

Potential surplus is not confirmed export. Potential deficit is not confirmed import.

## 14. Confidence and Uncertainty

Where analytical capabilities provide confidence/uncertainty, APIs expose it explicitly.

The frontend presents these values and never invents confidence.

## 15. Quality and Provenance

Where meaningful, API responses provide:

- source
- retrieval time
- freshness
- quality
- verification state
- model/version
- processing context
- measurement context

The frontend presents this context without changing it.

## 16. Pagination, Filtering, Search

APIs support contextual pagination/filtering/search where required.

Validate authorization, ownership, scope, filter validity, date ranges, and supported resolution.

Q18 remains adaptive data exploration, not a universal query interface.

## 17. Dashboard API Composition

Dashboard data originates from backend application/domain services.

React never queries database tables directly.

Dashboard APIs may compose authoritative capabilities while avoiding unnecessary frontend round trips.

A single capability failure should not necessarily blank the entire dashboard.

## 18. Analytics / Graph Contract

```text
Authoritative analytical result
↓
Analytics / Reporting
↓
Graph-ready dataset
↓
React chart component
↓
Chart library
```

Graph data preserves nature, status, confidence, units, measurement context, and provenance where needed.

## 19. Bills API Boundary

Bill processing APIs expose lifecycle states (`UPLOADED`, `PROCESSING`, `EXTRACTED`, `NEEDS_REVIEW`, `VERIFIED`, `REJECTED`, `PROCESSING_FAILED`). Original files remain private. AI extraction and statement imports remain draft until explicit verification.

### 19.1 Supported Intake Formats
- Scanned/digital invoices: PDF (`application/pdf`), JPEG (`image/jpeg`), PNG (`image/png`) via Mistral OCR.
- Official utility statements: APSPDCL monthly statement exports in `.xls` format (`application/vnd.ms-excel`, HTML table format). Bypasses OCR, parses canonical fields, and stores auxiliary non-canonical line-items inside bounded `source_metadata_json`.

### 19.2 Canonical Data Model
Every bill aggregate exposes canonical analytical fields:
- **Billing Period:** `billing_period_start`, `billing_period_end`, `bill_date`, `due_date`.
- **Consumption:** `consumption_kwh`, `opening_reading`, `closing_reading`, `billed_units_kwh`.
- **Electrical Measurements:** `opening_kvah`, `closing_kvah`, `consumption_kvah`.
- **Solar Net Metering:** `previous_solar_reading`, `present_solar_reading`, `solar_units_kwh`, `net_units_kwh` (negative values indicate net export into grid).
- **Charges & Costs:** `bill_amount`, `arrears`, `total_due`, `energy_charges`, `fixed_charges`, `customer_service_charges`, `other_charges`, `taxes_adjustments`.
- **Provenance & Source:** `source` (`ELECTRICITY_BILL`, `APSPDCL_EXPORT`, `USER_MANUAL`, `GOODWE_SEMS`), per-field provenance (`USER_CORRECTION`, `AI_EXTRACTED`, `MANUAL_ENTRY`, `IMPORT`), and `source_metadata_json`.

### 19.3 Verification Endpoint (`POST /api/v1/bills/{id}/verify`)
- Requires authenticated session and ownership match. Bills with `SERVICE_NUMBER_MISMATCH` cannot be verified (422).
- Performs backend-authoritative chronological sequence validation against prior `VERIFIED` bills.
- Returns 409 for `DUPLICATE_BILL_PERIOD` or `OVERLAPPING_BILL_PERIOD`.
- Detects missing historical months (`GAP_DETECTED`). Rejects with 422 `BILLING_GAP_DETECTED` unless the client submits `{ "accept_gap": true }`. When accepted, flags bill with `INCOMPLETE_HISTORY` and `BILLING_GAP_PRECEDING` without blocking downstream workflows.
- On success: transitions bill to `VERIFIED`, writes `verified_at`, and returns standard 200 envelope.

### 19.4 Bill Correction Endpoint (`PATCH /api/v1/bills/{id}`)
- Allows user corrections of canonical fields before verification.
- Re-runs deterministic validation rules (arithmetic reconciliation, date chronology).
- Attaches `USER_CORRECTION` provenance to modified fields while preserving original extraction draft verbatim.

## 20. Long-Running Operations

Long-running operations may return controlled operation references.

The frontend must not infer that queued or accepted means completed.

## 21. Refresh and Caching

Backend freshness remains authoritative. Caching must not silently present stale data as live data.

## 22. Authentication Session Model

```text
React Login Form
↓
POST /api/v1/auth/login
↓
PHP authentication service
↓
PHP session
↓
Secure session cookie
↓
React authenticated UI
```

The server determines session validity.

## 23. CSRF

Authenticated state-changing browser requests require CSRF protection according to the security rules.

## 24. Localization

APIs return semantic values. The frontend handles localized presentation. Units and canonical timestamps remain explicit.

## 25. Frontend Failure Presentation

Stable API errors are mapped by React to validation messages, banners, inline errors, retry actions, unavailable states, and degraded states.

The frontend must not expose internal server diagnostics.

## 26. Authorization Rule

A valid URL, route, component state, or client-side permission flag never grants access.

## 27. Recommendations API Boundary (F7)

Household-scoped advisory recommendations derived from authoritative F3 forecasts, F4 balance classifications, F5 cost estimations, F6 insights, and verified billing records.

### 27.1 Endpoints
- `GET /api/v1/recommendations`: Lists recommendations for the authenticated household.
  - Query parameters:
    - `status`: Optional filter (`ACTIVE` [default], `DISMISSED`, `EXPIRED`, `ALL`).
    - `type`: Optional filter by controlled taxonomy type.
    - `priority`: Optional filter (`HIGH`, `MEDIUM`, `LOW`).
  - Response: Envelope with `recommendations` array, `active_count`, and data quality metadata.
- `GET /api/v1/recommendations/{id}`: Returns single recommendation detail by ID. Enforces strict household isolation (returns 404 for recommendations belonging to other households).
- `POST /api/v1/recommendations/generate`: Triggers deterministic recommendation re-evaluation for the active household. Enforces deduplication and 12-hour dismissal cooldown.
- `POST /api/v1/recommendations/{id}/dismiss`: Dismisses an active recommendation. Marks status as `DISMISSED`, records `dismissed_at`, and activates a 12-hour cooldown preventing immediate regeneration of the same recommendation type.

### 27.2 Integrity and Advisory Rail
- Advisory-only boundary: Recommendations provide guidance to the household; endpoints carry no appliance switching, automation commands, or device controls.
- Confidence: Calculated via worst-of aggregation across critical inputs. Degraded source data yields honest `DATA_LIMITATION` notices rather than fabricated numbers.

## 28. Governing Principle

> **The API carries authoritative semantic information to the frontend; the frontend decides how to present that information, not what it means.**

**END OF API BLUEPRINT**
