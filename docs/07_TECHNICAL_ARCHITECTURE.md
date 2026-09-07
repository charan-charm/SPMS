# SPMS — Technical Architecture

## Document Status

- **Phase:** 5 — Technical Architecture
- **Status:** 🔒 ARCHITECTURE BASELINE ESTABLISHED
- **Decision coverage:** Q1–Q280
- **Final consistency audit:** ✅ Passed
- **Implementation status:** Not started

---

# 1. Architecture Objective

SPMS is a household Solar Power Management System that helps households understand solar availability and electricity requirements, predict future solar generation and household consumption, identify potential surplus/deficit situations, evaluate cost and impact, and provide understandable insights and recommendations.

The architecture supports:

- solar, household, billing, and environmental evidence collection
- validation, quality, provenance, and measurement context
- adaptive energy prediction
- authoritative energy-balance and cost analysis
- controlled AI-assisted interpretation
- recommendations, notifications, analytics, reports, and exports
- graceful degradation
- secure account, household, file, and admin lifecycles
- historical integrity and model evaluation

---

# 2. Core Architecture Principles

1. PHP is the actual application runtime.
2. No Node.js runtime is required by the final SPMS.
3. The existing GoodWe Node implementation is research/reference material only.
4. GoodWe-specific behavior is isolated behind a provider/integration boundary.
5. Business logic belongs in services/domain capabilities, not pages or JavaScript.
6. Database access belongs in repositories.
7. External-system communication belongs in integrations.
8. Analytical numerical truth remains inside authoritative SPMS analytical capabilities.
9. Amazon Bedrock is an AI interpretation/explanation capability, not the numerical prediction authority.
10. Frontend calculations are presentation-only.
11. Actual, predicted, estimated, simulated, cached, and stale data remain semantically distinguishable.
12. Data quality and provenance are first-class concerns.
13. Historical analytical truth must not be silently overwritten.
14. External-service failure degrades capabilities rather than destroying trustworthy independent data.
15. Backend authorization is authoritative.
16. Long-running work uses controlled background operations.
17. Graphs, reports, and exports consume backend-authoritative datasets.
18. Demo/Test uses the same core pipeline where practical.
19. Explicit user preferences take priority over defaults and learned personalization.
20. Provider-specific implementation details may be verified during implementation without unnecessarily reopening the architecture.

---

# 3. High-Level Architecture

```text
                         SPMS
                          │
                 ┌────────▼────────┐
                 │ Browser / User  │
                 │ HTML/CSS/JS     │
                 └────────┬────────┘
                          │
                 ┌────────▼────────┐
                 │ Public Entry     │
                 │ /public/index.php│
                 └────────┬────────┘
                          │
                 ┌────────▼────────┐
                 │ Router           │
                 └────────┬────────┘
                          │
                 ┌────────▼────────┐
                 │ Middleware       │
                 └────────┬────────┘
                          │
                 ┌────────▼────────┐
                 │ Controllers      │
                 └────────┬────────┘
                          │
                 ┌────────▼────────┐
                 │ Domain Services  │
                 └──────┬──────┬────┘
                        │      │
                ┌───────▼──┐ ┌─▼────────────┐
                │Repositories│ │ Integrations │
                └───────┬──┘ └──────┬───────┘
                        │            │
                        ▼      ┌─────┼─────────────┐
                      MySQL    ▼     ▼             ▼
                            GoodWe Weather      AWS/AI
```

Cross-cutting capabilities:

```text
Authentication
Authorization
Validation
Security
Data Quality
Provenance
Measurement Context
Confidence
Operations
Audit
Logging
Configuration
Error Handling
Testing
Lifecycle Management
```

---

# 4. Layer Responsibilities

## Browser / Frontend

Technology:

- HTML
- Custom CSS and SPMS design tokens
- Vanilla JavaScript
- ES modules
- Fetch API
- Chart.js

The browser renders pages, handles interaction, performs UX validation, calls APIs, maintains temporary UI state, and renders graph-ready data.

It is never authoritative for calculations, authorization, verification, operation completion, or analytical truth.

## Public Entry Point

`/public/index.php` is the main front controller. Only `public/` is web-accessible.

## Router

Centralized Web/API routing. API routes use `/api/v1/...`.

## Middleware

Ordered shared pipeline for correlation ID, rate limiting where applicable, session authentication, authorization, CSRF for applicable state-changing browser requests, and request validation.

## Controllers

Controllers handle HTTP concerns and delegate business behavior to services. They do not own raw SQL, GoodWe protocol details, authoritative calculations, or uncontrolled AI behavior.

## Services

Services own domain/application behavior and orchestration.

Major services include:

```text
AccountService
HouseholdService
SolarService
EnergyService
ForecastService
IntelligenceService
RecommendationService
BillService
AnalyticsService
ReportService
NotificationService
```

Supporting capabilities include quality, provenance, personalization, operations, and audit.

## Repositories

Repositories own persistence and retrieval through PDO/prepared statements. They do not become a second business-logic layer.

## Integrations

External systems are isolated under dedicated integration boundaries:

```text
Integrations/
├── GoodWe/
├── Weather/
├── AWS/
├── Bedrock/
└── Email/
```

---

# 5. GoodWe / SEMS Integration

The final SPMS does not reproduce the old Node project file-for-file.

```text
Solar Service
      ↓
SolarDataProvider
      ↓
GoodWeProvider
      ↓
GoodWe Integration
      ↓
GoodWe / SEMS
```

Conceptual GoodWe components, created only when actually needed:

```text
GoodWeProvider
GoodWeClient
GoodWeAuthenticator
GoodWeEndpointRegistry
GoodWeRequestBuilder
GoodWeResponseMapper
GoodWeException
DTOs
```

The GoodWe boundary owns authentication, provider sessions/tokens, signatures, headers, timestamps, UUID/client metadata, API versions, endpoint definitions, request construction, provider response interpretation, retries, timeouts, response normalization, and provider-specific failures.

Endpoint adoption is requirement-driven:

```text
SPMS requirement
      ↓
Required capability
      ↓
GoodWe endpoint research
      ↓
Endpoint verification
      ↓
Implementation
```

Do not integrate every endpoint found in the reference project.

## GoodWe synchronization

```text
Trigger
  ↓
Operation Created
  ↓
Validate Connection
  ↓
Authenticate
  ↓
Fetch
  ↓
Normalize
  ↓
Validate
  ↓
Deduplicate
  ↓
Quality Assessment
  ↓
Provenance
  ↓
Transactional Persistence
  ↓
Update Sync State
  ↓
Refresh affected analytics
```

Automatic background synchronization and manual Sync Now are supported. Synchronization uses bounded retries, timeouts, controlled concurrency, idempotency, late/out-of-order handling, and recovery reconciliation.

GoodWe failure preserves trusted historical data and marks live capability stale/degraded. The system never fabricates live GoodWe measurements.

---

# 6. Provider Abstraction

Solar providers implement a common internal contract:

```text
SolarDataProvider
├── GoodWeProvider
└── DemoSolarProvider
```

Demo/Test therefore follows the same normalized solar pipeline.

Weather follows:

```text
WeatherService
      ↓
WeatherProvider
      ↓
External Weather API
```

Provider-specific structures are normalized before reaching core SPMS logic.

---

# 7. Authoritative Data and Analytical Pipeline

```text
COLLECT
   ↓
VALIDATE
   ↓
QUALITY
   ↓
PROVENANCE
   ↓
PREDICT
   ↓
COMPARE
   ↓
COST / IMPACT
   ↓
INTELLIGENCE
   ↓
RECOMMEND
   ↓
NOTIFY
   ↓
LEARN
   ↓
REPEAT
```

Detailed analytical boundary:

```text
Validated Evidence
        ↓
Authoritative Analysis
        ↓
Prediction / Energy Balance / Cost
        ↓
Validated Facts
        ↓
Intelligence Layer
        ↓
Bedrock Explanation
        ↓
Insights
        ↓
Recommendations
        ↓
Possible User Action
```

---

# 8. Prediction Architecture

Solar and household-consumption prediction are separate capabilities with shared infrastructure where appropriate.

Prediction lifecycle:

```text
Validated Evidence
      ↓
Prediction Service
      ↓
Model
      ↓
Prediction Result
      ↓
Validation
      ↓
Confidence / Uncertainty
      ↓
Historical Prediction Record
```

Meaningful prediction records retain target period, value, horizon, resolution, generated time, model identifier/version, configuration, input/context metadata, confidence, uncertainty where valid, quality, provenance, and fallback context.

Historical predictions are immutable.

Fallback hierarchy:

```text
Primary Model
     ↓
Fresh Previous Prediction
     ↓
Statistical / Historical / Seasonal / Trend / Persistence / Baseline
     ↓
No Reliable Prediction
```

Fallbacks reduce precision/confidence appropriately. Bedrock is never the numerical fallback engine.

---

# 9. Energy Balance and Measurement Semantics

For future situations:

```text
Predicted Solar
       -
Predicted Household Consumption
       ↓
Potential Surplus / Potential Deficit
```

The system distinguishes:

```text
Potential Surplus ≠ Confirmed Grid Export
Potential Deficit ≠ Confirmed Grid Import
Predicted Consumption ≠ Measured Consumption
```

Data nature and measurement context remain explicit.

---

# 10. Cost Analysis

Cost analysis is a dedicated backend capability using verified bill/tariff evidence, historical relationships, and supported fallback assumptions.

The system distinguishes:

```text
Historical Verified Cost
Future Estimated Cost
```

Fixed charges are not automatically treated as proportional energy costs. Without direct grid-import measurement, confirmed future grid-import cost is not claimed.

---

# 11. Data Quality and Provenance

## Data Quality

Answers:

> Can this information be trusted for its intended use?

Relevant dimensions include reliability, freshness, completeness, plausibility, consistency, verification, source quality, and measurement context.

Quality propagates downstream and may reduce precision, confidence, recommendation strength, and notification priority.

## Provenance

Answers:

> What is this, where did it come from, and what happened to it?

Meaningful data retains sufficient source, identifier, timestamp, nature, processing, transformation, quality, and measurement-context information.

Provenance flows through validation, analysis, prediction, intelligence, recommendations, reports, exports, and UI.

---

# 12. AI / Amazon Bedrock Boundary

```text
Validated SPMS Facts
        ↓
Controlled Semantic Context
        ↓
AI Service
        ↓
Amazon Bedrock
        ↓
Structured Output
        ↓
Schema Validation
        ↓
Business / Safety / Quality Validation
        ↓
SPMS Output
```

Bedrock may support bill interpretation, semantic interpretation, insight explanations, recommendation explanations, report summaries, and contextual natural-language intelligence.

Bedrock must not become:

- the numerical prediction authority
- the energy-balance calculator
- the cost calculator
- an uncontrolled database writer

AI receives minimum necessary validated context. AI configuration/prompts are centralized and versioned.

If Bedrock fails, analytical truth continues and controlled deterministic/template explanations may be used.

---

# 13. Database Architecture

The database is domain-oriented and normalized.

Major domains/entities include:

```text
Account
Household
SolarPlant
SolarMeasurements
Bills
ConsumptionEvidence
Predictions
EnergyBalance
CostAnalysis
Insights
Recommendations
Feedback
Notifications
Reports
Operations
Audit
DataQuality
Provenance
ModelEvaluation
```

Technical database decisions:

- MySQL 8.x
- InnoDB
- utf8mb4
- normalized relational design
- foreign-key integrity
- explicit nullable/required semantics
- meaningful uniqueness constraints
- internal generated identifiers
- canonical timestamps
- domain-specific deletion behavior
- controlled lifecycle states
- immutable historical records where required

Database changes use version-controlled migrations.

---

# 14. Electricity Bill Processing

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

Original documents are stored securely. AI extraction remains draft information until user verification.

If automated extraction fails:

```text
Original Bill
      ↓
Manual Entry / Verification
```

The exact AWS document-analysis service is verified during implementation after supported bill formats are examined.

---

# 15. Background Operations

Long-running operations use controlled PHP background workers/cron.

Candidates include:

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

Where safe, operations support cancellation, bounded retries, backoff, idempotency, safe diagnostics, and user-visible status.

---

# 16. Authentication and Authorization

Authentication uses:

- PHP server-side sessions
- secure cookies
- `password_hash()` / `password_verify()`
- email verification
- secure recovery tokens
- bounded attempts/brute-force protection

Authorization is backend-enforced for every protected operation.

The backend validates account, role, capability, household ownership, resource scope, and lifecycle state.

Roles:

```text
Household User
Admin
```

Demo/Test is an account mode, not a role.

---

# 17. File Security

Bills and other private files are stored outside the public web root.

```text
Browser
   ↓
Upload
   ↓
MIME / content / size / filename validation
   ↓
Private Storage
   ↓
Controlled Processing
   ↓
Backend-authorized Access
```

Server-generated storage names are used. Suspicious/invalid files are rejected.

---

# 18. API Architecture

The browser communicates with SPMS through PHP APIs.

Base:

```text
/api/v1
```

Success envelope:

```json
{
  "success": true,
  "data": {},
  "meta": {}
}
```

Failure envelope:

```json
{
  "success": false,
  "error": {
    "code": "STABLE_ERROR_CODE",
    "message": "Safe user-facing message",
    "context": {}
  }
}
```

Protected APIs require authenticated session context and backend authorization.

APIs use request validation, structured errors, rate limiting for sensitive operations, correlation identifiers, and controlled lifecycle/status responses.

---

# 19. Frontend and Graph Architecture

Frontend structure:

```text
public/assets/js/
├── core/
├── components/
├── charts/
└── pages/
```

A shared Fetch-based API client handles consistent communication and errors.

Graph pipeline:

```text
Authoritative Data
       ↓
Analytics / Reporting
       ↓
Graph-Ready Semantic Dataset
       ↓
Shared SPMS Chart Layer
       ↓
Chart.js
```

Graph semantics include:

- Actual → solid
- Predicted → dashed
- Estimated → differentiated
- Stale → muted/subdued
- Demo/Simulated → explicitly differentiated

Important graphs provide accessible summaries/tables/data representations. Color is never the sole semantic mechanism.

---

# 20. Reports and Exports

Reports are backend-authoritative validated snapshots.

```text
Analytics
Prediction
Balance
Cost
Insights
Recommendations
       ↓
Validated Report Dataset
       ↓
Report Generator
       ↓
Version / Context Tracking
       ↓
Report
```

A generated report represents its generation-time context and does not silently change with later dashboard data.

Exports follow:

```text
Request
 ↓
Authentication
 ↓
Authorization
 ↓
Scope Validation
 ↓
Dataset Validation
 ↓
Export Pipeline
 ↓
CSV / XLSX / PDF / JSON
```

Exports preserve semantic meaning, provenance, and measurement context.

---

# 21. Notifications and Personalization

Notification flow:

```text
Event
 ↓
Significance
 ↓
Impact
 ↓
Confidence
 ↓
Urgency
 ↓
Relevance
 ↓
Recurrence / Deduplication
 ↓
User Preferences
 ↓
Notification Decision
 ↓
Delivery
```

Every event is not automatically a notification, and a recommendation is not automatically a notification.

Preference hierarchy:

```text
Explicit User Preference
        ↓
Intelligent Default
        ↓
Learned Personalization
```

Learning may affect presentation, relevance, frequency, timing, and supported categories but never overrides explicit preferences or changes objective analytical truth.

Feedback references recommendations/insights rather than replacing prediction evaluation.

---

# 22. Graceful Degradation

SPMS distinguishes:

```text
Actual
Predicted
Estimated
Simulated
Cached
Stale
Degraded
Unavailable
```

### GoodWe unavailable

Trusted historical data remains available; live capability becomes stale/degraded; supported fallback analytics may continue.

### Weather unavailable

Historical/pattern-based fallback may continue with reduced confidence.

### Bedrock unavailable

Numerical analytics continue with controlled explanation fallback.

### Prediction failure

The validated fallback hierarchy is used; if no reliable prediction exists, the system explicitly states that.

---

# 23. Historical Integrity and Lifecycle

Historically meaningful records are preserved, including verified bills, historical predictions, evaluation context, model/version information, recommendation history, analytical results where required, provenance, and audit records.

Older high-volume data may be aggregated/archived according to retention rules without destroying required historical meaning.

Account deletion:

```text
Deletion Request
 ↓
Authorization
 ↓
Disable Access
 ↓
Revoke Active Processing/Credentials
 ↓
Category-Specific Data Disposition
 ↓
Retain Only Legitimately Required Data
```

Deletion is a controlled lifecycle, not a single-row deletion.

---

# 24. Model Lifecycle and Evaluation

```text
Model Candidate
     ↓
Validation
     ↓
Evaluation
     ↓
Comparison
     ↓
Controlled Deployment
     ↓
Monitoring
     ↓
Rollback if necessary
```

Evaluation uses reliable comparable observations and appropriate metrics such as MAE, RMSE, bias, percentage error, and interval coverage/calibration where meaningful.

Results are contextualized by model, household, horizon, resolution, season, weather, and data quality.

A new model must be validated before replacing a validated production version.

---

# 25. Operations, Audit, Errors and Recovery

Operational records are separate from analytical records.

Operational/audit coverage includes synchronization, background operations, bill processing, prediction execution, reports/exports, failures, recovery, health checks, authentication/security events, account lifecycle, significant admin actions, deletion, and model/configuration lifecycle events.

Errors use stable categories/codes and safe user-facing messages.

General recovery:

```text
Detect
 ↓
Classify
 ↓
Preserve trustworthy state
 ↓
Retry when safe
 ↓
Fallback when valid
 ↓
Record
 ↓
Expose understandable state
 ↓
Recover / Reconcile
```

Logs are structured and minimized. Secrets and unnecessary sensitive data are not logged.

---

# 26. Concurrency and Idempotency

Meaningful mutable operations use backend version/context validation.

Duplicate execution is controlled through:

- idempotency context/keys
- provider/source identifiers
- timestamp/context uniqueness
- database constraints
- operation-state validation

Unsafe silent last-write-wins behavior is avoided for meaningful concurrent changes.

---

# 27. Configuration and Environment

Target local environment:

```text
Windows
XAMPP
Apache
PHP 8.4
MySQL 8.x
Composer
```

Development, Testing, and Production are separate environments.

Environment-specific configuration and secrets remain outside source control.

Composer lockfile provides reproducible dependency versions.

---

# 28. Testing Strategy

Testing layers:

```text
                 E2E / Workflow
                       ▲
                API / Security
                       ▲
                 Integration
                       ▲
              Service / Domain
                       ▲
                    Unit
```

Coverage includes:

- domain/service unit tests
- repository/database tests
- GoodWe/weather/AWS/Bedrock/email integration tests
- API tests
- W1–W29 workflow tests
- security tests
- failure/recovery tests
- analytical calculation validation
- separate prediction evaluation
- reproducible Demo/Test scenarios

---

# 29. Implementation Project Structure

```text
D:\SPMS
├── app/
│   ├── Controllers/
│   ├── Domain/
│   │   ├── Account/
│   │   ├── Analytics/
│   │   ├── Bills/
│   │   ├── Energy/
│   │   ├── Forecast/
│   │   ├── Household/
│   │   ├── Intelligence/
│   │   ├── Notification/
│   │   ├── Recommendation/
│   │   ├── Reports/
│   │   └── Solar/
│   ├── Integrations/
│   ├── Repositories/
│   ├── Services/
│   └── Support/
├── config/
├── database/
│   ├── migrations/
│   └── seeders/
├── docs/
├── public/
│   ├── index.php
│   └── assets/
│       ├── css/
│       ├── fonts/
│       ├── images/
│       └── js/
├── resources/
│   └── views/
├── storage/
│   ├── cache/
│   ├── logs/
│   └── temp/
├── tests/
│   ├── API/
│   ├── Integration/
│   ├── Security/
│   ├── Unit/
│   └── Workflow/
├── composer.json
├── composer.lock
├── .env
├── .env.example
├── .gitignore
└── README.md
```

This tree represents the established Phase 6 repository organization. Layer responsibilities remain as described in Sections 3–4; domain capability logic is organized under `app/Domain/` rather than a generic `Models/` directory. The concrete implementation placement of cross-cutting components (middleware, validation, exceptions, and similar mechanisms) is determined during M0 according to the Phase 6 implementation specification, without changing the architecture.

Only `public/` is the web root.

Composer uses PSR-4 autoloading with the `SPMS\` namespace.

Naming:

- PHP classes: PascalCase
- PHP methods/variables: camelCase
- DB: snake_case
- JavaScript: camelCase
- files: domain-oriented naming

---

# 30. W1–W29 Coverage

```text
W1  Account Registration & Activation
W2  Authentication & Session Lifecycle
W3  Household Setup & Energy Profile Initialization
W4  Household/Profile Modification
W5  Account/Household Deletion
W6  GoodWe Connection & Validation
W7  Solar Data Synchronization
W8  Solar Disconnection, Degradation & Recovery
W9  Electricity Bill Processing
W10 Manual Bill Entry & Verification
W11 Household Energy Profile Development
W12 Location Establishment
W13 Environmental Data Retrieval & Context Update
W14 Environmental Failure & Fallback
W15 Energy Forecasting
W16 Prediction Validation & Fallback
W17 Energy Balance & Situation Analysis
W18 Energy Cost Analysis
W19 Energy Intelligence & Insight Generation
W20 Recommendation Generation
W21 Notification Evaluation & Delivery
W22 Feedback & Personalization Adaptation
W23 Historical Analytics & Visualization Data Preparation
W24 Report Generation
W25 Export
W26 Data Quality Assessment & Propagation
W27 Provenance & Measurement Context Tracking
W28 Operational Monitoring & Audit
W29 Prediction Evaluation & Model Lifecycle
```

All W1–W29 have an implementation path.

---

# 31. Phase 3 Page Coverage

```text
Dashboard
  → analytical aggregation services

Solar
  → solar services

Energy
  → energy / balance / cost services

Forecasts
  → prediction services

Insights & Recommendations
  → intelligence / recommendation services

Analytics
  → analytics services

Bills
  → bill services

Reports
  → report services

Notifications
  → notification services

Settings
  → account / household / preference services

Demo/Test
  → scenario / simulation services
```

No major page is merely a disconnected visual shell.

---

# 32. Phase 4 UX Compatibility

The technical architecture supports the locked UX system through concrete mechanisms:

| UX requirement | Technical mechanism |
|---|---|
| Adaptive loading | Operation lifecycle |
| Actual/predicted distinction | Data-nature metadata |
| Confidence | Prediction metadata |
| Provenance | Provenance capability |
| Quality states | Data-quality capability |
| Graceful degradation | Capability/service states |
| Graph semantics | Graph-ready datasets |
| Personalization | Preference/default/learning hierarchy |
| Search/filtering | Authorized backend/context-aware exploration |
| Accessibility | Semantic UI + alternative data representation |
| Localization | Stable semantic IDs/resources |
| Dark mode | Persistent theme preference |
| Drafts | Lifecycle/state model |
| Confirmation | Action/result lifecycle |
| Cross-page context | Intent continuity + backend revalidation |
| Cross-device continuity | Persistent preference/intent synchronization |

---

# 33. Final Architecture Consistency Audit

The final audit covered:

1. layer responsibility separation
2. W1–W29 workflow coverage
3. Phase 3 page/backend coverage
4. analytical authority
5. Bedrock boundaries
6. GoodWe isolation
7. provider abstraction
8. database integrity
9. historical truth
10. quality/provenance propagation
11. measurement semantics
12. graceful degradation
13. security boundaries
14. background operations
15. graph architecture
16. report/export authority
17. notification separation
18. personalization rules
19. UX/technical compatibility
20. dependency direction

### Result

**🟢 PASS — No blocking architectural gap identified.**

Two implementation clarifications were confirmed:

1. Email remains behind an email service/provider boundary.
2. Browser state is never treated as authoritative system state.

Neither requires an architecture change.

---

# 34. Architecture Baseline

Phase 5 is implementation-ready at the architectural level.

```text
Phase 1 — Foundation
        ↓
Phase 2 — Functional Blueprint
        ↓
Phase 3 — Page Architecture
        ↓
Phase 4 — UI/UX Design System
        ↓
Phase 5 — Technical Architecture
        ↓
Q1–Q280 🔒
        ↓
Final Consistency Audit ✅
        ↓
ARCHITECTURE BASELINE
        ↓
Phase 6 — Implementation Foundation
```

Future verification items such as exact GoodWe endpoint behavior, exact AWS document-processing service selection, provider payload fields, dependency versions, database indexes, and concrete API payloads are implementation tasks unless they expose a genuine architectural contradiction.

---

# 35. Git Checkpoint

Phase 5 documentation is intended to be reviewed as a complete architecture baseline and committed as one meaningful stable Git checkpoint.

Do not create tiny commits for individual architecture paragraphs.

---

# 36. Next Phase

## Phase 6 — Implementation Foundation

Recommended sequence:

1. Verify local environment
2. Initialize Composer
3. Establish PSR-4 autoloading
4. Create project directories
5. Create `public/index.php`
6. Establish routing
7. Establish middleware pipeline
8. Establish configuration loading
9. Establish PDO database connection
10. Establish migration system
11. Establish base API response/error handling
12. Establish base HTML/view shell
13. Establish authentication foundation
14. Establish first tests
15. Verify the end-to-end skeleton
16. Review documentation
17. Create the stable Git checkpoint

GoodWe and Bedrock should be integrated only after the foundation is stable.

---

## Phase 5 Final Status

**Phase 5 Technical Architecture: 🔒 COMPLETE**

**Q1–Q280: 🔒 LOCKED**

**Final Architecture Consistency Audit: ✅ PASSED**

**Architecture Baseline: ✅ ESTABLISHED**

**Next: Phase 6 — Implementation Foundation**
