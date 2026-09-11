# 10 — Decision Log

**Status:** Phase 6 consolidated  
**Purpose:** Preserve important architectural and implementation decisions with rationale.

This document records the decisions that materially govern implementation. It is not intended to reproduce every individual Q&A line.

---

# 1. Architecture Decisions

## D-001 — XAMPP for Development

**Decision:** Use XAMPP for local development.

**Rationale:** It provides a practical Apache/PHP/MySQL environment while the application architecture remains portable to normal PHP hosting.

---

## D-002 — PHP + MySQL + Vanilla JavaScript

**Decision:** Use PHP, MySQL, HTML, CSS, and Vanilla JavaScript rather than returning to the previous React/Node architecture.

**Rationale:** The chosen stack is simpler to understand, maintain, demonstrate, and explain during a project viva.

---

## D-003 — Lightweight MVC-like Architecture

**Decision:** Use modular MVC-like separation without adopting a large full-stack framework.

**Rationale:** Clear layer responsibilities are more important than framework complexity.

---

## D-004 — Public Front Controller

**Decision:** Use one public front controller:

```text
public/index.php
```

**Rationale:** Centralizes bootstrap, routing, middleware, and request handling.

---

## D-005 — Layered Request Flow

**Decision:**

```text
Controller → Service → Repository / Integration
```

**Rationale:** Keeps HTTP, business logic, persistence, and external providers separated.

---

# 2. Backend Authority

## D-006 — Backend Owns Business Truth

**Decision:** Backend/domain/analytical services own authoritative business and numerical calculations.

**Rationale:** Prevents the frontend from becoming a second source of truth.

---

## D-007 — Frontend Is Presentation/Interaction

**Decision:** JavaScript handles presentation and presentation-only calculations.

**Rationale:** UI code must not redefine authoritative energy, prediction, balance, cost, authorization, or lifecycle truth.

---

## D-008 — Semantic API Contracts

**Decision:** APIs expose structured semantic identifiers and contextual metadata.

**Rationale:** The UI should not infer whether a value is actual, predicted, estimated, simulated, stale, degraded, or unavailable.

---

# 3. Data Meaning

## D-009 — Data Nature and Status Are Separate

**Decision:** Keep nature separate from status.

```text
Nature: ACTUAL / PREDICTED / ESTIMATED / SIMULATED
Status: FRESH / STALE / DEGRADED / UNAVAILABLE
```

**Rationale:** A value can be actual but stale, or predicted but fresh.

---

## D-010 — Measurement Context

**Decision:** Distinguish measured/actual, inferred, predicted, potential, estimated, and simulated meanings.

**Rationale:** SPMS does not have direct grid measurement and therefore must not claim confirmed grid import/export from predictions.

---

## D-011 — Provenance

**Decision:** Track source, time, processing, nature, transformation, model/version, and lineage where required.

**Rationale:** Analytical results must remain explainable and traceable.

---

# 4. GoodWe and External Services

## D-012 — GoodWe Is Actual Solar Source

**Decision:** GoodWe is the primary source of actual solar measurements.

**Rationale:** Actual solar evidence should come from the connected solar system.

---

## D-013 — External Services Are Bounded

**Decision:** GoodWe, weather, AWS, Bedrock, and email remain isolated integration dependencies.

**Rationale:** Provider behavior must not redefine SPMS truth.

---

## D-014 — Graceful Degradation

**Decision:** External failure degrades only affected capabilities where possible.

**Rationale:** Loss of GoodWe, weather, or Bedrock should not unnecessarily destroy valid historical or independent functionality.

---

# 5. AI / Bedrock

## D-015 — Bedrock Does Not Calculate Numerical Truth

**Decision:** Bedrock handles explanation, summarization, and contextual language.

**Rationale:** Numerical prediction, balance, and cost calculations require authoritative analytical services.

---

## D-016 — Minimum AI Context

**Decision:** Send only minimum necessary validated semantic context to AI.

**Rationale:** Reduces privacy/security exposure and constrains unsupported generation.

---

## D-017 — Validate AI Output

**Decision:** AI output is schema-constrained and validated.

**Rationale:** Unsupported claims must not reach the user as authoritative facts.

---

# 6. Prediction

## D-018 — Separate Prediction Capabilities

**Decision:** Solar forecasting and household consumption forecasting are distinct analytical capabilities.

**Rationale:** Their inputs, behavior, and evaluation contexts can differ.

---

## D-019 — Adaptive Resolution

**Decision:** Prediction resolution cannot exceed available evidence.

**Rationale:**

```text
Monthly bill ≠ hourly measured consumption
```

The system must not manufacture fine-grained truth.

---

## D-020 — Historical Predictions Are Immutable

**Decision:** Historical predictions retain their original model/version/context.

**Rationale:** Model improvements must not silently rewrite historical truth.

---

# 7. Intelligence

## D-021 — Intelligence Pipeline

```text
Validated Analytical Facts
        ↓
Intelligence
        ↓
Bedrock Explanation
        ↓
Insight
        ↓
Recommendation
        ↓
Notification Decision
        ↓
Feedback
        ↓
Personalization
```

**Rationale:** Separates objective analysis, explanation, recommendation policy, and user adaptation.

---

## D-022 — Recommendation Is Advisory

**Decision:** Recommendations do not directly control appliances.

**Rationale:** SPMS is a household energy-management advisory system, not an appliance-control system.

---

## D-023 — Feedback Does Not Redefine Truth

**Decision:** User feedback changes relevance/presentation/frequency/timing, not objective analytical truth.

**Rationale:** A dismissed recommendation is not automatically an incorrect prediction.

---

# 8. Security

## D-024 — Backend Authorization

**Decision:** Every protected operation validates authorization server-side.

**Rationale:** Frontend hiding is not authorization.

---

## D-025 — Protected Bill Files

**Decision:** Bills are stored outside the public web root and accessed through backend authorization.

**Rationale:** Bills are protected household documents.

---

## D-026 — Secrets Outside Source Control

**Decision:** Credentials and secrets are environment-specific and excluded from version control.

**Rationale:** Prevents accidental credential disclosure and supports environment separation.

---

# 9. Database

## D-027 — MySQL + PDO

**Decision:** MySQL with PHP PDO and prepared statements.

**Rationale:** Provides relational integrity and a clear persistence boundary.

---

## D-028 — Versioned Migrations

**Decision:** Database schema changes are version-controlled migration files.

**Rationale:** Makes schema evolution reproducible.

---

## D-029 — Historical Integrity

**Decision:** Historical analytical records are protected from silent destructive changes.

**Rationale:** Historical reports, predictions, and evaluations depend on preserving past truth.

---

# 10. Background Operations

## D-030 — Persistent Operations

**Decision:** Meaningful long-running jobs receive persistent operation records and IDs.

**Rationale:** Users and administrators need authoritative operation state.

---

## D-031 — Bounded Retry

**Decision:** External operations use timeouts, bounded retries, backoff, and failure classification.

**Rationale:** Prevents uncontrolled retry loops and cascading failures.

---

# 11. Testing

## D-032 — Layered Testing

**Decision:**

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

**Rationale:** Different correctness risks require different testing layers.

---

## D-033 — Demo/Test Uses Real Architecture

**Decision:** Demo/Test uses controlled simulated inputs through real SPMS boundaries.

**Rationale:** Demonstrates realistic degradation and recovery without building a fake application.

---

# 12. Model Lifecycle

## D-034 — Controlled Model Release

**Decision:**

```text
Candidate
 ↓
Validation
 ↓
Evaluation
 ↓
Release Gate
 ↓
Deployment
 ↓
Monitoring
 ↓
Rollback
```

**Rationale:** A new model must demonstrate acceptable behavior before becoming authoritative.

---

# 13. Recovery

## D-035 — Backup Is Not Enough

**Decision:**

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

**Rationale:** Recoverability must be demonstrated, not assumed.

---

# 14. Documentation

## D-036 — Existing Numbered Documents Are the Source of Truth

**Decision:** Maintain the established numbered documentation structure rather than creating duplicate phase documents.

**Rationale:** Keeps project knowledge organized and avoids conflicting specifications.

---

# 15. Git

## D-037 — Meaningful Stable Checkpoints

**Decision:** Commit meaningful stable milestones, not every tiny edit.

**Rationale:** Keeps Git history useful and makes rollback/checkpointing practical.

---

## D-038 — First Technical Skeleton Is a Checkpoint

**Decision:** The first complete:

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
API
```

receives a stable Git commit after verification.

**Rationale:** This proves the core architecture works before feature complexity is added.

---

# 16. Architectural Conflict Rule

## D-039 — No Silent Shortcuts

**Decision:** If implementation conflicts with a locked architectural rule, stop, document, evaluate, and explicitly decide whether the architecture or implementation should change.

**Rationale:** Convenience must not silently redefine system architecture.

---

# 17. Final Governing Decision

## D-040 — Implementation Readiness Rule

> Implement the locked architecture incrementally, verify each meaningful boundary, preserve the established semantic/security/analytical rules, document concrete implementation decisions, test continuously, and commit stable milestones.

This governs the transition from architecture into actual coding.
---

# 18. Energy Balance — F4 Implementation Decision

## D-041 — Balanced Classification Tolerance — F4

The official project documentation (docs/01 §15.1) defines the balanced state
qualitatively only:

``	ext
Solar ≈ Consumption → Approximately Balanced
``

The ≈ relation was never numerically defined in any official document.
During the F4 implementation review, the project owner ratified the following
numeric tolerance as the project's concrete implementation decision:

``	ext
tolerance = max(0.1 kWh, 5% × max(solar_generation_kwh, household_consumption_kwh))
``

Classification (backend-authoritative, docs/01 §15.3, D-015):

``	ext
abs(solar_generation_kwh - household_consumption_kwh) <= tolerance
    → BALANCED

solar_generation_kwh - household_consumption_kwh > tolerance
    → POTENTIAL_SURPLUS

household_consumption_kwh - solar_generation_kwh > tolerance
    → POTENTIAL_DEFICIT
``

Notes:

- The tolerance constants are fixed in code and are deliberately not
  environment-configurable.
- The applied tolerance is disclosed transparently in every classified API
  response via result.tolerance.
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

**END OF DECISION LOG**
