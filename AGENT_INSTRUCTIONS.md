# SPMS — AI Coding Agent Contract

## 1. Purpose

You are the implementation agent for the Solar Power Management System (SPMS).

Your responsibility is to implement the approved SPMS architecture accurately, incrementally, securely, and testably.

You are NOT the product architect.

The project's architecture, functional behavior, security boundaries, analytical authority, data semantics, and UX principles have already been decided.

Your job is to IMPLEMENT those decisions.

---

# 2. Source of Truth

Before making implementation decisions, inspect the relevant project documentation.

Primary documentation:

- docs/01_PROJECT_FOUNDATION.md
- docs/02_FUNCTIONAL_BLUEPRINT.md
- docs/03_WEBPAGE_USER_FLOW_BLUEPRINT.md
- docs/04_UI_DESIGN_SYSTEM.md
- docs/05_DATABASE_BLUEPRINT.md
- docs/06_API_BLUEPRINT.md
- docs/07_TECHNICAL_ARCHITECTURE.md
- docs/08_SECURITY_RULES.md
- docs/09_DEVELOPMENT_PLAN.md
- docs/10_DECISION_LOG.md
- docs/11_FRONTEND_ARCHITECTURE.md
- docs/12_FRONTEND_DESIGN_SYSTEM.md

Personal implementation notes:

- MY_SPMS_NOTES.md

The numbered documents are the project's official technical source of truth.

MY_SPMS_NOTES.md is a supplementary project notebook.

When implementing a feature, inspect the documentation relevant to that feature before coding.

Do not invent architecture that contradicts these documents.

---

# 3. Governing Rule

Implement the locked architecture incrementally.

Verify each meaningful boundary.

Preserve established:

- semantic rules
- security rules
- analytical authority
- data meaning
- provenance
- confidence
- quality
- measurement context
- failure behavior
- lifecycle behavior
- UX behavior

Document concrete implementation decisions.

Test continuously.

Commit stable milestones.

---

# 4. Technology Stack

The approved implementation stack is:

Backend:

- PHP 8.4 minimum
- MySQL (MariaDB-compatible development database)
- PDO
- Composer
- Apache/XAMPP for development
- PHP REST JSON APIs

Frontend (locked F0 direction — docs/11, docs/12):

- React
- Vite (frontend development/build tooling only)
- Tailwind CSS
- Motion
- shadcn/ui where appropriate
- Lucide Icons
- Recharts initially (or another explicitly approved charting library)
- React Router
- Native fetch() through a centralized API client

Architecture:

React SPA
→ centralized API client
→ PHP REST API (/api/v1)
→ application/domain/repository/integration layers
→ MySQL / external providers

Express is NOT permitted as the SPMS backend. Node.js is frontend
development/build tooling only, never an application runtime for SPMS.

Do NOT introduce:

- Vue
- Angular
- Svelte
- Node.js application servers
- Express
- frontend or backend technologies outside the locked stack without
  explicit owner approval

Third-party PHP dependencies must be managed through Composer.

---

# 5. Architecture

SPMS uses a lightweight modular MVC-like PHP backend behind a React SPA
frontend (see docs/07_TECHNICAL_ARCHITECTURE.md and
docs/11_FRONTEND_ARCHITECTURE.md).

Backend flow:

Browser (React SPA)
→ Central API client (fetch)
→ REST API (/api/v1)
→ Front Controller
→ Router
→ Middleware
→ Controller
→ Application/Domain Service
→ Repository / Integration
→ Database / External Provider
→ Service
→ Controller
→ JSON Response
→ React UI

Core backend responsibilities:

Controllers:
- HTTP input/output only
- request parsing
- validation coordination
- response formatting
- delegate business logic

Services:
- application orchestration
- domain/business rules
- authoritative operations

Repositories:
- database persistence and retrieval
- SQL execution through PDO

Integrations:
- external provider communication
- provider-specific authentication
- provider response normalization

Views:
- presentation only
- no authoritative business calculations

Support:
- shared infrastructure
- validation
- response handling
- logging
- exceptions
- configuration
- security utilities
- semantic identifiers

---

# 6. Dependency Direction

Preferred direction:

Controller
→ Service
→ Repository

or:

Controller
→ Service
→ Integration

Repositories and integrations must not depend on controllers.

Controllers must not contain domain business logic.

React must not become an alternative business-logic layer.

Avoid circular service dependencies.

---

# 7. Project Structure

Maintain the approved structure:

app/
├── Controllers/
├── Domain/
│   ├── Account/
│   ├── Analytics/
│   ├── Bills/
│   ├── Energy/
│   ├── Forecast/
│   ├── Household/
│   ├── Intelligence/
│   ├── Notification/
│   ├── Recommendation/
│   ├── Reports/
│   └── Solar/
├── Integrations/
├── Repositories/
├── Services/
└── Support/

config/

database/
├── migrations/
└── seeders/

frontend/
└── (React application — established at F1, see docs/11)

public/
├── assets/
│   ├── css/
│   ├── fonts/
│   ├── images/
│   └── js/
└── index.php

resources/
└── views/

storage/
├── cache/
├── logs/
└── temp/

tests/
├── API/
├── Integration/
├── Security/
├── Unit/
└── Workflow/

docs/

MY_SPMS_NOTES.md

composer.json
composer.lock
.env
.env.example
.gitignore
README.md

Do not reorganize the project structure casually.

---

# 8. Front Controller and Routing

The application uses:

public/index.php

as the central front controller.

Apache rewrite rules route requests through the front controller.

The router must distinguish web and API requests.

API namespace:

/api/v1/...

Use explicit HTTP methods:

GET
POST
PUT
PATCH
DELETE

Use HTTP methods according to operation semantics.

Do not create arbitrary endpoint conventions.

---

# 9. API Rules

All protected APIs require authenticated session context.

Use a standardized JSON envelope.

Success:

{
    "success": true,
    "data": {},
    "meta": {}
}

Errors must contain:

- stable error code
- safe human-readable message
- structured context when appropriate

Use meaningful HTTP status codes.

Never expose:

- database credentials
- provider credentials
- secrets
- stack traces
- sensitive internal diagnostics
- raw database records unnecessarily

APIs should return semantic projections appropriate to the client.

Do not dump database tables directly to the frontend.

---

# 10. Authentication and Authorization

Authentication uses:

- email + password
- secure PHP sessions
- strong one-way password hashing
- email verification
- controlled verification tokens
- controlled recovery tokens
- secure cookies
- session ID regeneration after authentication
- logout invalidation
- bounded brute-force protection
- rate limiting where required

Authorization is enforced on the backend.

Every protected resource must verify:

- authentication
- role/capability
- household/resource ownership
- lifecycle eligibility where relevant

Frontend checks are UX only.

Never trust frontend authorization.

Roles:

- USER
- ADMIN

REAL/DEMO is an account mode/state, NOT a role.

---

# 11. Security

Apply:

- CSRF protection for state-changing browser requests
- backend validation
- request normalization
- prepared SQL statements
- PDO
- secure sessions
- secure cookies
- rate limiting
- brute-force protection
- secure recovery tokens
- file validation
- protected file storage
- safe error handling
- minimized/redacted logging
- environment-based secrets

Sensitive operations may require re-authentication.

Do not log secrets or unnecessary personal/bill contents.

---

# 12. Database

Database:

MySQL

Use:

- normalized relational design
- snake_case table/column names
- plural table names
- explicit primary keys
- foreign keys where appropriate
- unique constraints
- explicit nullability
- intentional indexes
- transactions for atomic business operations
- version-controlled migrations

Use canonical numeric values.

Do not store presentation-formatted strings as authoritative values.

Monetary values use fixed-precision numeric storage.

Currency is part of monetary context.

Canonical timestamps are stored consistently.

Preserve source/provider measurement timestamps where relevant.

Preserve SPMS ingestion timestamps where relevant.

Household timezone must be explicit.

---

# 13. Historical Truth

Never silently rewrite historical analytical truth.

Historical predictions must remain associated with:

- model
- model version
- context
- confidence
- provenance
- relevant quality information

Historical analytical records may be immutable where their meaning requires it.

Current household context may change.

Past records must not be rewritten merely because the current profile changed.

---

# 14. Data Nature

SPMS must distinguish:

- ACTUAL
- PREDICTED
- ESTIMATED
- SIMULATED / DEMO
- CACHED / STALE where applicable

Data nature and data status are separate concepts.

Example:

ACTUAL + STALE

is valid.

PREDICTED + FRESH

is valid.

Do not silently convert estimated or predicted data into actual data.

---

# 15. Data Quality

Data quality is a first-class capability.

Consider:

- reliability
- freshness
- completeness
- plausibility
- consistency
- verification
- source/context suitability

Quality limitations must propagate into:

- predictions
- confidence
- energy balance
- cost analysis
- insights
- recommendations
- notifications
- reports
- exports

Do not manufacture precision to make a UI look complete.

---

# 16. Provenance

Relevant data must preserve provenance.

Depending on the data, provenance can include:

- source
- source timestamp
- ingestion timestamp
- processing stage
- data nature
- transformation
- model/version
- lineage
- measurement context

Preserve provenance throughout analytical processing.

---

# 17. Measurement Context

SPMS does NOT have direct household/grid measurement in the MVP unless explicitly provided by an authoritative source.

Therefore:

Predicted household consumption is NOT measured consumption.

Potential surplus is NOT confirmed grid export.

Potential deficit is NOT confirmed grid import.

Use terminology such as:

- potential surplus
- potential deficit
- solar-utilization opportunity
- estimated/projected cost

when measurement does not support stronger claims.

---

# 18. GoodWe Integration

GoodWe is the primary actual solar source.

GoodWe communication must occur through the integration layer.

Architecture:

Service
→ provider interface
→ GoodWe integration

Never expose GoodWe credentials to:

- frontend
- JavaScript
- browser
- HTML

GoodWe data must be:

- validated
- normalized
- timestamped
- quality assessed
- provenance tracked
- deduplicated

A valid GoodWe measurement becomes authoritative actual solar evidence only after validation.

GoodWe failure must degrade gracefully.

Preserve:

- historical solar data
- last trustworthy cached data
- synchronization state
- freshness information

Never fake live data.

Recovery requires:

- reconnect
- resync
- deduplication
- validation
- reconciliation

G-FOUNDATION reference boundary (established before G0):

The earlier GoodWe/SEMS+ prototype lives in
`references/goodwe-sems-prototype/`. It is EVIDENCE ONLY: endpoint
discovery, HAR capture/replay tooling, response samples, research CLIs.
Never copy its Node/Express architecture, routes, or code into the PHP
backend. Express is forbidden as the SPMS production backend. Node.js
remains build/prototype/research tooling only.

Production rules:

- The production GoodWe integration lives server-side in the PHP
  architecture (integration layer + Solar domain). React never calls
  GoodWe directly.
- GoodWe-specific URLs, headers, authentication protocol, token
  handling, request/response formats, retries and provider error codes
  stay inside the integration boundary. The rest of SPMS works with
  canonical SPMS concepts.
- SPMS passwords and GoodWe credentials are DIFFERENT credential
  domains. The provider's website password encoding (base64 of MD5) is
  a wire-protocol detail, not a storage mechanism. If replaying the
  original GoodWe secret is genuinely required, treat it as a reversible
  external credential protected by encryption at rest with keys outside
  the database and repository — never as a one-way password hash. If the
  official API instead uses application credentials (AppId/AppSecret +
  tokens), the architecture follows that mechanism. G0 verifies this
  against official provider documentation.
- GoodWe integration belongs to the household context
  (Account → Household Membership → Household → GoodWe Integration),
  never to an SPMS account password record. W3 owns household/membership
  architecture; G0 owns the integration schema.
- The GoodWe MVP is READ-ONLY: monitoring, historical/near-current
  production, telemetry, time series, flow, weather, station statistics,
  inverter/device information. Remote inverter/device/battery/appliance
  control is out of scope even where the prototype discovered such
  endpoints.
- HAR replay and the sems-* CLI scripts are research/discovery tooling,
  never part of the production request path.
- Provider responses must pass validation → normalization → canonical
  SPMS solar data → provenance/quality classification before any domain
  use (F4/F5/F6, forecasting).
- Do not implement GoodWe production code before the G0 contract
  decisions exist.

---

# 19. Weather Integration

Weather must use a provider abstraction.

Architecture:

Weather Service
→ Weather Provider Interface
→ configured provider

Weather data supports prediction.

GoodWe actual solar remains the authoritative actual solar reference.

If weather fails:

- use trustworthy historical/pattern fallback
- reduce confidence
- preserve provenance
- mark limitations

---

# 20. Electricity Bills

Bill workflow:

Upload
→ validation
→ secure storage
→ extraction
→ AI interpretation/normalization
→ user verification
→ authoritative storage

The original bill must be preserved securely according to retention rules.

AI-extracted values are NOT authoritative until user verification.

Manual entry must remain available.

If extraction fails:

- preserve original upload
- report extraction failure
- allow manual verification/entry

Never silently discard the original bill.

Bill files must not be publicly accessible.

Do not store files under:

public/uploads/

Use controlled server-side storage outside the public root.

---

# 21. AWS / AI / Bedrock

AI is NOT the numerical authority.

Authoritative numerical systems own:

- solar forecasting
- consumption forecasting
- energy balance
- cost analysis
- analytical calculations

Amazon Bedrock is used for:

- explanation
- summarization
- contextual phrasing
- natural-language insight/recommendation explanation

AI receives only the minimum necessary validated semantic context.

AI output must be:

- structured where appropriate
- schema-constrained
- validated
- checked for unsupported claims

Reject or flag unsupported numerical claims.

If Bedrock fails:

analytical processing continues.

Use deterministic/template explanation fallback where appropriate.

Never let AI failure destroy valid analytical results.

---

# 22. Prediction

Solar forecasting and household consumption forecasting are separate capabilities.

Prediction is:

- adaptive
- multi-horizon
- evidence-supported
- confidence-aware

Supported resolution must not exceed evidence.

Example:

A monthly electricity bill cannot be transformed into hourly measured household consumption.

New users may receive:

- informed baseline
- estimate
- low confidence

Confidence should improve as validated evidence accumulates.

Prediction records should preserve:

- value
- period
- horizon
- resolution
- confidence
- uncertainty
- model
- model version
- provenance
- quality
- status
- context

---

# 23. Prediction Failure

If prediction fails:

1. Use a trustworthy previous prediction if appropriate and fresh enough.
2. Otherwise use validated statistical/historical/seasonal/trend/persistence approaches where supported.
3. Otherwise use a baseline/estimate.
4. If no trustworthy fallback exists, mark unavailable/insufficient.

Do not force a numerical result.

Reduce confidence and communicate fallback status.

Never use Bedrock as a numerical prediction fallback.

---

# 24. Energy Balance

The backend analytical layer owns energy balance.

Compare validated predicted solar and predicted household consumption.

Possible classifications:

- potential surplus
- balanced
- potential deficit

Do not claim confirmed grid import/export without direct measurement.

Support evidence-appropriate aggregation:

- daily
- weekly
- monthly
- other validated resolutions

---

# 25. Cost Analysis

Backend owns authoritative cost analysis.

Use:

- tariff evidence
- bill evidence
- energy quantities
- billing context
- historical relationships
- explicit assumptions

If exact tariff is unavailable:

use a clearly identified fallback estimate.

Fixed charges must retain their distinct meaning.

Future costs must be labelled estimated/projected.

Do not present projected cost as a verified bill amount.

---

# 26. Intelligence

The intelligence layer transforms validated analytical evidence into meaningful household insights.

Not every metric is an insight.

Evaluate:

- significance
- impact
- historical context
- confidence
- quality
- recurrence
- household context

Preserve uncertainty.

Avoid duplicate/repetitive insights.

---

# 27. Recommendations

Recommendations are advisory.

SPMS does NOT directly control appliances.

Surplus situation:

recommend shifting flexible household consumption toward solar-rich periods.

Deficit situation:

recommend reducing or deferring non-essential consumption where appropriate.

Recommendation strength depends on:

- confidence
- impact
- timing
- actionability
- recurrence
- user context

Very low-confidence conditions should not produce strong actionable recommendations.

---

# 28. Feedback and Personalization

Feedback can include:

- Useful
- Not useful
- Acted
- Worked
- Dismissed
- Not relevant

Feedback influences:

- relevance
- presentation
- frequency
- timing
- category preference

Feedback must NOT rewrite objective analytical truth.

Explicit user preference always wins.

Priority:

Explicit preference
→ intelligent default
→ learned personalization

Never allow learned personalization to override explicit user choices.

---

# 29. Notifications

Recommendation ≠ notification.

Not every recommendation becomes a notification.

Notification evaluation considers:

- significance
- impact
- confidence
- urgency
- actionability
- recurrence
- duplicate suppression
- user context
- preferences

Normal notification preferences are user-controllable.

Security/service-critical notifications remain subject to system safety rules.

Minimize sensitive content in notifications.

---

# 30. Frontend

Frontend technology (locked F0 direction — docs/11_FRONTEND_ARCHITECTURE.md,
docs/12_FRONTEND_DESIGN_SYSTEM.md):

- React
- Vite (development/build tooling only)
- Tailwind CSS constrained by the SPMS design system
- Motion for purposeful animation
- shadcn/ui where appropriate
- Lucide Icons
- Recharts initially
- React Router for client-side navigation
- native fetch() through a centralized API client

The frontend communicates with the PHP backend only through the
REST JSON API (/api/v1/...) using the centralized API client.

React is responsible for:

- presentation
- component/layout rendering
- client-side UI state
- interaction
- API consumption
- chart rendering
- animation
- accessibility behavior
- presentation-only calculations

React is an untrusted client and must NOT become authoritative for:

- authentication truth
- authorization
- household/resource ownership
- business rules
- energy balance
- cost calculations
- prediction truth
- recommendation truth
- notification decisions
- data quality
- provenance
- lifecycle state
- operation completion

The backend determines authentication, authorization, validation,
ownership, analytical truth, quality, provenance, and operation
status. Frontend route guards are navigation UX only.

Backend confirmation determines authoritative state.

---

# 31. UI Design

Follow docs/04_UI_DESIGN_SYSTEM.md.

Core visual identity:

Calm + Premium Hybrid

Application UI:

Green + Teal Energy Hybrid

Brand/logo identity remains:

Navy + Blue + Yellow/Orange

Typography:

- Inter
- Space Grotesk

Use the established:

- colors
- spacing
- typography
- radii
- elevation
- motion
- responsive rules
- accessibility rules
- data visualization rules

Do not invent unrelated visual systems.

---

# 32. Charts

Charts use Recharts initially (or another explicitly approved charting
library) through shared SPMS chart components, per the locked frontend
direction (docs/11 §12, docs/12 §11–12).

Pipeline:

Authoritative analytical data
→ Analytics/Reporting
→ graph-ready semantic dataset
→ React chart component
→ shared SPMS chart components
→ Recharts / approved chart library
→ UI

Charts must preserve:

- data nature
- status
- confidence
- measurement meaning

Actual:
- solid

Predicted:
- differentiated/dashed
- explicit label
- confidence where useful

Estimated:
- clearly differentiated
- explicit label

Stale:
- muted/subdued

Demo/Test:
- explicit DEMO/SIMULATED indication

Charts must have accessible summary/table representations where appropriate.

---

# 33. Loading and Operations

Loading states must preserve page structure.

Do not use fake progress percentages.

Only show progress when the backend knows the actual progress.

Operation states may include:

- NOT STARTED
- QUEUED
- PROCESSING
- COMPLETED
- FAILED
- CANCELLED
- RECOVERING
- RECOVERED

SPMS signature loading visual:

A stationary solar panel with a small sun moving slowly around it in a subtle elliptical orbit.

This is presentation only.

Do not use animation to imply backend progress unless backend state supports that meaning.

---

# 34. Error Handling

Errors must be:

- meaningful
- safe
- actionable
- recoverable where possible

Preserve successful independent work.

Do not destroy valid data because one capability failed.

Examples:

GoodWe failure:
→ preserve historical solar data

Bill extraction failure:
→ preserve original bill + manual fallback

Prediction failure:
→ fallback or clearly unavailable

Bedrock failure:
→ deterministic/template explanation

Export failure:
→ leave source data unchanged

---

# 35. Background Jobs

Long-running operations should not block normal HTTP requests unnecessarily.

Use PHP CLI workers with OS/server scheduling where appropriate.

Meaningful operations must have persistent records.

Persist:

- operation/job ID
- state
- timestamps
- retry information where needed
- failure state
- recovery state where needed

Use:

- bounded retries
- backoff
- timeout
- idempotency
- concurrency controls
- failure classification

Do not create infinite retry loops.

---

# 36. Idempotency

Operations that can safely execute more than once must be protected against harmful duplication.

Especially consider:

- GoodWe ingestion
- bill processing
- background jobs
- notifications
- external requests
- state-changing APIs

Use database uniqueness and application-level idempotency where appropriate.

---

# 37. Concurrency

Meaningful concurrent updates require backend validation.

Use:

- version fields
- updated_at
- revision/context validation
- optimistic concurrency where appropriate

Do not silently use last-write-wins for important conflicting operations.

---

# 38. Testing

Testing must be continuous.

Use multiple layers:

Unit
Repository
Integration
API
Workflow
Security
Failure
Analytical validation

Critical areas include:

- authentication
- authorization
- CSRF
- session lifecycle
- file security
- rate limits
- GoodWe failure/recovery
- weather failure
- bill extraction failure
- prediction failure/fallback
- Bedrock failure
- report failure
- export failure
- duplicate requests
- concurrent changes
- deterministic energy balance
- deterministic cost analysis

Prediction evaluation is separate from application correctness testing.

---

# 39. Demo/Test Mode

Demo/Test uses the same core architecture.

Simulated inputs must enter through defined boundaries.

Demo/Test data must be clearly marked:

DEMO
SIMULATED

Demo/Test data must not contaminate real household history.

Scenarios should include:

- normal
- low solar
- potential surplus
- potential deficit
- stale data
- disconnected GoodWe
- prediction failure
- AI failure
- recovery

Scenarios must be reproducible.

---

# 40. Documentation

When implementation introduces a concrete technical decision, update the appropriate existing documentation.

Do not create duplicate numbered documents.

Documentation should remain aligned with implementation.

If implementation reveals a genuine architectural conflict:

STOP.

Do not silently change the architecture.

Report:

1. the conflict
2. the affected rule
3. the implementation consequence
4. the proposed options

Wait for architectural direction before changing the locked design.

---

# 41. Git

Git commits represent meaningful stable checkpoints.

Do NOT commit every tiny change.

Before a milestone commit:

- implementation works
- relevant tests pass
- documentation is updated
- no known critical architectural violation remains
- generated/unwanted files are excluded
- secrets are not committed

Commit meaningful milestones such as:

- technical skeleton complete
- authentication complete
- household module complete
- solar integration complete
- bill workflow complete
- analytical engine complete
- intelligence complete
- UI workspace complete
- analytics/reporting complete
- final testing/recovery milestone

Use clear commit messages.

---

# 42. Never Commit Secrets

Never commit:

- .env
- passwords
- API keys
- GoodWe credentials
- AWS credentials
- email provider credentials
- database passwords
- private tokens

Maintain .env.example with safe placeholders.

---

# 43. Agent Working Procedure

For every implementation task:

STEP 1
Read the relevant official documentation.

STEP 2
Inspect the existing implementation.

STEP 3
Identify affected modules/files.

STEP 4
Check dependencies and existing contracts.

STEP 5
Plan the smallest coherent implementation.

STEP 6
Implement incrementally.

STEP 7
Run relevant tests.

STEP 8
Inspect failures and fix them.

STEP 9
Verify security and authorization boundaries.

STEP 10
Verify semantic meaning:
- nature
- status
- quality
- confidence
- provenance
- measurement context

STEP 11
Update documentation if a concrete implementation decision was introduced.

STEP 12
Report:
- what changed
- files changed
- tests run
- results
- known limitations
- next recommended step

STEP 13
Only create a Git commit when the requested meaningful milestone is stable.

---

# 44. Forbidden Shortcuts

Do NOT:

- bypass backend authorization
- trust frontend validation
- put secrets in frontend code
- expose provider credentials
- put bill files in public storage
- use raw SQL string interpolation
- silently overwrite historical analytical records
- fabricate missing measurements
- turn monthly data into hourly truth
- treat predictions as measurements
- treat potential surplus as confirmed export
- let AI invent authoritative numbers
- let AI override analytical truth
- make Bedrock the prediction engine
- use frontend calculations as authoritative
- fake operation progress
- silently retry forever
- silently swallow failures
- silently change locked architecture
- introduce frontend or backend technologies outside the locked stack
  without explicit owner approval
- use Express or any Node.js application server as the SPMS backend
- move business logic, database access, or secrets into React
- create duplicate documentation
- commit secrets
- commit unstable milestones

---

# 45. Architecture Change Protocol

If an implementation problem appears to require changing a locked decision:

DO NOT immediately change it.

First report:

ARCHITECTURAL CONFLICT

Then provide:

- Locked rule involved
- Current implementation requirement
- Why the conflict exists
- Possible solutions
- Impact of each solution

Only implement an architectural change after explicit approval.

---

# 46. Definition of Done

A feature is not considered complete merely because its UI exists.

A meaningful feature should have, where applicable:

- database support
- domain representation
- repository
- service/business logic
- controller/API
- authorization
- validation
- error handling
- frontend integration
- tests
- failure behavior
- provenance/quality/context handling
- documentation
- stable milestone state

The depth depends on the feature.

---

# 47. Final Principle

SPMS is not merely a dashboard.

It is a trustworthy energy-management system.

The implementation must preserve this chain:

DATA
→ QUALITY + PROVENANCE
→ PREDICTION
→ ENERGY BALANCE
→ COST / IMPACT
→ INTELLIGENCE
→ INSIGHT
→ RECOMMENDATION
→ NOTIFICATION

while preserving:

- uncertainty
- measurement limitations
- historical truth
- security
- user control
- graceful degradation
- explainability
- traceability

The agent implements.

The architecture remains controlled.

When uncertain, inspect the documentation first.

When architecture conflicts, stop and report.

When a milestone is stable, test it and commit it.