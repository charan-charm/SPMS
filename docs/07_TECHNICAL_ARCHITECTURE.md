# SPMS — Technical Architecture

## Document Status

- **Phase:** 5 — Technical Architecture, frontend direction updated
- **Status:** 🔒 ARCHITECTURE BASELINE ESTABLISHED
- **Decision coverage:** Q1–Q280 plus approved F0 frontend direction
- **Final consistency audit:** ✅ Passed before implementation
- **Implementation status:** M0 backend foundation implemented; previous PHP-rendered frontend direction superseded

## 1. Architecture Objective

SPMS helps households understand solar availability and electricity requirements, predict future solar generation and household consumption, identify potential surplus/deficit situations, evaluate cost and impact, and provide understandable insights and recommendations.

The architecture supports evidence collection, validation, quality, provenance, adaptive prediction, authoritative analytical services, AI-assisted interpretation, recommendations, notifications, analytics, reports, exports, graceful degradation, secure lifecycles, historical integrity, model evaluation, and a modern React frontend.

## 2. Core Architecture Principles

1. PHP remains the backend runtime.
2. React is the frontend application.
3. Vite is frontend development/build tooling.
4. Node.js, when present, is frontend tooling only.
5. Express is not part of SPMS.
6. GoodWe behavior is isolated behind an integration boundary.
7. Business logic belongs in PHP services/domain capabilities.
8. Database access belongs in repositories.
9. External-system communication belongs in integrations.
10. Analytical numerical truth remains inside authoritative SPMS analytical capabilities.
11. Bedrock is an AI interpretation/explanation capability, not numerical prediction authority.
12. Frontend calculations are presentation-only.
13. Actual, predicted, estimated, simulated, cached, and stale data remain distinguishable.
14. Data quality and provenance are first-class concerns.
15. Historical analytical truth must not be silently overwritten.
16. External-service failure degrades capabilities rather than destroying trustworthy independent data.
17. Backend authorization is authoritative.
18. Long-running work uses controlled background operations.
19. Graphs, reports, and exports consume backend-authoritative datasets.
20. Demo/Test uses the same core pipeline where practical.
21. Explicit user preferences take priority over defaults and learned personalization.
22. React is an untrusted client from the backend security perspective.

## 3. High-Level Architecture

```text
Browser
   │
   ▼
React Frontend
   │
   │ REST / JSON
   ▼
PHP REST API
   │
   ▼
public/index.php
   │
   ▼
Router → Middleware
   │
   ▼
Controllers
   │
   ▼
Services / Domain
   ├───────────────┐
   ▼               ▼
Repositories   Integrations
   │            GoodWe / Weather /
   ▼            AWS / Bedrock / Email
 MySQL
```

## 4. Frontend Architecture

Frontend location:

```text
frontend/
```

Primary layers:

```text
app/
components/
layouts/
pages/
features/
charts/
hooks/
services/api/
lib/
styles/
```

The exact implementation may evolve without changing responsibility boundaries.

## 5. Frontend Responsibilities

React owns:

- presentation
- components
- layouts
- client-side UI state
- interactions
- API consumption
- chart rendering
- animation
- accessibility behavior
- presentation-only formatting/calculation

React does not own:

- authoritative energy balance
- authoritative cost
- prediction truth
- recommendation truth
- authorization
- data quality
- provenance
- operation completion
- lifecycle truth

## 6. Backend Responsibilities

PHP owns:

- REST API
- authentication
- authorization
- validation
- business rules
- services/domain logic
- repositories
- integrations
- analytical authority
- data quality
- provenance
- operations
- lifecycle management
- security
- persistence orchestration

## 7. Build and Development Tooling

Vite is the approved React build/development tool.

Development may use:

```text
Vite
localhost:5173
      │
      │ /api/*
      ▼
PHP development server / Apache
      │
      ▼
MySQL
```

The frontend should use an API proxy during development where practical.

Production may serve compiled React assets through Apache while `/api/*` is routed to PHP.

Node.js is not an application runtime for SPMS.

## 8. Routing

React Router manages frontend navigation.

Public:

```text
/
/login
/register
/verify
/recover
```

Protected:

```text
/dashboard
/solar
/energy
/forecasts
/insights
/analytics
/bills
/reports
/notifications
/settings
```

Frontend guards are navigation UX. Backend authorization remains authoritative.

## 9. API Client

React communicates with PHP through a centralized API client.

```text
Component
↓
Feature service
↓
Central API client
↓
REST API
```

## 10. Backend Request Flow

```text
HTTP
↓
public/index.php
↓
Bootstrap
↓
Router
↓
Middleware
↓
Controller
↓
Service
↓
Repository / Integration
↓
Database / Provider
↓
Service
↓
Controller
↓
JSON
```

## 11. Database Architecture

MySQL-compatible relational database.

Use normalized schema, foreign keys, unique constraints, explicit nullability, version-controlled migrations, canonical timestamps, units, currency, provenance, quality, historical records, and lifecycle fields where required.

## 12. GoodWe

GoodWe remains the primary source of actual solar measurements.

GoodWe/SEMS+ is an official external integration domain of SPMS. The
earlier prototype under `references/goodwe-sems-prototype/` is evidence
only — endpoint discovery, HAR capture/replay tooling, response samples,
research CLIs. Its Node/Express implementation never enters production
(Express is forbidden as the SPMS production backend).

Production request flow (entirely server-side):

```text
React
↓
SPMS PHP REST API (authenticated/authorized)
↓
Solar domain service
↓
GoodWe integration boundary
↓
GoodWe/SEMS+ API
```

React never calls GoodWe directly. GoodWe-specific URLs, headers,
authentication protocol, token handling, retries, provider error codes
and naming stay inside the integration boundary.

Ingestion pipeline (G0 formalizes endpoint semantics):

```text
GoodWe raw response
↓
Provider adapter/client
↓
Validation
↓
Normalization
↓
Canonical SPMS solar data
↓
Provenance / quality classification
↓
Domain services (F4/F5/F6, forecasting)
↓
Persistence
```

Availability follows the existing SPMS degradation model (available /
stale / degraded / unavailable / error / recovered): GoodWe failure never
fabricates live data, keeps last-known actual data distinct from
estimated/predicted fallbacks, exposes freshness, and reconciles on
recovery.

The GoodWe MVP is READ-ONLY: monitoring, historical/near-current
production, telemetry, time series, flow, weather, station statistics,
inverter/device information. Remote inverter/device/battery/appliance
control is out of scope; control surfaces discovered in the reference
prototype are documentation-only.

SPMS and GoodWe credentials are separate credential domains (docs/08
§14, docs/10 D-059). Provider authentication mechanism, token lifecycle,
storage and household-bound ownership are G0 decisions after official
provider verification.

G0 outcome (docs/10 D-060, D-061): the canonical GoodWe solar ingestion
contract, nature/quality/freshness mapping, sync lifecycle, degradation
rules and raw-retention policy are ratified SPMS-side decisions; the
provider authentication mechanism, endpoint schemas, token TTL and rate
limits remain PROVISIONAL (prototype observation only) and are locked to
the G1 validation gate before any production integration code exists.

G1 Round 2A outcome (docs/10 D-063): the official GOODWE Open Platform
API contract (AppId/AppSecret login, 4-hour reusable TOKEN, IP
whitelist, tenant-scoped station/device/day-month-year data) is now
documented as OFFICIAL evidence and is preferred for production; access
for this project's specific household plant is CONDITIONAL on GoodWe
authorization and remains unvalidated. No production code is
authorized yet.

Credentials remain backend-only.

Failure preserves prior actual solar data and degrades live capability.

Recovery:

```text
Reconnect
↓
Resync
↓
Deduplicate
↓
Validate
↓
Reconcile
```

## 13. Weather

Weather supports solar prediction and does not replace GoodWe actual measurements.

Preserve provider, retrieval time, forecast/observation context, location, quality, and provenance.

## 14. Electricity Bill Processing

```text
Upload
↓
File Validation
↓
Private Storage
↓
Processing
↓
Extraction
↓
AI Interpretation / Normalization
↓
Verification
↓
Correction if needed
↓
Authoritative Bill Record
```

## 15. Background Operations

Candidates:

- GoodWe synchronization
- bill processing
- prediction execution
- report generation
- export generation

Lifecycle:

```text
NOT STARTED
↓
QUEUED
↓
PROCESSING
↓
COMPLETED

PROCESSING
↓
FAILED
↓
RECOVERING
↓
RECOVERED
```

## 16. Authentication and Authorization

Authentication uses PHP server-side sessions, secure cookies, password hashing, email verification, recovery tokens, and bounded brute-force protection.

Roles:

```text
Household User
Admin
```

Demo/Test is an account mode, not a role.

## 17. File Security

Private files remain outside the public web root and are accessed through authorized backend paths.

## 18. Analytical Architecture

```text
Validated Evidence
↓
Prediction
↓
Energy Balance
↓
Cost Analysis
↓
Validated Facts
↓
Intelligence
↓
Bedrock Explanation
↓
Insights
↓
Recommendations
↓
Notification Decision
```

React only presents these results.

## 19. Chart Architecture

```text
Authoritative Data
↓
Analytics / Reporting
↓
Graph-ready semantic dataset
↓
React chart component
↓
Recharts / approved chart library
↓
Rendered chart
```

Charts preserve nature, status, confidence, units, measurement context, and relevant provenance.

## 20. Reports and Exports

Reports are backend-authoritative validated snapshots.

Exports are authorized packaging of supported data.

React may initiate and display operation state; PHP determines authorization, scope, content, and completion.

## 21. Security Boundary

```text
React = untrusted client
PHP = security authority
MySQL = persistence/integrity
```

Client state, URLs, local storage, and UI permission flags never grant access.

## 22. Failure / Recovery

Failures degrade affected capabilities. Never fabricate certainty.

React network failure must not be interpreted as backend completion or failure without authoritative confirmation where operation state matters.

## 23. Demo / Test

Demo/Test uses the same architecture where practical. Simulated data is explicitly labeled.

## 24. Design System Architecture

Approved foundations:

- Tailwind CSS
- shadcn/ui
- Lucide
- Motion
- Recharts/approved chart library

Page-specific styling must compose the shared SPMS design system rather than create unrelated visual languages.

## 26. Electricity Bill & Utility Net Metering Architecture

### 26.1 Dual Intake Pipeline
SPMS supports two intake paths into the bill domain:
1. **Unstructured Documents (PDF/JPEG/PNG):** Processed through Mistral OCR, normalized via `BillAnnotationNormalizer`, with AI-extracted values held as immutable drafts (`bill_extraction_drafts`) until explicit user review.
2. **Structured Utility Statements (APSPDCL `.xls`):** Ingests official HTML-table monthly statements, bypassing OCR. Extracts canonical fields (`billing_period_start`, `billing_period_end`, `bill_date`, `due_date`, `opening_kvah`, `closing_kvah`, `consumption_kvah`, `solar_units_kwh`, `net_units_kwh`, `bill_amount`), verifies deterministic arithmetic cross-checks, and captures utility-specific line-items inside bounded `source_metadata_json` (VARCHAR(4000)).

### 26.2 Strict Telemetry vs Utility Bill Separation
- **GoodWe SEMS Telemetry:** High-frequency, inverter-measured power and energy (`data_nature: ACTUAL`). Stored in `solar_readings`. Evaluated for real-time freshness (15-minute SLA).
- **APSPDCL Net Metering:** Monthly utility settlement records (`data_nature: VERIFIED` once accepted). Billed grid export (`net_units_kwh < 0`) is a monthly commercial credit, never conflated with instantaneous inverter telemetry or battery state.

### 26.3 Chronological Sequence Validation
To ensure forecasting and consumption models receive clean time-series history:
- `BillingPeriodSequenceValidator` enforces contiguous billing cycles (with a ±5 day reading-schedule tolerance).
- Detects `DUPLICATE_PERIOD` and `OVERLAPPING_PERIOD`, which fail closed (HTTP 409).
- Flags `GAP_DETECTED` when an intervening billing cycle is absent.

### 26.4 Controlled Missing-History Exception
To prevent trapped households who lack past paper statements:
- Verification permits explicit gap acknowledgment (`accept_gap: true`).
- Attaches persistent quality flags (`INCOMPLETE_HISTORY`, `BILLING_GAP_PRECEDING`).
- Forecasting algorithms detect these flags and widen uncertainty intervals without inventing or interpolating fabricated consumption numbers.

## 27. Architecture Baseline

```text
React / Vite Frontend
          ↓
       REST API
          ↓
     PHP Backend
          ↓
        MySQL
```

Backend services/integrations remain responsible for authoritative business and analytical truth.

**END OF TECHNICAL ARCHITECTURE**
