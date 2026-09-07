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

---

# 13. M0 Implementation Record

**Status:** Implemented — pending owner review
**Scope:** Implementation foundation only; no business modules.

## 13.1 Environment

| Component | Verified version | Location |
|---|---|---|
| PHP (CLI) | 8.5.10 | `C:\php85\php.exe` (standalone, XAMPP untouched) |
| Composer | 2.10.3 | `C:\ProgramData\ComposerSetup\bin\composer.phar` |
| MariaDB | 10.4.x (XAMPP) | started standalone from `C:\xampp\mysql\bin\mysqld.exe` |

PHP 8.5 exceeds the locked 8.4 minimum. No framework and no Composer
packages are used; Composer provides PSR-4 autoloading only.

## 13.2 Concrete implementation decisions

- **Namespace:** PSR-4 `App\ → app/` (autoloader generated via
  `composer dump-autoload -o`; tests map via `autoload-dev` `Tests\ → tests/`).
- **Configuration:** minimal built-in `.env` parser (`App\Support\Env`)
  loaded by `App\Support\Config` — no dotenv package required. `.env`
  remains untracked; `.env.example` documents all keys.
- **Front controller:** `public/index.php` only bootstraps the autoloader,
  enables the built-in-server static-file passthrough, and calls
  `App::create()->run()`. No business logic.
- **Bootstrap (`App\App`):** config initialization, timezone, error
  display policy (`display_errors` on only in `development`), logger
  construction, request/correlation ID generation, route registration,
  centralized exception → JSON error mapping.
- **Router (`App\Support\Router`):** centralized, explicit-method
  (GET/POST/PUT/PATCH/DELETE), no business logic; unknown paths throw
  `NotFoundException` (404), known path with wrong method throws
  `MethodNotAllowedException` (405 + `Allow`/`meta.allow`).
- **Layering for health:** Controller (`HealthController`, HTTP only) →
  Service (`HealthService`, health orchestration/state classification) →
  Repository (`HealthRepository`, `SELECT 1` ping). No artificial layers.
- **JSON envelope (`App\Support\Response`):** every API response is
  `{success, data, error, meta}`; `meta.request_id` and `X-Request-Id`
  header carry the correlation ID; HTTP status is meaningful
  (200/404/405/500/503).
- **Errors (`App\Support\AppException`):** stable error codes with safe
  messages; unexpected exceptions log full diagnostics and return a
  generic `INTERNAL_ERROR` 500; database failures never expose SQL/DSN/
  credentials/paths.
- **Database (`App\Support\Database`):** lazily constructed shared PDO,
  `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, utf8mb4, env-driven
  credentials.
- **Logging (`App\Support\Logger`):** JSON lines in `storage/logs/`
  (outside public root) with timestamp, level, request ID, redacted
  context; sensitive keys auto-redacted.
- **Migrations:** `database/migrate.php` applies `database/migrations/*.php`
  in filename order inside transactions and records applied IDs in the
  `migrations` table. M0 creates only the tracking table — no invented
  domain tables.
- **Tests:** plain PHP runner `tests/run-tests.php` (no test framework);
  suites Unit / Integration / API / Security / Workflow.

## 13.3 Verification results

- `php tests/run-tests.php` → **27/27 checks pass**
  (Unit 14, Integration 6, API 2, Security 5).
- Live HTTP (built-in server) verified: `/api/health` 200 healthy,
  404 unknown route, 405 wrong method, 503 degraded with DB stopped,
  recovery to 200 after DB restart, `X-Request-Id` present, no internal
  details in any response body.

## 13.4 Local run / database / health testing

- Run: `php -S 127.0.0.1:8080 -t public public/index.php`
- DB config: `.env` (see `.env.example`); the migration runner creates the
  database if missing.
- Migrations: `php database/migrate.php` (`--status` to inspect).
- Health: `curl http://127.0.0.1:8080/api/health`.

## 13.5 Known limitations

- Apache rewrite rules are not yet configured; the PHP built-in server is
  the verified development path (`index.php` also works as URL prefix).
- M0 has no authentication, CSRF, or rate limiting — these arrive with
  the first protected operations (authentication milestone), not needed
  for a public health read.
- MariaDB was started manually for this session; a service must be running
  for database-backed checks.
- `composer.lock` is not yet generated (no dependencies); it will be
  committed once the first real dependency is added or via
  `composer install` in the deployment environment.
