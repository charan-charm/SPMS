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

# 14. Database Foundation and Authentication — Ratified Architecture (W1/W3)

Status of this section: RATIFIED by the project owner as the governing
architecture for the upcoming W1 (Authentication Foundation) and W3
(Household Ownership) implementation. Items marked LOCKED below were
already authoritative through earlier decisions; items marked RATIFIED
record the new specific implementation architecture. Application code,
migrations, and tests are NOT part of this ratification.

Ratified architectural principles (repeated here as one list; each is
already grounded in the cited rules):

1.  The backend is the sole security authority (docs/08 §2, §24).
2.  Authentication establishes identity; authorization establishes
    resource access (docs/06 §5).
3.  Household ownership is a domain relationship, not merely an account
    attribute (this section).
4.  Client-provided identifiers are never proof of ownership
    (docs/06 §26, docs/08 §19).
5.  Repositories do not decide authorization (this section).
6.  React route protection is UX only, never security (docs/08 §18).
7.  Sessions are server-side and revocable (docs/08 §8).
8.  Secrets/tokens are stored hashed where possible (docs/08 §23).
9.  Authentication failures must not leak whether sensitive account
    data exists (docs/08 §7).
10. Deletion behavior must preserve security/audit history
    (docs/01 §39, docs/01 §31).
11. Data lifecycle and security lifecycle are separate concerns
    (docs/01 §39).
12. Demo/Test mode is an operating mode, not an authorization role
    (docs/01 §33.2, docs/08 §10).

## D-049 — Account Identity Model — Database/Auth

Status: RATIFIED.

1.  One `accounts` table is the canonical authentication/account
    identity. No separate user/profile authentication tables exist in
    v1. Conceptual fields: `id CHAR(36) UUIDv4` (existing identifier
    convention), `email` normalized lowercase and UNIQUE,
    `password_hash`, `status` (PENDING_VERIFICATION, ACTIVE, DISABLED,
    DELETION_PENDING), `role` (USER, ADMIN), `mode` (REAL, DEMO_TEST),
    `email_verified_at`, `created_at`, `updated_at`.
2.  Client input can never determine `role`, `mode`, `status`, or any
    authorization property. These are server-assigned only.
3.  Demo/Test is an account mode attribute, not an authorization role
    (docs/01 §33.2 — LOCKED, restated for the schema).

## D-050 — Household Membership Ownership Model — Database/Auth

Status: RATIFIED.

1.  Household-domain identity lives in a separate `households` table.
    Ownership/sharing is modeled by a `household_memberships` table
    between `accounts` and `households`. Ownership is never encoded as
    `accounts.household_id`.
2.  The schema is N:N-capable. Ratified v1 business rules: one account
    may own/belong to one household; a household may eventually have
    multiple members; the primary v1 membership role is OWNER;
    MEMBER/VIEWER roles may be added later. Single-household-per-account
    is enforced by v1 application rules, not by the schema shape.
3.  The membership layer is the authorization boundary. Household-scoped
    data must be authorized from the authenticated account's
    server-side membership. A client-supplied `household_id` is only a
    requested resource identifier: access is verified server-side, and
    for normal current-user operations the effective household is
    derived from authenticated membership. Cross-household access is
    denied (404 semantics where existence would otherwise leak).

## D-051 — Server-Side Database-Backed Sessions and Cookie Policy — Database/Auth

Status: RATIFIED (formalizes docs/07 §16, docs/08 §8, docs/04 Q101/Q103).

1.  Sessions are server-side and database-backed (application-managed
    session store). JWT is NOT used as the primary browser
    authentication mechanism.
2.  Session identifier: opaque, cryptographically random, 256-bit
    entropy, carried in a cookie. The database stores ONLY a secure
    hash of the identifier (SHA-256), never the raw identifier.
3.  Lifecycle: the identifier is regenerated on successful login; the
    session is revoked/deleted on logout; idle expiry and absolute
    expiry are both enforced server-side.
4.  Ratified defaults: 24 hours idle timeout; 7 days absolute lifetime.
    Values may be tuned during W1 with security review.
5.  Cookie policy: HttpOnly; SameSite=Lax; Secure=true in production
    HTTPS; Secure=false only for local development over plain HTTP;
    Path=/. The authoritative session token is never stored in browser
    storage (docs/08 §8, §20).

## D-052 — CSRF Synchronizer-Token Policy — Database/Auth

Status: RATIFIED (formalizes docs/08 §9, docs/06 §23).

1.  State-changing authenticated browser requests use a synchronizer
    token: server-generated, associated with the server-side session,
    sent by the frontend through the centralized API client.
2.  SameSite alone is not the CSRF defense. GET/HEAD/OPTIONS remain
    non-mutating.

## D-053 — Account Tokens, Password Policy and Rate Limiting — Database/Auth

Status: RATIFIED (formalizes docs/08 §5–7, §12).

1.  A dedicated `account_tokens` table serves verification and recovery
    workflows. Tokens are random, opaque, single-use, time-limited, and
    invalid after use. Stored token identifiers are hashed with
    SHA-256; raw tokens are never stored.
2.  Ratified TTLs: email verification 24 hours; password recovery 1
    hour.
3.  Raw verification/recovery tokens are never written to ordinary
    application logs.
4.  Passwords use PHP `password_hash`/`password_verify`; Argon2id is
    preferred where the runtime supports it, with a secure
    PHP-supported fallback otherwise. No custom hashing. No plaintext.
5.  The password policy is centralized and backend-enforced. v1 minimum
    length: 12 characters.
6.  Authentication-sensitive operations are rate-limited with DB-backed
    state (not process memory). Protected at minimum: login,
    registration, verification resend, password recovery request,
    password reset attempts.
7.  Login protection: bounded failed-attempt throttling per normalized
    account/email + IP, broader IP throttling, and temporary cooldown —
    never a permanent lockout (which would enable trivial
    denial-of-service against another user).

## D-054 — Authentication Email Transport Abstraction — Database/Auth

Status: RATIFIED.

1.  Authentication email delivery uses a transport abstraction:
    SMTP/provider transport through configuration in production; a
    local mail-catcher/dev sink in development when available.
2.  If no development sink is available, delivery fails safely rather
    than exposing authentication secrets (including raw tokens) in
    ordinary logs.

W1.1 implementation record (no new decision): the abstraction gained a
Brevo HTTPS transport (`MAIL_TRANSPORT=brevo`) alongside `file` and
`smtp`. Real Brevo sending is gated behind an explicit
`BREVO_ALLOW_REAL_SEND` flag that the automated test runner pins off —
tests can never produce a Brevo API request; the only sanctioned path
that enables real delivery is the dedicated manual entry point
(`scripts/manual-brevo-server.php`) with the flag set in that process
only. The API key never leaves backend configuration and is never
logged or included in error messages.

## D-055 — Middleware Layer, Authorization Boundaries and Admin — Database/Auth

Status: RATIFIED (formalizes docs/07 §16 request flow, docs/08 §10–11).

1.  A deliberate middleware layer is introduced matching the documented
    architecture: Router → Middleware → Controller → Service/Domain →
    Repository. Authentication middleware establishes the authenticated
    account context.
2.  Authorization remains a backend service/controller-boundary
    responsibility. Repositories remain authentication-agnostic and
    receive already-authorized, validated identifiers.
3.  No authorization logic lives in React; frontend route guards are UX
    only (docs/08 §18).
4.  Roles are USER and ADMIN. ADMIN does not automatically mean
    unrestricted access to every household: administrative operations
    use explicit backend capabilities/authorization rules, and sensitive
    administrative actions are auditable (docs/08 §11, docs/04 Q194–Q196).

## D-056 — Security/Audit Events and Deletion Foreign-Key Policy — Database/Auth

Status: RATIFIED (formalizes docs/01 §39, docs/01 §31, docs/08 §26).

1.  A dedicated security/audit event mechanism is part of the DB
    architecture. Events retain actor information where available.
2.  Security/audit history must not depend on the lifecycle of the
    deleted account or household: event rows reference actors/resources
    by identifier without destructive foreign-key cascades that would
    erase security history.
3.  Ratified relationship behavior: account → sessions CASCADE; account
    → account_tokens CASCADE; account → memberships CASCADE; account →
    owned household RESTRICT. Household → insights RESTRICT initially;
    other analytical/domain records are lifecycle-aware and RESTRICT
    where appropriate.
4.  SET NULL must not be used merely to make deletion succeed. Account
    deletion follows the controlled hybrid lifecycle (docs/01 §31):
    disable, revoke, delete/anonymize per retention policy, stop
    processing, retain minimal audit/proof.

## D-057 — Insights Household Foreign-Key Integration — Database/Auth

Status: RATIFIED (extends D-046; does not alter the F6 migration).

1.  `insights.household_id = NULL` has intentional semantics:
    system-scope / undetermined-household insights (D-046). NULL is
    preserved for exactly that meaning.
2.  Once household ownership is implemented and existing F6 test
    residue is cleaned, a foreign key is added from
    `insights.household_id` to `households.id` with RESTRICT behavior.
    SET NULL is never used for this relationship (a deleted household's
    insights must not silently become system-scope insights).
3.  `account_id` is not added to insights: household domain ownership
    is the correct scope, reached through memberships.
4.  Transitional fact: current F6 insight data may be visible across
    household scopes because authentication/ownership does not exist
    yet. This is known transitional behavior — closed server-side by
    W3, never by client-side filters.

## D-058 — Strict SQL Mode and Ratified Migration Sequence — Database/Auth

Status: RATIFIED.

1.  SPMS database access operates under strict SQL semantics. Strict
    mode is configured for the SPMS connection/session explicitly, not
    by blindly changing the global XAMPP/MariaDB installation, which
    must keep working for unrelated applications.
2.  Ratified migration sequence (architectural intent; created only in
    their own future milestones):

```text
0002_create_accounts
0003_create_sessions
0004_create_account_tokens
0005_create_households
0006_create_household_memberships
0007_add_insights_household_foreign_key
0008_create_security_events
```

3.  Dependencies: 0003/0004 require 0002; 0006 requires 0002 + 0005;
    0007 requires 0005 plus a real household-creation path. Before any
    migration: inspect the MariaDB version and sql_mode, inspect
    migration state, preserve F6 data semantics, identify and
    explicitly handle F6 fake/test household residue, never reset or
    drop tables as a shortcut. 0007 must NOT be implemented until
    incompatible residue is cleaned or safely migrated.

## D-059 — GoodWe/SEMS+ External Integration Boundary and Foundation — Integration

Status: RATIFIED (G-FOUNDATION; establishes boundaries only — the detailed
provider contract is a G0 deliverable).

1.  GoodWe/SEMS+ is an official SPMS external integration domain. It is
    not the SPMS backend, the SPMS database, the SPMS authentication
    system, the frontend API, or the AI layer.
2.  The production integration lives server-side in the PHP architecture
    (integration layer + Solar domain). React never calls GoodWe directly.
    The earlier Node/Express prototype under
    `references/goodwe-sems-prototype/` is evidence/reference only —
    endpoint discovery, HAR capture/replay tooling, response samples,
    research CLIs — and is never migrated into production. Node.js stays
    build/prototype/research tooling; Express is forbidden as the SPMS
    production backend.
3.  GoodWe-specific protocol details (URLs, headers, authentication
    protocol, token handling, request/response formats, retries, provider
    error codes, provider naming and quirks) remain inside the
    integration boundary. The rest of SPMS works with canonical SPMS
    concepts; raw provider structures never leak through the domain.
4.  Provider data becomes usable only after validation, normalization to
    canonical SPMS solar data, and provenance/quality classification.
    Provider-sourced measurements are actual data where semantically
    supported; SPMS forecasts are predicted; unavailability fallbacks are
    estimated/stale per existing rules; demo datasets are simulated
    (docs/01 §Data Nature). Endpoint-level semantics are G0 decisions.
5.  SPMS credentials and GoodWe credentials are separate credential
    domains. The prototype's SEMS+ website authentication encodes the
    password as base64(MD5(password)) before the login request — a wire
    protocol detail, NOT a storage mechanism. If production requires
    replaying the original GoodWe secret, it is a reversible external
    credential protected by encryption at rest with keys outside the
    database and repository; if the official API instead uses application
    credentials (e.g. AppId/AppSecret + access tokens), the architecture
    follows that mechanism. G0 resolves this from official provider
    documentation.
6.  GoodWe secrets and tokens are server-side only: never exposed to
    React, browser storage, frontend API responses, AI/Bedrock prompts,
    application logs, error messages, Git, or analytics (extends
    docs/08 §14).
7.  GoodWe integration belongs to the household integration context
    (Account → Household Membership → Household → GoodWe Integration),
    not to an SPMS account password record. No account-owned GoodWe
    credential fields; no migrations in this foundation pass. W3 owns the
    household/membership architecture; G0 owns the eventual integration
    schema, which must respect household authorization.
8.  The GoodWe MVP is READ-ONLY: solar monitoring, historical/near-current
    production, telemetry, time series, flow, weather, station statistics,
    inverter/device information. Remote inverter/device/battery/appliance/
    grid control is out of scope; such endpoints found in the reference
    material are recorded as discovered, never adopted.
9.  HAR capture/replay is research tooling for endpoint discovery and
    verification, never part of the production request path. Approved
    discovery sequence: SEMS+ browser behavior → HAR capture → endpoint
    discovery → isolated replay → response inspection → official provider
    verification where possible → SPMS contract decision → PHP
    integration.
10. Provider session/token reuse, expiration handling, re-authentication,
    secure server-side token handling, no token logging and rate-limit-aware
    authentication are required properties; login-per-request is not
    carried into production. Exact token lifetime and storage strategy are
    G0 decisions.
11. Availability follows the existing SPMS degradation model (available /
    stale / degraded / unavailable / error / recovered): never fabricate
    live data, retain usable last-known actual data, expose
    freshness/sync state, use only approved fallbacks, resynchronize and
    reconcile on recovery.
12. G0 deliverables: official authentication mechanism, credential
    ownership, token lifecycle/storage, required production endpoints,
    endpoint request contracts, response schemas, endpoint data
    semantics, station/inverter identity model, rate limits, retry rules,
    provider error mapping, normalization model, raw-response retention,
    sync/freshness model, and the GoodWe → SPMS provenance mapping.

## D-060 — GoodWe Canonical Solar Ingestion Contract — Integration

Status: RATIFIED (G0; SPMS-side data contract). Provider-side field
semantics marked provisional remain subject to G1 validation; provisional
mappings are derived from prototype response samples and are NOT presented
as official provider contracts.

1.  Canonical ingestion model: every GoodWe observation becomes a
    canonical solar measurement record with at minimum: household scope
    (via the future household integration binding), source identifier
    (provider + external station/inverter identifiers), controlled metric
    code, value, canonical unit, event timestamp (UTC instant) plus the
    station timezone, retrieval timestamp, data nature, freshness status,
    quality, confidence (nullable), provenance (source, endpoint
    identifier, sync batch, optional raw-content hash) and a deterministic
    deduplication key (source + station + inverter + metric + timestamp +
    period). No migration is created in this phase; this is the model
    future schemas must implement.
2.  Provisional metric mapping (PROTOTYPE OBSERVATION evidence, verify in
    G1): current AC power (flow/telemetry `pAc`/`pSystem`, kW) →
    instantaneous generation ACTUAL; 5-minute `pAc` time series → power
    curve ACTUAL; `proSystemTotalStats` (kWh, per day) → daily generation
    total ACTUAL; `profitProStats` (currency) → provider-claimed revenue,
    stored only as non-authoritative context — SPMS F5 computes cost and
    never trusts provider profit figures; `proGridStats` /
    `proPurchaseStats` → provisional daily grid feed-in/purchase kWh,
    consumed as ACTUAL metered evidence ONLY where station metering is
    confirmed — F4's POTENTIAL semantics are unchanged regardless.
3.  Nature assignment at ingestion: provider-reported measurements are
    ACTUAL; provider forecasts (e.g. statisticsAndPre-style endpoints) are
    PREDICTED with provider provenance and must never be merged into
    SPMS-generated predictions; demo/test datasets use the existing
    DEMO_TEST/SIMULATED rules (D-049, docs/01 §39). ESTIMATED arises only
    in SPMS domain services, never from raw ingestion. Ingestion never
    upgrades nature.
4.  Quality rules: missing identity/timestamp → reject the record (never
    persist INVALID); partial optional fields → PARTIAL/POOR quality
    ceiling; unit/parsing anomalies → reject plus integration error
    event. Telemetry numeric-as-string payloads require strict
    locale-independent parsing. Quality ceilings propagate through the
    existing worst-of semantics (F4/F6 rules); ingestion adds no new
    semantics.
5.  Freshness model: staleness is a derived status, never a relabeling.
    Provisional thresholds pending G1-validated provider update cadence:
    instantaneous readings become STALE beyond 3× their poll interval;
    daily totals remain reconcilable (revisions) for a minimum 48h
    grace window after station-local day end. "Live" is not an SPMS
    state; UI truthfulness uses FRESH/STALE/DEGRADED/UNAVAILABLE with
    retrieval timestamps.
6.  Raw-response retention (G0 decision): canonical records only in the
    MVP. Raw provider bodies are NOT persisted permanently; optional
    per-record raw-content hashes preserve integrity, and a bounded,
    encrypted, config-gated debug ring for anomalous/failed responses may
    be introduced in G2 only if justified. Station address/coordinate/
    owner-name fields from provider responses are PII-adjacent: retained
    only where the household profile explicitly needs them, never in
    metric rows, React payloads or AI prompts.
7.  Synchronization lifecycle: near-real-time poll for current state +
    daily-total reconciliation (fixed-lag backfill of the trailing days)
    + post-recovery catch-up; every sync is a batch with a batch id;
    upserts are idempotent through the dedup key; revisions of a day's
    value create provenance-linked replacement records rather than silent
    mutation. Concrete intervals and rate budgets are provisional until
    official rate limits are established (no evidence exists yet).
8.  Degradation behavior (reuses the existing model): provider
    unreachable/timeout → last canonical data kept, derived status ages
    FRESH → STALE → DEGRADED/UNAVAILABLE per read-time rules; auth
    failure after exactly one re-auth attempt → integration health
    DEGRADED + security/audit event; empty results are honest "no data
    yet", never zero; recovery → resync/backfill, no fabricated
    intermediates.
9.  Downstream boundary: F4/F5/F6 consume ONLY canonical records via the
    existing domain/adapter seams — no GoodWe-aware branching inside the
    analytical engines; integration availability/freshness insights flow
    through the existing F6 data-limitation mechanism.

## D-061 — GoodWe Provider Authentication/Credential Architecture and G1 Validation Gate — Integration

Status: RATIFIED (G0 framework; provider facts classified, not assumed).

1.  Evidence classification at G0: the prototype's SEMS+ website
    cross-login (`account` + `pwd = base64(md5(password))`, JSON `token`
    header, `X-Signature`, per-session dynamic `api` base URL) is
    PROTOTYPE OBSERVATION of a browser-oriented protocol — validity from
    a server-side client, anti-bot behavior, token TTL and ToS posture
    are UNKNOWN/REQUIRES VALIDATION. GoodWe has historically offered a
    developer/open-API surface with app credentials (AppId/AppSecret
    style signing), but current official documentation could not be
    reached during G0 from this environment — therefore the official
    mechanism remains an OFFLINE VERIFICATION ITEM, not a decision input.
2.  Mechanism preference order for SPMS: (a) official open API with
    per-integration app credentials; (b) official user-authorized token
    flow if one exists; (c) website credential replay strictly last, and
    only if ToS/acceptability is explicitly established. SPMS does not
    assume any mechanism before G1 verification.
3.  Credential storage architecture (mode-independent rules): GoodWe
    secrets are never stored in `accounts` (D-049) or hashed with
    SPMS-password mechanics (D-059 §5). A replayable GoodWe secret (if
    the final mechanism requires one) is protected with authenticated
    encryption at rest (AEAD, AES-256-GCM class) in the future
    household-bound integration record; encryption keys live outside the
    database and repository (environment/secret manager; the project
    follows the existing single-config convention for key material).
    Non-replayable app secrets follow the same protection class.
4.  Provider tokens: server-side only; in-memory reuse until expiry with
    single-flight re-authentication is the MVP default; persistence of
    provider session tokens in the database is deliberately deferred and
    forbidden until G1 evidence shows a need (and then encrypted,
    expiring, never logged). No login-per-request (D-059 §10).
5.  Transport posture: HTTPS only; the integration accepts only the
    provider-supplied base origin resolved at auth time for that
    household session; strict log-redaction list for
    `token`/`X-Signature`/`pwd`/app-secret values; request logging is
    metadata-only (endpoint id, status, duration).
6.  Household binding: the integration record (credential or
    app-credential reference) belongs to a household
    (household_id RESTRICT, unique provider + external station identity);
    authorization is membership-based (W3); prototype environment-variable
    configuration is never inherited as production ownership semantics.
7.  G1 VALIDATION GATE — mandatory before any production integration
    code: (i) obtain and archive the official GoodWe/SEMS API
    documentation (developer portal / partner access — network-restricted
    environments like this one cannot substitute for it); (ii) one
    owner-authorized, isolated manual probe using the sanctioned
    explicit-enable pattern established by W1.1 (research CLI +
    separate opt-in flag; never in automated tests) to establish: server-
    side auth viability, token TTL, base-URL/region behavior, metering
    availability on the target station, endpoint list confirmation and
    observed rate-limit signals; (iii) ToS/acceptable-use confirmation;
    (iv) findings recorded as amendments to D-060/D-061. Until G1 passes,
    endpoint contracts in D-060 §2 stay PROVISIONAL and no GoodWe HTTP
    client, migration, controller or route may be implemented.

## D-062 — GoodWe G1 Validation Round Results and Continued Gate Status — Integration

Status: RATIFIED (records G1 round-1 findings with evidence classes).
The G1 gate from D-061 §7 remains OPEN — production authentication
decision NOT made; production code remains forbidden.

1.  OFFICIAL documentation — evidence class: UNAVAILABLE (G1 round 1).
    Every legitimate route failed from the research environment: the
    developer portals (direct attempts across two sessions), archived
    copies (Wayback holds zero captures of the developer hosts; CDX
    verified), the global-portal legacy API-help path (HTTP 403/404), and
    search routes (blocked). Official terms of API access are therefore
    UNKNOWN, and their absence must not be read as permission. Resolution
    is an owner-side action item (browser access / GoodWe partner
    inquiry).
2.  Live host observation (document level only): the legacy global portal
    host still serves, but its published API-help page is gone.
    Whether the SEMS+ login/data hosts behave from this network remains
    UNTESTED (no credentials, no probing without owner authorization).
    [LIVE PROVIDER OBSERVATION / UNKNOWN split as noted.]
3.  Prototype authentication mechanics re-validated
    [PROTOTYPE OBSERVATION]: cross-login payload
    {account, agreement:1, isChinese:false, isLocal:false,
    pwd:base64(md5(password))}; session fields {uid, token, timestamp,
    client:"semsPlusWeb", api, region, uuid}; per-request signed headers
    (JSON token header + X-Signature =
    base64(sha256Hex("<ts>@<uid>@<token>") . "@" . "<ts>"), fresh ms
    timestamp) with browser-mimicking Origin/Referer/uuid/os/brand.
    Validity from a server-side client is a PROTOTYPE OBSERVATION only
    (executed in a browser-like Node context); it is not evidence that
    the provider sanctions automated use.
4.  Gateway topology [PROTOTYPE OBSERVATION]: login is served on the
    SEMS+ host, while the data API base is returned per session and may
    point at a REGION GATEWAY on a different portal domain (observed
    fallback: an "hk-gateway" host). Production integration MUST resolve
    the base URL from the auth response and never hard-code it; the
    prototype's hard-coded IP-fallback hack is research damage-control
    and is banned from production.
5.  Envelope/error facts [PROTOTYPE OBSERVATION]: success codes
    {"00000","0",0}; error text in description/msg/message; prototype
    retry taxonomy retries transport errors (DNS/connect/timeout codes)
    and HTTP 5xx with linear backoff (500ms×n, default 4 attempts) and
    never retries 4xx/business-code failures. These are prototype
    conventions, NOT a provider rate-limit contract; official rate limits
    remain UNKNOWN. G0's bounded retry posture stands.
6.  Metric semantics evidence [PROTOTYPE OBSERVATION — platform UI
    translation keys]: proSystemTotalStats = power_generation (kWh);
    proGridStats = "grid_energy_1" (kWh — direction/name ambiguity
    unresolved); proPurchaseStats = purchased_electricity (kWh);
    proCharStats/proDischarStats = battery charge/discharge (kWh);
    installedPower = rated_power (kW). This strengthens (does not
    finalize) D-060 §2 provisional mappings.
7.  Metering capability [PROTOTYPE OBSERVATION]: grid/consumption data
    are capability- and meter-dependent (consumFlag indicator; sample
    station without metering returned no grid/consumption values).
    Generation is the guaranteed class for GoodWe-inverter stations;
    consumption/grid/battery fields are conditional. SPMS must never
    claim provider-measured consumption or grid flow where the provider
    only has POTENTIAL derivations (locked F4 semantics unchanged).
8.  Multi-inverter [PROTOTYPE OBSERVATION]: station-scoped endpoints
    return station-level aggregates; device-scoped series require a
    serial. Canonical scope keeps station totals and per-inverter series
    distinct, and SPMS must never sum inverter series to reconstruct
    station totals (G0 posture confirmed, no change).
9.  Control surfaces [PROTOTYPE OBSERVATION]: the discovered registry
    contains a remote-settings service base (device function menus and
    Modbus-address parameter endpoints). Reaffirms D-059 §8: ALL
    sems-remote functionality is out of the MVP boundary, and the
    research probe allowlist structurally excludes it (no such endpoint
    is callable). Alarms remain deferred (G2+).
10. Research probe (G1 tooling, non-production):
    `scripts/goodwe-research-probe.php` — default path performs zero
    network calls; live mode requires BOTH `GOODWE_LIVE_PROBE=1` and the
    explicit `--confirm` phrase; fixed read-only endpoint allowlist;
    credentials read only via the existing backend env mechanism;
    never prints credentials, tokens, or request headers; fingerprints
    every identifier; writes no files. Automated tests cannot and do not
    invoke it (mirrors the W1.1 real-email isolation philosophy).
11. Authentication decision: **NO PRODUCTION AUTHENTICATION DECISION
    YET.** Blocking evidence: (a) official API existence/terms for
    SEMS+ accounts (owner-side documentation/partner access);
    (b) the single owner-authorized live probe run establishing
    server-side cross-login viability and token expiration signals.
    Website credential replay remains the last-resort option under the
    D-061 §2 preference order and is NOT normalized by this round.
12. Token lifecycle, rate limits, and acceptable-use posture remain
    UNKNOWN (D-061 unchanged); persistent provider-token storage stays
    prohibited until measured.

## D-063 — GoodWe G1 Round 2A: Official OpenAPI Discovery and Contract Evidence — Integration

Status: RATIFIED (records officially documented provider facts with
citations; the G1 gate remains OPEN pending owner-side GoodWe
authorization and target-plant applicability confirmation).

1.  OFFICIAL evidence obtained (sources cited; accessed 2026-09-12):
    the GOODWE Open Platform (GoodWe 固德威技术股份有限公司) publishes
    developer documentation at developers.we.goodwe.com/docs (version
    "main"), serving the WE-platform open API hosted at
    we.goodwe.com/api/openapi/v1/. A separate international developer
    portal (openapi.goodwe.com, "GOODWE OpenAPI Developer Platform")
    exists — its content is a JS application shell and was not
    retrievable from the research environment. Classification: the
    we.goodwe.com contract below is OFFICIAL; the international
    portal's contract is UNKNOWN.
2.  OFFICIAL authentication: POST we.goodwe.com/api/openapi/v1/common/
    login with {grantType: "client_credentials", appId, appSecret};
    success envelope code "00000"; TOKEN returned in the response
    headers and as data.accessToken (bearer-class, sample expiresIn
    ≈12507 s); documented TOKEN lifetime 4 hours, reusable until
    expiry, re-login afterwards. appId/appSecret are described as
    obtained "through GoodWe authorization-service registration"
    (注册固德威授权服务) — there is NO documented self-service path.
    Documented login rate limit: 10 requests/minute/IP.
3.  OFFICIAL onboarding model: (a) caller IPs are submitted and entered
    into a whitelist by an administrator; (b) the administrator
    provisions tenant (租户) information and issues AppId/AppSecret;
    (c) all subsequent calls carry the TOKEN. Individual household
    SEMS+ account holders are not documented as able to self-provision
    OpenAPI credentials; whether GoodWe grants such access is
    CONDITIONAL on GoodWe business/authorization contact.
4.  OFFICIAL asset/authorization model: resources are scoped to the
    tenant; station/device/system list endpoints return per-tenant
    collections with isShared flags and an assetScope parameter
    (own / shared-from-other-tenant); an error sample (A1008) proves
    token-level device-range enforcement. Identifiers are platform
    Long ids (station id, device nodeId, systemId) plus serial numbers
    (sn); devices without a nodeId are documented to have no
    real-time/historical point data.
5.  OFFICIAL MVP-relevant endpoints (WE platform, all POST,
    Authorization header, documented limit 10 req/min/IP each):
    getTenantStationList (C1); getStationPointsDayMonthYearDataList
    (C2; dimension day/month/year/total; ≤50 stations/call; day
    dimension ≤31 days/call); getDeviceRealTimeData (C3; ≤100
    devices/call; metric tp/tpTime collection timestamp);
    getDevicePointsDayMonthYearDataList and the system-level variant
    (C4 at day granularity and coarser); getTenantDeviceList (C5;
    device type enum incl. Inverter/ElectricMeter/EnvironmentalTester/
    BMS/PCS; state enum 1000 running…1005 offline, 1011 abnormal).
    NO documented intraday (sub-day) historical series endpoint
    exists in the official API; provider-side intra-day curves remain
    a PROTOTYPE-ONLY capability (SEMS+ timeSeriesData). SPMS option:
    accumulate its own ACTUAL series by polling the real-time
    endpoint within rate limits (decision at G2).
6.  OFFICIAL data semantics (station history appendix): eTotal =
    generation (kWh), gridE = energy sold to grid (kWh), ep = grid
    (mains) consumption (kWh), selfUseE = self-consumption (kWh),
    per day/month/year/total dimensions, plus profit fields in CNY —
    profit fields are non-authoritative for SPMS F5 (existing rule).
    Inverter real-time dictionary: p = active power in W (unit
    differs from the prototype's kW — normalization must convert),
    eDay = daily generation kWh, plus errorCode/warningCode/eventCode.
    Device-level 上行/下行 (uplink/downlink) energy direction labels
    require careful G2 confirmation per device type. Official null
    rule "unlisted fields default to 0" cannot be distinguished from
    measured zero — the normalization layer must treat missing-then-
    zero fields cautiously (presence policy at G2).
7.  OFFICIAL error taxonomy (error-samples page): rate-limited → HTTP
    503 {error_msg: too fast}; expired/invalid token → HTTP 200 with
    code A0312; non-whitelisted IP → HTTP 200 with code A0201;
    device-range violation → code A1008; business errors carry
    description/errorMsg fields. SPMS retry policy maps to: 503 →
    bounded backoff; A0312 → single re-login; A0201/A1008 →
    configuration fault (no retry).
8.  No official contract found for: weather, provider forecasts,
    alarms beyond getDeviceAlarmInformation (deferred), consumption
    without metering, EU/US regional openapi.semsportal.com surfaces
    (only legacy/internal warranty/datalogger help pages observed —
    not adopted), and ToS/acceptable-use terms (UNKNOWN; absence of
    documented prohibition is not permission).
9.  PRODUCTION DIRECTION (recommendation, not yet authorization):
    Option A — official WE-platform-style OpenAPI — remains the
    preferred architecture and is technically sufficient for SPMS
    MVP capabilities C1/C2/C3/C5 and day-granularity C4. Closure of
    the G1 gate now depends on exactly two owner-side facts:
    (i) obtaining GoodWe OpenAPI authorization (tenant + AppId/
    AppSecret + IP whitelist, incl. confirming whether the SEMS+
    international account/plant can be served — possibly via
    openapi.goodwe.com rather than we.goodwe.com); (ii) one live
    validation round with provisioned credentials (the existing
    gated probe pattern extended to the official login flow).
    Website credential replay remains the last-resort option under
    D-061 §2 and is NOT adopted by this round.
10. Evidence-discipline note: official WE endpoints and prototype
    SEMS+ endpoints are DIFFERENT platform surfaces and must not be
    mapped onto each other without new evidence; the prototype
    remains PROTOTYPE OBSERVATION only.

G1 Round 2A.1 research note (2026-09-12; a note, not a new decision):
the official onboarding question is routed through verified GoodWe
channels — GoodWe India (en.goodwe.com/india): service email
Service.in@goodwe.com, regional sales contacts, and the official
Zendesk support portal linked from en.goodwe.com ("reply within 1
working day" stated on the official contact page) [OFFICIAL —
existence of channels]; the international developer portal
openapi.goodwe.com exists but its registration surface was not
retrievable [UNKNOWN]; third-party integrators report that SEMS-portal
API access is requested through the GoodWe sales channel and may
require an NDA [THIRD-PARTY — not relied upon as permission]. Whether
a residential SEMS+ plant in India can be bound to an OpenAPI tenant
remains UNKNOWN pending GoodWe's answer to the owner inquiry (the
official help center does document an SEMS "add visitor for plant"
sharing feature — an account-sharing capability, NOT evidence of
OpenAPI tenant eligibility [OFFICIAL fact, INFERENCE boundary]).

## D-064 — W3 Household Context Resolution and F6 Ownership Closure — Database/Auth

Status: RATIFIED (implements the boundaries reserved by D-050/D-056/
D-057/D-058; the resolution rules and API-shape choices below are new
decisions established by the W3 implementation).

1.  Household lifecycle is a soft-state mirror of the account lifecycle
    (docs/01 §31): ACTIVE, DELETION_PENDING, DELETED. No hard-deletion
    path exists in W3 — RESTRICT foreign keys make silent loss
    impossible, and physical removal remains a W5 retention-policy
    decision. Analytical history (insights and onward) is never
    destroyed by a household lifecycle transition.
2.  Effective household resolution: exactly one ACTIVE household
    reached through household_memberships of the authenticated account,
    deterministically (oldest membership). DELETION_PENDING/DELETED
    households and membership-less accounts resolve to a null context —
    never treated as active. V1 one-household-per-account is enforced
    at this service layer (D-050), while the schema stays N:N-capable;
    only the OWNER role exists.
3.  F6 ownership closure (closes the D-057 §4 transitional fact):
    insight reads require authentication; the household scope is derived
    server-side (session → account → membership → household). A
    household-scoped listing returns owned rows plus system-scope (NULL)
    rows; cross-household detail ids return the same 404 as unknown ids
    (existence never disclosed); a client claim of any household other
    than the caller's derived scope is rejected 403
    HOUSEHOLD_ACCESS_DENIED — a client-supplied household_id can only
    restate the server-derived value, never widen reach.
4.  POST /api/v1/household is the v1 onboarding primitive (CSRF-
    protected like every authenticated mutation). Registration does not
    auto-create households — onboarding stays a separate, explicit,
    authenticated step until the dashboard milestone defines its UX.
    A second creation attempt is refused 409 HOUSEHOLD_EXISTS; existing
    households are never replaced implicitly.
5.  There is no GET /api/v1/households/{id} and no household-id-
    addressed read surface: household context is always fetched through
    the caller's own authenticated route. Future multi-household work
    must re-open this deliberately, not incrementally.
6.  ADMIN receives no implicit household reach (D-055 applied):
    resolution is role-agnostic; administrative capabilities will
    require explicit, audited rules — never membership bypass.
7.  Migration 0007 (executed per the D-058 sequence) performed the
    D-058 residue handling deterministically: insight rows whose
    synthetic household scope referenced nothing that exists (98 rows
    of pre-W3 F6 test fixtures) were removed, system-scope NULL rows
    were preserved, household_id was narrowed to CHAR(36) with the
    dedup unique key rebuilt around it, and the ratified RESTRICT
    foreign key was added. The same rule is asserted in tests: no
    insight may reference a non-existent household.
8.  Test-fixture consequence: F6 integration tests now use real
    household rows (the synthetic 'f6-hh-*' identifiers are rejected by
    the FK by design); the W3 fixture helpers create account + ACTIVE
    household + OWNER membership through the production service layer.

## D-065 — W3.1 Household Profile Completion and Derived Onboarding Readiness — Database/Auth

Status: RATIFIED (implements the onboarding deferral boundary reserved
by D-064 §4 and the W4 profile-modification workflow; the PATCH shape
and the readiness-projection rules below are new decisions established
by the W3.1 implementation).

1.  PATCH /api/v1/household is the current household-profile
    modification primitive (W4-lite): own-route only, scope derived
    server-side through the same membership resolution as every other
    household read — D-064 §5's "no household-id addressing" stands.
    The body accepts exactly timezone, label and location_label;
    unknown fields (including any household_id/status/role claim) and
    malformed types fail closed with a field-scoped 422 and write
    nothing. Null or empty-string on a nullable field clears it; the
    fields share one validator with creation, so POST and PATCH
    semantics can never drift apart.
2.  The modification changes current display context only. The
    household id, status, memberships, roles, created_at and every
    historical/analytical row are outside the operation by
    construction; updated_at moves when a value actually changes.
    An empty PATCH is a valid no-op. This is the W4 boundary from
    docs/02 §9: profile changes do not rewrite the past.
3.  With no ACTIVE household context the PATCH answers 404 NOT_FOUND
    (the honest existing not-found envelope) — it never creates
    implicitly, never redirects, and never manufactures readiness.
    Non-ACTIVE households (DELETION_PENDING/DELETED) keep resolving to
    the null context per D-064 §2, so they are equally unmodifiable.
4.  GET /api/v1/household gained a derived `readiness` projection
    (additive; the W3 shape is unchanged). It is computed from
    authoritative facts on every read and is NEVER persisted: no
    onboarding_progress table, no wizard state, no step/progress
    storage exists or is introduced. The frontend reconstructs
    onboarding from API state, per docs/04 §35.
5.  The projection is exactly: email_verified (account truth),
    timezone_set and location_provided (household field truth), and
    the capability states solar=UNAVAILABLE, verified_bills=UNAVAILABLE
    and analysis=LIMITED. UNAVAILABLE means the source does not exist
    in this system yet — never a queried-and-empty zero, never
    "connected", and it keeps the docs/04 §15 boundary that "not
    connected" and service "unavailable" are not conflated into a fake
    positive. No number, percentage, score or prediction is
    fabricated (docs/04 §15/§20; D-042 verified-bill honesty).
6.  W3.1 introduces no GoodWe, bills, AWS/Bedrock, location-
    structuring or forecast dependency: GoodWe stays behind the open
    G1 gate (D-061–D-063), bills behind the future F13/W9–W10
    persistence, and the wizard UX still lands with the dashboard
    milestone (D-064 §4). Authentication, CSRF (the middleware
    already treats PATCH as a mutating method), the one-household v1
    rule and the F6 closure from D-064 §3 are inherited unchanged.
7.  Future milestones (structured location/W12, solar integration/
    W6, bills/W9–W10, dashboard) extend the readiness projection when
    authoritative sources exist — they must not replace its UNAVAILABLE
    states with invented readiness, nor move progress into the client.

**END OF DECISION LOG**
