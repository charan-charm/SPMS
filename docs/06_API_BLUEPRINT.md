# 06 — API Blueprint

**Status:** Phase 6 consolidated  
**Scope:** PHP JSON API contracts and semantics  
**API version:** `/api/v1`

---

## 1. Purpose

The SPMS API is the controlled boundary between the browser/frontend and backend application capabilities.

Primary flow:

```text
Browser / JavaScript
        ↓
HTTP Request
        ↓
Router
        ↓
Controller
        ↓
Application Service
        ↓
Repository / Integration
        ↓
Authoritative Result
        ↓
Controller
        ↓
JSON Response
```

The frontend never becomes authoritative for business truth.

---

## 2. API Versioning

The API is versioned from the beginning:

```text
/api/v1/...
```

Domain-oriented resources may include:

```text
/api/v1/households
/api/v1/solar
/api/v1/bills
/api/v1/forecasts
/api/v1/energy
/api/v1/analytics
/api/v1/reports
/api/v1/notifications
```

Exact endpoint inventory is finalized during implementation.

---

## 3. HTTP Methods

Use explicit HTTP semantics:

```text
GET
POST
PUT
PATCH
DELETE
```

Examples:

- GET for retrieval
- POST for creation/actions
- PUT/PATCH for meaningful updates
- DELETE for deletion

---

## 4. Authentication and Authorization

Protected APIs require authenticated session context.

Authentication establishes identity/session.

Authorization establishes permitted scope.

Every protected operation must validate:

- authentication
- account state
- role/capability
- household/resource ownership
- operation scope

The frontend cannot grant itself access.

---

## 5. Standard Success Envelope

The standard success response is:

```json
{
  "success": true,
  "data": {},
  "meta": {}
}
```

`meta` may contain safe information such as:

- pagination
- request/correlation reference
- operation status
- relevant context

---

## 6. Standard Error Envelope

Errors should provide, where appropriate:

- stable error code
- safe user-facing message
- structured context
- field-level validation information
- request/correlation reference

Example shape:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Please correct the highlighted fields.",
    "context": {}
  },
  "meta": {}
}
```

Exact schema is finalized during implementation and kept consistent.

---

## 7. HTTP Status Semantics

Meaningful HTTP status codes are used in addition to application error codes.

Typical categories:

```text
2xx → successful operation
4xx → client/request/auth/scope problem
5xx → server/infrastructure failure
```

The API should not return HTTP 200 for every outcome.

---

## 8. Stable Semantic Identifiers

APIs expose stable semantic identifiers rather than making UI labels authoritative.

This allows:

```text
Semantic Identifier
        ↓
Localized / contextual UI label
```

Labels can change without breaking application semantics.

---

## 9. Data Nature

Where relevant, API responses expose data nature explicitly:

```text
ACTUAL
PREDICTED
ESTIMATED
SIMULATED
```

The frontend must not infer nature from styling alone.

---

## 10. Data Status

Data status remains separate:

```text
FRESH
STALE
DEGRADED
UNAVAILABLE
```

A record can therefore be represented meaningfully as:

```text
ACTUAL + STALE
PREDICTED + FRESH
ESTIMATED + DEGRADED
SIMULATED + FRESH
```

---

## 11. Measurement Context

Where relevant, API data distinguishes:

- measured/actual
- inferred
- predicted
- potential
- estimated
- simulated

For example, potential surplus is not exposed as confirmed grid export when direct grid measurement does not exist.

---

## 12. Confidence

Applicable predictions/recommendations expose structured confidence information.

The frontend does not calculate confidence from arbitrary UI fields.

Confidence may include:

- stable level
- validated numeric measure where appropriate
- uncertainty/range
- supporting context

---

## 13. Provenance

Where useful and safe, APIs expose user-understandable provenance.

Examples:

- source type
- measurement/source time
- freshness
- data nature
- relevant processing/model context

Internal secrets, credentials, and unnecessary technical internals are never exposed.

---

## 14. DTOs

DTOs provide controlled semantic data transfer between application boundaries.

They prevent accidental coupling between:

```text
Database Entity
       ≠
Domain Object
       ≠
API Representation
```

The exact degree of separation is chosen per domain.

---

## 15. Field Exposure

Controllers explicitly select fields appropriate to the API response.

Entire database entities must not be serialized by default.

Sensitive/internal fields are explicitly excluded.

Examples of fields that must never accidentally enter normal API responses:

- password hashes
- recovery token data
- provider credentials
- secret configuration
- unnecessary private file paths
- internal security diagnostics

---

## 16. Request Validation

Every state-changing request passes authoritative backend validation before business processing.

Validation should be represented through dedicated request/validation components where practical.

Frontend validation remains UX support only.

---

## 17. Normalization

Incoming values are normalized centrally before domain processing.

Normalization should be consistent across endpoints.

Controllers should not independently implement conflicting normalization rules.

---

## 18. Filtering, Sorting, Pagination

Backend validates:

- filters
- ranges
- date periods
- pagination
- limits
- supported sorting

APIs should not expose unrestricted database queries.

---

## 19. API Operation Status

Long-running operations return authoritative backend operation status.

Possible states include:

```text
QUEUED
PROCESSING
COMPLETED
FAILED
CANCELLED
```

The frontend must not convert `ACCEPTED` or `QUEUED` into `COMPLETED`.

---

## 20. Correlation IDs

Meaningful requests/operations may expose a safe correlation/request ID.

Correlation context can flow through:

```text
HTTP Request
 ↓
Application Operation
 ↓
Background Job
 ↓
External Integration
 ↓
Logs
```

The identifier is for traceability, not authorization.

---

## 21. API Security

Protected APIs use:

- session authentication
- authorization
- CSRF protection for authenticated state-changing browser requests
- backend validation
- rate limiting where required
- bounded abuse protection
- safe errors

---

## 22. API Principle

> APIs transfer validated semantic meaning from authoritative backend capabilities to the presentation layer. They do not expose raw database state, delegate business truth to JavaScript, or hide the distinction between actual, predicted, estimated, simulated, fresh, stale, degraded, and unavailable data.
