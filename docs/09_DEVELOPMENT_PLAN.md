# 09 — Development Plan

**Status:** Phase 6 complete / implementation-ready  
**Current milestone:** Transition from architecture to implementation

---

# 1. Development Strategy

SPMS is implemented incrementally from technical foundation toward complete domain functionality.

The governing rule is:

> Implement the locked architecture incrementally, verify each meaningful boundary, preserve the established semantic/security/analytical rules, document concrete implementation decisions, test continuously, and commit stable milestones.

Development should not proceed as random page-by-page coding.

---

# 2. Completed Planning Phases

```text
Phase 1 — Project Foundation              COMPLETE
Phase 2 — Functional Blueprint             COMPLETE
Phase 3 — Webpage/User Flow Blueprint     COMPLETE
Phase 4 — UI Design System                COMPLETE
Phase 5 — Technical Architecture         COMPLETE
Phase 6 — Implementation Foundation      COMPLETE
```

Phase 6 contains:

```text
Q281–Q670
390 decisions
ALL LOCKED
```

---

# 3. Immediate Implementation Foundation

The first implementation objective is a working technical skeleton.

```text
Browser
   ↓
Apache
   ↓
public/index.php
   ↓
Bootstrap
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
JSON API
   ↓
Browser
```

This is the first meaningful Git checkpoint.

---

# 4. Implementation Foundation Milestones

## Milestone 1 — Environment Verification

Verify:

- XAMPP
- Apache
- PHP 8.4+
- MySQL
- Composer
- required PHP extensions
- local project path
- environment configuration

---

## Milestone 2 — Project Structure

Create the agreed structure:

```text
public/
app/
config/
database/
storage/
tests/
```

with domain/application/integration/support responsibilities.

---

## Milestone 3 — Composer and Autoloading

Configure:

- Composer
- PSR-4
- `SPMS\` namespace
- lockfile

---

## Milestone 4 — Configuration

Create centralized configuration using environment-specific values.

Secrets remain outside source control.

Environments:

```text
Development
Demo
Testing
Production
```

---

## Milestone 5 — Bootstrap

Bootstrap establishes:

- autoloading
- configuration
- error handling
- core infrastructure
- shared services

---

## Milestone 6 — Router and Front Controller

Implement:

```text
public/index.php
```

and centralized routing.

Use explicit HTTP methods.

---

## Milestone 7 — Request/Response Layer

Implement:

- request representation
- validation boundary
- standardized JSON responses
- stable error codes
- meaningful HTTP status

---

## Milestone 8 — Controller → Service → Repository

Create the first complete backend flow.

The controller must delegate business behavior.

The service owns application behavior.

The repository owns database persistence.

---

## Milestone 9 — MySQL Connection

Implement PDO configuration and a safe database connection.

Use prepared statements.

---

## Milestone 10 — First Migration

Create the first version-controlled SQL migration.

Do not create the entire final schema before the corresponding domain is implemented.

Build the database incrementally.

---

## Milestone 11 — First API

Create one small end-to-end API endpoint proving:

```text
HTTP
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
JSON
```

---

## Milestone 12 — Verification

Test:

- normal request
- validation failure
- unauthorized access
- database failure
- safe error response
- correct JSON envelope
- correlation ID/logging behavior where applicable

---

## Milestone 13 — Git Checkpoint

Once the skeleton is genuinely working and verified:

```text
Git Stable Checkpoint #1
```

This is a meaningful milestone and should be committed.

Do not create commits for every tiny edit.

---

# 5. Domain Implementation Sequence

After the technical skeleton:

```text
Authentication / Account Lifecycle
        ↓
Household / Location
        ↓
Solar / GoodWe
        ↓
Bills / Extraction / Verification
        ↓
Environmental Data
        ↓
Prediction
        ↓
Energy Balance
        ↓
Cost
        ↓
Intelligence / Bedrock
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

The exact sub-order may adapt to dependency constraints.

---

# 6. Testing Strategy

Testing occurs continuously rather than only at the end.

Layers:

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

---

# 7. Demo/Test Development

Demo/Test scenarios use the real architecture with controlled simulated inputs.

Required scenarios include:

- normal
- low solar
- potential surplus
- potential deficit
- stale data
- GoodWe unavailable
- prediction failure/fallback
- Bedrock unavailable
- recovery/reconciliation

Simulated data remains explicitly marked and cannot contaminate real household history.

---

# 8. Documentation During Development

Relevant numbered Markdown documentation must be updated alongside meaningful implementation changes.

At minimum, implementation decisions should be reflected in:

```text
05_DATABASE_BLUEPRINT.md
06_API_BLUEPRINT.md
07_TECHNICAL_ARCHITECTURE.md
08_SECURITY_RULES.md
09_DEVELOPMENT_PLAN.md
10_DECISION_LOG.md
```

The UI/design specifications remain in:

```text
04_UI_DESIGN_SYSTEM.md
```

---

# 9. Git Strategy

Git commits represent meaningful stable checkpoints.

Examples:

```text
Checkpoint 1 — Technical skeleton working
Checkpoint 2 — Authentication foundation working
Checkpoint 3 — Household lifecycle working
Checkpoint 4 — Solar integration working
...
```

The exact commit sequence can evolve with implementation.

---

# 10. Architectural Conflict Rule

If implementation conflicts with a locked decision:

```text
Do not silently bypass it.
```

Instead:

```text
Conflict
 ↓
Stop at relevant boundary
 ↓
Document
 ↓
Evaluate
 ↓
Explicitly decide
 ↓
Update documentation
 ↓
Continue
```

---

# 11. Phase Completion Rule

A meaningful implementation milestone is complete only when:

- implementation exists
- relevant tests pass
- documentation is updated
- architecture remains consistent
- security boundaries remain intact
- the resulting state is reviewable/stable

---

# 12. Phase 6 Final State

```text
Q281–Q670
390 LOCKED DECISIONS
        ↓
Architecture
        ↓
Implementation Foundation
        ↓
READY
```

The next development activity is the first technical skeleton, not feature-heavy UI coding.
