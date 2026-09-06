# Phase 6 — Implementation Foundation

**Status:** COMPLETE  
**Decision Range:** Q281–Q670  
**Total Decisions:** 390  
**All Decisions:** LOCKED  
**Phase Type:** Implementation Foundation / Technical Readiness

---

## 1. Phase Objective

Phase 6 converts the established SPMS functional, page, UX, security, and architecture decisions into a concrete implementation foundation.

It establishes the rules for:

- PHP application structure and development environment
- routing and API contracts
- DTOs, validation, normalization, and errors
- MySQL schema and migrations
- authentication, authorization, sessions, CSRF, and lifecycle
- background jobs and operation states
- GoodWe, weather, AWS, Bedrock, and email boundaries
- prediction and analytical authority
- data quality, provenance, confidence, and measurement context
- intelligence, recommendations, notifications, and personalization
- frontend/backend boundaries and graph architecture
- reports and exports
- testing and model evaluation
- Demo/Test scenarios
- observability, deployment, backups, and recovery
- documentation and Git checkpoints

Phase 6 does not authorize arbitrary shortcut-based implementation. Coding proceeds incrementally under the locked architecture.

---

## 2. Decision Coverage

| Batch | Decisions | Status |
|---|---:|---|
| Batch 1 | Q281–Q310 | LOCKED |
| Batch 2 | Q311–Q350 | LOCKED |
| Batch 3 | Q351–Q390 | LOCKED |
| Batch 4 | Q391–Q430 | LOCKED |
| Batch 5 | Q431–Q470 | LOCKED |
| Batch 6 | Q471–Q510 | LOCKED |
| Batch 7 | Q511–Q550 | LOCKED |
| Batch 8 | Q551–Q590 | LOCKED |
| Batch 9 | Q591–Q630 | LOCKED |
| Batch 10 | Q631–Q670 | LOCKED |

**Phase 6 total: 390 locked decisions.**

---

# 3. Technology Foundation

Development uses XAMPP with Apache, PHP, MySQL, Composer, HTML, CSS, and Vanilla JavaScript. The architecture remains portable to normal Apache/PHP hosting.

PHP 8.4 is the minimum target, with compatible newer versions allowed after environment verification.

Composer manages third-party PHP dependencies and its lockfile is committed for reproducibility.

The application namespace is:

```text
SPMS\
```

PSR-4 autoloading is used.

---

# 4. Application Architecture

SPMS uses a lightweight modular MVC-like architecture.

```text
Browser
   ↓
public/index.php
   ↓
Bootstrap
   ↓
Router
   ↓
Controller
   ↓
Application / Domain Service
   ↓
Repository / Provider Interface
   ↓
MySQL / External Provider
   ↓
Service Result
   ↓
Controller
   ↓
JSON / View Response
```

Only `public/` is directly web-accessible.

The front controller is:

```text
public/index.php
```

A centralized router maps HTTP methods and routes.

Controllers handle HTTP input/output only. Services own business/application logic. Repositories own persistence. Integrations own provider-specific communication and normalization.

Preferred dependency direction:

```text
Controller → Service → Repository / Provider Interface
```

Circular service dependencies should be avoided.

---

# 5. Domain Structure

The application uses a hybrid domain-oriented structure:

```text
app/
├── Controllers/
├── Services/
├── Repositories/
├── Domain/
│   ├── Account/
│   ├── Household/
│   ├── Solar/
│   ├── Energy/
│   ├── Forecast/
│   ├── Bills/
│   └── ...
├── Integrations/
└── Support/
```

Shared infrastructure belongs in `Support/` or equivalent infrastructure components.

Stable semantic identifiers are separated from UI labels.

---

# 6. API Foundation

The API is versioned from the beginning:

```text
/api/v1/...
```

Meaningful HTTP methods are used:

```text
GET
POST
PUT
PATCH
DELETE
```

The standard success envelope is:

```json
{
  "success": true,
  "data": {},
  "meta": {}
}
```

Errors use:

- stable error code
- safe message
- structured context where appropriate
- meaningful HTTP status

API DTOs provide controlled semantic data transfer.

Controllers explicitly select safe fields rather than serializing entire database entities.

Sensitive/internal fields are excluded from public DTOs.

Where relevant, API responses expose structured:

- semantic identifiers
- data nature
- data status
- confidence
- safe provenance
- measurement context

Examples:

```text
Nature:
ACTUAL
PREDICTED
ESTIMATED
SIMULATED

Status:
FRESH
STALE
DEGRADED
UNAVAILABLE
```

Nature and status remain separate.

---

# 7. Validation and Normalization

Frontend validation provides UX feedback.

Backend validation is authoritative.

State-changing requests must pass backend validation before business processing.

Dedicated request/validation components are preferred.

Incoming values are centrally normalized before domain processing.

Validation errors use stable error codes that remain independent of localized user-facing wording.

Recoverable errors may include structured context when safe.

Production responses never expose raw exceptions or stack traces.

---

# 8. Database Foundation

MySQL is the target database.

Portable SQL is preferred where practical, with justified MySQL-specific behavior allowed.

Conventions:

- snake_case
- plural table names
- explicit primary keys
- explicit foreign keys where authoritative
- explicit nullability
- intentional unique constraints
- intentional indexes

The database prevents invalid orphan relationships where appropriate.

Delete behavior is domain-specific. Historical records are protected where their meaning requires preservation.

Soft-deletion/lifecycle states are used where required.

---

# 9. Historical and Temporal Integrity

Meaningful historical analytical records are immutable.

Prediction records retain relevant:

- prediction value
- period
- horizon
- resolution
- confidence
- uncertainty
- model/version
- provenance
- quality
- context

Historical predictions are not silently rewritten when a new model arrives.

Canonical timestamps use UTC.

Household timezone is explicit.

Where relevant, both source/provider measurement time and SPMS ingestion time are preserved.

Energy values use canonical numeric representations with semantic units.

Monetary values use fixed-precision decimals.

Currency is part of monetary context.

Formatted presentation strings do not belong in the database.

---

# 10. Migrations and Transactions

Database changes use version-controlled SQL migrations.

Tables are built incrementally as implementation modules are developed.

Atomic business operations use service/application transaction boundaries.

Failures roll back atomic operations.

Optimistic concurrency/version/context validation protects meaningful concurrent changes.

---

# 11. Authentication, Authorization, and Sessions

Email/password authentication is used.

Email is unique.

Passwords use strong one-way PHP password hashing.

Email verification is required before full protected access.

Verification and recovery tokens are:

- secure
- time-limited
- single-use
- replay-resistant

Sessions are server-managed with appropriate Secure, HttpOnly, and SameSite cookie settings.

Session identifiers are regenerated after authentication.

Logout invalidates the session.

Roles:

```text
USER
ADMIN
```

`REAL` and `DEMO` are account modes/states, not roles.

Authorization is enforced on the backend using role, capability, ownership, and resource scope.

CSRF protection applies to authenticated state-changing browser requests.

Sensitive operations may require confirmation and re-authentication.

---

# 12. Account Deletion

Deletion follows:

```text
Deletion Request
      ↓
Authorization + Confirmation
      ↓
Access Revoked
      ↓
Sessions/Credentials Invalidated
      ↓
Active Processing Stopped
      ↓
External Integrations Revoked
      ↓
Controlled Data Disposition
      ↓
Audit / Reconciliation
      ↓
DELETED
```

Deletion is category-specific.

Only the minimum legitimately required information may remain.

Partial deletion failure is never reported as successful deletion.

---

# 13. Background Operations

Long-running operations use PHP CLI/background workers with OS/server scheduling such as cron or Windows Task Scheduler.

Meaningful operations receive persistent job/operation records and unique operation IDs.

States include:

```text
QUEUED
PROCESSING
COMPLETED
FAILED
CANCELLED
```

Recovery states may be added where needed.

Operations use:

- controlled concurrency
- locking/idempotency
- bounded retries
- backoff
- transient/permanent error classification
- explicit external timeouts
- persistent failure state
- controlled recovery

Backend operation status is authoritative.

---

# 14. External Integrations

External services are bounded integration dependencies.

General boundary:

```text
Application Service
      ↓
Provider Interface
      ↓
Integration
      ↓
External Service
```

External providers never redefine SPMS truth.

Integration behavior is:

- validated
- bounded by timeout
- retry-controlled
- idempotent where necessary
- observable
- health-aware
- isolated from unrelated capabilities

Recovered external data is reconciled before normal operation resumes.

---

# 15. GoodWe / Solar

GoodWe is the primary source for actual solar measurements.

The GoodWe integration owns provider-specific authentication/session/token handling and normalizes provider responses into SPMS semantics.

A validated GoodWe measurement becomes authoritative actual solar evidence.

If GoodWe is unavailable:

- preserve historical data
- retain trustworthy cached data
- mark live data stale/degraded/unavailable as appropriate
- continue unaffected analytics where trustworthy
- avoid fabricating live measurements

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

Manual sync is supported.

---

# 16. Weather

Weather uses a provider abstraction so providers can be changed or added.

Weather quality considers:

- freshness
- relevance
- completeness
- reliability

If weather is unavailable, historical/pattern fallback may be used with reduced confidence.

GoodWe actual measurements remain authoritative over external predictive context.

---

# 17. Bill and File Processing

Bill workflow:

```text
Upload
 ↓
Backend Validation
 ↓
Controlled Storage
 ↓
Processing
 ↓
Extraction
 ↓
Normalization
 ↓
User Verification
 ↓
Authoritative Storage
```

Protected bill files are stored outside the public web root.

Validation considers:

- MIME/content
- allowed type
- size
- filename/security characteristics

Stored filenames are server-generated controlled identifiers.

Authorized access occurs through the backend.

If processing fails:

```text
Original preserved
      ↓
Failure recorded
      ↓
Manual entry / verification available
```

Extraction failure never becomes automatic verification.

---

# 18. AWS and Bedrock

AWS processing remains behind a controlled backend integration/service boundary.

Only minimum necessary validated semantic context is sent to AI services.

Bedrock is used for:

- natural-language explanation
- summarization
- contextual phrasing
- supported localization

Bedrock does not own numerical truth.

Numerical forecasting, balance, cost, and other authoritative analytical calculations remain backend analytical responsibilities.

AI output is structured/constrained and validated.

Unsupported claims are rejected, constrained, or flagged.

If Bedrock fails:

```text
Analytics continue
Prediction continues
Balance continues
Cost continues
        ↓
Explanation degrades
        ↓
Deterministic/template fallback
```

Prompts, AI configuration, and relevant model/configuration versions are centrally controlled.

Email follows:

```text
Application Service
      ↓
Email Service
      ↓
Email Provider
```

---

# 19. Prediction

Prediction uses dedicated backend analytical services.

Separate capabilities exist for:

- solar forecasting
- household consumption forecasting

Inputs may include validated:

- solar history
- consumption history
- bills
- weather/environmental context
- household context
- quality
- provenance
- historical analytical context

Prediction is adaptive across supported horizons and resolutions.

Resolution cannot exceed evidence.

A monthly bill cannot become hourly measured truth.

New households can receive an informed low-confidence baseline.

Predictions retain value, period, horizon, resolution, confidence/uncertainty, model/version, provenance, quality, and relevant context.

Confidence is derived from evidence/model-supported reliability and relevant contextual factors.

---

# 20. Prediction Failure and Fallback

Fallback is adaptive.

Possible sources include:

```text
Previous valid prediction
        ↓
Statistical / historical / seasonal / trend / persistence
        ↓
Baseline / estimate
```

The applicable fallback depends on available evidence.

Fallback results must preserve:

- provenance
- status
- uncertainty
- reduced confidence where appropriate

If no trustworthy fallback exists, the system reports unavailable/insufficient data rather than manufacturing precision.

---

# 21. Energy Balance

The backend analytical service owns energy balance.

Primary comparison:

```text
Predicted Solar
      vs
Predicted Household Consumption
```

Possible situations:

```text
Potential Surplus
Balanced
Potential Deficit
```

Without direct grid measurement, SPMS does not claim confirmed grid import/export.

Predicted consumption remains predicted.

Potential surplus/deficit remains potential.

Evidence-supported daily, weekly, and monthly aggregates are supported.

---

# 22. Cost Analysis

The backend owns authoritative cost analysis.

Inputs can include:

- tariff/bill evidence
- energy quantities
- billing context
- historical cost relationships
- assumptions

Fallback estimates are clearly identified.

Fixed charges retain distinct meaning.

Future costs are explicitly estimated/projected.

---

# 23. Data Quality and Provenance

Data quality is a dedicated capability.

Quality evaluates:

- reliability
- freshness
- completeness
- plausibility
- consistency
- verification
- source/context suitability

Quality limitations propagate into affected outputs, confidence, and recommendations.

Provenance can retain:

- source
- source timestamp
- ingestion time
- processing
- data nature
- transformation
- model/version
- lineage

SPMS preserves the strongest validated evidence without manufacturing precision.

---

# 24. Intelligence, Recommendations, and AI Explanation

Intelligence consumes validated analytical facts plus historical context, confidence, quality, and household context.

Not every metric becomes an insight.

Recommendation logic remains backend-controlled.

Examples:

```text
Potential surplus
    ↓
Shift flexible household consumption toward solar-rich periods

Potential deficit
    ↓
Reduce/defer appropriate non-essential consumption
```

SPMS does not directly control appliances.

Recommendation strength depends on:

- confidence
- impact
- timing
- recurrence
- actionability
- user context

User preference is highest priority for controllable personalization.

Bedrock explains validated facts; it does not invent them.

The explanation can expose:

- relevant facts
- timing
- confidence
- quality
- limitations

---

# 25. Notifications and Personalization

Recommendation and notification are separate decisions.

Not every recommendation becomes a notification.

Notification evaluation considers:

- significance
- impact
- confidence
- urgency
- recurrence
- actionability
- user context
- preferences

Normal notification preferences are user-controllable.

Security/service-critical notifications are not suppressed by ordinary preferences.

Duplicate/repeated notifications are controlled.

Feedback signals include:

```text
Useful
Not useful
Acted
Worked
Dismissed
Not relevant
```

Feedback affects relevance, category, presentation, frequency, and timing.

Feedback does not alter objective truth.

Preference priority:

```text
Explicit Preference
        >
Intelligent Default
        >
Learned Personalization
```

---

# 26. Frontend Boundary

Frontend technologies:

- HTML
- CSS
- Vanilla JavaScript
- `fetch()`

Frontend owns presentation and interaction.

JavaScript may perform presentation-only calculations but never authoritative energy, prediction, balance, cost, authorization, lifecycle, or operation-truth calculations.

Backend confirmation determines authoritative state.

---

# 27. Dashboard and Semantic Presentation

Backend APIs provide authoritative dashboard/application data.

Dashboard KPIs are backend-authoritative.

Freshness/status is presented contextually.

Unavailable capabilities degrade independently so unaffected sections remain useful.

Dashboard card arrangement may be household-specific.

---

# 28. Graph Architecture

Graph pipeline:

```text
Analytics Service
      ↓
Validated Graph-Ready Semantic Dataset
      ↓
Shared SPMS Chart Layer
      ↓
Chart.js
      ↓
Rendered Graph
```

Chart.js is used through the shared SPMS chart layer.

Visual semantics:

```text
ACTUAL     → solid + explicit label
PREDICTED  → differentiated/dashed + label + confidence
ESTIMATED  → clearly differentiated + label
STALE      → muted status
SIMULATED  → explicit DEMO/SIMULATED label
```

Confidence bands are used where useful.

Graphs provide accessible textual/summary/table representations.

Resolution adapts to available evidence.

---

# 29. Analytics, Reports, and Export

Analytics owns historical datasets and analytical calculations.

Backend validation controls:

- filters
- date ranges
- pagination
- comparison meaning

Comparisons preserve period, resolution, nature, quality, provenance, and assumptions.

Reports are validated snapshots owned by the backend report service.

Reports retain generation context/version.

AI narrative remains grounded in validated facts.

Export is backend-authorized and purpose-specific.

Supported formats include, where appropriate:

```text
CSV
XLSX
PDF
JSON
```

Exports preserve semantic meaning, units, nature, provenance, and context.

---

# 30. Testing Architecture

Testing is layered:

```text
Unit
 ↓
Repository
 ↓
API
 ↓
Integration
 ↓
Workflow / E2E
 ↓
Security
 ↓
Failure / Recovery
 ↓
Analytical Validation
 ↓
Model Evaluation
```

Required coverage includes:

- domain/service unit tests
- repository tests
- API authentication/authorization/validation/contracts/error tests
- critical W1–W29 workflow tests
- security tests
- failure/recovery tests
- duplicate/idempotency tests
- concurrency tests
- deterministic energy-balance/cost tests

---

# 31. Failure and Recovery Testing

Reproducible tests cover:

- GoodWe unavailable
- stale solar
- weather failure
- bill extraction failure
- manual bill fallback
- prediction failure
- prediction fallback
- Bedrock unavailable
- report failure
- export failure
- duplicate requests
- concurrent changes
- recovery and reconciliation

Successful independent work must remain usable when another capability fails.

---

# 32. Model Evaluation

Model evaluation is separate from application correctness.

Evaluation requires reliable comparable outcomes.

Where meaningful, evaluation may use:

- MAE
- RMSE
- bias
- percentage error
- interval coverage

Evaluation is segmented by relevant dimensions such as:

- model/version
- household
- horizon
- resolution
- season
- weather
- data quality

Model release follows:

```text
Candidate Model
      ↓
Validation
      ↓
Evaluation
      ↓
Release Gate
      ↓
Controlled Deployment
      ↓
Monitoring
      ↓
Rollback if Required
```

Historical predictions remain tied to their generating model/version/context.

---

# 33. Demo/Test

Demo/Test uses the real SPMS architecture with controlled simulated inputs.

```text
Real SPMS Architecture
        ↓
Controlled Test/Demo Inputs
        ↓
Real Workflows
        ↓
Real Degradation / Recovery Paths
```

Scenarios include:

- normal
- low solar
- potential surplus
- potential deficit
- stale data
- GoodWe disconnect
- prediction failure
- AI failure
- recovery

All simulated data carries explicit:

```text
DEMO
SIMULATED
```

provenance/status.

Demo/Test data must never contaminate real household history.

Scenarios are reproducible.

---

# 34. Environment and Configuration

Separate configuration contexts are maintained for:

```text
Development
Demo
Testing
Production
```

Secrets remain environment-specific and outside source control.

Central configuration is used.

Production errors expose safe messages while technical diagnostics remain protected.

---

# 35. Observability

Structured logging is required.

Correlation IDs connect meaningful events where useful:

```text
HTTP Request
      ↓
Application Operation
      ↓
Background Job
      ↓
External Integration
      ↓
Logs
```

Logs use structured fields.

Sensitive information is excluded/redacted, including:

- passwords
- recovery tokens
- GoodWe credentials
- API secrets
- unnecessary bill contents
- unnecessary personal information

Operational events remain separate from analytical history.

External service health and data freshness remain separate concepts.

---

# 36. Backup and Recovery

Backups are scheduled and protected.

Where applicable, backup storage is encrypted and separate from normal application storage.

Recovery is considered complete only after:

```text
Backup
 ↓
Restore
 ↓
Integrity Validation
 ↓
Reconciliation
 ↓
Health Verification
 ↓
Recovery Complete
```

A backup existing is not sufficient proof of recoverability.

---

# 37. Documentation

The existing numbered project documentation remains the architectural source of truth:

```text
01_PROJECT_FOUNDATION.md
02_FUNCTIONAL_BLUEPRINT.md
03_PAGE_BLUEPRINT.md
04_UI_DESIGN_SYSTEM.md
05_DATABASE_BLUEPRINT.md
06_API_BLUEPRINT.md
07_TECHNICAL_ARCHITECTURE.md
08_SECURITY_RULES.md
09_DEVELOPMENT_PLAN.md
10_DECISION_LOG.md
```

Relevant documents must be updated alongside implementation.

Documentation should retain concrete viva-friendly details such as:

- technologies
- verified versions
- folder responsibilities
- security mechanisms
- database conventions
- API conventions
- UI/design specifications
- rationale

`MY_SPMS_NOTES.md` remains the personal Tinglish explanation layer and is not a duplicate technical specification.

---

# 38. Git Checkpoint Rule

Git commits are meaningful stable checkpoints, not commits for every tiny edit.

The first technical checkpoint is the complete working path:

```text
Browser
 ↓
Router
 ↓
Controller
 ↓
Service
 ↓
Repository
 ↓
MySQL
 ↓
API Response
```

Once verified, this receives a stable Git commit.

Later commits correspond to meaningful stable implementation milestones.

---

# 39. Implementation Sequence

Implementation proceeds approximately:

```text
Foundation
   ↓
Authentication / Lifecycle
   ↓
Household
   ↓
Solar / GoodWe
   ↓
Bills
   ↓
Environmental Data
   ↓
Prediction
   ↓
Energy Balance
   ↓
Cost
   ↓
Intelligence
   ↓
Recommendations / Notifications
   ↓
Analytics / Graphs
   ↓
Reports / Export
   ↓
Frontend Integration / Refinement
   ↓
Testing / Security / Recovery Hardening
   ↓
Deployment Readiness
```

The exact order may adapt where dependency relationships require it.

---

# 40. Architectural Deviation Rule

If implementation exposes a conflict with a locked decision:

```text
Do not quietly bypass it.
```

Instead:

```text
Conflict
   ↓
Stop at Relevant Boundary
   ↓
Document Issue
   ↓
Evaluate Architecture vs Implementation
   ↓
Make Explicit Justified Decision
   ↓
Update Documentation
   ↓
Continue
```

No frontend shortcut or convenience implementation may silently redefine authoritative system behavior.

---

# 41. Phase Completion Criteria

Phase 6 is complete because:

- Q281–Q670 are all locked
- architecture is internally consistent
- implementation layers are defined
- API semantics are defined
- validation/error contracts are defined
- security boundaries are defined
- database conventions are defined
- integration boundaries are defined
- analytical authority is preserved
- testing is defined
- Demo/Test is defined
- recovery is defined
- documentation rules are defined
- Git checkpoint rules are defined
- no blocking architectural gap remains

---

# 42. Final Governing Principle

> **Implement the locked architecture incrementally, verify each meaningful boundary, preserve the established semantic, security, and analytical rules, document concrete implementation decisions, test continuously, and commit stable milestones.**

This is the governing transition rule from architecture into coding.

---

# 43. Final Phase 6 Status

```text
PHASE 6 — IMPLEMENTATION FOUNDATION

Q281–Q670
390 DECISIONS
ALL LOCKED

ARCHITECTURE READY
        ↓
DOCUMENTATION CONSOLIDATION
        ↓
STABLE GIT CHECKPOINT
        ↓
ACTUAL IMPLEMENTATION
```

**Phase 6 is COMPLETE.**
