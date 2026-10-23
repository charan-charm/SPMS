# SPMS Technical Evidence & Specification Inventory

> **Note for Academic Documentation:**  
> This document records verified, empirical facts, architecture diagrams, schema specifications, route inventories, and test results from the finalized Solar Power Management System (SPMS) codebase (`D:\SPMS`).  
> **Generation of the final academic report is explicitly paused awaiting the user's reference PDF/template.** Once provided, this evidence will be formatted directly into the institution's expected structure without inventing requirements.

---

## 1. System Overview & Technology Stack

| Tier | Component | Technology / Version | Role & Notes |
| :--- | :--- | :--- | :--- |
| **Frontend** | Single Page Application | **React 19**, **Vite 6** | Modern component-based reactive dashboard with Tailwind & Vanilla CSS tokens |
| **Frontend Routing** | Router | **React Router DOM 7** | Client-side routing with authentication guards (`ProtectedRoute`, `PublicAuthRoute`) |
| **Frontend State** | State Management | React Context + Custom Hooks | Isolated feature contexts (`AuthContext`, `HouseholdContext`, `SolarContext`) |
| **Visualization** | Charting & UI Icons | **Recharts**, **Lucide React** | Production energy curves, solar generation vs consumption, peak analysis |
| **Backend API** | REST API Server | **PHP 8.4** (Strict Types) | PSR-compliant architecture, zero external framework overhead, front-controller |
| **Database** | Relational Store | **MySQL 8.0** / MariaDB | ACID transactional schema, InnoDB engine, utf8mb4 charset, strict foreign keys |
| **Solar Telemetry**| Hardware Inverter | **GoodWe SEMS Portal API** | Live inverter station telemetry, 5-minute sampling interval, `GoodWeSemsPortalAdapter` |
| **Meteorology** | Weather Service | **Open-Meteo API** | Solar irradiance, temperature, cloud cover, day-ahead solar forecasting |
| **Bill Intelligence**| Document Analysis | **Mistral AI Vision / OCR** | Multi-modal electricity bill intake, tariff validation, and human-in-the-loop review |
| **Cryptography** | Secrets & Sessions | **AES-256-GCM (AEAD)** | Hardware credentials and session tokens encrypted at rest; Argon2id / bcrypt hashing |
| **Target Cloud** | Cloud Hosting | **Amazon Web Services (AWS)** | Amazon EC2 (Ubuntu 24.04 LTS), Nginx reverse proxy, PHP-FPM, UFW firewall |
| **Localization** | Timezone & Currency | `Asia/Kolkata` (IST, UTC+5:30), **INR (₹)** | Strict timezone conversion preserving hardware timestamps |

---

## 2. Verified High-Level Architecture

```
                                  ┌───────────────────────────────┐
                                  │      GoodWe SEMS Portal       │
                                  │   (Solar Inverter Telemetry)  │
                                  └───────────────┬───────────────┘
                                                  │ HTTPS (JSON API)
                                                  ▼
┌─────────────────────────┐           ┌───────────────────────────────┐
│       User Client       │           │        SPMS Backend API       │
│  (React 19 + Vite SPA)  │◄─────────►│         (PHP 8.4 REST)        │
│  Port 5173 / Production │ HTTP/JSON │      FastCGI / Nginx Reverse  │
└─────────────────────────┘           └───────┬───────────────┬───────┘
                                              │               │
                                              │               │ HTTPS
                                              ▼               ▼
                              ┌───────────────────────┐ ┌───────────────┐
                              │     MySQL 8.0 RDBMS   │ │  Open-Meteo   │
                              │ (spms_real: 25 tables)│ │ (Solar Irrad.)│
                              └───────────────────────┘ └───────────────┘
```

---

## 3. Database Schema Information (`spms_real`)

The production database schema comprises **25 relational tables** enforcing strict integrity constraints:

| Table Name | Verified Rows | Core Purpose & Foreign Keys |
| :--- | :--- | :--- |
| `accounts` | 11 | User identities, email verification state, password hashes (Argon2id/bcrypt) |
| `account_tokens` | 17 | Time-bound tokens for email verification and password recovery |
| `sessions` | 95 | Active user sessions, user-agent metadata, IP tracking, expiration |
| `households` | 9 | Household tenant entities, primary address, tariff preferences |
| `household_memberships` | 9 | Multi-tenant link: `account_id` ↔ `household_id`, role (`owner`, `member`) |
| `household_solar_connections` | 1 | Encrypted SEMS credentials (`AES-256-GCM`), station ID, sync state |
| `solar_readings` | 41,884 | **100% `ACTUAL` measured inverter telemetry (5-min intervals)**, 0 simulated |
| `bills` | 40 | Household electricity bills (units, tariff, total cost, billing cycle) |
| `bill_temp_documents` | 17 | Uploaded bill documents in temporary private storage pending OCR |
| `bill_extraction_drafts` | 28 | AI-extracted draft parameters from electricity bill documents |
| `bill_field_provenance` | 590 | Extraction confidence scores, bounding boxes, and field provenance |
| `bill_validation_results` | 84 | Sanity and tariff consistency check outcomes |
| `bill_quality_flags` | 6 | Anomaly flags (e.g. unexpected spike, missing meter reading) |
| `household_forecasts` | 100 | Day-ahead and short-term solar generation forecasts |
| `forecast_validations` | 36 | Actual vs predicted generation accuracy evaluations (MAE, RMSE) |
| `forecast_model_evaluations` | 186 | Historical forecast model performance track records |
| `insights` | 26 | Deterministic energy insights (self-consumption, grid dependency) |
| `intelligence_orchestrator_runs`| 3 | Execution log for deterministic intelligence rule engine |
| `recommendations` | 47 | Advisory suggestions (e.g. shift laundry to 11:00 AM peak solar) |
| `recommendation_outcomes` | 71 | Historical outcome tracking for generated recommendations |
| `recommendation_feedback` | 2 | User feedback on recommendation utility (`helpful`, `dismissed`) |
| `assistant_messages` | 30 | SolarAI chat context, prompt/response pairs scoped to household |
| `notifications` | 32 | In-app alerts, telemetry sync reports, threshold warnings |
| `security_events` | 2,570 | Auditing logs (logins, failed auth, CSRF rejections, profile changes) |
| `migrations` | 31 | Schema migration version ledger |

---

## 4. API Route Inventory (65 Endpoints)

### A. System & Page Endpoints
- `GET /api/health` — Health check (Database connectivity, environment status, memory)
- `GET /` — Public landing page
- `GET /login` — Web login entry
- `GET /register` — Web registration entry
- `GET /privacy` — Privacy policy
- `GET /contact` — Contact information

### B. Authentication & Session Management (`/api/v1/auth/*`)
- `POST /api/v1/auth/register` — Create user account with Argon2id hash
- `POST /api/v1/auth/verify` — Verify email via token
- `POST /api/v1/auth/verify/resend` — Resend verification token
- `POST /api/v1/auth/login` — Authenticate and issue secure session cookie
- `POST /api/v1/auth/logout` — Terminate session and invalidate cookie
- `GET  /api/v1/auth/session` — Retrieve current authenticated session state
- `POST /api/v1/auth/recovery/request` — Request password reset token
- `POST /api/v1/auth/recovery/verify` — Validate password reset token
- `POST /api/v1/auth/recovery/confirm` — Set new password using verified token

### C. Household Tenant Management (`/api/v1/household/*`)
- `GET   /api/v1/household` — Retrieve current user's household profile
- `POST  /api/v1/household` — Create new household profile
- `POST  /api/v1/household/demo/ensure` — Ensure demo household exists (Demo profile only)
- `PATCH /api/v1/household` — Update household profile & tariff configuration

### D. Solar Inverter Telemetry (`/api/v1/solar/*`)
- `GET    /api/v1/solar/connection` — Read solar inverter connection status
- `POST   /api/v1/solar/stations/discover` — Discover inverter power stations via SEMS
- `POST   /api/v1/solar/connection/test` — Test GoodWe credentials live
- `POST   /api/v1/solar/connection` — Save & encrypt inverter credentials
- `DELETE /api/v1/solar/connection` — Disconnect solar inverter
- `GET    /api/v1/solar/latest` — Retrieve most recent live solar observation
- `GET    /api/v1/solar/history` — Query historical 5-minute telemetry for date range
- `GET    /api/v1/solar/days` — List dates with available telemetry
- `POST   /api/v1/solar/sync` — Trigger manual telemetry synchronization
- `POST   /api/v1/solar/sync/auto` — Trigger background telemetry synchronization

### E. Electricity Bill Management (`/api/v1/bills/*`)
- `POST  /api/v1/bills/upload` — Secure multipart upload of electricity bill (PDF/JPG/PNG)
- `POST  /api/v1/bills/{bill_id}/process` — Trigger AI/OCR extraction workflow
- `GET   /api/v1/bills` — List bills with pagination and status filters
- `GET   /api/v1/bills/{bill_id}` — Get single bill details with extraction provenance
- `POST  /api/v1/bills/{bill_id}/verify` — Mark bill verified by human user
- `PATCH /api/v1/bills/{bill_id}` — Update bill parameters and corrections
- `POST  /api/v1/bills/{bill_id}/reconcile` — Reconcile bill with solar generation

### F. Forecasting & Weather Intelligence (`/api/v1/forecasts/*`, `/api/v1/weather`)
- `GET  /api/v1/forecasts` — Get active day-ahead solar generation forecast
- `GET  /api/v1/forecasts/validation` — Evaluate forecast accuracy against actual generation
- `GET  /api/v1/forecasts/performance` — Retrieve historical model performance metrics
- `POST /api/v1/forecasts/generate` — Generate day-ahead forecast based on weather model
- `GET  /api/v1/weather` — Current weather conditions & solar irradiance from Open-Meteo

### G. Energy Balance & Cost Analysis (`/api/v1/energy/*`)
- `POST /api/v1/energy/balance` — Classify instant energy balance (Surplus / Deficit)
- `GET  /api/v1/energy/balance` — Current energy situation snapshot
- `POST /api/v1/energy/cost/estimate` — Estimate cost savings from solar generation
- `GET  /api/v1/energy/cost/historical` — Historical financial savings breakdown

### H. Deterministic Intelligence & Recommendations (`/api/v1/intelligence/*`, `/api/v1/recommendations/*`)
- `GET  /api/v1/intelligence/insights` — List rule-based energy insights
- `GET  /api/v1/intelligence/insights/{id}` — Get specific insight explanation
- `GET  /api/v1/recommendations` — List active energy-saving recommendations
- `GET  /api/v1/recommendations/{id}` — Recommendation details and action items
- `POST /api/v1/recommendations/generate` — Trigger rule engine for recommendations
- `POST /api/v1/recommendations/{id}/dismiss` — Dismiss recommendation
- `POST /api/v1/recommendations/{id}/feedback` — Record user satisfaction feedback

### I. Analytics & Reporting (`/api/v1/analytics/*`, `/api/v1/reports/*`)
- `GET /api/v1/analytics/summary` — High-level KPI metrics (generation, self-consumption)
- `GET /api/v1/analytics/monthly` — Monthly generation & consumption totals
- `GET /api/v1/analytics/daily` — Daily generation breakdown
- `GET /api/v1/analytics/day-curve` — Intraday power curve (5-minute resolution)
- `GET /api/v1/reports/summary` — Reporting executive summary
- `GET /api/v1/reports/monthly` — Monthly performance report
- `GET /api/v1/reports/monthly/export/pdf` — Export monthly report to PDF
- `GET /api/v1/reports/monthly/export/excel` — Export monthly report to Excel/CSV
- `GET /api/v1/reports/bills/export` — Export bill history
- `GET /api/v1/reports/solar/export` — Export raw solar telemetry

### J. Notifications & SolarAI Assistant (`/api/v1/notifications/*`, `/api/v1/assistant`)
- `GET    /api/v1/notifications` — In-app notification feed
- `POST   /api/v1/notifications/read-all` — Mark all notifications as read
- `POST   /api/v1/notifications/{id}/read` — Mark single notification as read
- `POST   /api/v1/assistant` — Prompt SolarAI energy assistant
- `GET    /api/v1/assistant` — SolarAI conversation history
- `DELETE /api/v1/assistant` — Clear assistant conversation history

### K. Demo Scenarios (Isolated Demo Profile Only)
- `GET  /api/v1/demo/scenarios` — List available demonstration scenarios
- `POST /api/v1/demo/scenarios/switch` — Switch active scenario (strictly blocked in production)

---

## 5. Verification Test Evidence

### A. Backend PHP Test Suite
- **Command:** `php tests/run-tests.php`
- **Total Tests:** 833
- **Passed:** **833**
- **Failed:** **0**
- **Skipped:** **0**
- **Pass Rate:** **100%**
- **Coverage Areas:**
  - `Tests\Unit\RouterTest` — HTTP routing, methods, 404/405 error envelopes.
  - `Tests\Unit\SecurityTest` — Password hashing, AEAD AES-256-GCM encryption, session isolation.
  - `Tests\Unit\SolarTelemetryTest` — 5-minute sampling, provenance enforcement, duplicate rejection.
  - `Tests\Unit\EnvironmentIsolationTest` — Strict blocking of `FakeSolarProvider` in real profile.
  - `Tests\Workflow\*` — End-to-end integration workflows (Auth, Bill Intake, Sync, Forecasting).

### B. Frontend Vitest Test Suite
- **Command:** `npm run test -- --run` (in `frontend/`)
- **Total Test Files:** 41 passed (41 total)
- **Total Tests:** **498**
- **Passed:** **498**
- **Failed:** **0**
- **Coverage Areas:**
  - UI Navigation & Header / Control Center.
  - Solar Telemetry & Curve Visualizations.
  - Forecast Energy Balance Section.
  - Bill Upload & Verification Modals.
  - Responsive Viewport Testing (including mobile 390 × 844).
  - Empty, Loading, and Stale State UI Indicators.

### C. Frontend Production Build
- **Command:** `npm run build` (in `frontend/`)
- **Output:** `frontend/dist/index.html` (single-file production bundle, 6,299 kB)
- **Build Duration:** 10.53 seconds
- **Verification:** Verified static asset generation and bundle packaging.

---

## 6. Solar Telemetry Data Integrity Verification

- **Contamination Remediation:**
  - 5 simulated demo records and 16 dependent downstream records (forecasts, insights, recommendations, notifications) were purged via an atomic database transaction.
  - SHA-256 pre-cleanup backup verified: `2a3e77a71799daf2f30ef9cdc73b73ab5acdd42fab8f6152a373d16afcedfbcb`.
- **Live GoodWe Telemetry Ingestion:**
  - Executed real GoodWe SEMS synchronization for verified household `hh_0192000000000001`.
  - Ingested **139 genuine 5-minute telemetry observations** spanning the day's solar generation cycle.
  - Final database count: exactly **41,884 readings**.
  - **100% `ACTUAL` measured data; 0 `SIMULATED` records; 0 duplicate timestamps.**

---

## 7. AWS Cloud Deployment Specification ($70 Budget)

### Cost Analysis (Region: `ap-south-1` Mumbai)
- **EC2 Instance:** `t4g.small` (ARM64, 2 vCPU, 2 GB RAM) = $0.0168/hr × 730 hrs = **~$12.26 / month**
- **EBS Storage:** 25 GB gp3 SSD = **~$2.20 / month**
- **Public IPv4 Address:** $0.005/hr × 730 hrs = **~$3.65 / month**
- **Data Transfer:** Outbound internet (first 100 GB/month included free) = **$0.00**
- **Total Estimated Operating Cost:** **~$18.11 / month** (₹0 out of pocket)
- **Financial Runway with $70 Credits:** **~3.85 months (116+ days)** continuous operation.

### Prepared Deployment Artifacts (`deploy/`)
1. `deploy/env/production.env.example` — Hardened production environment configuration template.
2. `deploy/nginx/spms.conf` — Nginx server block with FastCGI proxying, static asset caching, and security header enforcement.
3. `deploy/php/spms-php-fpm.conf` — Tuned PHP-FPM pool configuration.
4. `deploy/systemd/spms-collector.service` & `spms-collector.timer` — Sandboxed systemd service for 5-minute automated telemetry ingestion.
5. `deploy/scripts/setup-ec2.sh` — Automated host initialization script for Ubuntu 24.04 LTS.
6. `deploy/scripts/deploy-app.sh` — Zero-downtime deployment script with pre-flight configuration validation.
7. `deploy/scripts/backup-db.sh` — Automated nightly MySQL database dump with 14-day retention.
8. `deploy/README.md` — Complete operational handover and step-by-step AWS provisioning manual.

---

## 8. Known Limitations & Operating Boundaries

1. **Hardware Telemetry Sampling Resolution:** GoodWe SEMS Cloud API updates data at approximately 5-minute intervals. Intraday real-time telemetry reflects this 5-minute window rather than second-by-second changes.
2. **Weather API Dependency:** Solar forecasting uses Open-Meteo's numerical weather prediction models; heavy sudden localized cloudbursts may cause minor variances between forecasted and actual generation.
3. **Bill Extraction Human-in-the-Loop:** While AI extraction processes bills with high accuracy, human verification (`POST /api/v1/bills/{id}/verify`) is strictly required before bill amounts influence financial savings ledgers.
4. **AWS Infrastructure Provisioning:** Automated remote AWS provisioning requires either AWS CLI credentials or manual instance creation in the AWS Console as documented in `deploy/README.md`.
