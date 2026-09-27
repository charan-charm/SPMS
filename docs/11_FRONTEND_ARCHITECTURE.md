# SPMS — Frontend Architecture

**Status:** 🔒 LOCKED — F0  
**Purpose:** Define the implementation architecture of the React frontend and its boundary with the PHP backend.

## 1. Objective

SPMS uses a modern React frontend for a dynamic, responsive, accessible application experience.

The frontend consumes backend-authoritative data through REST APIs.

## 2. Architecture

```text
Browser
↓
React Application
↓
Central API Client
↓
PHP REST API
↓
Controllers
↓
Services
↓
Repositories / Integrations
↓
MySQL / External Providers
```

## 3. Approved Stack

| Area | Technology |
|---|---|
| UI | React |
| Build/dev | Vite |
| Styling | Tailwind CSS |
| Component foundation | shadcn/ui where appropriate |
| Animation | Motion |
| Icons | Lucide |
| Charts | Recharts initially |
| Routing | React Router |
| API | Native `fetch()` through centralized client |
| Backend | PHP |
| API | REST JSON |
| Database | MySQL-compatible relational DB |
| Local environment | XAMPP |

Node.js is frontend tooling only. Express is not the SPMS backend.

## 4. Directory Structure

```text
frontend/
├── src/
│   ├── app/
│   ├── components/
│   │   ├── ui/
│   │   ├── navigation/
│   │   ├── feedback/
│   │   └── data-display/
│   ├── layouts/
│   ├── pages/
│   │   ├── public/
│   │   ├── auth/
│   │   └── app/
│   ├── features/
│   │   ├── authentication/
│   │   ├── household/
│   │   ├── solar/
│   │   ├── energy/
│   │   ├── forecasts/
│   │   ├── intelligence/
│   │   ├── analytics/
│   │   ├── bills/
│   │   ├── reports/
│   │   ├── notifications/
│   │   └── settings/
│   ├── charts/
│   ├── hooks/
│   ├── services/
│   │   └── api/
│   ├── lib/
│   └── styles/
├── public/
├── package.json
└── ...
```

## 5. Component Layers

### UI primitives

Button, Input, Select, Checkbox, Radio, Switch, Dialog, Sheet, Tabs, Tooltip, Badge.

### SPMS shared components

StatusBadge, DataNatureBadge, ConfidenceIndicator, QualityIndicator, EmptyState, LoadingState, ErrorState, OperationStatus, MetricCard, ChartContainer, RecommendationCard, NotificationItem.

### Feature components

Domain-specific presentation under `features/`.

Feature components consume API data and do not recreate backend business rules.

## 6. Page Architecture

```text
Layout
↓
Feature components
↓
Shared components
↓
UI primitives
```

Pages contain no SQL, backend business logic, provider credentials, authoritative calculations, or authorization decisions.

## 7. API Architecture

```text
React component
↓
Feature service
↓
Central API client
↓
/api/v1/...
```

## 8. Authentication Architecture

```text
Login form
↓
POST /api/v1/auth/login
↓
PHP authentication
↓
PHP session
↓
Secure cookie
↓
React authenticated UI
```

React authentication context is presentation context only. The server is authoritative.

## 9. Routing

React Router provides client-side navigation.

Public:

```text
/
/login
/register
/verify
/recover
```

Protected:

```text
/dashboard
/solar
/energy
/forecasts
/insights
/analytics
/bills
/reports
/notifications
/settings
```

## 10. State Management

Start with React state and Context where genuinely global state is required.

Do not introduce Redux or another global store without demonstrated need.

## 11. Design System Integration

Tailwind consumes centralized SPMS design tokens.

shadcn/ui provides foundations where useful.

Lucide provides icons.

Motion provides purposeful animation.

Charts use shared SPMS chart components.

## 12. Chart Architecture

```text
PHP analytical service
↓
API
↓
Graph-ready semantic dataset
↓
React chart component
↓
Recharts
```

Charts preserve nature, status, confidence, units, measurement context, and relevant provenance.

## 13. Responsive Architecture

Desktop: sidebar and multi-column analytical layouts.

Tablet: compact navigation and adaptive grids.

Mobile: drawer/compact navigation, stacked content, touch-friendly controls, responsive charts, adaptive tables.

## 14. Theme Architecture

Use one SPMS theme system:

```text
System preference
↓
Explicit user preference
↓
Persisted preference
```

## 15. Motion Architecture

Use CSS for simple transitions and Motion for coordinated animation.

Respect reduced motion.

Never use animation to conceal system state or uncertainty.

## 16. Error and Failure Architecture

Support:

```text
Loading
Available
Empty
Insufficient
Stale
Degraded
Unavailable
Error
Recovered
```

React does not invent fallback analytical values.

## 17. Security Boundary

React is untrusted.

Client-side route guards, local storage, URLs, and UI permission flags never replace backend authorization.

## 18. Governing Principle

> **Build reusable presentation systems, not isolated pages.**

**END OF FRONTEND ARCHITECTURE**
