# 10 — Decision Log

**Status:** Phase 6 consolidated + F0 frontend direction locked  
**Purpose:** Preserve important architectural and implementation decisions with rationale.

This document records decisions that materially govern implementation.

# 1. Architecture Decisions

## D-001 — XAMPP for Development

Use XAMPP for local development because it provides a practical Apache/PHP/MySQL-compatible environment while the application remains portable to normal PHP hosting.

## D-002 — PHP + MySQL Backend

Keep PHP and MySQL as the backend/data stack. The established backend architecture remains valid.

## D-003 — React Frontend — F0

**Decision:** Use React as the SPMS frontend.

**Rationale:** SPMS requires a dynamic, component-based, responsive interface containing dashboards, analytical charts, interactive forms, notifications, animated states, and reusable UI patterns.

This supersedes the previous PHP-rendered HTML/vanilla-JavaScript presentation direction.

The change affects frontend implementation only. Existing functional requirements, workflows, backend architecture, security principles, analytical authority, provenance, quality, and lifecycle rules remain valid.

## D-004 — Vite Frontend Tooling — F0

Use Vite for React development/build tooling.

Node.js is tooling only. Node.js/Express is not the SPMS backend.

## D-005 — Tailwind CSS — F0

Use Tailwind CSS for frontend styling, constrained by the SPMS design system.

## D-006 — shadcn/ui — F0

Use shadcn/ui where appropriate as a reusable accessible component foundation adapted to SPMS.

## D-007 — Motion — F0

Use Motion for purposeful application animation. Animation remains subtle, consistent, and reduced-motion aware.

## D-008 — Lucide Icons — F0

Use Lucide Icons for consistent professional outline iconography.

## D-009 — Recharts / Approved Chart Library — F0

Use Recharts initially, or another explicitly approved charting library if implementation evidence justifies a change. The chart library is never analytical authority.

## D-010 — React Router — F0

Use React Router for frontend navigation. Backend authorization remains authoritative.

## D-011 — Centralized Fetch API Client — F0

Use native `fetch()` through a centralized React API client to keep transport behavior consistent without unnecessary client frameworks.

## D-012 — Frontend State — F0

Start with React state and Context where genuinely global state is required. Avoid unnecessary global-state complexity.

## D-013 — Frontend/Backend Boundary — F0

```text
React
↓
REST API
↓
PHP
↓
MySQL
```

React owns UI/interaction/visualization. PHP owns business logic/security/validation/database/external integrations.

## D-014 — Public Front Controller

Keep `public/index.php` as the PHP public front controller for backend/API requests.

## D-015 — Backend Authority

Backend/domain/analytical services own authoritative business and numerical calculations.

## D-016 — Semantic API Contracts

APIs expose structured semantic identifiers and contextual metadata. UI labels are not authoritative semantics.

# 2. Data Meaning

## D-017 — Data Nature and Status Are Separate

```text
Nature: ACTUAL / PREDICTED / ESTIMATED / SIMULATED
Status: FRESH / STALE / DEGRADED / UNAVAILABLE
```

## D-018 — Measurement Context

Distinguish measured/actual, inferred, predicted, potential, estimated, and simulated meanings.

## D-019 — Provenance

Track source, time, processing, nature, transformation, model/version, and lineage where required.

# 3. Integrations

## D-020 — GoodWe Is Actual Solar Source

GoodWe remains the primary source of actual solar measurements.

## D-021 — External Services Are Bounded

GoodWe, weather, AWS, Bedrock, and email remain isolated integration dependencies.

## D-022 — Graceful Degradation

External failure degrades affected capabilities without destroying independent valid information.

# 4. AI and Analytics

## D-023 — Bedrock Boundary

Bedrock is an explanation/intelligence capability. It does not define numerical prediction truth.

## D-024 — Authoritative Analytical Pipeline

```text
Validated Evidence
↓
Prediction
↓
Energy Balance
↓
Cost
↓
Validated Facts
↓
Intelligence
↓
AI Explanation
↓
Insight
↓
Recommendation
↓
Notification Decision
```

## D-025 — Recommendation Authority

Recommendations are advisory. SPMS does not directly control appliances.

# 5. Security

## D-026 — Backend Security Authority

The backend is the security authority. React is an untrusted client.

## D-027 — Server-Managed Sessions

Use PHP server-managed sessions with secure cookie controls.

## D-028 — CSRF

Authenticated state-changing browser requests require CSRF protection.

## D-029 — File Security

Private files remain outside the public web root and are accessed through authorized backend paths.

## D-030 — Secrets

Secrets remain server-side and outside source control.

# 6. UI / UX

## D-031 — Calm + Premium Hybrid

SPMS uses a calm, premium, modern energy-tech visual identity.

## D-032 — Green + Teal Application Identity

Application UI uses the established green/teal palette. The logo/brand palette remains separate.

## D-033 — Design System First

Reusable UI foundations must be established before large-scale page implementation.

## D-034 — Adaptive Data Exploration

Q18 remains **C — Adaptive Data Exploration**:

> Make information easy to find without making the interface harder to understand.

No giant universal query interface by default.

## D-035 — Purposeful Motion

Motion communicates state, continuity, hierarchy, or feedback. It does not exist merely for decoration.

## D-036 — Data Visualization Semantics

Charts preserve actual/predicted/estimated/stale/simulated distinctions. Color alone never communicates meaning.

# 7. Development

## D-037 — Incremental Implementation

Implementation proceeds through meaningful slices:

```text
Design
↓
Backend/API where required
↓
Frontend
↓
Integration
↓
Testing
↓
Review
↓
Git checkpoint
```

## D-038 — Coding Agent Role

The coding agent implements approved decisions. It is not the project architect. Architectural conflicts must be reported.

## D-039 — Git Checkpoints

Meaningful stable milestones are committed before moving to the next milestone.

# 8. Frontend Transition Record

The previous presentation direction used PHP-rendered HTML/CSS/vanilla JavaScript.

It is now superseded by:

```text
React
+
Vite
+
Tailwind CSS
+
Motion
+
shadcn/ui
+
Lucide
+
Recharts / approved charting
```

The current M1.0 PHP/HTML landing page is a visual prototype/reference rather than the final frontend implementation.

The M0 backend foundation remains valid.

# 9. Current Locked Frontend Stack

```text
React
Vite
Tailwind CSS
Motion
shadcn/ui
Lucide Icons
Recharts
React Router
fetch()
```

Backend:

```text
PHP
REST JSON API
PDO
MySQL/MariaDB-compatible development database
XAMPP
```

No Node.js/Express application backend.

# 10. Current Next Step

**F1 — React Frontend Foundation**

No domain functionality should be implemented until the frontend foundation/design-system boundary is established.

# 11. Energy Balance — F4 Implementation Decision

## D-040 — Balanced Classification Tolerance — F4

The official project documentation (docs/01 §15.1) defines the balanced state
qualitatively only:

```text
Solar ≈ Consumption → Approximately Balanced
```

The `≈` relation was never numerically defined in any official document.
During the F4 implementation review, the project owner ratified the following
numeric tolerance as the project's concrete implementation decision:

```text
tolerance = max(0.1 kWh, 5% × max(solar_generation_kwh, household_consumption_kwh))
```

Classification (backend-authoritative, docs/01 §15.3, D-015):

```text
abs(solar_generation_kwh - household_consumption_kwh) <= tolerance
    → BALANCED

solar_generation_kwh - household_consumption_kwh > tolerance
    → POTENTIAL_SURPLUS

household_consumption_kwh - solar_generation_kwh > tolerance
    → POTENTIAL_DEFICIT
```

Notes:

- The tolerance constants are fixed in code and are deliberately not
  environment-configurable.
- The applied tolerance is disclosed transparently in every classified API
  response via `result.tolerance`.
- Result semantics remain potential: measurement context POTENTIAL
  (docs/06 §13). A potential surplus is not confirmed grid export and a
  potential deficit is not confirmed grid import.

# 12. Energy Cost — F5 Implementation Decision

## D-042 — Energy Cost Analysis Honesty Boundary — F5

The project owner ratified the following F5 implementation decisions
(docs/01 §19, docs/02 §2 F5, docs/05 §12, Q31/Q34):

1.  Pipeline-first increment (F5.1): F5 v1 accepts validated future /
    predicted / estimated energy inputs and an explicitly declared
    pricing basis, and computes an honest estimated variable cost.
    Historical verified cost remains an honest UNAVAILABLE result until
    verified bill persistence exists (F13/F2).

2.  Currency (F5.2): INR (₹) only in v1, locale en-IN for presentation.
    Currency is explicit data on the API contract, never presentation-only.

3.  Pricing evidence ladder (F5.3), whitelisted and declared per request:
    verified tariff/rate evidence → reliable verified historical bill
    relationship → explicit user-provided rate → insufficient evidence.
    SPMS never invents tariff information; declared bases are disclosed
    as unverified in v1 responses.

4.  Cost range (F5.4): a numeric range is mandatory whenever fallback
    pricing evidence is used, the worst input confidence is LOW or
    VERY_LOW, or prediction uncertainty (non-ACTUAL input nature)
    materially affects the result. The widest applicable disclosed
    factor wins. An uncertain future cost is never presented as a
    guaranteed or exact bill. The owner has ratified the factors below
    UNCHANGED as v1 implementation defaults only — they are not
    scientifically calibrated uncertainty, not statistically validated
    prediction intervals, and not permanent domain truth.

5.  Fixed charges (F5.5): never multiplied by kWh, never folded into an
    inferred ₹/kWh rate, never silently included in the variable
    estimate. They are echoed separately with treatment
    SEPARATE_NOT_INCLUDED.

6.  Frontend location (F5.6): dedicated /energy/cost page; the locked F4
    /energy page is not modified beyond routing integration.

7.  Tariff modeling (F5.7): no full tariff/slab engine in v1; the
    request contract keeps pricing basis explicit and extensible for
    later tariff-structure modeling.

8.  Other charges (F5.9): FPPCA, electricity duty, taxes and similar
    adjustments are not predicted and never silently included in future
    variable cost.

9.  Balanced cost semantics (owner-ratified refinement): when the
    energy position is BALANCED within the disclosed F4 tolerance
    (D-040), the sub-tolerance residual is treated as NO material
    potential deficit for cost analysis — grid_dependent_kwh is 0 and
    the estimated variable cost is 0.00. The raw difference remains
    preserved in energy_position.balance_kwh, the tolerance stays
    disclosed, and F4's own classification behavior is untouched.

10. Degradation handling (owner-ratified refinement): STALE / DEGRADED
    status and POOR quality introduce NO additional range factors in
    v1. Degradation is shown honestly through the existing
    status/quality indicators, explanations, and worst-of semantics;
    only the locked range triggers (fallback basis, LOW/VERY_LOW
    confidence, non-ACTUAL nature) determine whether a range applies.

11. Zero-width range presentation (owner-ratified refinement): when the
    backend range is 0–0 (genuine zero variable cost), the frontend
    presents "No variable energy cost identified" instead of a
    misleading ₹0.00–₹0.00 band. Backend range mathematics and the
    disclosed triggers are unchanged.

Honesty boundary (core rules):

-   The cost result is an ESTIMATE with measurement_context ESTIMATED;
    the echoed energy position keeps measurement_context POTENTIAL and
    is never reinterpreted as measured grid import/export.
-   Insufficient pricing evidence returns an honest
    INSUFFICIENT_EVIDENCE result, never a fabricated zero.
-   Result data nature is the worst of the input natures, floored to
    ESTIMATED (a future cost is never labeled ACTUAL); predicted,
    estimated, and simulated inputs keep their own nature.
-   Disclosed default range half-width factors (v1 implementation
    defaults, ratified unchanged by the owner; fixed in code, disclosed
    in every ranged response): fallback basis
    HISTORICAL_BILL_RELATIONSHIP 15%, USER_PROVIDED_RATE 25%; non-ACTUAL
    input nature ESTIMATED 10% / PREDICTED 15% / SIMULATED 20%; worst
    input confidence LOW 25% / VERY_LOW 40%.

API boundary: POST /api/v1/energy/cost/estimate and GET
/api/v1/energy/cost/historical under the versioned /api/v1 envelope,
mirroring the F4 implementation idiom without modifying F4 (locked at
ec869a8).

Note on decision numbering: the F4 balanced-classification tolerance is
canonically D-040 (section 11 above). An early F4 test-suite citation
referenced "docs/10 D-041" for the same decision; that citation was a
reference error and has been corrected to D-040. No decision content was
changed, no decision was renamed or renumbered, and D-041 remains
genuinely unused — this F5 entry deliberately takes D-042 so that no
existing reference can collide.

# 13. Deterministic Intelligence — F6 Implementation Decision

## D-043 — Controlled Insight Taxonomy and Detector Architecture — F6

The project owner ratified the following F6.1 implementation decisions
(docs/01 §38.1, docs/02 §2 F6, AGENT_INSTRUCTIONS §26):

1.  Insight types are controlled. Every insight belongs to exactly one
    whitelisted type in `InsightTaxonomy`, and every type belongs to
    exactly one category (SOLAR, CONSUMPTION, ENERGY, COST, GENERAL).
    Taxonomy v1 declares 20 types across the five categories. The set is
    extensible only by editing the taxonomy class — never by accepting
    arbitrary strings from input, storage, AI output, or the frontend.

2.  Detection is deterministic and rule-based. Each detector is a
    separate class implementing one `InsightDetector` contract, reading
    only the canonical validated evidence input. There is no AI/LLM
    generation of insight types, wording-as-truth, or candidate insight
    in v1. The engine performs no I/O and no persistence; ordering of
    results is deterministic.

3.  The canonical evidence input is a single validated structure with
    optional sections (household scope, solar, consumption, forecast,
    balance, cost, historical, quality context, provenance context).
    Missing sections mean "not provided", never zero. A value record
    cannot claim UNAVAILABLE status. The F6 evidence adapter maps locked
    F4/F5 result payloads into this shape verbatim: F6 never recomputes
    a balance (docs/10 D-040) and never recomputes a cost (docs/10
    D-042). Detector thresholds (change/pattern/recurrence heuristics)
    are fixed v1 implementation defaults, disclosed in every insight's
    supporting facts, and are not scientifically calibrated.

## D-044 — Evidence-Weighted Significance Model — F6

Insight significance (LOW, MODERATE, HIGH) is assigned by a dedicated
deterministic model, not by detectors alone and never by the frontend:

```text
significance = detector magnitude hint (LOW/MODERATE/HIGH)
               capped by honesty ceilings
```

Honesty ceilings (v1, fixed in code):

-   DATA_LIMITATION insights are always LOW.
-   Any involved evidence that is not ACTUAL (predicted, estimated,
    simulated, or no analytical value at all) caps significance at
    MODERATE.
-   INSUFFICIENT insight confidence caps significance at LOW.

These are evidence-weighted heuristics ratified as v1 implementation
defaults only — not scientifically validated impact measurements.

## D-045 — Independent F6 Insight Confidence — F6

Insight confidence is F6's own judgement about how well the evidence a
candidate actually used supports the finding:

-   It is never copied from an upstream F4/F5 confidence value and never
    presented as a numeric score — levels only (HIGH, MODERATE, LOW,
    VERY_LOW, INSUFFICIENT). F4's result-level confidence is deliberately
    not even carried into F6 evidence; F4's result-level quality IS
    carried through and participates in the quality ceilings below.
-   Base level comes from evidence breadth (how many distinct evidence
    domains carry usable records for this candidate) and observation
    depth (three or more compared records can raise the base).
-   Honesty ceilings apply: SIMULATED evidence caps at LOW;
    ESTIMATED/PREDICTED at MODERATE; POOR quality at VERY_LOW;
    UNVERIFIED quality at LOW; PARTIAL quality at MODERATE; two-period
    comparisons at MODERATE; no usable evidence is INSUFFICIENT.
-   Status ceilings (owner-ratified F6.1 correction): UNAVAILABLE
    evidence status — and defensively ERROR, which cannot pass validated
    evidence input — caps insight confidence at INSUFFICIENT; STALE and
    DEGRADED evidence status caps at MODERATE. Status is applied as a
    ceiling only and never upgrades confidence.
-   Degradation is propagated worst-of and never upgraded (docs/05 §7,
    §9).

## D-046 — Insight Persistence, Deduplication and Lifecycle — F6

1.  Persistence (F6 v1): insights are persisted in a single `insights`
    table (migration `0001_create_insights`) with semantic data fields
    (data nature nullable = "no analytical value involved"; data status;
    quality), provenance, supporting facts, limitations, explanation and
    explanation source, UTC timestamps (docs/05 §13), and lifecycle
    counters (first_detected_at, last_observed_at, observation_count).

2.  Deduplication: the identity is household + type + affected period.
    It is enforced by a database unique key over NULL-safe generated
    columns (MySQL unique indexes ignore NULLs), complemented by an
    explicit lookup inside a repository transaction (docs/05 §15, §18).
    Repeated detections converge on the same row instead of duplicating
    it; materially changed evidence updates the row while historical
    anchors are preserved. Period-scoped variants of the same type are
    distinct rows, preserving historical truth (docs/05 §6).

3.  Lifecycle states: DETECTED, VALIDATED, ACTIVE, UPDATED, RESOLVED,
    EXPIRED, HISTORICAL. The engine/repository ladder is deterministic:
    first detection DETECTED, second unchanged observation VALIDATED,
    third+ ACTIVE; changed evidence moves a validated insight to
    UPDATED. ONGOING is accepted as an input/filter alias and normalized
    to UPDATED — never persisted verbatim. RESOLVED, EXPIRED and
    HISTORICAL are reserved states: filterable in v1, assigned only by
    future evaluation paths (no background job exists yet).

## D-047 — Intelligence API Boundary and Backend-Controlled Generation — F6

1.  Endpoints (versioned /api/v1 envelope, mirroring the F4/F5 idiom
    without modifying F4 or F5):

```text
GET /api/v1/intelligence/insights        list persisted insights
GET /api/v1/intelligence/insights/{id}   single persisted insight
```

2.  Generation is backend-controlled and never triggered by ordinary
    reads. GET /api/v1/intelligence/insights is strictly read-only
    (docs/06 §3 read-oriented GET semantics): it validates filters,
    queries persisted insights, and returns the standard envelope — no
    INSERT or UPDATE can occur on a read, and repeated reads leave
    observation counters and timestamps unchanged. Detection,
    significance, confidence, deduplication and lifecycle run only
    through the controlled internal service path
    (IntelligenceService::refreshFromEnergyPipeline /
    refreshFromPayloads), which is not exposed as any public endpoint;
    future ingestion (GoodWe synchronization, prediction, bills) will
    invoke that internal seam. Until a controlled evaluation has run,
    the honest state is an empty insight list — reads never fabricate a
    DATA_LIMITATION row merely because someone performed a GET.

3.  List filters (category, type, significance, confidence, status,
    household scope, period bounds, limit, offset) are validated against
    allowlists with bounded pagination (docs/05 §17). POST to the list
    route is a 405 by construction.

4.  Ownership: household-scoped insights are returned only to requests
    scoped to the owning household; a mismatch returns 404 rather than
    disclosing existence. Full authentication arrives with the
    account/session milestones; the scoping is structurally prepared in
    v1.

5.  Router extension: the shared router gained additive support for
    `{param}` path segments (exact-match routes keep absolute
    precedence; 404/405 semantics unchanged), required for the insight
    detail route.

## D-048 — Data Quality, Provenance and Data Limitation Insights — F6

1.  Every insight carries the semantic data fields of the evidence it
    used, propagated worst-of and never upgraded: SIMULATED stays
    SIMULATED end to end (docs/10 D-048 supersedes no earlier decision;
    D-041 remains unused per section 12). DEMO is a product/demo context
    concept, never a data_nature value.

2.  Data limitation insights (DATA_LIMITATION) state honestly what is
    missing, unavailable, insufficient, or simulated-only — including
    F4/F5 UNAVAILABLE results and INSUFFICIENT_EVIDENCE pricing — so
    consumers can see why coverage is limited. Missing data is never
    treated as zero and never silently ignored (docs/01 §27, §42.3).

3.  Provenance is persisted per insight: engine version, taxonomy
    version, contributing detectors, evidence domains, and the F4/F5
    pipeline sources. Explanations are deterministic, carry an
    explanation_source identifier, and never claim measured certainty
    for potential/estimated/simulated results.

**END OF DECISION LOG**
