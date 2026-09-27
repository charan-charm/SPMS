# SPMS — Phase 3: Webpage & User Flow Blueprint

**Status:** LOCKED / COMPLETE — Frontend implementation direction updated  
**Phase:** 3 — Webpage & User Flow

## 1. Purpose

Phase 3 maps the locked SPMS functional architecture into a coherent user-facing page, navigation, responsive application, and user-journey structure.

The page architecture is technology-independent. The current implementation direction uses a React frontend consuming PHP REST APIs.

### Core principle

> **Module ≠ Page.**

A backend module may support multiple pages, and a page may consume outputs from multiple modules.

## 2. Frontend Presentation Direction

The user-facing SPMS application is implemented as a modern React application.

Approved frontend stack:

- React
- Vite
- Tailwind CSS
- Motion
- shadcn/ui where appropriate
- Lucide Icons
- Recharts or another explicitly approved charting library
- Semantic HTML5
- CSS3 concepts
- JavaScript

The frontend is responsible for presentation, interaction, client-side UI state, API consumption, visualizations, and purposeful animation.

The frontend is **not** authoritative for business truth, analytical truth, authorization, data quality, provenance, lifecycle completion, or security.

## 3. Locked Structural Decisions

1. Landing: calm, premium project-introduction page → Login/Register.
2. Authentication: Login, Register, Email Verification, Account Recovery.
3. Onboarding: adaptive multi-step experience.
4. Household Setup/Profile: same underlying profile, distinct setup vs later-edit experiences.
5. Solar: Overview + History + Connection.
6. Energy: Overview + Consumption + Balance + Cost.
7. Forecasts: Solar Forecast + Consumption Forecast + Energy Outlook.
8. Insights & Recommendations: one destination with separate Insights and Recommendations sections.
9. Analytics: dedicated major workspace.
10. Bills: dedicated area with overview, upload, verification.
11. Reports: dedicated area with reports and generation workflow.
12. Notifications: notification bell + Notification Center.
13. Settings: all user-controlled settings.
14. Demo/Test: mode-based for Demo/Test accounts; Admin manages scenarios.
15. Admin: separate Admin workspace under the same security architecture.
16. Dashboard: personalized household-energy command center.
17. Page states: adaptive capability-aware states.
18. Data nature, confidence, quality and provenance are shown contextually wherever misunderstanding is possible.
19. Missing data degrades capabilities rather than unnecessarily blocking the user.
20. Main journey is adaptive rather than rigid.
21. Desktop, tablet, and mobile are first-class presentation contexts.
22. Interactive UI uses the shared SPMS design system rather than page-specific visual languages.
23. Analytics and charts are interactive where useful, but visual complexity adapts to user purpose and device.
24. AI recommendations are presented as advisory intelligence and remain separate from numerical prediction authority.
25. Bills are a first-class household-energy input and include upload, processing, extraction, verification, correction, and trusted-record states.

## 4. Official Page Tree

```text
SPMS
├── PUBLIC
│   └── Landing / Welcome
├── AUTHENTICATION
│   ├── Login
│   ├── Register
│   ├── Email Verification
│   └── Account Recovery
├── ONBOARDING
│   ├── Household Setup
│   ├── Location Setup
│   ├── Solar / GoodWe Connection
│   ├── Electricity Bill
│   └── Initial Data Review
├── MAIN USER WORKSPACE
│   ├── Dashboard
│   ├── Solar
│   ├── Energy
│   ├── Forecasts
│   ├── Insights & Recommendations
│   ├── Analytics
│   ├── Bills
│   ├── Reports
│   └── Notifications
├── SETTINGS
│   ├── Household Profile
│   ├── Preferences
│   ├── Notification Settings
│   ├── Privacy & Data
│   └── Account
├── DEMO / TEST
└── ADMIN
```

## 5. Main User Navigation

```text
Dashboard
Solar
Energy
Forecasts
Insights & Recommendations
Analytics
Bills
Reports
Notifications
Settings
```

Presentation adapts by device: expanded/collapsible sidebar on desktop, compact navigation on tablet, drawer and/or approved compact navigation on mobile.

## 6. Public Landing Page

The landing page communicates:

- what SPMS is
- why household solar management matters
- how household bills contribute
- how analytics work
- how forecasts work
- what energy balance means
- how AI-assisted recommendations help
- why confidence, quality, and provenance matter
- how users can begin

Illustrative examples must be clearly marked and must never appear to be real household measurements.

## 7. User Journey

```text
Landing
↓
Login / Register
↓
Authentication
↓
Household Setup
↓
Location Setup
↓
GoodWe Connection (or Skip/Later)
↓
Bill Processing
↓
Data Review
↓
Dashboard
↓
Solar / Energy / Forecasts
↓
Energy Situation
↓
Cost Impact
↓
Intelligence
↓
Insights
↓
Recommendations
↓
Notifications where appropriate
↓
Analytics / Reports
↓
Feedback
↓
Personalization
```

Missing or unavailable data should degrade capabilities rather than unnecessarily blocking the user.

## 8. Page State Model

Important pages/components may present:

- Loading
- Available
- No data
- Insufficient data
- Stale
- Degraded
- Unavailable
- Processing
- Error
- Recovered
- Demo/Test
- Disabled
- Permission restricted
- Filtered empty

## 9. Data Meaning

Nature:

```text
ACTUAL
PREDICTED
ESTIMATED
SIMULATED
```

Status:

```text
FRESH
STALE
DEGRADED
UNAVAILABLE
PROCESSING
ERROR
```

Measurement context:

```text
MEASURED
INFERRED
PREDICTED
ESTIMATED
POTENTIAL
SIMULATED
```

Nature and status are independent. Color alone never communicates meaning.

## 10. Analytics and Graphs

```text
Authoritative analytical data
↓
Analytics / Reporting
↓
Graph-ready semantic dataset
↓
React chart component
↓
Approved charting library
↓
Rendered visualization
```

Continuous time-series data should use smooth curves where semantically valid.

Interactive chart controls may include time range, comparison, series visibility, hover/focus details, drill-down, reset, and expansion. Controls adapt to page, dataset, purpose, and device.

## 11. Bills

```text
Upload
↓
Processing
↓
Extraction
↓
AI Interpretation / Normalization
↓
Verification
↓
Correction
↓
Verified Bill
```

AI-extracted values remain draft until user verification.

## 12. Insights and Recommendations

```text
Validated analytical evidence
↓
Intelligence
↓
Insight
↓
Recommendation
↓
Possible notification
```

AI may explain validated facts but does not create authoritative numerical truth. Recommendations remain advisory. SPMS does not directly control appliances.

## 13. Adaptive Exploration

Q18 remains **C — Adaptive Data Exploration**:

> **Make information easy to find without making the interface harder to understand.**

Use contextual search, filters, filter chips, date ranges, comparisons, sorting, and saved preferences where useful. Do not replace this with a giant universal query interface.

## 14. Accessibility

All pages and reusable components must support semantic HTML, logical heading hierarchy, keyboard access, visible focus, accessible labels, meaningful alternative text, reduced-motion behavior, non-color-only status communication, and readable responsive layouts.

## 15. Frontend/Backend Boundary

React is responsible for rendering, components, UI state, interactions, API consumption, visualizations, animations, and presentation-only transformations.

PHP remains responsible for authentication, authorization, validation, business rules, analytical authority, data quality, provenance, lifecycle state, persistence, and external integrations.

The browser cannot grant itself access or establish system truth.

**END OF PHASE 3 — WEBPAGE & USER FLOW BLUEPRINT**
