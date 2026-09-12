# 08 — Security Rules

**Status:** Phase 6 consolidated  
**Scope:** SPMS application, data, integration, and operational security

---

## 1. Security Principle

Security is enforced primarily at the backend.

The core rule is:

```text
Authentication
    ↓
Authorization
    ↓
Validation
    ↓
Controlled Operation
    ↓
Auditable Result
```

Frontend controls are not security boundaries.

---

## 2. Public Web Root

Only the public application entry area is directly web-accessible.

Protected:

- application code
- configuration
- secrets
- logs
- bill files
- private storage

must remain outside the public web root.

---

## 3. Password Security

Passwords use strong one-way PHP password hashing APIs.

Backend password policy is authoritative.

Passwords are never logged, returned through APIs, or stored in plaintext.

---

## 4. Email Verification

New accounts require controlled email verification before full protected access.

Verification tokens are:

- short-lived
- unpredictable
- single-use
- replay-resistant

Already consumed tokens cannot be reused.

---

## 5. Account Recovery

Recovery uses secure, time-limited, single-use tokens.

Recovery token storage is protected.

The system should avoid unnecessary account-enumeration leakage.

---

## 6. Session Security

Sessions are server-managed.

Cookies use appropriate:

```text
Secure
HttpOnly
SameSite
```

controls.

Session IDs are regenerated after authentication.

Logout invalidates the session.

Session expiration is controlled and configurable.

Account/lifecycle state changes can invalidate active sessions.

---

## 7. Authorization

Authorization is enforced for every protected operation.

Checks may include:

- authenticated identity
- role
- capability
- household ownership
- resource ownership
- lifecycle state
- operation scope

Roles:

```text
USER
ADMIN
```

Demo/Real is an account mode/state, not an authorization role.

---

## 8. Admin Security

Admin accounts are individual authenticated identities.

Admin actions are auditable.

Sensitive admin capabilities require explicit authorization.

Admin access does not bypass all business/security controls automatically.

---

## 9. CSRF

Authenticated state-changing browser requests require CSRF protection.

CSRF tokens must be unpredictable and server-associated.

GET requests remain safe/read-oriented.

---

## 10. Sensitive Operations

Sensitive operations may require:

- explicit confirmation
- re-authentication
- authorization
- lifecycle checks

Examples include account deletion and other high-impact operations.

Re-authentication does not automatically complete an operation.

---

## 11. Rate Limiting and Abuse Protection

Sensitive/high-risk operations use rate limiting where appropriate.

Bounded brute-force protection applies to authentication/recovery-sensitive operations.

External integrations use bounded retry behavior.

Repeated provider failures must not create uncontrolled retry loops.

---

## 12. Secrets

Secrets remain outside source control and are environment-specific.

Examples:

- database credentials
- application secrets
- GoodWe credentials
- AWS credentials/configuration
- email provider credentials

Secrets must never be hardcoded in JavaScript or committed to Git.

---

## 13. GoodWe Credential Security

GoodWe credentials are handled only in the backend integration boundary.

They are never exposed to browser JavaScript.

They are never placed in ordinary analytical API responses.

They are excluded from logs.

GoodWe credentials are a separate credential domain from SPMS account passwords: SPMS never stores the GoodWe secret as a one-way password hash, and the provider's wire encoding is not a storage mechanism. If the integration requires the original secret, it is encrypted at rest with keys held outside the database and repository (docs/10 D-059; mechanism decided in G0). GoodWe credentials/tokens never appear in AI/Bedrock prompts.

---

## 14. File Upload Security

Uploaded bills must pass backend security/content validation.

Validation includes, as appropriate:

- MIME/content checks
- allowed type
- file size
- filename/security checks

Browser-provided MIME information is not trusted by itself.

---

## 15. Protected File Storage

Bill files are stored outside the public web root.

Stored filenames are server-generated controlled identifiers.

Raw user filenames are not used as storage identifiers.

Authorized retrieval is through backend-controlled access.

There are no public static bill URLs.

---

## 16. File Processing

Processing follows:

```text
Upload
 ↓
Validate
 ↓
Secure Storage
 ↓
Process
 ↓
Verify
```

If processing fails:

- original remains protected
- failure is recorded
- manual recovery/entry remains available

A failed extraction must never become a verified bill automatically.

---

## 17. Input Validation

Backend validation is authoritative for:

- API requests
- form data
- query parameters
- file uploads
- identifiers
- date ranges
- pagination
- sorting
- sensitive operations

Prepared statements are used for database access.

---

## 18. Error Exposure

Production users receive safe meaningful messages.

Do not expose:

- stack traces
- SQL errors
- filesystem paths
- credentials
- internal architecture details that create unnecessary risk

Technical diagnostics remain in protected logs.

---

## 19. Logging Security

Logs are structured, minimized, and redacted.

Never log:

- passwords
- recovery tokens
- GoodWe credentials
- AWS/API secrets
- unnecessary bill contents
- unnecessary personal information

Security-relevant events should still be recorded.

---

## 20. Account Deletion Security

Deletion follows:

```text
Authorize
 ↓
Confirm
 ↓
Revoke Access
 ↓
Invalidate Sessions/Credentials
 ↓
Stop Active Processing
 ↓
Revoke External Integrations
 ↓
Controlled Data Disposition
 ↓
Audit/Reconcile
```

Partial failure must not be represented as successful deletion.

---

## 21. Data Lifecycle

Data deletion/retention is category-specific.

Historical records that must retain analytical meaning are protected from inappropriate deletion.

Only minimum legitimately required technical/security/audit information may remain after deletion.

---

## 22. AI Security

AI receives only minimum necessary validated semantic context.

Sensitive raw data should not be sent unnecessarily.

AI output is validated before presentation.

AI cannot override backend numerical truth.

---

## 23. Operational Security

External integrations have:

- explicit timeouts
- bounded retries
- idempotency where necessary
- health monitoring
- controlled failure states

Operational failures are isolated so they do not silently corrupt unrelated capabilities.

---

## 24. Core Principle

> Authentication establishes who the user is. Authorization determines what that identity may do. Backend validation and lifecycle controls determine whether an operation is allowed. Security mechanisms must remain enforced even when frontend behavior is manipulated.
