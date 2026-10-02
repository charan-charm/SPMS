# SPMS — F8 AI Energy Assistant Specification & Documentation

## 1. Overview & Core Objective

The SPMS AI Energy Assistant is a restricted, context-aware intelligent companion designed to help homeowners interpret and understand their own authenticated Smart Power Management System (SPMS) metrics, telemetry, and forecasts.

It is **NOT** a general-purpose ChatGPT clone or arbitrary chatbot. Its purpose is strictly bounded to explaining verified household facts:
- Solar generation telemetry & inverter connection status
- F3 Machine Learning Day-Ahead forecasts (solar & consumption)
- F4 Energy Balance & potential surplus/deficit status
- F5 Energy cost estimates and uncertainty ranges
- APSPDCL electricity bills and verification provenance
- F6 Automated insights and anomaly observations
- F7 Actionable load-shifting recommendations
- GoodWe/SEMS station & telemetry staleness status
- How SPMS features work and data quality indicators

---

## 2. Non-Negotiable AI Safety Model

1. **The LLM is NEVER the Numerical Authority**:
   - The SPMS backend is the sole, authoritative source of truth for all numerical values (kWh, Watts, INR tariffs, balance, confidence scores, and dates).
   - The LLM explains validated backend facts; it must never recompute, substitute, or invent figures.
   - Example: If the backend determines `solar_forecast = 16.292 kWh`, `consumption_forecast = 2.581 kWh`, and `balance = +13.711 kWh (POTENTIAL_SURPLUS)`, the assistant explains "Your forecast indicates a potential surplus of 13.711 kWh". It never calculates an alternate balance.

2. **Strict Distinction Between Data Natures**:
   - **ACTUAL**: Real inverter telemetry readings and verified APSPDCL bill figures.
   - **PREDICTED**: F3 solar and consumption ML day-ahead forecasts.
   - **ESTIMATED**: F5 future variable energy costs.
   - **SIMULATED**: DEMO mode test scenario data.
   - Predicted values are never presented as actual measurements.

3. **Advisory-Only / Zero Physical Actuation**:
   - SPMS provides analytical guidance. The assistant has no ability or authorization to switch relays, adjust inverter settings, modify thermostats, or command appliances.

4. **Information Availability Honesty**:
   - If telemetry, bills, or forecasts are missing or unverified, the assistant states clearly and honestly that the data is unavailable.

---

## 3. Backend Architecture & Pipeline

All assistant endpoints are strictly authenticated and guarded against cross-household access:

```
User Query
    ↓
Authentication (Session & Account derivation)
    ↓
Household Authorization (Active household scope verification)
    ↓
Domain & Intent Classification (Detect domains from message & page_context)
    ↓
SPMS Context Builder (Build minimal authoritative factual context)
    ↓
AI Provider Gateway (Sequential invocation: Gemini → Groq → OpenRouter)
    ↓
Response Validator (Security patterns, device control, numerical contradiction guards)
    ↓
Safe API Response Envelope
```

### Endpoints
- `POST /api/v1/assistant`: Send a question to the assistant (Authenticated, CSRF-protected).
- `GET /api/v1/assistant`: Retrieve recent conversation history for the active household.
- `DELETE /api/v1/assistant`: Clear conversation history for the active household (CSRF-protected).

---

## 4. Provider Abstraction & Sequential Fallback

The backend implements `AIProviderInterface` with three production providers and a deterministic test mock:

1. **Primary: Google Gemini** (`GeminiProvider`):
   - Targets Google Gemini API (`gemini-1.5-flash` default).
   - Uses server-side API key `GEMINI_API_KEY`.
2. **Fallback 1: Groq Cloud** (`GroqProvider`):
   - Targets Groq Cloud OpenAI-compatible endpoint (`llama-3.3-70b-versatile` default).
   - Fast, low-latency fallback on Gemini timeout, rate limit, or outage.
3. **Fallback 2: OpenRouter** (`OpenRouterProvider`):
   - Targets OpenRouter (`meta-llama/llama-3.3-70b-instruct` default).
   - Deep fallback for high reliability.
4. **Test Fixture: FakeAIProvider** (`FakeAIProvider`):
   - Deterministic offline responses and failure simulation for continuous automated testing without outbound HTTP traffic or API costs.

### Fallback Behavior
- Fallback is triggered **only** for operational/provider failures: HTTP 429 (Rate Limit), HTTP 504 (Timeout), HTTP 503 (Provider Unavailable), or network errors.
- Fallback is **never** used for client validation errors, unauthorized requests, or out-of-scope questions.

---

## 5. SPMS Context Builder & Context Minimization

`SPMSContextBuilder` constructs a concise, structured factual context without dumping database internals:

- **Dashboard Domain**: Current solar generation, today's total, telemetry timestamp, staleness flag, connection status.
- **Forecast Domain**: Horizon, solar forecast kWh, consumption forecast kWh, balance classification (`POTENTIAL_SURPLUS` / `POTENTIAL_DEFICIT`), model version, confidence level, limitations.
- **Energy Balance Domain**: Net balance, period start/end, actual vs. predicted distinction.
- **Cost Domain**: Estimated variable cost, fixed charges, cost range, pricing basis.
- **Bills Domain**: Latest verified bill period, units consumed, billed amount, validation status.
- **Recommendations Domain**: Top 3 active recommendations, actions, priorities, and evidence.
- **GoodWe Domain**: Station ID, inverter status code, connection status, data lag.

### Privacy Invariants
Context serialization strictly excludes:
- Passwords and password hashes
- GoodWe credentials and bearer tokens
- Session tokens and CSRF secrets
- Encryption keys and internal database connection strings
- Unrelated household accounts

---

## 6. Server-Side Response Validator

`AssistantResponseValidator` inspects the raw output from the AI provider before sending it to the client:
1. **Secret & Key Leak Detection**: Matches and blocks API key patterns (`AIza...`, `gsk_...`, `sk-or-...`, JWT tokens, 64-char hex strings, `.env`, `DB_PASSWORD`).
2. **Device Control Claim Guard**: Detects and annotates hallucinated physical appliance switching claims.
3. **Numerical Contradiction Guard**: Compares numbers cited by the LLM against the authoritative balance and solar forecast in the backend context. If a contradiction exceeds threshold (> 2 kWh and > 30% error), an authoritative correction note is appended.
4. **Grid Export Claim Guard**: Rejects unverified monetary compensation claims when no net metering agreement is recorded.

---

## 7. Frontend Integration

- **Trigger Buttons**:
  - Top header `Assistant` button with Sparkles icon in `AppHeader`.
  - Floating bottom-right `Energy Assistant` pill on authenticated pages.
- **Slide-in Drawer (`AssistantDrawer.jsx`)**:
  - Displays environment badge: `REAL HOUSEHOLD` or `DEMO SIMULATION`.
  - Displays scope tag (e.g. `Context: Forecasts`, `Context: Dashboard`).
  - Welcome card with guided explanation of backend authority.
  - Suggested question chips:
    - *"Why do I have a potential surplus?"*
    - *"Explain my latest recommendation"*
    - *"Why is my solar data stale?"*
    - *"How accurate is my forecast?"*
    - *"Explain my latest bill"*
    - *"What does my energy balance mean?"*
  - Provenance badges on each response: Data Nature (`PREDICTED`, `ACTUAL`, `ESTIMATED`, `SIMULATED`), Confidence, Provider, and Fallback indicator.
  - Error state with Retry button.
  - Clear conversation action.

---

## 8. Configuration & Environment Variables

```env
# Primary Provider
AI_PRIMARY_PROVIDER=gemini

# Provider API Keys (Server-Side Only - NEVER exposed to React client)
GEMINI_API_KEY=...
GEMINI_MODEL=gemini-1.5-flash

GROQ_API_KEY=...
GROQ_MODEL=llama-3.3-70b-versatile

OPENROUTER_API_KEY=...
OPENROUTER_MODEL=meta-llama/llama-3.3-70b-instruct

# Sequential Fallback Control
AI_FALLBACK_ENABLED=true
```

---

## 9. Verification & Test Suite Summary

- **Backend Suites**: 665/665 tests passing (`php tests/run-tests.php`).
  - Unit tests: 315 passing (including `AssistantSafetyAndIsolationTest`).
  - Integration tests: 64 passing (including Migration 0026).
  - API tests: 130 passing (including `AssistantApiTest`).
  - Security tests: 85 passing.
  - Workflow tests: 71 passing.
- **Frontend Suites**: 229/229 tests passing (`npm test -- --run` in `frontend`).
  - Including 9 tests in `AssistantDrawer.test.jsx`.
- **Lint**: Passed with 0 errors and 0 warnings (`npm run lint`).
- **Production Build**: Built in 4.05s (`npm run build`).
