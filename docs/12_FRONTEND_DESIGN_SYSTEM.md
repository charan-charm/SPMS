# SPMS — Frontend Design System Specification

**Status:** 🔒 FOUNDATION SPECIFICATION — F0  
**Purpose:** Define the concrete React implementation layer for the approved SPMS UI/UX system.

This document complements `04_UI_DESIGN_SYSTEM.md`.

## 1. Design System Layers

```text
SPMS Design Tokens
↓
Tailwind Configuration / CSS Variables
↓
UI Primitives
↓
SPMS Shared Components
↓
Feature Components
↓
Layouts
↓
Pages
```

## 2. Technology

Approved:

- React
- Tailwind CSS
- shadcn/ui
- Motion
- Lucide
- Recharts
- React Router

## 3. Core Tokens

Primary Green:

```text
Light #22C55E
Dark  #34D399
```

Primary Teal:

```text
Light #06B6D4
Dark  #22D3EE
```

Light background:

```text
#F9FAFB
```

Dark background:

```text
#0B1220
```

Light surface:

```text
#FFFFFF
```

Dark surface:

```text
#111827
```

## 4. Typography

Use:

```text
Inter
Space Grotesk
```

Space Grotesk: display, headings, hero values, major analytical values.

Inter: body, controls, labels, tables, chart labels, descriptions.

## 5. Spacing

Approved:

```text
4
8
12
16
24
32
48
64 px
```

## 6. Radius

```text
4px  → tags / compact controls
8px  → buttons / inputs
12px → cards
16px → large panels
```

## 7. Component Inventory

```text
Button
IconButton
Input
Textarea
Select
Checkbox
Radio
Switch
Tabs
Badge
Tooltip
Dropdown
Dialog
Sheet
Card
MetricCard
StatusBadge
DataNatureBadge
ConfidenceIndicator
QualityIndicator
DataContext
ChartContainer
EmptyState
LoadingState
ErrorState
SuccessState
OperationStatus
ProgressIndicator
Table
Pagination
DateRangePicker
FilterBar
FilterChip
SearchField
NotificationItem
RecommendationCard
InsightCard
FileUpload
BillProcessingStatus
ThemeToggle
Sidebar
Header
Breadcrumbs
```

Avoid duplicate components that implement the same pattern.

## 8. Button System

Variants:

```text
Primary
Secondary
Tertiary
Destructive
Icon
```

States:

```text
Default
Hover
Active
Loading
Disabled
Focus
```

Loading preserves dimensions.

## 9. Data Status Components

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

Do not collapse nature and status into one semantic field.

## 10. Confidence

Where supported:

```text
High
Moderate
Low
Very Low
Insufficient
```

Confidence is not represented solely by color.

## 11. Chart Components

Create shared wrappers such as:

```text
EnergyCurveChart
ForecastChart
SolarConsumptionChart
CostTrendChart
ComparisonChart
```

Shared infrastructure handles tooltip style, legend, semantic styling, responsive sizing, accessible summaries, theme adaptation, and loading/error/empty states.

## 12. Chart Semantics

Actual:

```text
solid
explicit ACTUAL
```

Predicted:

```text
dashed
explicit PREDICTED
confidence where meaningful
```

Estimated:

```text
dotted/differentiated
explicit ESTIMATED
```

Stale:

```text
muted/subdued
explicit STALE
```

Demo:

```text
explicit DEMO / SIMULATED
```

## 13. Motion Tokens

```text
Hover              100–150ms
Button feedback    100–200ms
Expand/collapse    200–300ms
Theme              200–300ms
Page reveal        300–400ms
Chart entrance     400–600ms
```

Respect `prefers-reduced-motion`.

## 14. Theme Transition

The theme toggle uses a Sun ↔ Moon metaphor.

Normal theme transition:

```text
200–300ms
```

Optional visual orbit/atmosphere may accompany the transition without delaying interaction.

## 15. Loading System

Loading preserves eventual structure.

Use skeletons where useful.

Do not use fake progress percentages.

## 16. Empty State System

Variants:

```text
No Data
Insufficient Data
Unavailable
Stale
Degraded
No Search Results
No Selection
Processing
Error
No Actionable Insight
```

Structure:

```text
Context
↓
Title
↓
Explanation
↓
Limitation
↓
Action
```

## 17. Form System

Shared form primitives support labels, help text, validation, warning, error, disabled, loading, and read-only states.

Server validation remains authoritative.

## 18. File Upload

Bill upload supports:

```text
Idle
Drag Active
Selected
Uploading
Processing
Extracting
Awaiting Verification
Verified
Failed
```

Upload success does not mean verification.

## 19. Recommendation Component

Recommendation cards may include:

```text
Category
Title
Explanation
Impact
Confidence
Timing
Evidence
Why this recommendation?
Feedback
```

Recommendations remain advisory.

## 20. Responsive Component Rules

Components adapt through width, spacing, stacking, visibility, density, and interaction method.

Do not merely scale desktop UI down.

## 21. Accessibility

Reusable components provide semantic HTML, keyboard operation, focus states, labels, descriptions, accessible state communication where appropriate, reduced motion, and non-color-only semantics.

## 22. Documentation / Viva

For important frontend decisions document:

- technology
- reason for selection
- component responsibility
- design token source
- animation approach
- responsive approach
- chart approach
- API boundary
- accessibility approach
- trade-offs

## 23. Governing Rule

> **Every new frontend component must either reuse an existing SPMS pattern or have a documented reason to introduce a new pattern.**

**END OF FRONTEND DESIGN SYSTEM SPECIFICATION**
