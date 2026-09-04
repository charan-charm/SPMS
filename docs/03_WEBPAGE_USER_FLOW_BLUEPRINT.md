# SPMS — Phase 3: Webpage & User Flow Blueprint

**Status:** LOCKED / COMPLETE  
**Phase:** 3 — Webpage & User Flow

## 1. Purpose

Phase 3 maps the locked SPMS functional architecture into a coherent user-facing webpage, navigation, and journey structure.

### Core principle

> **Module ≠ Page.**

A backend module may support multiple pages, and a page may consume outputs from multiple modules.

---

## 2. Locked Structural Decisions

1. Landing: calm, minimal project-introduction page → Login/Register.
2. Authentication: Login, Register, Email Verification, Account Recovery.
3. Onboarding: adaptive multi-step experience.
4. Household Setup/Profile: same underlying profile, distinct setup vs later-edit experiences.
5. Solar: Overview + History + Connection.
6. Energy: Overview + Consumption + Balance + Cost.
7. Forecasts: Solar Forecast + Consumption Forecast + Energy Outlook.
8. Insights & Recommendations: one destination with separate sections.
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

---

## 3. Official Page Tree

```text
SPMS
│
├── PUBLIC
│   └── Landing / Welcome
│
├── AUTHENTICATION
│   ├── Login
│   ├── Register
│   ├── Email Verification
│   └── Account Recovery
│
├── ONBOARDING
│   ├── Household Setup
│   ├── Location Setup
│   ├── Solar / GoodWe Connection
│   ├── Electricity Bill
│   └── Initial Data Review
│
├── MAIN USER WORKSPACE
│   ├── Dashboard
│   │   ├── Energy Status
│   │   ├── Solar Snapshot
│   │   ├── Consumption Snapshot
│   │   ├── Energy Balance
│   │   ├── Forecast Snapshot
│   │   ├── Cost Snapshot
│   │   ├── Key Insight
│   │   ├── Recommended Action
│   │   ├── Important Notification
│   │   └── Trend Snapshot
│   │
│   ├── Solar
│   │   ├── Solar Overview
│   │   ├── Solar History
│   │   └── Solar Connection
│   │
│   ├── Energy
│   │   ├── Energy Overview
│   │   ├── Consumption
│   │   ├── Energy Balance
│   │   └── Cost
│   │
│   ├── Forecasts
│   │   ├── Solar Forecast
│   │   ├── Consumption Forecast
│   │   └── Energy Outlook
│   │
│   ├── Insights & Recommendations
│   │   ├── Insights
│   │   └── Recommendations
│   │
│   ├── Analytics
│   │   ├── Historical Analysis
│   │   ├── Comparisons
│   │   └── Trends
│   │
│   ├── Bills
│   │   ├── Bills Overview
│   │   ├── Upload Bill
│   │   └── Bill Verification
│   │
│   ├── Reports
│   │   ├── Reports
│   │   └── Generate Report
│   │
│   └── Notifications
│       └── Notification Center
│
├── SETTINGS
│   ├── Household Profile
│   ├── Preferences
│   ├── Notification Settings
│   ├── Privacy & Data
│   └── Account
│
├── DEMO / TEST
│   ├── Scenario Selection
│   ├── Simulation
│   └── System States
│
└── ADMIN
    ├── Admin Dashboard
    ├── Users / Households
    ├── System Health
    ├── Integrations
    ├── Model Monitoring
    ├── Audit / Operations
    └── Demo/Test Management
```

This is the **logical page tree**, not a prescription that every item must become a separate physical PHP file.

---

## 4. Primary User Navigation

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

Contextual information may include user profile, household, connection status, data quality/status, and Demo/Test indicator where applicable.

---

## 5. Dashboard

Dashboard is the **personalized energy command center**.

It should quickly answer:

> What is happening with my solar and household energy, what is likely to happen next, and what should I pay attention to?

Potential sections:

```text
Current Energy Status
Solar Today
Household Demand
Energy Balance
Forecast Snapshot
Cost Snapshot
Key Insight
Recommended Action
Important Notification
Trend Snapshot
```

Dashboard must not duplicate the full Analytics workspace.

---

## 6. Solar

```text
Solar
├── Overview
├── History
└── Connection
```

Solar focuses on solar-source information and actual/source-related status. Forecasting remains primarily under Forecasts.

---

## 7. Energy

```text
Energy
├── Overview
├── Consumption
├── Balance
└── Cost
```

This provides a clear household-energy view without turning every analytical module into a top-level page.

---

## 8. Forecasts

```text
Forecasts
├── Solar Forecast
├── Consumption Forecast
└── Energy Outlook
```

Forecast presentation preserves horizon, supported resolution, confidence, uncertainty, model/version context where appropriate, actual-vs-predicted distinction, and fallback/status information where applicable.

---

## 9. Insights & Recommendations

```text
Insights & Recommendations
├── Insights
└── Recommendations
```

The distinction remains:

```text
Insight
"What does this mean?"

Recommendation
"What could I reasonably do?"
```

Recommendations do not directly control appliances.

---

## 10. Analytics and Graphs

Analytics is a major SPMS destination because graphs are a required functional capability.

Supported analytical views include:

- Solar generation
- Household consumption
- Solar vs consumption
- Energy balance
- Cost
- Trends
- Forecast vs actual
- Historical comparisons
- Supported time ranges and resolutions

### Graph architecture

```text
Authoritative analytical data
        ↓
Historical Analytics & Reporting
        ↓
Graph-ready dataset
        ↓
JavaScript chart component
        ↓
Rendered graph
```

Analytics prepares authoritative graph-ready information; the frontend renders the visualization.

---

## 11. Bills

```text
Bills
├── Bills Overview
├── Upload Bill
└── Bill Verification
```

The experience preserves:

- Original uploaded evidence
- AI-extracted information
- User-corrected information
- Verified billing evidence

---

## 12. Reports

```text
Reports
├── Reports
└── Generate Report
```

Reports package authoritative outputs rather than recreating upstream calculations.

Potential report content:

- Validated metrics
- Graphs
- Trends
- Energy situation
- Cost
- Insights
- Recommendations
- Confidence
- Data quality
- Provenance
- Measurement limitations

---

## 13. Notifications

```text
Notification Bell
        ↓
Notification Center
```

A detected event is not automatically a notification. A recommendation is not automatically a notification. Notification Management evaluates significance, reliability, actionability, urgency, relevance, recurrence, preferences, and system priority.

---

## 14. Settings

```text
Settings
├── Household Profile
├── Preferences
├── Notification Settings
├── Privacy & Data
└── Account
```

Explicit user preferences override learned personalization within system-defined boundaries.

---

## 15. Demo/Test

Demo/Test is an account mode rather than a normal household-user role.

```text
Normal User
    ↓
No Demo controls

Demo/Test Account
    ↓
Demo/Test workspace

Admin
    ↓
Demo/Test management
```

Simulated information must remain clearly labeled and must not be confused with live household measurements.

---

## 16. Admin Workspace

```text
Admin
├── Admin Dashboard
├── Users / Households
├── System Health
├── Integrations
├── Model Monitoring
├── Audit / Operations
└── Demo/Test Management
```

Admin capabilities remain bounded by authorization rules.

---

## 17. Primary Adaptive User Journey

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
GoodWe Connection ──────┐
   ↓                    │
Solar Available         │
                        │
Bill Processing         │
   ↓                    │
Data Review              │
   └──────────┬─────────┘
              ↓
          Dashboard
              ↓
    ┌─────────┼──────────┐
    ↓         ↓          ↓
  Solar     Energy    Forecasts
    │         │          │
    └─────────┼──────────┘
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
        Notifications
              ↓
    Analytics / Reports
              ↓
          Feedback
              ↓
      Personalization
              ↺
```

The flow is adaptive. Optional or unavailable inputs must not unnecessarily trap the user.

---

## 18. Missing Data / Degraded Experience

```text
Missing data
     ↓
Degraded capability
     ↓
Clear status
     ↓
Trustworthy fallback where available
     ↓
Continue where possible
```

Not:

```text
Missing data
     ↓
User blocked
```

Applicable page/capability states may include:

- Loading
- Available
- No Data
- Insufficient Data
- Stale
- Degraded
- Unavailable
- Error
- Recovered
- Demo

Only relevant states should appear for a particular page/capability.

---

## 19. Data Meaning in the UI

Where misunderstanding is possible, the interface should expose appropriate context such as:

```text
ACTUAL
PREDICTED
ESTIMATED
SIMULATED
CACHED
STALE
FALLBACK
UNAVAILABLE
```

Example:

```text
Solar Generation
12.4 kWh
ACTUAL
GoodWe
Updated 10 min ago
```

versus:

```text
Household Consumption
9.8 kWh
PREDICTED
Confidence: Moderate
```

SPMS must never present a calculated potential deficit as confirmed grid import unless direct measurement exists.

---

## 20. Page vs Module

The architecture intentionally avoids:

```text
29 workflows = 29 pages
```

Instead:

```text
Few strong destinations
        ↓
Logical sections/sub-pages
        ↓
Reusable components
        ↓
Cross-cutting intelligence
```

---

## 21. Phase 3 Completion

Phase 3 locks:

- Page hierarchy
- Authentication structure
- Adaptive onboarding
- Main navigation
- Dashboard role
- Solar/Energy/Forecast separation
- Insights/Recommendations presentation
- Analytics workspace
- Graph placement
- Bills and reports placement
- Notification center
- Settings
- Demo/Test boundaries
- Admin workspace
- Adaptive user journey
- Degraded/missing-data behavior
- Contextual confidence/quality/provenance/data-nature visibility
- Module ≠ Page principle

**PHASE 3: COMPLETE / LOCKED**

---

## 22. Next Phase

**Phase 4 — UI/UX Design System**

To define:

- Brand/visual identity
- Light and dark themes
- Color system
- Typography
- Spacing
- Cards
- Buttons
- Forms
- Tables
- Graph styling
- Status indicators
- Data-state presentation
- Navigation styling
- Loading/empty/error/degraded states
- Responsive behavior
- Accessibility
- Motion/animation principles
- Solar/SPMS visual identity
