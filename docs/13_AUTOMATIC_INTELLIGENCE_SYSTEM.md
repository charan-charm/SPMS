# SPMS Automatic Intelligence, Weather-Aware Analysis & Recommendation Orchestration System

## 1. Overall Intelligence Pipeline Architecture

SPMS automatically evaluates incoming telemetry, NWP weather forecasts, physics-based solar generation forecasts, verified utility bills, and energy balance to synthesize actionable insights and prioritized recommendations without requiring user-initiated queries.

```
       [ GoodWe Telemetry ]   [ Open-Meteo Weather ]   [ Verified APSPDCL Bills ]   [ Physics Day-Ahead Forecast ]
                │                       │                          │                              │
                └───────────────────────┼──────────────────────────┼──────────────────────────────┘
                                        ▼
                         [ DataReadiness & Provenance Evaluator ]
                                        │
                                        ▼
                         [ Condition Detector Registry ]
                         ├─ WeatherConditionDetector
                         ├─ SolarHardwareDetector
                         ├─ SolarPatternDetector
                         ├─ EnergyMismatchDetector
                         └─ DataQualityDetector
                                        │
                                        ▼
                         [ Cross-Source Correlation & Synthesis ]
                                        │
                                        ▼
                         [ Idempotent Insight Upsert & Dedup ]
                                        │
                                        ▼
                         [ Recommendation Engine & Suppression ]
                                        │
                                        ▼
                         [ Lifecycle Management (Expire/Resolve) ]
                                        │
                                        ▼
                     [ Persistence: Database & Audit Run Logs ]
                                        │
                                        ▼
           [ User Interfaces: Dashboard, Insights, Recommendations, SolarAI ]
```

---

## 2. Existing Modules Reused

The automatic pipeline directly connects and reuses authoritative core modules without duplicating logic:
1. **Solar Telemetry (`SolarReadingRepository`, `SolarService`)**: Telemetry records and read-time freshness bounds (15-minute STALE threshold).
2. **Solar Forecast (`SolarForecastModel`, `ForecastService`)**: Physics-based clear-sky model with NWP irradiance integration.
3. **Weather Feed (`WeatherService`, `OpenMeteoWeatherProvider`, `FakeWeatherProvider`)**: Direct NWP hourly feed normalized to standard contracts.
4. **Utility Bills (`BillRepository`)**: Verified billing periods, unit consumption, and tariff relationships under strict trust boundaries.
5. **Energy Balance & Cost (`EnergyBalanceService`, `EnergyCostService`)**: Day-ahead energy balance classification and tariff calculation.
6. **SolarAI (`SPMSContextBuilder`, `AssistantPrompt`)**: Reads persisted insights and recommendations through authoritative domain accessors.

---

## 3. Detector Catalogue & Evidence Contracts

All detectors adhere to the typed `InsightDetectorInterface` returning candidates with explicit structured evidence, confidence, and deduplication keys:

| Detector | Type / Key | Category | Condition & Threshold | Data Nature | Actionable Next Step |
|---|---|---|---|---|---|
| **WeatherConditionDetector** | `WEATHER_PLANNING_WINDOW` | `WEATHER` | Daylight irradiance > 350 W/m², cloud < 40%, duration >= 3 hrs | `PREDICTED` | Shift flexible electricity loads to midday window (10:00 - 15:00) |
| **WeatherConditionDetector** | `CLOUDY_PRODUCTION_RISK` | `WEATHER` | Daylight mean cloud cover >= 70% | `PREDICTED` | Review expected yield shortfall; conserve grid-dependent loads |
| **WeatherConditionDetector** | `WEATHER_VARIABILITY_RISK` | `WEATHER` | Precipitation probability > 50% or cloud variance >= 0.15 | `PREDICTED` | Anticipate solar generation fluctuations; avoid critical load timing |
| **WeatherConditionDetector** | `WEATHER_DATA_UNAVAILABLE` | `WEATHER` | Weather provider timeout, error, or unconfigured location | `ACTUAL` | Informational: regional weather forecast is temporarily unavailable |
| **SolarHardwareDetector** | `SOLAR_TELEMETRY_STALE` | `SOLAR` | Last inverter telemetry reading > 15 minutes old | `ACTUAL` | Inspect GoodWe inverter Wi-Fi / network communication status |
| **SolarHardwareDetector** | `INVERTER_DISCONNECTED` | `SOLAR` | Connection status `OFFLINE`, `DISCONNECTED`, or inverter fault | `ACTUAL` | Check AC breaker, inverter power switch, and GoodWe status indicators |
| **SolarPatternDetector** | `RECURRING_SOLAR_PATTERN` | `SOLAR` | Recurring midday surplus over historical evaluation window | `ACTUAL` / `SIMULATED` | Align daytime appliance schedules with recurring generation hours |
| **EnergyMismatchDetector** | `SOLAR_DEMAND_MISMATCH` | `ENERGY` | Incongruence between midday solar peak and evening demand | `ESTIMATED` / `SIMULATED` | Pre-cool or run heavy appliances before solar decline |
| **DataQualityDetector** | `DATA_LIMITATION` | `GENERAL` | Telemetry missing, < 3 days history, or degraded baseline | `ACTUAL` | Await telemetry synchronization before relying on automated advice |

---

## 4. Weather Provider Configuration, Freshness & Caching

- **Provider**: Open-Meteo NWP hourly API (latitude/longitude coordinates resolved from household location label via `OpenMeteoGeocodingClient`).
- **Location Resolution**: Robust parsing handles Indian regional addresses (e.g., `Kodumur / Andhra Pradesh`).
- **Caching**: Forecasts are cached with 1-hour TTL. Forecasts fetched > 1 hour ago are marked `STALE`.
- **Probabilistic Treatment**: Weather is treated as probabilistic. Cloud cover is never converted directly into exact solar generation reduction without physics-model backing.
- **Fail-Safe & Degradation**: In the event of network timeout, HTTP 429, or unresolvable location, the provider yields an honest `FALLBACK` with zero fabricated data.

---

## 5. Forecast-versus-Actual Semantics & Data Trust

SPMS enforces strict distinction across 7 data nature classifications:
- **`ACTUAL`**: Directly observed inverter readings, telemetry timestamps, and verified connection states.
- **`VERIFIED`**: Explicitly reviewed and accepted APSPDCL electricity bills.
- **`ESTIMATED`**: Derived values from household consumption models and demand baselines.
- **`PREDICTED`**: Day-ahead physics and weather forecasts with explicit uncertainty ranges.
- **`SIMULATED`**: Deterministic scenario data generated in `DEMO_TEST` mode.
- **`STALE`**: Telemetry older than 15 minutes or weather older than 1 hour.
- **`UNAVAILABLE`**: Missing records; never represented as zero.

---

## 6. Automatic Execution & Scheduling Setup

### Local XAMPP / Windows Task Scheduler:
Run via PHP CLI at 5-minute intervals:
```powershell
# Single pass
C:\Users\ADMIN\php84\php.exe D:\SPMS\scripts\orchestrate-intelligence.php

# Continuous daemon mode (runs every 300 seconds)
C:\Users\ADMIN\php84\php.exe D:\SPMS\scripts\orchestrate-intelligence.php --daemon --interval=300
```

To configure in Windows Task Scheduler:
1. Action: `Start a program`
2. Program/script: `C:\Users\ADMIN\php84\php.exe`
3. Add arguments: `D:\SPMS\scripts\orchestrate-intelligence.php`
4. Trigger: `At log on`, repeat every `5 minutes` indefinitely.

### Production (Linux / systemd / cron):
```cron
# /etc/cron.d/spms-intelligence
*/5 * * * * www-data /usr/bin/php /var/www/spms/scripts/orchestrate-intelligence.php >> /var/log/spms/orchestrator.log 2>&1
```

---

## 7. Insight Deduplication & Lifecycle Management

- **Deduplication Identity**: Uniquely determined by `(household_id, category, type, dedup_key)`.
- **First-Detected & Last-Evaluated**: Tracks when condition was first detected and updates timestamp on subsequent passes without creating duplicate rows.
- **Auto-Expiration**: Active insights with `period_end < NOW()` automatically transition to `EXPIRED` via `InsightRepository::expirePast()`.
- **Auto-Resolution**: Active insights whose conditions no longer apply transition to `RESOLVED` via `InsightRepository::resolveCondition()`.
- **Recommendation Deduplication**: Actionable recommendations are matched by `(household_id, type)`. Unchanged findings update timestamps without spamming users.

---

## 8. Real vs Demo Environment Isolation

- **Real Database (`spms_real`)**: Contains authentic household telemetry, verified APSPDCL bills, and real Open-Meteo weather. All records maintain `ACTUAL`, `VERIFIED`, or `PREDICTED` provenance.
- **Demo Database (`spms_demo`)**: Contains scenario households (SCEN-01 through SCEN-05). All records maintain `SIMULATED` provenance.
- **Test Database (`spms_test`)**: Dedicated database for automated test suites.
- **Physical Isolation**: Enforced via `DataEnvironments::connectionConfig()` and `Database::switchTo()`. Scripts and tests cannot cross-contaminate databases.

---

## 9. Test Verification Results

All suites executed with 100% pass rate:
- **Backend Unit Tests**: 393/393 passed (`php tests/run-tests.php Unit`)
- **Backend Integration Tests**: 68/68 passed (`php tests/run-tests.php Integration`)
- **Backend API Tests**: 171/171 passed (`php tests/run-tests.php API`)
- **Backend Security Tests**: 169/169 passed (`php tests/run-tests.php Security`)
- **Backend Workflow Tests**: 127/127 passed (`php tests/run-tests.php Workflow`)
- **Total Backend Tests**: **800 / 800 PASSED**
- **Frontend Vitest Suite**: **472 / 472 PASSED** (`npm test -- --run`)
- **Frontend ESLint**: **0 errors, 0 warnings** (`npm run lint`)
- **Frontend Production Build**: **Successfully built in 1.43s** (`npm run build`)
