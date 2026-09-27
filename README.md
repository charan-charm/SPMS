# SPMS — Solar Power Management System

Household solar energy management and decision-support system.

Documentation of record: `docs/01_PROJECT_FOUNDATION.md` … `docs/12_FRONTEND_DESIGN_SYSTEM.md`.
Agent contract: `AGENT_INSTRUCTIONS.md`.

## Architecture

```text
React SPA (frontend/, Vite + Tailwind + React Router)
        ↓
centralized API client (fetch)
        ↓
PHP REST JSON API (/api/v1)
        ↓
public/index.php → Router → Controller → Service → Repository
        ↓
PDO / MySQL (MariaDB)
```

The PHP backend is authoritative for business rules, analytical results,
authentication/authorization, data quality, provenance, and lifecycle.
React is the presentation layer and an untrusted client
(`docs/11_FRONTEND_ARCHITECTURE.md`, `docs/12_FRONTEND_DESIGN_SYSTEM.md`).

## M0 — Backend Foundation (committed)

M0 proves the backend skeleton only:

```text
HTTP request
  → public/index.php (front controller)
  → App\ bootstrap (config, error handling, request ID)
  → central Router
  → Controller → Service → Repository
  → PDO / MySQL (MariaDB)
  → centralized JSON response envelope
```

No business modules are implemented at this stage. The only endpoint is the health API.

## M1.0 — Landing Page (transitional PHP prototype)

The current PHP-rendered landing page is a **transitional visual
prototype/reference** built before the locked React frontend direction.
It is not the final frontend implementation.

| Route | Description |
|---|---|
| `GET /` | Public landing page prototype (hero, workflow, bills, analytics preview, AI recommendations, energy balance, cost, features, data trust, personalization, about, CTA) |
| `GET /login` | Public placeholder — authentication is a later milestone |
| `GET /register` | Public placeholder — registration is a later milestone |
| `GET /privacy` | Public privacy commitment summary |
| `GET /contact` | Public contact placeholder |
| `GET /api/health` | M0 health API (JSON) |

This prototype demonstrates the approved UX (calm/premium green+teal,
light/dark themes, illustrative-only data, accessibility) and serves as
the visual reference for the React rebuild.

## Frontend Plan (React — locked F0 direction)

```text
F1 — React frontend foundation (React + Vite + Tailwind + Router + API client)
F2 — Design system foundation
F3 — Application shell
F4 — React ↔ PHP API boundary (/api/v1)
F5 — React M1.0 landing page (rebuilds the PHP prototype)
```

Node.js is frontend build tooling only. Express is not the SPMS backend.

## Requirements

- PHP 8.4+ (verified environment: PHP 8.5.10 CLI at `C:\php85`)
- Composer (verified: 2.10.3)
- MySQL-compatible server (verified: XAMPP MariaDB 10.4.x)
- PDO + pdo_mysql extensions enabled

## Configuration

1. Copy `.env.example` to `.env` (already excluded from version control).
2. Adjust values as needed:

```text
APP_ENV=development
APP_URL=http://localhost:8080
APP_TIMEZONE=UTC
APP_LOG_LEVEL=debug

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=spms
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
```

The database itself does not need to be created manually — the migration
runner creates it if missing.

## Run locally (development)

Using the PHP built-in server (no Apache configuration required):

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

Then verify:

```bash
curl http://127.0.0.1:8080/api/health
```

Expected (database available):

```json
{
  "success": true,
  "data": {
    "overall": "healthy",
    "application": { "state": "available" },
    "database": { "state": "connected" },
    "checked_at": "2026-09-07T11:21:38Z"
  },
  "error": null,
  "meta": { "request_id": "..." }
}
```

When the database is unavailable the endpoint responds `503` with
`data.database.state = "unavailable"` and `overall = "degraded"` — health
status is never faked.

## Migrations

```bash
php database/migrate.php            # apply pending migrations
php database/migrate.php --status   # show applied/pending migrations
```

Migrations live in `database/migrations/`, are applied in filename order,
are recorded in the `migrations` tracking table, and run inside a
transaction. M0 ships no domain migrations — the runner creates only the
tracking table.

## Tests

```bash
php tests/run-tests.php         # all suites
php tests/run-tests.php Unit    # one suite: Unit|Integration|API|Security
```

## Logs

JSON-line logs are written to `storage/logs/` (outside the public web
root), including timestamp, level, request/correlation ID, and redacted
context. Sensitive keys (password, token, secret, credential, api_key,
authorization) are automatically redacted.
