# SPMS — Phase 2 Functional Blueprint

## Phase
**Phase 2 — Functional Blueprint**

## Status
**LOCKED / COMPLETED**

---

# 1. Purpose

The Functional Blueprint defines what SPMS does at a system-functional level before deciding how individual pages or code modules will be implemented.

A functional module is not automatically a webpage. One module may power several pages, and several supporting capabilities may operate across the entire application.

---

# 2. Core Functional Modules

## F1 — Solar Data Management

Responsibilities:
- Establish GoodWe/SEMS connection.
- Validate the solar source.
- Synchronize actual solar measurements.
- Maintain source status and synchronization state.
- Preserve actual solar history.
- Handle source disconnection and recovery.
- Keep actual, cached, estimated, predicted, stale, and simulated data distinguishable.

Primary dependency:
- GoodWe/SEMS

Feeds:
- Energy Balance
- Prediction
- Cost/Impact
- Intelligence
- Analytics
- Reports

---

## F2 — Household Energy Management

Responsibilities:
- Maintain household context.
- Maintain household energy profile.
- Process electricity bills.
- Support manual bill entry.
- Build historical consumption understanding.
- Maintain household-related inputs used by prediction and recommendation.

Bill flow:
Upload → Extract → Interpret/Normalize → User Verify → Store Verified Data.

Household size and detailed appliance information are not compulsory unless genuinely required by a downstream capability.

---

## F3 — Energy Prediction & Forecasting

Responsibilities:
- Predict future solar generation/availability.
- Predict future household electricity consumption.
- Support adaptive multi-horizon forecasts.
- Use historical data, bills, weather/environmental data, and household context.
- Track prediction confidence.
- Track model/version/context.
- Support validated fallback behavior.

Numerical prediction belongs to the prediction engine, not Bedrock.

---

## F4 — Energy Balance & Situation Analysis

Responsibilities:
- Compare solar generation with household consumption.
- Determine potential surplus, balanced, or deficit situations.
- Support time-based and aggregate analysis.
- Distinguish potential energy balance from confirmed grid import/export.

Backend/authoritative analytics owns numerical classification.

---

## F5 — Energy Cost Analysis

Responsibilities:
- Analyze historical verified cost.
- Estimate future cost impact where supported.
- Use tariff information when available.
- Use historical bill relationships or simple unit-rate fallback when necessary.
- Keep historical verified cost separate from future estimated cost.
- Avoid treating fixed charges as consumption-proportional by default.

---

## F6 — Energy Intelligence & Insight Generation

Responsibilities:
- Interpret validated analytical facts.
- Combine energy situation, cost impact, historical context, data quality, and confidence.
- Detect meaningful patterns and opportunities.
- Generate concise user-facing insights.

Bedrock may explain validated facts, but cannot invent numerical facts.

---

## F7 — Recommendation Management

Responsibilities:
- Convert validated insights into household-specific recommendations.
- Consider confidence, impact, data quality, user context, and feedback.
- Adjust recommendation strength to uncertainty.
- Avoid recommendations when evidence is too weak.

Example:
Potential solar surplus → recommend shifting flexible household usage into the solar-rich period.

---

## F8 — Historical Analytics & Reporting

Responsibilities:
- Prepare historical analytical datasets.
- Prepare graph-ready representations.
- Support interactive analytics.
- Support comparisons and trends.
- Generate report-ready analytical outputs.
- Preserve historical truth and provenance.

Graphs are a required functional capability, not an optional visual enhancement.

---

# 3. Supporting / Cross-Cutting Capabilities

## F9 — Data Quality & Validation

Evaluates:
- Freshness
- Completeness
- Reliability
- Plausibility
- Consistency
- Verification
- Source conflicts

Quality must propagate into confidence and downstream decision-making.

It must not silently rewrite authoritative source data.

---

## F10 — Data Provenance & Measurement Context

Tracks:
- Source
- Timestamp
- Nature of data
- Freshness
- Quality
- Confidence
- Measurement context

Examples:
- Actual
- Predicted
- Estimated
- Simulated
- Cached
- Stale
- Fallback
- Demo

---

## F11 — Notification & Alert Management

Separates:
1. Event
2. Notification decision
3. Delivery

Considers:
- Significance
- Impact
- Confidence
- Data quality
- Actionability
- Urgency
- Recurrence
- Duplicate suppression
- User preferences

---

## F12 — User Preferences, Personalization & Feedback

Users can provide lightweight feedback:
- Useful / Not useful
- Acted / Worked

Feedback can adapt:
- Recommendation relevance
- Frequency
- Timing
- Personalization

Feedback does not directly change objective prediction truth.

---

# 4. Supporting Sub-Functions

## F13 — Electricity Bill Management

This is a sub-function of Household Energy Management.

Includes:
- Upload
- Extraction
- Normalization
- Verification
- Manual entry
- Historical bill management

---

## F14 — Location & Environmental Data Management

Location:
- GoodWe-first
- Manual fallback

Environmental data:
- Weather/solar information
- Freshness and quality checks
- Prediction support
- Fallback when unavailable

---

# 5. System / Platform Functions

## F15 — Account, Authorization, Privacy & System Lifecycle

Includes:
- Registration
- Email verification
- Authentication
- Session lifecycle
- Role/ownership authorization
- Account deletion
- Privacy/data lifecycle
- Demo/Test account lifecycle

Roles:
- Household User
- Admin

Demo/Test is an account mode, not a role.

---

## F16 — Operational Monitoring, Audit & Traceability

Tracks important:
- Sync events
- Failures/recovery
- Bill processing
- Prediction/fallback
- Model deployment
- Recommendation generation
- Notification decisions
- Authentication
- Deletion
- Admin operations

Operational logs are not a second analytical database.

---

# 6. Functional Dependency Graph

### Dependency 1
Solar Data + Household Context  
→ Energy Balance

### Dependency 2
Historical Solar + Historical Consumption + Bills + Weather + Household Context  
→ Prediction

### Dependency 3
Solar Forecast + Consumption Forecast  
→ Future Energy Balance

### Dependency 4
Energy Situation + Cost Impact + Historical Context  
→ Energy Intelligence

### Dependency 5
Meaningful Insight + Confidence + Impact + User Context  
→ Recommendation

### Dependency 6
Recommendation + Significance + Urgency + Preferences  
→ Notification Decision

System/data events can also trigger notifications.

### Dependency 7
Historical Data  
→ Analytics + Intelligence

### Dependency 8
Feedback  
→ Personalization

Feedback does not directly become prediction truth.

---

# 7. End-to-End Functional Lifecycle

SPMS begins by establishing a household and progressively building its energy profile from solar, electricity-bill, household, and environmental evidence.

It validates and contextualizes that information, predicts future solar production and household consumption, determines the resulting energy situation, evaluates cost and impact, converts validated results into household-specific intelligence, and produces appropriate insights, recommendations, notifications, analytics, and reports.

As new data and feedback become available, SPMS updates historical understanding and adapts future intelligence while preserving provenance, confidence, data quality, and measurement limitations.

External disconnection degrades capabilities without destroying history.

Account deletion ends the household's SPMS data relationship according to lifecycle policy.

### One-line lifecycle

**Collect → Validate → Understand → Predict → Compare → Evaluate → Explain → Recommend → Notify → Learn → Repeat**

---

# 8. Locked Workflow Inventory

## Account / Household
- W1 Account Registration & Activation
- W2 Authentication & Session Lifecycle
- W3 Household Setup & Energy Profile Initialization
- W4 Household/Profile Modification
- W5 Account/Household Deletion

## Solar
- W6 GoodWe Connection & Validation
- W7 Solar Data Synchronization
- W8 Solar Disconnection, Degradation & Recovery

## Household / Bills / Environment
- W9 Electricity Bill Processing
- W10 Manual Bill Entry & Verification
- W11 Household Energy Profile Development
- W12 Location Establishment
- W13 Environmental Data Retrieval & Context Update
- W14 Environmental Failure & Fallback

## Intelligence
- W15 Energy Forecasting
- W16 Prediction Validation & Fallback
- W17 Energy Balance & Situation Analysis
- W18 Energy Cost Analysis
- W19 Energy Intelligence & Insight Generation
- W20 Recommendation Generation

## Supporting / Output
- W21 Notification Evaluation & Delivery
- W22 Feedback & Personalization Adaptation
- W23 Historical Analytics & Visualization Data Preparation
- W24 Report Generation
- W25 Export
- W26 Data Quality Assessment & Propagation
- W27 Provenance & Measurement Context Tracking
- W28 Operational Monitoring & Audit
- W29 Prediction Evaluation & Model Lifecycle

---

# 9. Workflow Principles

## W1 — Account Registration & Activation
Ensure the user's account is valid, verified, securely established, and appropriately authorized before protected SPMS access.

Flow:
Collect → Validate → Eligibility → Create Account → Email Verification → Activation → Authorization Context → Welcome/Onboarding → W3.

## W2 — Authentication & Session Lifecycle
Ensure identity is securely authenticated and a valid session/authorization context exists before protected resources are accessible.

W2 establishes who the user is. Account/Authorization determines what the user may access.

## W3 — Household Setup
Ensure a valid, contextualized household profile exists with readiness, quality, and limitation states before downstream intelligence relies on it.

House size is not compulsory.

## W4 — Household/Profile Modification
Validate and authorize changes, version them, and propagate affected changes while preserving historical truth.

**Boundary:** W4 changes current context; it does not rewrite the past.

## W5 — Deletion
Securely terminate the user's SPMS data relationship, revoke access, stop processing, and apply controlled data disposition.

**Boundary:** Deletion is a lifecycle operation, not merely deleting an account row.

## W6–W8 — Solar Source Lifecycle
Establish, synchronize, monitor, degrade, and recover a validated GoodWe source.

Actual measurements must remain identifiable as actual.

## W9–W14 — Household, Billing & Environmental Lifecycle
Progressively establish reliable household and environmental context while preserving verification, quality, provenance, source authority, historical truth, and fallback behavior.

## W15–W20 — Energy Intelligence Lifecycle
Transform validated evidence into:
Prediction → Energy Situation → Cost Impact → Intelligence → Insights → Recommendations.

Architecture boundary:

**Validated Evidence → Authoritative Analysis → Validated Facts → Intelligence → Bedrock Explanation → Insights → Recommendations**

Bedrock does not invent numerical facts.

## W21–W29 — Supporting / Analytical / Lifecycle Functions
Maintain trustworthiness, traceability, adaptation, security, resilience, notification behavior, analytics, reports, quality, provenance, audit, and model lifecycle.

---

# 10. Required System Behaviors

SPMS must support graceful handling of:
- No data
- Insufficient data
- Poor-quality data
- Stale data
- Conflicting data
- Service failure
- Model failure
- Fallback operation
- Report failure
- Export failure
- Graph/data preparation failure
- Audit/database failure
- Duplicate operations
- Unauthorized access
- Disabled/deleted accounts
- Demo/Test scenarios
- Concurrent operations
- Model deployment failure and rollback

---

# 11. Graph / Visualization Rule

Graphs are mandatory project functionality.

Authoritative analytical data  
→ Historical Analytics & Reporting  
→ Graph-ready dataset  
→ JavaScript chart component  
→ Rendered graph

The frontend renders graphs; it must not invent analytical values.

Graphs should support, where data permits:
- Solar generation
- Household consumption
- Solar vs consumption
- Energy balance
- Trends
- Forecasts
- Cost outlook
- Historical comparisons
- Other supported analytical information

Actual/predicted/estimated data must remain distinguishable in visualization.

---

# 12. Functional Architecture Boundary

The system should preserve the following conceptual separation:

**Data Sources**
→ GoodWe / SEMS  
→ Bills  
→ Weather / Environmental Sources  
→ Household Context

**Validation & Context**
→ Data Quality  
→ Provenance  
→ Measurement Context

**Authoritative Analysis**
→ Prediction  
→ Energy Balance  
→ Cost Analysis

**Intelligence**
→ Insight Generation  
→ Bedrock Explanation

**User Value**
→ Recommendations  
→ Notifications  
→ Analytics  
→ Reports  
→ Feedback / Personalization

---

# 13. Phase 2 Completion Criteria

Phase 2 is complete when:
- Functional modules are defined.
- Supporting capabilities are defined.
- System functions are defined.
- Workflow inventory is locked.
- Dependencies are understood.
- Core boundaries are explicit.
- Graphs are treated as required functionality.
- The distinction between analytical truth and AI explanation is explicit.
- Failure/degradation behavior is part of the functional design.

**Status: Phase 2 LOCKED.**
