# 08 — Security Rules

**Status:** Phase 6 consolidated — React frontend boundary updated  
**Scope:** SPMS application, data, integration, frontend, API, and operational security

## 1. Security Principle

Security is enforced primarily at the backend.

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

## 2. React Is an Untrusted Client

All React-provided values are untrusted, including form values, route parameters, query parameters, IDs, filters, local storage, client state, UI permission flags, client-calculated values, and uploaded file metadata.

PHP must validate and authorize them.

## 3. Public Web Root

Only the intended public web entry area is directly web-accessible.

Protected application code, configuration, secrets, logs, private files, and storage remain outside the public web root.

## 4. API Security

Every protected API validates authentication, account state, role/capability, household/resource ownership, lifecycle state, and operation scope as appropriate.

## 5. Password Security

Passwords use strong one-way PHP password hashing APIs. Backend policy is authoritative. Passwords are never logged, returned, or stored in plaintext.

## 6. Email Verification

Verification tokens are short-lived, unpredictable, single-use, and replay-resistant.

## 7. Account Recovery

Recovery uses secure, time-limited, single-use tokens. Avoid unnecessary account-enumeration leakage.

## 8. Session Security

Sessions are server-managed.

Cookies use:

```text
Secure
HttpOnly
SameSite
```

Session IDs are regenerated after authentication. Logout invalidates the session. React does not store the authoritative session token in ordinary local storage.

## 9. CSRF

Authenticated state-changing browser requests require CSRF protection. GET remains read-oriented.

## 10. Authorization

Authorization is enforced on every protected operation.

Roles:

```text
USER
ADMIN
```

Demo/Test is an account mode, not an authorization role.

## 11. Admin Security

Admin accounts are individual authenticated identities. Admin actions are auditable and capability-scoped.

## 12. Rate Limiting

Sensitive/high-risk operations use rate limiting where appropriate. Authentication/recovery-sensitive operations have bounded brute-force protection.

## 13. Secrets

Secrets remain outside source control and server-side.

Never place server secrets in React source, browser-exposed build variables, API responses, or logs.

## 14. GoodWe Credential Security

GoodWe credentials are backend-only and never exposed to React, returned through ordinary APIs, stored in browser storage, or logged.

GoodWe credentials are a separate credential domain from SPMS account passwords: SPMS never stores the GoodWe secret as a one-way password hash, and the provider's wire encoding is not a storage mechanism. If the integration requires the original secret, it is encrypted at rest with keys held outside the database and repository (docs/10 D-059; mechanism decided in G0). GoodWe credentials/tokens never appear in AI/Bedrock prompts.

## 15. File Upload Security

Uploaded bills are untrusted.

Backend validation includes content/MIME checks, allowed file type, file size, and filename/security checks as appropriate.

Files are stored outside the public web root.

## 16. File Processing Security

```text
React
↓
PHP API
↓
Validation
↓
Private Storage
↓
Controlled Extraction
↓
AI Interpretation
↓
Verification
```

AI extraction remains draft until verification.

## 17. AI Security

AI receives minimum necessary validated context. External/user-provided content is treated as untrusted for prompt-injection purposes. AI output is constrained and validated before presentation.

AI is never:

- numerical authority
- authorization authority
- lifecycle authority
- data-quality authority

## 18. Client-Side Security Limitations

The following are UX mechanisms only:

- hidden buttons
- disabled buttons
- React route guards
- client permission checks
- local storage flags
- client role labels
- optimistic UI

They never replace backend authorization.

## 19. URL Security

URLs may carry safe navigation context. URLs never grant access, ownership, verification, completion, confidence, quality, or analytical truth.

## 20. Browser Storage

Use browser storage only for safe presentation/context where appropriate. Never use it as authoritative authentication, authorization, lifecycle, GoodWe, operation, or analytical state.

## 21. XSS / Output Safety

Preserve React escaping. Avoid unsafe HTML injection. User-generated and AI-generated content remains untrusted until appropriately constrained/sanitized.

## 22. API Error Safety

Production responses must not expose stack traces, SQL, filesystem paths, secrets, provider credentials, or internal class names.

## 23. Logging

Logs are structured, redacted, correlation-aware, and outside the public web root.

Do not log passwords, session secrets, GoodWe credentials, AWS secrets, recovery tokens, unnecessary bill contents, or unnecessary sensitive personal data.

## 24. Data Authority

Backend remains authoritative for analytical calculations, energy balance, cost, prediction, recommendation policy, notification decisions, data quality, provenance, and operation completion.

## 25. Security and Failure

UI success is never backend success.

For example:

```text
API timeout ≠ operation completed
UI success animation ≠ backend success
```

Where operation state is unknown, retrieve authoritative backend state.

## 26. Account Deletion

```text
Authorize
↓
Confirm
↓
Revoke access
↓
Invalidate sessions/credentials
↓
Stop active processing
↓
Revoke external integrations
↓
Category-specific data disposition
↓
Audit
↓
Reconcile
```

Partial failure must not be reported as complete success.

## 27. Governing Rule

> **The browser is an interface, not a trust boundary.**

**END OF SECURITY RULES**
