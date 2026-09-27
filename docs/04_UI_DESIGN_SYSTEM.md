# SPMS — UI DESIGN SYSTEM
## Phase 4 Official Specification

**Status:** COMPLETE / LOCKED  
**Decisions:** Q1–Q220  
**Project:** Solar Power Management System (SPMS)

> This is the official Phase 4 design-system specification. Implementation, testing, documentation, presentation, and viva explanations must follow these rules. Page-specific styling must not silently invent a different semantic, visual, interaction, or state convention.

---

# 1. PHASE 4 SCOPE

Phase 4 defines the SPMS visual identity, component language, data visualization, navigation, responsive behavior, accessibility, loading and processing behavior, empty/error/recovery states, personalization, feedback, search/filtering, drill-down, state restoration, localization, exports, semantic presentation, cross-device continuity, and the UI/backend responsibility boundary.

The system must remain **calm, premium, fresh, energy-oriented, analytical, intelligent, understandable, accessible, and honest about uncertainty**.

Core design principle:

> **Nature inspires the atmosphere; data defines the interface.**

Core semantic principle:

> **Presentation may adapt to context; semantics must remain consistent.**

Core personalization principle:

> **Personalize presentation and relevance — never analytical truth.**

Core continuity principle:

> **Cross-device continuity synchronizes intent — not truth.**

---

# 2. VISUAL IDENTITY — Q1/Q2

## 2.1 Visual Direction

**Q1 — C: Calm + Premium Hybrid**

SPMS combines clean energy, freshness, intelligence, calm technology, spacious layouts, and analytical clarity.

### Light Mode

- Fresh, bright, natural, spacious, clean, calm.
- Base/off-white backgrounds.
- Clean white surfaces.
- Fresh green primary energy accent.
- Small solar yellow/gold accents only where useful.
- Soft borders and restrained shadows.

### Dark Mode

- Deep charcoal / dark green-black background.
- Dark elevated cards.
- Brighter green accents.
- Controlled cyan/teal.
- Light typography.
- Reduced brightness.
- Green may appear luminous against dark surfaces, but never neon/cyberpunk/gaming-like.

### Theme Transition

Light ↔ Dark is mandatory, approximately **200–300 ms**, smooth and subtle. Backgrounds, surfaces, borders, typography, charts, shadows, icons, and controls transition; layout geometry and information hierarchy do not.

## 2.2 Exact Color Tokens

### Primary Green

| Token | Light | Dark |
|---|---|---|
| Primary | `#22C55E` | `#34D399` |
| Hover | `#16A34A` | `#22C55E` |
| Active | `#15803D` | `#16A34A` |
| Soft | `#DCFCE7` | — |
| Deep Surface Accent | — | `#064E3B` |

### Secondary Teal

| Token | Light | Dark |
|---|---|---|
| Primary | `#06B6D4` | `#22D3EE` |
| Hover | `#0891B2` | `#06B6D4` |
| Active | `#0E7490` | `#0891B2` |
| Soft | `#E0F7FA` | — |
| Deep Surface | — | `#0E7490` |

### Neutrals

| Token | Light | Dark |
|---|---|---|
| Background | `#F9FAFB` | `#0B1220` |
| Surface | `#FFFFFF` | `#111827` |
| Border | `#E5E7EB` | `#1F2937` |
| Muted | `#9CA3AF` | `#6B7280` |
| Primary Text | `#374151` | `#D1D5DB` |
| Heading | `#111827` | `#F9FAFB` |

### Semantic

| Meaning | Light | Dark |
|---|---|---|
| Success | `#22C55E` | `#34D399` |
| Warning | `#F59E0B` | `#FBBF24` |
| Danger | `#EF4444` | `#F87171` |
| Info | `#3B82F6` | `#60A5FA` |

### Chart Tokens

| Meaning | Token |
|---|---|
| Solar Actual | `#22C55E` |
| Consumption Actual | `#3B82F6` |
| Solar Forecast | `#86EFAC` |
| Consumption Forecast | `#93C5FD` |
| Potential Surplus | `#10B981` |
| Potential Deficit | `#F97316` |
| Grid/Reference | `#6B7280` |
| Estimated | `#F59E0B` |
| Stale | `#9CA3AF` |
| Demo/Test | `#8B5CF6` |

Color is never the only semantic signal. Use text, icons, patterns, line styles, shapes, position, and status labels.

### Data Nature

- **ACTUAL:** solid, normal weight, explicit ACTUAL label.
- **PREDICTED:** dashed, lighter/secondary, explicit PREDICTED, confidence where meaningful.
- **ESTIMATED:** dotted/differentiated, lighter/secondary, explicit ESTIMATED.
- **STALE:** muted/subdued, explicit STALE.
- **SIMULATED/DEMO:** differentiated visual treatment plus explicit badge/label.

Data nature and status are independent. Example: `ACTUAL — STALE`; `PREDICTED — MODERATE CONFIDENCE`.

---

# 3. TYPOGRAPHY — Q3

**D — Inter + Space Grotesk**

### Inter

Navigation, sidebar, body, forms, tables, buttons, tooltips, notifications, descriptions, chart labels, status labels, and supporting information.

### Space Grotesk

Major headings, important section headings, dashboard hero numbers, energy values, forecasts, analytical highlights, large KPIs, and selected feature titles.

| Role | Font | Size/Line Height |
|---|---|---|
| Display | Space Grotesk | 48/56 |
| H1 | Space Grotesk | 36/44 |
| H2 | Space Grotesk | 28/36 |
| H3 | Space Grotesk | 20/28 |
| H4 | Space Grotesk | 16/24 |
| Body Large | Inter | 16/24 |
| Body | Inter | 14/20 |
| Body Small | Inter | 12/16 |
| Caption | Inter | 12/16 |
| Label | Inter | 12/16 |
| Button | Inter | 14/20 |

Inter weights: 300/400/500/600/700. Space Grotesk weights: 300/400/500/600/700.

Numerical values use Space Grotesk; units/context use Inter. Responsive scaling may occur without changing hierarchy. Important text must remain readable and sufficiently contrasted; avoid excessive all-caps and overly thin important text.

---

# 4. SPACING & LAYOUT — Q4

**C — Balanced Adaptive**

> **Spacious where understanding matters. Compact where comparison matters.**

Tokens: **4, 8, 12, 16, 24, 32, 48, 64 px**.

- Card-to-card default: **24 px**.
- Card internal default: **24 px**; smaller cards may use 16 px.
- Desktop page margin: **64 px**.
- Mobile page margin: **24 px**.
- Desktop grid: **12 columns**.
- Mobile: **4-column/stacked**, driven by content priority.
- Radius: 4 px tags/compact controls; 8 px buttons/inputs; 12 px normal cards; 16 px large panels.
- Dashboard: spacious/information-rich.
- Analytics: moderately dense.
- Tables: compact.
- Reports: structured.
- Solar/Energy/Forecasts: comfortable.
- Settings: comfortable.
- Mobile: adaptive/compact.

Charts must have enough space for title, context/time range, legend, graph, and data-state information. Light/Dark preserve geometry and hierarchy.

---

# 5. CARDS & SURFACES — Q5

**C — Border + Subtle Elevation**

Light: `#F9FAFB` background, `#FFFFFF` cards, `#E5E7EB` borders, subtle shadow.  
Dark: `#0B1220` background, `#111827` cards, `#1F2937` borders; surface contrast is the primary elevation mechanism.

Standard card radius: **12 px**. Elevation levels: 0 flat, 1 subtle default, 2 medium for overlays/modals/dropdowns/high-priority interactive elements.

Card architecture may contain header/icon/action, title, key value, change/context, and chart/info.

Types: KPI, Solar, Chart, Forecast, Insight, Recommendation, Cost, Notification, Table container, Settings section.

Dashboard hierarchy: primary energy status/main graph; secondary forecast/balance/cost; supporting insights/recommendations/notifications.

Do not fully color cards by default. Use restrained icons, indicators, borders, badges, and supporting text. No default glassmorphism. Loading preserves structure. Important state is never communicated only through shadow/color.

---

# 6. BUTTONS & CONTROLS — Q6

**C — Premium Structured**

Primary = SPMS green: Save Changes, Connect GoodWe, Add Electricity Bill, Generate Report, Apply Changes.  
Secondary = neutral surface: Cancel, Back, View Details, Reset.  
Tertiary/text = Learn more, View details, See all, Change settings.

States: Default → Hover → Active/Pressed → Loading → Disabled. Loading preserves size. Destructive actions use danger semantics and confirmation for irreversible/high-impact actions.

Use toggles for understandable binary preferences. Theme switch is Sun ↔ Moon with 200–300 ms transition. Dropdowns/selects use borders, moderate radius, clear selected state, subtle elevation. Tabs use accent without heavy fill. Filters are compact/readable. Forms support text, number, date/range, select, search/select, checkbox, radio, toggle, textarea, file upload.

Focus states must be visible. Desktop actions may be horizontal; mobile may stack. Same action has the same visual language throughout SPMS. Avoid gratuitous gradients/glow/animation/pills.

---

# 7. ICONS & VISUAL LANGUAGE — Q7

**A — Lucide-style Outline Icons**

Use a professional outline icon library. No emoji as UI icons. Custom solar/energy icons are allowed only where generic icons are insufficient and must match stroke, geometry, corners, weight, and alignment.

Navigation: Dashboard/home, Solar/panel-sun, Energy/lightning, Forecasts/trending chart, Analytics/bar chart, Bills/document, Insights/lightbulb, Reports/file, Settings/gear.

Analytical mappings: Solar/sun, Consumption/home/activity, Forecast/trending line, Balance/scale, Cost/currency, Historical/clock, Data Quality/shield-check, Provenance/link-source.

Status: success/check, warning/danger/exclamation, info/i, pending/clock, online/connection, offline/disconnected.

Sizes: 16 px metadata, 20 px standard controls, 24 px navigation/primary, 32 px card/feature, 40+ px empty/major illustration. Default nav 24 px; stroke approximately 2 px. Important statuses prefer icon + text. Sun/Moon has special theme motion.

---

# 8. CHARTS & DATA VISUALIZATION — Q8

**C — Adaptive Analytical + Smooth Natural Curve-Based Visualization Language**

> **Clean at a glance. Rich when exploring. Smooth by design. Honest about the data.**

Dashboard charts are clean/smooth/low-clutter. Energy charts have moderate detail. Forecasts emphasize actual vs predicted and confidence/uncertainty. Analytics is rich and interactive. Reports are publication-clean.

Smooth curves are preferred for continuous time-series data (solar, consumption, forecasts, cost trends, historical trends, solar-vs-consumption) when semantically valid. Do not force curves onto discrete, categorical, or sparse data.

Use the exact chart tokens in Section 2. Actual = solid; predicted = dashed; estimated = differentiated/dotted; stale = muted; demo = explicit. Confidence bands/ranges are used when meaningful.

Interactions: hover, tooltip, time range, compare, zoom/pan, toggle series, expand, reset. Motion is restrained and faithful.

Authoritative energy balance:

`Solar Generation − Household Consumption`

Positive = **Potential Surplus**. Negative = **Potential Deficit**. Neither means confirmed grid export/import without appropriate direct measurement.

Graph pipeline:

`Authoritative Analytical Data → Historical Analytics & Reporting → Graph-Ready Dataset → JavaScript Chart Component → Rendered Graph`

Every important graph has an accessible summary/table/data representation.

---

# 9. KPI / DATA CARD PRESENTATION — Q9

**C — Adaptive KPI + Context-Aware Data Presentation**

Primary dashboard KPIs emphasize solar generation, consumption, energy balance, important forecast, and cost/impact as appropriate. A primary KPI may contain value, comparison, and data state. Secondary KPIs are lighter.

Analytical KPIs may contain comparison, forecast status, confidence, time period, provenance/state, trend, and mini-chart. Historical verified cost and future estimated cost remain distinct. Forecasts explicitly say predicted and show confidence when meaningful. Sparklines are optional support, never a replacement for the metric.

Page density adapts: Dashboard clean/high-priority; Energy moderate; Forecasts prediction-focused; Analytics rich; Reports compact/publication-ready.

Loading/no-data/stale/degraded/unavailable/error states are deliberate. User customization can show/hide/rearrange supported cards but cannot change calculation, confidence, provenance, or quality.

---

# 10. NAVIGATION — Q10

**C — Adaptive Sidebar + Responsive Drawer/Bottom Navigation**

Global navigation:

- MAIN: Dashboard
- ENERGY: Solar, Energy, Forecasts
- INTELLIGENCE: Insights & Recommendations, Analytics
- OUTPUT: Bills, Reports, Notifications
- SYSTEM: Settings

Desktop: expanded by default, collapsible. Tablet: compact/collapsible. Mobile: drawer, with optional bottom navigation for very small screens.

Active state uses green accent, subtle background, stronger text, optional slim indicator. No heavy saturated block. Notification badges are meaningful and accessible. Capability-aware navigation remains usable when a service is unavailable. Admin destinations depend on authorization. Demo/Test retains the same architecture plus persistent DEMO/SIMULATED indication.

Sidebar answers **where can I go?**; header answers **where am I, what is happening, what can I do?**

---

# 11. HEADER / TOP BAR — Q11

**C — Adaptive Context Header + Context-Aware GoodWe Connection Status**

Header can contain navigation trigger/brand, page context/title/description/breadcrumb, contextual actions, GoodWe status, notifications, theme, and account.

GoodWe states: connected, synchronizing, recently synced, stale, degraded, disconnected, error, reconnecting. Distinguish connection identity, service availability, freshness, and quality.

Connected controls may expose status, last sync, sync now, manage, disconnect. Unavailable states preserve last successful sync and communicate limitations. Demo/Test shows DEMO/SIMULATED and never performs real GoodWe auth/credential/sync/linking.

---

# 12. BACKGROUND / ATMOSPHERE — Q12

**C — Adaptive Atmospheric + Nature/Solar-Inspired Visual Soul**

Hierarchy: **Data → Content → Components → Atmosphere**.

Allowed: extremely subtle green/teal radial gradients, soft sunlight-like illumination, gentle environmental transitions, organic curved forms sparingly, soft solar highlights, subtle ambient illumination.

Normally disallowed: large nature photos, landscapes, trees/leaves, mountains, clouds, moving particles, animated backgrounds, excessive blur, heavy glassmorphism, strong colorful gradients, parallax.

Atmosphere: slightly richer on Dashboard; solar influence on Solar; clean Energy; restrained forward-looking Forecasts; mostly neutral Analytics; softer Insights; publication-clean Reports; neutral/trustworthy Bills; quiet Settings. Atmosphere never communicates analytical state. Charts take priority. Decorative atmosphere must remain accessible, performant, static/restrained, and reduced-motion aware.

---

# 13. MOTION — Q13

**C — Adaptive Intelligent Motion + Subtle Ambient Motion**

Motion must explain, connect, or reassure.

Ambient motion, if used, is extremely slow soft-light movement, gentle green/teal drift, sunlight-inspired illumination, or very slow organic gradients. It is non-interactive/non-informational.

Targets: hover 100–150 ms; button/state 100–200 ms; expand/sidebar/theme 200–300 ms; page 300–400 ms; chart/data 400–600 ms. Natural controlled easing; no bounce.

Meaningful motion: sidebar, cards, dropdowns, modals/drawers, buttons, tabs, filters, upload/processing, GoodWe connect/reconnect, notifications, chart transitions. Avoid particles, floating icons, excessive transitions, flashing, bouncing, neon, constant chart reanimation, aggressive counters, parallax. Reduced motion must be respected. Mobile is more restrained.

---

# 14. FORMS — Q14

**C — Adaptive Intelligent Forms**

Ask only what is genuinely required. Required/optional are explicit. Use progressive disclosure: basic → location → optional energy context → advanced.

House size is optional unless a real future requirement makes it necessary.

Bill AI flow:

`Upload → Processing → Extraction → AI Interpretation/Normalization → Verification Form → User Correction → Confirmation → Verified Bill`

Clearly distinguish AI Extracted, User Corrected, Verified. User verification is final authority.

States: default, focus, filled, valid, warning, error, loading, processing, success, disabled, read-only. Errors explain what happened, why, and action. Security errors use safe disclosure.

GoodWe connection: purpose, required info, received data, current state, post-connection result; `Connecting → Validating → Connected`. Connection is not proof of current freshness.

---

# 15. EMPTY / NO-DATA STATES — Q15

**C — Adaptive Intelligent Empty States**

Distinguish no data, insufficient data, unavailable, stale, degraded, no actionable insight, no search results, no selected-period history, processing/not ready, and actual error.

No fake household numbers. Only explicit Demo/Simulation can show simulated values.

No data = next action. Insufficient = why/what is needed. Unavailable = preserve history + limitation + recovery. Stale = last sync/update. Degraded = what remains. No recommendations = valid result, no artificial recommendation. Empty analytics = add/build history, no fake graph. Empty forecasts explain why. Empty bills offer add/upload/manual. Empty solar distinguishes not connected from unavailable. Empty reports explain validated-data requirement. Empty notifications can say all caught up. Search/filter empty explains no match.

Standard structure: icon/context → title → explanation → limitation → next action → optional help.

---

# 16. ALERTS / NOTIFICATIONS — Q16

**C — Adaptive Attention**

Pipeline:

`System Event → Significance → Impact + Confidence + Urgency → Relevance + Recurrence → Preferences/Context → Attention Level → UI Delivery → Action/Dismissal/Resolution`

Levels:

1. Quiet — minor trends/info.
2. Notice — meaningful surplus, recommendation, important forecast, report ready.
3. Warning — GoodWe disconnected, stale/degraded data, limited prediction.
4. Prominent/Critical — important security/account events.

Potential surplus/deficit gets proportionate attention and is never silently converted to grid import/export. Forecast changes notify only when significant/confident/relevant. Bill verification is action-required, not automatically emergency. Recovery is brief.

Toasts = immediate action feedback. Badges = meaningful grouped attention. Banners = contextual/persistent while condition remains. No flashing/bouncing/pulsing. Data quality influences attention. Recurrence suppression applies. Preferences can control supported categories/push/sound/recommendations/reports; critical security/account events may override normal preferences. Bedrock can assist wording; policy decides severity.

---

# 17. TABLES — Q17

**C — Adaptive Data Tables**

Household tables are moderate/readable. Analytical history can be richer. Prediction tables may show period, value, horizon, resolution, confidence, uncertainty, model, version, nature, and status. Analytics supports sorting, filtering, date, comparison, search, column controls, expansion, pagination. Admin/operations/model evaluation may be dense.

Sticky headers are allowed. Mobile uses controlled horizontal scrolling or meaningful card transformation. States include loading, no data, insufficient, stale, degraded, error, unavailable, filtered empty. Data nature and status remain separate. Export preserves meaning, quality, provenance, confidence, limitations, and measurement context.

---

# 18. SEARCH / FILTERING — Q18

**C — Adaptive Data Exploration**

> **Make information easy to find without making the interface harder to understand.**

No giant universal query interface by default. Exploration adapts to page, dataset, user context, device, and analytical purpose.

Global search finds meaningful authorized resources/destinations: bills, reports, notifications, recommendations, pages, saved analytical views. Contextual search explores analytical dimensions: dates, metrics, comparisons, series, verification, source, etc.

Architecture:

`Query → Authentication → Authorization → Household/Resource Ownership → Scope → Results`

Search cannot grant access or establish truth/ownership. Numeric analytical values are not generic global-search objects. Use contextual filter chips and clear-all where useful; mobile may use a filter drawer/sheet.

---

# 19. DETAIL / DRILL-DOWN — Q19

**C — Adaptive Detail & Drill-Down**

Hierarchy:

`Summary → KPI/Chart/Table → Selected Record → Detail View → Supporting Evidence → Quality + Provenance + Measurement Context`

Depth adapts to record type, context, role, complexity, analytical importance, quality, confidence, and evidence. Known information is shown; uncertainty is shown; unavailable information is stated unavailable; not-applicable information is not shown.

> **Drill-down reveals information; it never creates information.**

Example: `18.6 kWh Solar Generation — ACTUAL — Fresh` may drill into date/time, source, quality, measurement context, original source, processing history, transformations, and limitations.

---

# 20. LOADING / PROCESSING — Q20

**C — Adaptive Loading & Processing + Signature Solar Loading**

### Signature Loader

A stationary mounted solar panel with a small sun smoothly revolving in a subtle elliptical orbit. Clean/premium, slow, no bounce/excessive glow, seamless loop, Light/Dark variants. It is only a SPMS loading indicator and does not represent real solar movement, generation, time, or performance.

### Processing

Use stage lists: current highlighted, completed ✓, upcoming subdued. No fake percentages.

GoodWe sync uses a connection/data-flow visual `GoodWe → SPMS`, not the solar loader. `Synchronizing → Synced just now`; failure → Retry.

Bill/AI: `Uploading → Extracting → AI Interpretation → Structured Fields → Verification`.

Prediction may show historical line extending into forecast region but must not imply accuracy increases merely because the line extends.

Report: `Validated Data → Analytics → Charts → Report`. Export: `Preparing → Ready`. Recovery: retry/reconnect indication.

Operation lifecycle:

`NOT STARTED → QUEUED → PROCESSING → COMPLETED / FAILED / CANCELLED`

Recovery:

`FAILED → RECOVERING → RECOVERED`

Real percentage/step is shown only when backend knows it. Background operations should not unnecessarily block the UI. Operation state and capability/data state remain separate.

---

# 21. RESPONSIVE — Q21

**C — Adaptive Responsive**

Responsive design changes arrangement, density, navigation, controls, graph/table treatment, and disclosure according to device and priority. It does not change analytical meaning. Desktop uses 12 columns and expanded navigation; tablet uses compact navigation; mobile uses 4-column/stacked layouts, drawer navigation, compact header, priority-first information, single-column forms, sufficient touch targets, and controlled table/graph adaptation.

---

# 22. ACCESSIBILITY — Q22

**C — Accessibility-First Adaptive Design**

Accessibility applies to every component, state, theme, chart, form, notification, and responsive layout.

Required: color is never the only signal; visible focus; readable text; usable touch targets; accessible icon labels; icon+text for important statuses; graph summaries and/or data tables; reduced-motion support; readable Dark/Light themes; localization-safe text expansion; persistent accessibility preferences where supported.

---

# 23. PERSONALIZATION — Q23

**C — Adaptive Personalization**

Model:

`Explicit User Preference → Intelligent Default → Learned Personalization → Presentation/Relevance`

Priority: **Explicit > Default > Learned**.

Supported controls may include dashboard card visibility/order/reset, analytics ranges/comparisons/views/saved exploration, notification categories/frequency, theme/units/density/default views, recommendation categories/relevance.

Learned behavior may influence presentation/relevance only. It cannot change actuals, predictions, confidence, quality, provenance, measurement context, balance, cost, history, model evaluation, system state, authorization, or lifecycle.

Two resets: **Reset to Default** and **Reset Personalization**.

---

# 24. FEEDBACK — Q24

**C — Adaptive Feedback & Interaction Learning**

`Explicit Feedback + Interaction Signals → Feedback Layer → Personalization Layer → Presentation/Relevance`

Feedback: useful, not useful, acted, dismissed, not relevant. Signals may include opened, ignored, expanded, changed, viewed, repeatedly explored.

Feedback can affect recommendation relevance, presentation, notification frequency, dashboard emphasis, analytics defaults, and supporting content. It does not establish prediction truth. **Prediction Outcome** and **Recommendation Feedback** are separate concepts.

---

# 25. CONFIRMATION / UNDO — Q25

**C — Adaptive Confirmation & Reversibility**

Low-impact reversible actions: immediate + Undo. Important reversible actions: execute + Undo/brief confirmation. Irreversible/high-impact actions: explicit confirmation.

Examples: hide card → Undo; extracted bill → explicit verification; delete bill, disconnect GoodWe, account deletion → strong confirmation.

Backend flow: `Confirmation → Authentication → Lifecycle Validation → Operation → Result`.

---

# 26. ERROR / RECOVERY — Q26

**C — Adaptive Error & Recovery Experience**

> **Show the actual problem, preserve the trustworthy remainder, and provide the safest useful recovery.**

Classify severity, recoverability, and data impact. Transient failures may receive bounded automatic retries. GoodWe failure preserves prior solar; bill extraction failure preserves original and offers manual path; prediction failure says reliable forecast is unavailable and uses validated fallback when supported; export failure leaves data unchanged; session expiry asks sign-in. One failed capability does not imply all data is unavailable.

---

# 27. ACTION RESULT — Q27

**C — Adaptive Action Result Feedback**

Show actual authoritative result at suitable attention. Distinguish `Accepted`, `Processing`, and `Completed`. Examples: profile updated; GoodWe connected + sync + View Solar; report started then ready; export ready; recovery confirmed. Frontend never declares completion without authoritative backend state.

---

# 28. REFRESH / LIVE UPDATES — Q28

**C — Adaptive Data Refresh & Live Updates**

Refresh adapts to source, freshness, page, importance, network, and user activity. GoodWe may refresh in background and manually; dashboard can update components; forecasts update after validated predictions; Analytics can show Update available; reports are immutable; settings avoid unnecessary refresh.

Pipeline: `External → Ingest → Validation → Quality/Provenance → Storage → Analytics → UI`.

No direct GoodWe → frontend shortcut.

---

# 29. OFFLINE — Q29

**C — Adaptive Offline & Connectivity-Aware**

Distinguish network, backend, GoodWe, freshness, and operation state. Offline can show trustworthy cached information with freshness/quality/provenance/nature. Backend-required actions are blocked/deferred. Safe local presentation preferences can continue. Queue only safely queueable operations; queued ≠ completed. Reconnect revalidates session, reconciles eligible queued operations, refreshes authoritative data, and shows the actual result.

---

# 30. SESSION / REAUTH — Q30

**C — Session & Re-Authentication**

Approaching expiry → warning. Expiry → protected access blocked. Reauthentication → session/account validation → authorization → safe restore. Unsaved local state may be preserved. Sensitive operations never auto-resume solely because the user reauthenticated.

Authentication ≠ Authorization ≠ Operation Validation ≠ Completion.

---

# 31. DRAFTS — Q31

**C — Adaptive Draft & Unsaved Changes**

`Temporary Input → Draft → Submitted → Validated → Authoritative/Verified`

AI-extracted data remains draft until verification. Offline drafts can become Pending Sync and are revalidated for authentication, authorization, version/conflict, validation, and persistence on reconnect.

Pending Sync ≠ Saved ≠ Submitted ≠ Verified.

---

# 32. WORKFLOW PROGRESS — Q32

**C — Adaptive Workflow Progress**

Show progress, dependencies, required/optional steps, data availability, validation, and draft state. States: NOT STARTED, AVAILABLE, CURRENT, IN PROGRESS, COMPLETED, SKIPPED, DEFERRED, BLOCKED, FAILED, UNAVAILABLE. Backend is authoritative. Progress must never be confused with validation/completion.

---

# 33. WORKFLOW EXIT — Q33

**C — Adaptive Workflow Exit & Navigation Protection**

Protect meaningful unsaved work. Safe drafts can leave normally; important unsaved changes warn; active operations are assessed. Never auto-submit, verify, cancel, or complete because the user navigated away. Frontend protection does not replace backend persistence.

---

# 34. MULTI-CONTEXT CONSISTENCY — Q34

**C — Adaptive Multi-Context Consistency**

Multiple tabs/windows/devices can observe changes. Safe contexts update; active drafts/analysis may remain stable and reconcile. Meaningful concurrent edits use backend versioning, auth, validation, persistence, and conflict handling. No silent last-write-wins where meaningful changes could be lost.

---

# 35. BROWSER RESTORATION — Q35

**C — Adaptive Browser Navigation & State Restoration**

Refresh/back/forward are respected. UI is reconstructed from current authoritative state, not an old snapshot. Sessions are revalidated. Background operations continue where supported. Browser history is navigation context, not truth.

---

# 36. URL / DEEP LINKS — Q36

**C — Adaptive URL & Deep-Link State**

URLs represent safe navigation/presentation context. Backend validates authentication, authorization, ownership, workflow, capabilities, and resource availability. URLs may contain resource identifiers and safe query parameters, but never secrets. URLs cannot grant access, ownership, verification, completion, confidence, quality, or analytical truth.

> **The URL identifies navigation context; the backend determines whether that context is currently valid, authorized, and available.**

---

# 37. PRESENTATION STATE — Q37

**C — Adaptive Presentation State Preservation**

May preserve filters, date ranges, comparisons, series, view, sorting, columns, and dashboard arrangement. Restore only after validating against current capabilities/data/context. Never remember old auth, GoodWe state, verification, operation state, confidence, quality, provenance, or analytical truth.

> **SPMS may remember how the user prefers to view information, but it must never remember an old UI state as a substitute for current system truth.**

---

# 38. CROSS-PAGE CONTEXT — Q38

**C — Adaptive Cross-Page Context Continuity**

Carry safe intent such as resource IDs, date range, metric, comparison, selected record, forecast horizon, and workflow entry context. Destination revalidates current authorization, ownership, resource, capability, data quality, provenance, freshness, and confidence.

> **Cross-page context carries user intent, not system truth.**

> **Carry the reason. Revalidate the reality.**

---

# 39. GLOBAL + CONTEXTUAL SEARCH — Q39

**C — Adaptive Global Search + Contextual Search**

Global search = authorized meaningful resources/destinations. Contextual search = analytical exploration. Search pipeline: `Query → Authentication → Authorization → Ownership → Scope → Results`. Search never grants access or establishes truth.

---

# 40. FAVORITES / SAVED VIEWS — Q40

**C — Adaptive Favorites & Saved Views**

Favorites remember where the user wants to go. Saved Views remember supported presentation context. Store references/context, not analytical snapshots. On restoration validate current context, authz, data, and capabilities; adapt/discard invalid settings. Never preserve auth, ownership, GoodWe status, freshness, quality, confidence, provenance, verification, workflow completion, operation state, or analytical truth.

> **Favorites remember where the user wants to go; Saved Views remember how they prefer to explore a supported context. Neither remembers what the system was true at the time of saving.**

---

# 41. PREFERENCE PRIORITY — Q41

**C — Explicit Preference Priority + Adaptive Learning Within Boundaries**

Explicit preference always wins over default and learning. Temporary interactions do not become persistent preferences. Explicit changes apply immediately. Reset Personalization removes learned adaptations while preserving explicit preferences unless separately reset.

> **Learn from the user, but never learn over the user's explicit choice.**

---

# 42. PROGRESSIVE DISCLOSURE — Q42

**C — Adaptive Progressive Disclosure**

Level 1: value, meaning, time/context, nature, important status.  
Level 2: comparison, confidence, quality, explanation, measurement limitations.  
Level 3: source, timestamp, provenance, validation, processing context.  
Level 4: diagnostics, correlation IDs, model/version, operational events, technical metadata.

Authorization determines what may be accessed; progressive disclosure determines how much authorized information is initially shown. It may never hide information that materially changes reliability or meaning.

---

# 43. VALIDATED COMPARISONS — Q43

**C — Context-Aware Validated Comparisons**

`Authoritative Data → Quality + Provenance → Comparison Validation → Validated Comparison → Intelligence/Explanation → UI/Graph/Report`

Validate units, periods, completeness, quality, nature, freshness, measurement context, horizon/resolution, seasonal/contextual differences, and misleading risk. Support previous period, same historical period, rolling/seasonal baselines, solar-vs-consumption, actual-vs-predicted, and current-vs-historical cost when meaningful.

Actual-vs-actual, actual-vs-predicted, and estimated-vs-actual remain distinct. If weak, qualify/omit. If unsupported: `Comparison unavailable — insufficient comparable data.`

> **A mathematically possible comparison is not automatically a meaningful comparison.**

---

# 44. EVIDENCE-BASED RESOLUTION — Q44

**C — Adaptive Evidence-Based Resolution**

`Available Evidence → Source Resolution → Quality/Completeness → Context + Analytical Purpose → Supported Resolution → Valid Aggregation/Transformation → Graph/Table/KPI`

Defaults: Dashboard daily/summary; Solar history hourly where reliable; Analytics daily/weekly/monthly where supported; Bills billing-period/monthly; Forecasts supported resolution; long-term monthly/seasonal.

These are adaptive defaults, not rigid limits. Valid aggregation is allowed; reverse-engineering unsupported detail is not. A monthly bill cannot be reconstructed into hourly consumption. Offer the nearest supported resolution and explain limitations.

> **Resolution may adapt to the evidence; evidence must never be adapted to satisfy the requested resolution.**

---

# 45. VALIDATED EXPORT — Q45

**C — Context-Aware Validated Export**

`Export Request → Authentication → Authorization/Ownership → Current Context → Scope → Quality + Provenance + Measurement Context → Dataset → Format → Export Validation → Ready`

Formats may include CSV, XLSX, PDF, JSON according to purpose. Preserve values, units, timestamps/periods, nature/status, confidence, quality, source, provenance, measurement context, comparison context, model/version where relevant, limitations, and Demo/Test state.

Export is not a serialization of arbitrary frontend state. Backend determines authorized/current scope. Potential Surplus stays Potential Surplus; Demo stays DEMO/SIMULATED.

Report = human-readable analytical communication. Export = structured portable data.

> **Export makes authorized SPMS information portable without changing what it means.**

---

# 46. SHARED SEMANTIC PRESENTATION — Q46

**C — Shared Semantic Data Presentation System**

`Authoritative Analytical Data → Semantic Layer → Shared Presentation Rules → Dashboard / Analytics / Reports`

Shared semantics govern nature, status, confidence, quality, provenance/source, measurement context, units, time meaning, comparison meaning, potential vs confirmed outcomes, Demo/Test, and important limitations.

Example: `18.6 kWh Solar Generation — ACTUAL — Fresh` remains semantically identical on Dashboard, Solar, Analytics, and Reports even when detail differs.

Bedrock can phrase validated facts but cannot define semantics.

---

# 47. CROSS-DEVICE / CROSS-SESSION — Q47

**C — Adaptive Cross-Device & Cross-Session Continuity**

### Global persistent state

Explicit preferences, theme, units, supported dashboard configuration, favorites, saved views, notification settings, applicable personalization may sync where supported.

### Context/session state

Current filters, temporary comparisons, open panels, drill-down, temporary sorting, chart interaction, current page normally remain local.

### Authoritative system state

Household profile, GoodWe connection, verified bills, predictions, recommendations, notifications, quality, provenance, workflow/operation state always come from backend truth.

Passwords, tokens, credentials, GoodWe credentials, and authentication state are never ordinary synced presentation state.

---

# 48. SINGLE-HOUSEHOLD UX / MULTI-HOUSEHOLD-READY BACKEND — Q48

**C — Single-Household UX + Multi-Household-Ready Backend**

MVP UX has one primary household and one primary solar plant. Backend models `Account → Households → Solar Plants`; resources are household-scoped. Client household IDs, URLs, saved context, and device state never grant access. Backend validates authentication, authorization, household membership/ownership, resource ownership, lifecycle, and privacy.

Account-level: theme/accessibility. Household-level: dashboard arrangement, analytics preferences, recommendation preferences. No full multi-household switcher/shared-membership MVP.

> **Keep the MVP simple without creating a structural dead end.**

---

# 49. LOCALIZATION — Q49–Q80

## Q49 — C: Localization-ready architecture + English MVP

Architecture: `System Meaning → Stable Semantic ID → Shared Semantic Presentation → Localization → Localized Presentation`. Localization cannot change numerical truth, nature, confidence, quality, provenance, measurement context, authorization, or system state.

## Q50 — B: Language selector in Settings, English initial

Priority: explicit language → browser/device suggestion → English default.

## Q51 — E: Sensible India default + future explicit locale support

Use a sensible India-oriented locale baseline while keeping explicit locale support extensible.

## Q52 — A: INR-first MVP, currency-ready architecture

INR is the initial currency. Currency remains a semantic value/context and formatting is localized.

## Q53 — A: kWh primary, unit-ready architecture

kWh is the primary household energy unit. Unit meaning is semantic.

## Q54 — B: Locale-aware formatting + canonical timestamps

Display formatting localizes; canonical values remain stable.

## Q55 — B: Canonical timestamps + household timezone

Store/use canonical timestamps and present according to household timezone.

## Q56 — B: RTL-ready architecture

Layouts/components must tolerate future RTL languages.

## Q57 — A: Preserve original user-generated content, no automatic translation

Original user text is not silently translated/replaced.

## Q58 — B: Stable message/semantic keys + localization resources

Meaning is identified by stable keys; displayed strings come from localization resources.

## Q59 — B: Validated semantic facts + selected language for AI explanation

Bedrock receives controlled validated facts plus language context, not authority to define truth.

## Q60 — B: Reports follow selected language when supported

Human-readable report text localizes; semantic values remain stable.

## Q61 — B: Export selected language for human-readable fields + semantic values preserved

Localized labels are allowed; values/meaning remain unchanged.

## Q62 — B: Same localization architecture for accessibility content

Accessible labels/descriptions use the same stable semantic/localization system.

## Q63 — B: APIs return semantic/data values + stable identifiers

APIs expose values and semantic IDs; UI strings are not backend truth.

## Q64 — B: Separate localization resources/catalog

Localization remains separate from analytical/domain logic.

## Q65 — B: Stable validation/error codes + structured context

Backend errors use stable codes and structured context; UI renders localized messages.

## Q66 — B: Unit meaning semantic; localization changes representation

Localization changes display, not unit meaning.

## Q67 — B: Currency is value/context; formatting localized

Currency remains semantic; formatting follows locale.

## Q68 — B: Canonical numeric storage + presentation formatting

Store canonical numeric values; format only at presentation.

## Q69 — B: Semantic graph data + localized presentation

Graph data stays semantic; labels/context localize.

## Q70 — B: Stable data-nature identifiers + controlled labels

Store nature codes; localize labels.

## Q71 — B: Stable confidence levels + localized presentation

Confidence meaning remains stable; labels localize.

## Q72 — B: Notification semantics/context stored; text rendered locally

Store notification meaning/context, render language-specific text.

## Q73 — B: Apply supported UI language immediately

Language changes should apply without unnecessary restart/re-login.

## Q74 — B: Persist explicit account-level language preference

Explicit selection persists with the account.

## Q75 — B: Explicit language always wins

Explicit choice outranks browser/device suggestion.

## Q76 — B: Safe English/default fallback

Unsupported localization falls back safely.

## Q77 — B: Responsive layouts tolerate text expansion

Translated strings may be longer; layout must survive expansion.

## Q78 — B: Localization + accessibility together

Localized content preserves accessibility requirements.

## Q79 — B: Preserve original user-entered text

Original text remains available and is not silently overwritten.

## Q80 — B: Semantic-resource search + authorized localized presentation

Search uses stable semantic resources and authorization; presentation localizes.

---

# 50. THEME, ACCESSIBILITY & PREFERENCE PERSISTENCE — Q81–Q90

## Q81 — B: Account-level persisted theme preference

Theme is account-level persistent preference.

## Q82 — B: Explicit theme preference synchronizes where supported

User-selected theme follows supported sessions/devices.

## Q83 — C: System preference initially unless explicit choice exists

Initial theme respects system preference; explicit selection then wins.

## Q84 — B: System + explicit reduced-motion preference

Respect both system and explicit reduced-motion settings.

## Q85 — B: Account-level accessibility preferences

Supported accessibility preferences persist at account level.

## Q86 — B: Household-level dashboard arrangement

Dashboard arrangement belongs to household context.

## Q87 — B: Household-specific analytics preferences

Analytics defaults belong to household context.

## Q88 — B: Account-global + household-specific notification preferences

Use appropriate account and household scopes.

## Q89 — B: Household-specific recommendation preferences

Recommendation categories/relevance belong to household context.

## Q90 — B: Separate Reset Personalization from Reset to Default

The two resets have different meanings and remain separate.

---

# 51. APPLICATION ARCHITECTURE — Q91–Q100

> **Implementation direction update (F0):** docs/04 is the shared SPMS
> UX/design-system authority — visual identity, tokens, components,
> states, data presentation, and accessibility rules. The concrete
> frontend implementation layer is defined in `docs/12_FRONTEND_DESIGN_SYSTEM.md`
> and `docs/11_FRONTEND_ARCHITECTURE.md`: React + Tailwind CSS +
> shadcn/ui + Motion + Lucide + Recharts implement the design system
> defined here. The original Q93 stack wording below is superseded by
> that locked direction (decision log D-003–D-012); all other decisions
> in this section remain valid. Chart semantic rules (actual solid,
> predicted dashed, estimated dotted, stale muted, demo labeled) remain
> unchanged.

## Q91 — B: Modular PHP application with clear layers

Use understandable modules and layers rather than one giant file/page.

## Q92 — B: PHP + MVC-like separation

Separate presentation, request/controller handling, domain/business services, and data access.

## Q93 — B: (SUPERSEDED — see F0 note above) React SPA with Tailwind CSS

The original decision ("HTML + CSS + Vanilla JS with progressive enhancement") is superseded by the locked React frontend direction. The spirit is preserved: accessible, semantic markup rendered through reusable components, without introducing technologies outside the approved stack (React, Vite, Tailwind CSS, Motion, shadcn/ui, Lucide, Recharts, React Router).

## Q94 — B: PHP JSON API layer

Frontend communicates with PHP JSON endpoints through a centralized API client over `/api/v1/...`.

## Q95 — B: PDO + prepared statements

Database access uses PDO and prepared statements.

## Q96 — B: Normalized relational schema with clear domain boundaries

Keep accounts, households, solar plants, bills, solar data, environment, predictions, recommendations, notifications, reports, feedback, and audit/operations logically separated.

## Q97 — B: Consistent structured JSON responses

APIs use predictable success/data/status/error/metadata structures.

## Q98 — B: Stable error code + message + structured context

Errors have stable codes and safe structured context.

## Q99 — B: Server validation authoritative; frontend validation UX

Frontend validation assists users; server validation is authoritative.

## Q100 — B: Backend/domain services own authoritative business logic

Authoritative calculations/rules remain in backend/domain services.

---

# 52. SECURITY / AUTHENTICATION — Q101–Q110

Q101 B — Secure PHP session-based authentication.  
Q102 C — Strong password hashing.  
Q103 B — Server-managed session + secure cookie.  
Q104 B — Backend authorization on every protected operation.  
Q105 B — CSRF protection for state-changing browser requests.  
Q106 C — Environment/server configuration outside version control.  
Q107 C — Backend-only protected GoodWe credential handling.  
Q108 B — Prepared statements + validation.  
Q109 C — MIME/content/size/name validation + controlled storage.  
Q110 B — Security-relevant logs without secrets/sensitive data.

These rules mean credentials, tokens, passwords, and sensitive configuration are never exposed to frontend code or committed to Git. UI hiding is not authorization.

---

# 53. INTEGRATION / AI / CONFIGURATION — Q111–Q130

Q111 B — PHP integration/service layer → GoodWe.  
Q112 B — Capability-aware graceful degradation.  
Q113 B — Background/controlled sync + manual refresh.  
Q114 B — Provider abstraction + configurable external weather source.  
Q115 B — Dedicated AI service boundary receiving validated inputs.  
Q116 B — Analytical/model layer remains numerical authority.  
Q117 B — Controlled non-AI fallback/template explanation.  
Q118 B — Minimum necessary validated AI context.  
Q119 B — Centralized versioned prompt/config management.  
Q120 B — Validate/constrain AI output before presentation.  
Q121 B — Centralized environment/configuration system.  
Q122 B — Development/Testing/Production separation.  
Q123 B — Centralized safe error handling + internal diagnostics.  
Q124 B — Structured application logging with appropriate levels.  
Q125 B — Request/correlation IDs for important operations.  
Q126 B — Transactions for multi-step atomic operations.  
Q127 B — Backend validation/versioning for meaningful concurrent changes.  
Q128 B — Idempotency where duplicate execution could harm.  
Q129 B — Background processing for long-running/non-blocking operations.  
Q130 B — Authoritative operation lifecycle/status.

Long-running operations include GoodWe synchronization, bill extraction, AI processing, prediction, and report generation where appropriate. Operation state is backend truth.

---

# 54. BILL / SOLAR / QUALITY / PREDICTION — Q131–Q150

Q131 B — Bill upload → extraction → normalization → verification.  
Q132 B — Preserve original bill securely per retention.  
Q133 B — User verification required before authoritative storage.  
Q134 B — Preserve authoritative history/version context.  
Q135 B — Backend validates/stores authoritative solar measurements.  
Q136 B — Formal quality assessment/propagation.  
Q137 B — Provenance tracked throughout lifecycle.  
Q138 B — Data nature is structured semantic metadata.  
Q139 B — Freshness/status independently assessed from data nature.  
Q140 B — Explicit measured vs inferred/predicted/potential context.  
Q141 B — Separate prediction capabilities/models where appropriate.  
Q142 B — Historical predictions with model/version/context.  
Q143 B — Confidence derived from analytical/model/data evidence.  
Q144 B — Validated fallback hierarchy with reduced precision as needed.  
Q145 B — Explicit model versions.  
Q146 B — Validate → deploy → monitor → rollback.  
Q147 B — Prediction evaluation against reliable comparable outcomes.  
Q148 B — Evaluation results with model/context/horizon/data quality.  
Q149 B — Controlled rollback to previously validated version.  
Q150 B — Numerical prediction and Bedrock explanation separate.

### Prediction fallback

When prediction fails, is invalid, insufficient, or too uncertain: use a fresh reliable previous prediction where appropriate, otherwise validated statistical/historical/seasonal/trend/persistence/baseline methods. Reduce precision and recommendation strength when evidence is weak. Bedrock is not the numerical fallback.

### Prediction evaluation

Compare predictions with reliable comparable outcomes after validating ground-truth quality. Where meaningful, use MAE, RMSE, bias, percentage error, and interval coverage. Evaluate by model, household where appropriate, horizon, resolution, season/weather/context, and data quality. Historical predictions remain immutable and tied to their model/version/context.

---

# 55. DATA LIFECYCLE / BACKUP / RESILIENCE — Q151–Q170

Q151 B — Internal identifiers + safe public references where needed.  
Q152 B — Controlled soft-delete/lifecycle states where historical integrity requires.  
Q153 B — Preserve immutable historical records where analytical/audit meaning requires.  
Q154 B — Separate audit/operational from analytical records.  
Q155 B — Category-specific retention/lifecycle policy.  
Q156 B — Intelligent archival/aggregation for older high-volume data.  
Q157 B — Scheduled backups + recovery strategy.  
Q158 B — Encrypted/protected backup storage with restricted access.  
Q159 B — Controlled restore + integrity validation + reconciliation.  
Q160 B — Versioned/migration-based schema changes.  
Q161 B — Bounded retry with appropriate backoff.  
Q162 B — Explicit timeout + controlled failure handling.  
Q163 B — Protection against repeated external-service failures where appropriate.  
Q164 B — Idempotent ingestion/deduplication using source/context identifiers.  
Q165 B — Validate timestamps/source ordering and handle late/out-of-order data.  
Q166 B — Validate timezone/timestamp plausibility/source context.  
Q167 B — Preserve successful independent work and isolate failed capability.  
Q168 B — Reconcile recovered external data against stored state.  
Q169 B — Service/integration health checks.  
Q170 B — Controlled maintenance/degraded state with communication.

Older high-volume records may be aggregated/archived without destroying required historical meaning. Recovery must validate integrity and reconcile, not blindly overwrite.

---

# 56. ANALYTICS / REPORTS / EXPORTS / API SECURITY — Q171–Q180

Q171 B — Backend analytical services own analytics calculation authority.  
Q172 B — Backend prepares validated graph-ready datasets.  
Q173 B — Professional JavaScript charting library.  
Q174 B — Graph + accessible summary/table/data representation.  
Q175 B — Validate comparability before presenting.  
Q176 B — Backend authoritative report content/data; presentation packages it.  
Q177 B — Generated report = defined validated snapshot/context.  
Q178 B — Track report generation/version/context where useful.  
Q179 B — Validate authorized scope and exported dataset before delivery.  
Q180 B — Appropriate formats such as CSV/XLSX/PDF/JSON by purpose.

Frontend charts do not calculate authoritative analytics. Reports package validated snapshots. Exports preserve semantic metadata and authorized scope.

---

# 57. API / FILE / ADMIN / PRIVACY SECURITY — Q181–Q200

Q181 B — Session + authorization validation for APIs.  
Q182 B — Appropriate rate limiting.  
Q183 B — Brute-force protection/bounded attempts.  
Q184 B — Secure token-based account recovery.  
Q185 B — Email verification before full protected access.  
Q186 B — Private controlled file storage.  
Q187 B — Authorized backend-mediated file access.  
Q188 B — Safe internal storage names.  
Q189 B — Controlled file processing boundary.  
Q190 B — Validate/reject suspicious files safely.  
Q191 B — DB credentials in environment/server config.  
Q192 B — Disable detailed production errors; keep internal diagnostics.  
Q193 B — Redact/minimize sensitive log information.  
Q194 B — Individual authenticated admin identities + authorization.  
Q195 B — Audit significant admin actions.  
Q196 B — Role/capability-based admin authorization where needed.  
Q197 B — Ownership/scope validation before export.  
Q198 B — Protected report access.  
Q199 B — Minimize sensitive content in notifications.  
Q200 B — Record relevant security events.

Files are private, processed through controlled boundaries, and served through authorized backend paths. Production errors never expose internal implementation details. Notifications minimize sensitive content.

---

# 58. GOODWE / WEATHER / AI / DASHBOARD — Q201–Q220

Q201 B — Secure GoodWe token lifecycle/refresh/revocation where applicable.  
Q202 B — GoodWe connection scoped to authorized household/plant.  
Q203 B — Explicit provider → SPMS mapping/normalization layer.  
Q204 B — Version-aware GoodWe integration + validation.  
Q205 B — External API credentials backend-only.  
Q206 B — Weather source/provider/time/context provenance.  
Q207 B — AI service credentials server/backend-only.  
Q208 B — AI timeout + fallback.  
Q209 B — User/external content treated as untrusted for prompt injection.  
Q210 B — Validate constrained AI output before presentation.  
Q211 B — Capability-aware modular dashboard loading.  
Q212 B — Dashboard sections degrade independently where practical.  
Q213 B — Backend authorization + UI adaptation.  
Q214 B — Capability-aware UI.  
Q215 B — Expose freshness/status where meaningful.  
Q216 B — Context-aware refresh.  
Q217 B — Controlled caching appropriate to sensitivity.  
Q218 B — Local storage only safe non-sensitive presentation/context where appropriate.  
Q219 B — All client-provided state untrusted.  
Q220 B — Frontend calculations presentation-only; authoritative calculations backend.

### GoodWe

GoodWe is integrated through a backend service boundary. Provider fields are normalized into SPMS semantics. Credentials remain server-side. Connection, service availability, freshness, and data quality are separate. Failure preserves prior actual solar data and degrades live capability. Recovery reconciles returned data and prevents duplicates.

### Weather

Weather supports solar prediction but does not replace GoodWe actuals. Provider, retrieval time, forecast/observation context, and location are preserved. Provider failures trigger controlled fallback to history/patterns where valid.

### AI

Bedrock is an explanation/intelligence boundary. It receives minimum necessary validated context, has versioned prompt/configuration, uses timeout/fallback, treats external/user content as untrusted, and has constrained output validation. It cannot become the numerical authority.

### Dashboard

Dashboard sections load independently where practical. One unavailable capability should not blank the entire dashboard. UI adapts to capability; backend authorization remains authoritative. Freshness is exposed where it affects interpretation. Caching is controlled and never silently presented as current live truth. Client state is always untrusted.

---

# 59. COMPLETE SPMS UI STATE SEMANTICS

Every significant capability must deliberately support the states relevant to it:

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

Data nature and data status remain separate.

### Nature

ACTUAL / PREDICTED / ESTIMATED / SIMULATED

### Status

FRESH / STALE / DEGRADED / UNAVAILABLE / PROCESSING / ERROR where applicable.

### Confidence

High / Moderate / Low / Very Low or Insufficient where supported by analytical evidence.

### Measurement Context

Measured / Inferred / Predicted / Estimated / Potential / Simulated.

---

# 60. CORE SPMS ANALYTICAL MEANING

## Solar Generation

Represents solar production according to the available authoritative measurement/prediction context.

## Household Consumption

Only measured when direct measurement exists. Otherwise it must be labeled predicted/estimated.

## Energy Balance

`Solar Generation − Household Consumption`

## Potential Surplus

Positive analytical/predicted balance. It is not automatically grid export.

## Potential Deficit

Negative analytical/predicted balance. It is not automatically grid import.

## Grid Import/Export

Do not claim confirmed import/export unless appropriate direct measurement supports it.

---

# 61. AUTHORITATIVE RESPONSIBILITY MAP

## Backend / Domain / Analytical Layers Own

- Authentication
- Authorization
- Validation
- Business rules
- Data storage
- Data quality
- Provenance
- Solar ingestion validation
- Environmental context
- Numerical prediction
- Energy balance
- Cost analysis
- Comparison validation
- Supported resolution
- Recommendation policy
- Notification decision
- Report content/data
- Export authorization/scope
- Lifecycle state
- Audit/operations
- Model evaluation

## Frontend Owns

- Presentation
- Interaction
- Responsive layout
- Visual states
- Input UX
- Loading presentation
- Accessible graph rendering
- Formatting
- Client-side convenience validation
- UI-only presentation calculations

## Bedrock Owns Only

- Controlled natural-language explanation
- Validated-fact interpretation
- Insight wording
- Localized explanation assistance where supported

Bedrock does **not** own numerical truth, prediction, balance, cost, confidence, quality, provenance, authorization, verification, or lifecycle.

---

# 62. REQUIRED DATA FLOW

`GoodWe / SEMS + Bills + Household + Weather`  
`→ Ingestion / Validation`  
`→ Quality + Provenance + Measurement Context`  
`→ Prediction / Authoritative Analysis`  
`→ Energy Balance + Cost`  
`→ Validated Facts`  
`→ Intelligence`  
`→ Bedrock Explanation`  
`→ Insights`  
`→ Recommendations`  
`→ Notification Decision`  
`→ Dashboard / Analytics / Reports / Export`

Historical data, feedback, preferences, audit, and lifecycle controls surround this pipeline without replacing analytical authority.

---

# 63. REQUIRED UX CONSISTENCY RULES

1. Same semantic fact must retain the same meaning on Dashboard, Solar, Energy, Forecasts, Analytics, Reports, and Export.
2. Page density may change; semantics may not.
3. Actual/predicted/estimated/simulated must remain identifiable.
4. Fresh/stale/degraded/unavailable must remain independent of nature.
5. Confidence and quality must be shown when they materially affect interpretation.
6. Potential outcomes must not be presented as confirmed physical outcomes.
7. Graphs must not create unsupported precision.
8. Comparisons must be validated before presentation.
9. Resolution must follow evidence.
10. Reports are snapshots.
11. Exports preserve meaning.
12. Search cannot grant authorization.
13. URLs cannot grant authorization or truth.
14. Saved views are presentation context, not truth snapshots.
15. Personalization cannot change analytical truth.
16. Client state is untrusted.
17. Backend is authoritative.
18. Failed capabilities degrade independently where practical.
19. Fake live values, fake progress, fake confidence, and fake precision are prohibited.
20. Demo/Test remains explicitly simulated.

---

# 64. REQUIRED IMPLEMENTATION CHECKLIST

Before implementing a component/page, verify:

- [ ] Global color token used
- [ ] Correct typography
- [ ] Correct spacing/radius
- [ ] Light mode defined
- [ ] Dark mode defined
- [ ] Hover/focus/active/disabled states as relevant
- [ ] Loading state as relevant
- [ ] No-data state as relevant
- [ ] Error/recovery state as relevant
- [ ] Stale/degraded/unavailable state as relevant
- [ ] Data nature explicitly represented where relevant
- [ ] Data status separated from nature
- [ ] Confidence shown where interpretation requires it
- [ ] Quality shown where meaningful
- [ ] Provenance available where needed
- [ ] Measurement context preserved
- [ ] Demo/Test clearly labeled
- [ ] Responsive behavior defined
- [ ] Accessibility considered
- [ ] Reduced motion considered
- [ ] Localization text expansion considered
- [ ] Backend authority preserved
- [ ] No client-side authoritative calculation
- [ ] No unsupported precision
- [ ] No semantic meaning conveyed by color alone
- [ ] No sensitive state stored in ordinary client presentation state

---

🎨 SPMS — Global Design Token System
1. Color architecture

One important distinction:

Logo colors ≠ application UI colors.

The logo keeps its navy + blue + yellow/orange identity.

The application UI uses the already-decided green + teal energy system.

Light theme
Token	Value	Purpose
--color-bg	#F9FAFB	Application background
--color-surface	#FFFFFF	Cards/panels
--color-border	#E5E7EB	Borders/dividers
--color-heading	#111827	Headings
--color-text	#374151	Main text
--color-muted	#9CA3AF	Secondary text
--color-primary	#22C55E	Main actions/energy
--color-primary-hover	#16A34A	Hover
--color-primary-active	#15803D	Active
--color-primary-soft	#DCFCE7	Soft green backgrounds
--color-secondary	#06B6D4	Teal accent
--color-secondary-hover	#0891B2	Teal hover
--color-secondary-active	#0E7490	Teal active
--color-secondary-soft	#E0F7FA	Soft teal
Dark theme
Token	Value
--color-bg	#0B1220
--color-surface	#111827
--color-border	#1F2937
--color-heading	#F9FAFB
--color-text	#D1D5DB
--color-muted	#6B7280
--color-primary	#34D399
--color-primary-hover	#22C55E
--color-primary-active	#16A34A
--color-primary-soft	#064E3B
--color-secondary	#22D3EE
--color-secondary-hover	#06B6D4
--color-secondary-active	#0891B2
--color-secondary-soft	#0E7490
⚡ 2. Semantic colors

These are meaning-based, not decorative.

Success
Light: #22C55E
Dark:  #34D399

Warning
Light: #F59E0B
Dark:  #FBBF24

Danger
Light: #EF4444
Dark:  #F87171

Info
Light: #3B82F6
Dark:  #60A5FA

For example:

Success → GoodWe connected

Warning → Solar data is stale

Danger → Integration failed

Info → Forecast information

And importantly:

We never communicate meaning through color alone.

☀️ 3. Energy/data visualization tokens

This is particularly important for SPMS.

Solar Actual          #22C55E
Consumption Actual    #3B82F6

Solar Forecast        #86EFAC
Consumption Forecast  #93C5FD

Potential Surplus     #10B981
Potential Deficit     #F97316

Grid / Reference      #6B7280
Estimated             #F59E0B
Stale                 #9CA3AF
Demo / Test           #8B5CF6

But the color is only one signal.

Data nature
ACTUAL
→ solid line
→ explicit "Actual" label

PREDICTED
→ dashed line
→ explicit "Predicted" label
→ confidence information

ESTIMATED
→ dotted line
→ explicit "Estimated" label

STALE
→ subdued/muted
→ stale indicator

SIMULATED / DEMO
→ explicit DEMO/SIMULATED label

This directly protects one of our most important SPMS principles:

Predicted data must never visually masquerade as measured data.

🔤 4. Typography

We already selected:

Inter

Used for:

Navigation
Body text
Forms
Buttons
Tables
Labels
Chart labels
Metadata
Space Grotesk

Used for:

Major headings
Hero numbers
KPI values
Important energy values
Dashboard numbers
Type scale
Display       48 / 56
H1            36 / 44
H2            28 / 36
H3            20 / 28
H4            16 / 24

Body Large    16 / 24
Body          14 / 20
Caption       12 / 16
Label         12 / 16

Weights:

300 Light
400 Regular
500 Medium
600 Semibold
700 Bold
📐 5. Spacing system

We will not randomly use 13px here, 19px there, 27px somewhere else.

Our base spacing scale:

4px
8px
12px
16px
24px
32px
48px
64px

Common usage:

4px   → tiny internal spacing
8px   → icon/text relationships
12px  → compact controls
16px  → normal component spacing
24px  → card internal spacing
32px  → section spacing
48px  → major sections
64px  → page-level spacing
🔲 6. Border radius
4px   → tags / tiny elements
8px   → buttons / inputs / controls
12px  → standard cards
16px  → large feature panels

We are not making everything extremely rounded.

SPMS should feel:

structured + premium + calm

rather than playful.

🌫️ 7. Elevation

Three levels:

Elevation 0
→ flat

Elevation 1
→ normal card

Elevation 2
→ important/raised component

Default light card:

background: #FFFFFF
border: 1px solid #E5E7EB
subtle shadow

Dark card:

background: #111827
border: 1px solid #1F2937
subtle shadow

No default glassmorphism.

🖥️ 8. Layout tokens

Desktop:

Page horizontal margin: 64px
Grid: 12 columns

Mobile:

Page horizontal margin: 24px
Grid: 4 columns / stacked

Standard card gap:

24px

Compact card gap:

16px
🎬 9. Motion tokens
Theme transition       200–300ms
Hover                  100–150ms
Button/state           100–200ms
Expand/sidebar         200–300ms
Page transition        300–400ms
Chart/data animation   400–600ms

Motion must be:

smooth
subtle
purposeful
non-distracting

No:

❌ bouncing
❌ flashing
❌ excessive particles
❌ neon effects
❌ parallax

And reduced-motion preferences must be respected.

☀️ 10. SPMS signature visual language

This is where the application becomes recognizably SPMS, rather than just another dashboard.

We use:

Green
   ↓
energy / healthy utilization

Teal
   ↓
technology / intelligence

Blue
   ↓
solar / analytical visualization

Yellow + Orange
   ↓
sun / solar identity

Deep Navy
   ↓
brand identity

Atmosphere is subtle:

Green/teal radial accents
Organic curves
Soft sunlight-like illumination
Very restrained ambient effects

But the priority remains:

DATA
 ↓
CONTENT
 ↓
COMPONENT
 ↓
ATMOSPHERE

Never the reverse.

🧠 11. The most important implementation rule

All of this eventually becomes CSS custom properties.

Conceptually:

:root {
    --color-bg: #F9FAFB;
    --color-surface: #FFFFFF;
    --color-primary: #22C55E;
    --color-secondary: #06B6D4;

    --color-heading: #111827;
    --color-text: #374151;
    --color-muted: #9CA3AF;

    --space-1: 4px;
    --space-2: 8px;
    --space-3: 12px;
    --space-4: 16px;
    --space-5: 24px;
    --space-6: 32px;
    --space-7: 48px;
    --space-8: 64px;

    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --radius-xl: 16px;
}

Dark theme then overrides the semantic values rather than duplicating the entire component system.

---

# 65. VIVA-FRIENDLY CORE EXPLANATIONS

### Why green?
Green communicates fresh, sustainable solar energy and gives SPMS a calm energy identity.

### Why Inter + Space Grotesk?
Inter keeps the interface readable; Space Grotesk creates distinctive emphasis for major analytical values/headings.

### Why Actual vs Predicted vs Estimated?
Because measured facts, model outputs, and estimates have different reliability and must never be confused.

### Why Actual can also be Stale?
Nature describes what the data is; status describes freshness. An actual measurement can be old.

### Why is Potential Surplus not Grid Export?
Without direct grid measurement, a positive solar-minus-consumption balance is a potential surplus, not confirmed export.

### Why is Bedrock not the numerical engine?
Numerical analytics require deterministic/validated authority and traceability. Bedrock is used for controlled explanation of validated facts.

### Why verify AI bill extraction?
AI extraction can make mistakes; user verification creates authoritative billing data.

### Why graceful degradation?
GoodWe, weather, AI, network, and backend services can fail. SPMS must preserve trustworthy information instead of pretending everything is live.

### Why progressive disclosure?
Household users need immediate understanding, while deeper evidence and technical detail remain available when needed.

### Why graphs as a functional capability?
SPMS is an energy-management and analytical system. Historical trends, comparisons, forecasts, balance, and cost require validated visualization.

### Why no fake percentage in loading?
A progress percentage is meaningful only when the backend actually knows progress.

### Why backend authority?
It prevents different pages, graphs, reports, exports, and clients from calculating different versions of the same truth.

---

# 66. FINAL PHASE 4 GOVERNING RULES

1. **Data defines the interface; atmosphere supports it.**
2. **Green is the primary SPMS energy identity.**
3. **Light Mode is fresh, spacious, and calm.**
4. **Dark Mode is deep, luminous, and controlled.**
5. **Light/Dark geometry and semantic hierarchy remain consistent.**
6. **Inter handles UI readability; Space Grotesk handles major analytical emphasis.**
7. **Spacing is adaptive but tokenized.**
8. **Cards use borders and subtle elevation.**
9. **Controls communicate action and state.**
10. **Icons use one professional outline language.**
11. **Graphs are required functionality, not decoration.**
12. **Smooth curves are preferred for continuous data when semantically valid.**
13. **Actual, predicted, estimated, stale, and simulated remain distinguishable.**
14. **Color alone never communicates meaning.**
15. **Potential surplus is not confirmed grid export.**
16. **Potential deficit is not confirmed grid import.**
17. **Nature and freshness/status are independent.**
18. **Confidence and quality are meaningful data, not decoration.**
19. **Progressive disclosure cannot hide meaning-changing uncertainty.**
20. **Drill-down reveals; it never creates.**
21. **Comparisons require validation.**
22. **Resolution follows evidence.**
23. **Export preserves meaning.**
24. **Reports are validated snapshots.**
25. **Personalization changes presentation/relevance, never truth.**
26. **Explicit preferences outrank learned behavior.**
27. **Temporary interactions are not automatically persistent preferences.**
28. **Search finds authorized resources; it does not grant access.**
29. **URLs carry context, not authority.**
30. **Browser state is not system truth.**
31. **Backend state is authoritative across sessions/devices.**
32. **Offline data exposes freshness and limitations.**
33. **Queued does not mean completed.**
34. **Accepted does not mean completed.**
35. **Connected does not mean fresh.**
36. **A failed capability must not destroy unrelated valid information.**
37. **Fallbacks are validated and explicitly labeled.**
38. **Bedrock explains validated facts; it does not define numerical truth.**
39. **Frontend calculations are presentation-only.**
40. **All client state is untrusted.**
41. **Protected operations require backend authorization.**
42. **Secrets remain server-side.**
43. **Uploaded files are untrusted and privately controlled.**
44. **Historical analytical meaning is preserved where required.**
45. **Demo/Test data remains visibly simulated.**
46. **Accessibility is part of every state and component.**
47. **Localization changes presentation, not semantic meaning.**
48. **Cross-device continuity synchronizes intent, not truth.**
49. **The UI adapts to evidence, capability, context, role, confidence, and device.**
50. **SPMS remains simple enough for household use and deep enough for analysis, administration, implementation, and viva presentation.**

---

# 67. PHASE 4 COMPLETION GATE

**Q1–Q220: LOCKED.**

Phase 4 is **COMPLETE**.

Before Phase 5 begins:

1. Save this file as `docs/04_UI_DESIGN_SYSTEM.md`.
2. Update `docs/10_DECISION_LOG.md` with Phase 4 decisions.
3. Verify consistency with Phases 1–3.
4. Commit this stable Phase 4 documentation checkpoint in GitHub Desktop.
5. Only then begin Phase 5.

**END OF PHASE 4 — UI/UX DESIGN SYSTEM**
