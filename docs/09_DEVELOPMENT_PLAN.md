# 09 — Development Plan

**Status:** Phase 6 complete / implementation-ready — frontend direction updated  
**Current milestone:** Frontend architecture transition and React foundation

## 1. Development Strategy

SPMS is implemented incrementally from technical foundation toward complete domain functionality.

> Implement the locked architecture incrementally, verify each meaningful boundary, preserve the established semantic/security/analytical rules, document concrete implementation decisions, test continuously, and commit stable milestones.

## 2. Current Architecture

```text
React / Vite Frontend
        ↓
REST JSON API
        ↓
PHP Backend
        ↓
MySQL
```

Backend:

```text
Controller
↓
Service
↓
Repository / Integration
```

React is presentation/interaction/API consumption.

## 3. Frontend Foundation Sequence

### F1 — Frontend Project Foundation

Establish:

- React
- Vite
- Tailwind CSS
- Motion
- shadcn/ui where appropriate
- Lucide
- React Router
- centralized API client
- frontend environment configuration
- frontend lint/test/build strategy
- shared styles/tokens

Do not build all pages in this milestone.

### F2 — Design System Foundation

Implement reusable foundations for typography, colors, spacing, radii, elevation, controls, cards, badges, navigation, responsive patterns, states, themes, reduced motion, and data presentation.

### F3 — Application Shell

Implement public layout, authenticated application layout, navigation, sidebar/drawer, header, theme control, notification entry, account entry, and route structure.

### F4 — React ↔ PHP API Boundary

Implement centralized API client, standardized success/error handling, API version path, authentication/session awareness, CSRF handling, request/correlation handling, and loading/error/degraded presentation.

## 4. Landing Page

### F5 — React M1.0 Landing Page

Rebuild the superseded PHP/HTML prototype using the React design system.

Target:

```text
Hero
↓
How SPMS Works
↓
Bills
↓
Energy Analytics
↓
AI Recommendations
↓
Energy Balance
↓
Cost Impact
↓
Features
↓
Data Trust
↓
Personalization
↓
About
↓
Final CTA
↓
Footer
```

Requirements include premium visual hierarchy, light/dark themes, smooth theme transition, purposeful Motion, animated analytical curve, bill-processing visual, separate AI recommendation section, responsive behavior, accessibility, and clearly labeled illustrative data.

## 5. Authentication Sequence

After React foundation and M1.0 acceptance:

```text
M1.1 Account Data Foundation
↓
M1.2 Registration
↓
M1.3 Email Verification
↓
M1.4 Login / Sessions
↓
M1.5 Authorization Foundation
↓
M1.6 Account Recovery
↓
M1.7 Logout / Account State
↓
M1.8 React Integration
↓
M1.9 M1 Verification
```

PHP remains the authentication authority.

## 6. Domain Sequence

```text
Household / Location
↓
Solar / GoodWe
↓
Bills / Extraction / Verification
↓
Environmental Data
↓
Prediction
↓
Energy Balance
↓
Cost
↓
Intelligence / Bedrock
↓
Recommendations / Notifications
↓
Analytics / Graphs
↓
Reports / Export
↓
Frontend feature integration/refinement
↓
Testing / Security / Recovery Hardening
↓
Deployment Readiness
```

## 7. Implementation Slice

```text
Design
↓
Database / persistence where required
↓
Backend service where required
↓
API contract
↓
React feature/UI
↓
Integration
↓
Tests
↓
Review
↓
Fix
↓
Stable Git checkpoint
```

Frontend-only work may omit backend/database steps when no backend behavior exists.

## 8. Testing Strategy

Testing occurs continuously.

Backend:

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

Frontend includes component tests, UI behavior tests, accessibility checks, route tests, API integration tests, responsive verification, theme verification, reduced-motion verification, and end-to-end workflows.

## 9. Frontend Quality Gates

Before acceptance:

- build succeeds
- relevant tests pass
- API contracts remain valid
- light/dark themes work
- responsive layouts work
- keyboard/accessibility checks pass
- important states are implemented
- no fake authoritative values are introduced
- design system is reused
- frontend does not duplicate backend business logic

## 10. Demo/Test

Use real architecture with controlled simulated inputs.

Scenarios include normal, low solar, potential surplus, potential deficit, stale data, GoodWe unavailable, prediction failure/fallback, Bedrock unavailable, and recovery/reconciliation.

## 11. Documentation

Frontend architecture:

```text
docs/11_FRONTEND_ARCHITECTURE.md
```

Frontend design system:

```text
docs/12_FRONTEND_DESIGN_SYSTEM.md
```

Shared UI system:

```text
docs/04_UI_DESIGN_SYSTEM.md
```

## 12. Git Strategy

Meaningful stable milestones are committed before proceeding.

Examples:

```text
M0 — Backend technical foundation
F1 — React frontend foundation
F2 — SPMS design system foundation
F3 — Application shell
F4 — React/PHP API boundary
M1.0 — React landing page
M1 — Authentication foundation
M2 — Household lifecycle
...
```

Do not create commits for every tiny edit.

## 13. Architectural Conflict Rule

If implementation conflicts with a locked decision:

```text
Conflict
↓
Stop
↓
Document
↓
Evaluate
↓
Explicitly decide
↓
Update documentation
↓
Continue
```

## 14. Phase Completion Rule

A meaningful milestone is complete only when implementation, relevant tests, documentation, architecture consistency, security boundaries, reviewable/stable state, owner/architect acceptance where applicable, and the Git checkpoint are complete.

## 15. Current Next Step

```text
F1 — React Frontend Foundation
```

Before F1 coding, finalize the detailed frontend architecture/design-system specification and then give the coding agent a controlled implementation prompt.

**END OF DEVELOPMENT PLAN**
