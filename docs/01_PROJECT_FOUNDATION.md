# Solar Power Management System (SPMS)

## Project Foundation

**Document:** 01_PROJECT_FOUNDATION.md\
**Project:** Solar Power Management System (SPMS)\
**Document Status:** Draft for Review\
**Foundation Decisions:** Q1--Q45 Locked\
**Purpose:** Official project foundation and scope definition

------------------------------------------------------------------------

## 1. Document Purpose

This document defines the foundational decisions for the Solar Power
Management System (SPMS).

It establishes:

-   the identity and purpose of SPMS;
-   the target user and core problem;
-   the system's major inputs and data sources;
-   the role of AI/ML and Amazon Bedrock;
-   energy-balance and measurement principles;
-   recommendation, notification, and personalization principles;
-   data quality, provenance, confidence, privacy, and audit
    requirements;
-   account, solar-plant, battery, and lifecycle boundaries;
-   forecasting, cost, analytics, reporting, and graph requirements;
-   the MVP boundary; and
-   the controlled Demo/Test environment.

All Q1--Q45 decisions in this document are considered locked for the
Foundation phase unless explicitly revised through the project's
decision-management process.

This document is the conceptual foundation. Detailed functional modules,
page flows, database structures, APIs, technical implementation, and
code are defined in later project phases.

------------------------------------------------------------------------

# 2. Project Identity

## 2.1 Project Name

**SPMS --- Solar Power Management System**

## 2.2 Core Purpose

SPMS is a system that helps households use their solar energy more
efficiently by understanding when solar power is insufficient or
available in excess, and recommending the best time to use or shift
their electricity consumption.

SPMS is not merely a solar monitoring system. It is a solar power
management and decision-support system.

### Core objectives

-   Reduce avoidable dependence on grid electricity.
-   Improve the utilization of generated solar energy.
-   Identify periods of potential solar surplus.
-   Identify periods of potential energy deficit.
-   Understand the relationship between household demand and solar
    generation.
-   Forecast future household consumption and solar generation.
-   Estimate cost and energy impacts.
-   Provide understandable, household-level recommendations.
-   Present historical analytics, graphs, and reports.
-   Communicate uncertainty rather than presenting unsupported
    precision.

### Core principle

> Use more of the right energy at the right time, reduce wastage, and
> reduce the household's dependence on costly grid electricity.

------------------------------------------------------------------------

# 3. Target User

The primary target user is a household solar owner who:

-   has a solar installation;
-   does not receive compensation for excess energy produced or
    exported;
-   does not have battery storage as the intended MVP scenario; and
-   wants to manage electricity consumption during low-solar periods and
    make better use of high-solar periods.

SPMS recommendations are intended to reduce avoidable surplus and
improve solar utilization. SPMS does not promise to eliminate all
surplus because actual grid flow and export behavior depend on the
available measurement and source data.

------------------------------------------------------------------------

# 4. Core Problem

Households with solar power may not have a clear way to understand and
match their actual electricity requirements with the solar energy they
generate.

They may not know:

-   when solar generation is insufficient;
-   when excess solar availability is likely;
-   when household demand is likely to be high;
-   how solar and consumption timing differ;
-   what impact these differences may have on energy utilization and
    cost; or
-   what practical changes could improve alignment between household
    consumption and solar availability.

SPMS addresses this by combining:

-   solar-generation information;
-   user-provided electricity-bill and usage information;
-   household information;
-   weather and solar information; and
-   historical energy behavior.

The system then predicts, analyzes, and explains the expected
relationship between household demand and solar availability.

------------------------------------------------------------------------

# 5. Core SPMS Concept

The conceptual intelligence pipeline is:

``` text
Electricity Bills / Household Usage
                +
GoodWe / SEMS Solar Data
                +
Weather / Solar Information
                +
Household Information
                ↓
      Data Validation & Quality
                ↓
        Prediction Engine
                ↓
 Consumption + Solar Forecasts
                ↓
          Energy Balance
                ↓
         Impact Analysis
                ↓
       Pattern Detection
                ↓
        Recommendations
                ↓
       Explanation Layer
                ↓
 Notification Decision / Delivery
                ↓
 Dashboard + Graphs + Analytics + Reports
```

SPMS should calculate what the available evidence supports and should
not claim measurements that it does not possess.

------------------------------------------------------------------------

# 6. Electricity Bill as a Core Data Source

The electricity bill is a fundamental SPMS input rather than merely a
document-upload feature.

GoodWe provides information about solar generation, while electricity
bills provide information about household electricity requirements and
cost history.

## 6.1 Bill information

Where available, SPMS may capture:

-   billing period;
-   electricity consumption in kWh/units;
-   total bill amount;
-   energy/electricity charges;
-   tariff information;
-   fixed charges;
-   other relevant charges; and
-   previous usage information included in the bill.

SPMS must not assume that every electricity bill contains the same
fields or structure.

## 6.2 Bill processing flow

``` text
Electricity Bill
      ↓
Document Extraction / Manual Entry
      ↓
User Verification
      ↓
Structured Bill Data
      ↓
Household Energy Profile
      ↓
Historical Consumption & Cost Trends
      ↓
Prediction / Analysis
```

Bills progressively build the household energy profile. A long history
of bills is not mandatory at onboarding.

------------------------------------------------------------------------

# 7. Electricity Bill Data Entry

SPMS will use a hybrid bill-entry approach.

Users may:

1.  upload an electricity bill as a PDF or image; or
2.  enter the required information manually.

For supported document uploads:

``` text
PDF / Image
    ↓
AWS Document-Analysis Capability
    ↓
Extracted Text / Information
    ↓
Amazon Bedrock
    ↓
Interpretation & Normalization
    ↓
User Verification / Correction
    ↓
Validated Structured Bill Data
```

The extracted information must not automatically become trusted
household data.

The user must be shown the detected values and allowed to verify or
correct them before the data becomes part of the household energy
profile.

Manual entry remains a fallback when:

-   extraction is unsuccessful;
-   the bill cannot be processed reliably; or
-   the user prefers manual entry.

### Principle

> Let AWS extract the information, let Bedrock understand and structure
> it, let the user verify it, and only then let SPMS use it for
> prediction.

The exact document-analysis service, extraction implementation,
supported bill formats, and integration details are technical decisions
for later phases.

------------------------------------------------------------------------

# 8. Household Profile

SPMS will use a hybrid adaptive household profile.

## 8.1 Initial information

The initial setup should collect only essential household information,
such as:

-   household size;
-   location;
-   general house information; and
-   typical occupancy pattern.

Detailed appliance schedules are not required during initial onboarding.

## 8.2 Progressive learning

SPMS may progressively derive or learn:

-   daily consumption patterns;
-   time-based consumption behavior;
-   weekday/weekend differences;
-   seasonal trends;
-   consumption variability; and
-   other useful household characteristics.

Sources may include:

-   verified bills;
-   GoodWe data;
-   weather/solar information; and
-   historical household behavior.

Additional household or appliance information may be requested later
when it provides meaningful analytical value.

### Principle

> SPMS should ask for what it cannot know, derive what it can calculate,
> and learn what it can discover from the household's actual energy
> behavior.

------------------------------------------------------------------------

# 9. Location

For real GoodWe-connected households:

-   GoodWe plant location should be used when available.
-   The plant location should be converted to geographic coordinates
    internally.
-   Coordinates can then support weather and solar information
    retrieval.

Users should not normally be required to enter latitude and longitude
manually.

For Demo/Test users:

-   a simple city/area selector may be used;
-   the system can resolve the selected location internally.

If a connected GoodWe plant does not provide usable location
information, a manual city/area selection can serve as fallback.

The relevant location is the **solar plant location**, not the user's
current physical location.

------------------------------------------------------------------------

# 10. Solar Data Source

## 10.1 GoodWe / SEMS

GoodWe/SEMS is the primary source of actual and historical
solar-generation information because the target real-world solar plant
uses a GoodWe inverter.

Potential GoodWe/SEMS information includes:

-   current solar generation;
-   historical generation;
-   daily/monthly generation;
-   plant information;
-   inverter information; and
-   other supported statistics.

The exact GoodWe/SEMS API capabilities and integration method will be
finalized during the technical architecture and implementation phases.

## 10.2 External weather/solar information

External weather and solar information supports future prediction.

Potential information includes:

-   weather conditions;
-   cloud conditions;
-   solar irradiance where available;
-   temperature;
-   precipitation;
-   forecast information; and
-   other relevant environmental variables.

### Source roles

``` text
GoodWe / SEMS → Actual Solar Generation
Weather / Solar Sources → Environmental Forecast Evidence
Prediction Engine → Future Solar Generation Forecast
```

SPMS should use an internal data/integration layer so the rest of the
application works with standardized SPMS data rather than coupling every
component directly to GoodWe.

------------------------------------------------------------------------

# 11. GoodWe Availability and Graceful Degradation

SPMS must continue functioning when GoodWe is temporarily unavailable.

When GoodWe cannot provide current information, SPMS may use:

-   the last successfully received actual data;
-   historical GoodWe data;
-   external weather/solar information;
-   household information;
-   validated predictions; and
-   appropriate fallback estimates.

The interface must clearly indicate:

-   live data unavailable;
-   last synchronization time;
-   whether displayed information is cached, estimated, or predicted;
    and
-   any reduction in confidence.

Stale actual data must never be presented as live actual data.

Non-live capabilities may continue, including:

-   historical analytics;
-   consumption prediction;
-   solar forecasting;
-   estimated energy balance;
-   reports; and
-   historical graphs.

When GoodWe becomes available again, SPMS should:

1.  validate the connection;
2.  synchronize new data;
3.  reconcile it with existing historical records;
4.  prevent duplicates;
5.  restore supported live capabilities; and
6.  recalculate affected analytical outputs where appropriate.

### Principle

> SPMS should degrade gracefully, not fail completely.

------------------------------------------------------------------------

# 12. Weather Intelligence

Weather information is supporting predictive evidence, not the source of
actual solar-generation measurements.

GoodWe remains the reference for actual solar generation.

Weather information may include:

-   solar irradiance;
-   cloud cover;
-   temperature;
-   precipitation;
-   other atmospheric variables;
-   current conditions;
-   forecast information; and
-   relevant historical environmental information.

Weather sources should be evaluated based on:

-   availability;
-   freshness;
-   geographic relevance;
-   completeness;
-   consistency;
-   quality; and
-   forecast horizon.

Multiple providers may be supported in the future when they provide
meaningful reliability or fallback value. Multiple providers are not
mandatory for the MVP.

If weather data becomes unavailable, solar prediction may continue
using:

-   historical GoodWe generation;
-   learned solar-generation patterns;
-   other validated evidence.

Confidence or forecast capability should be reduced when appropriate.

### Principle

> Weather data should strengthen SPMS's understanding of future solar
> conditions without becoming a single point of failure.

------------------------------------------------------------------------

# 13. AI and Prediction Responsibilities

SPMS uses AI/ML for broader household energy forecasting and impact
analysis.

Potential prediction and analytical outputs include:

-   household consumption;
-   solar generation;
-   future surplus periods;
-   future deficit periods;
-   cost impact;
-   solar-utilization opportunities;
-   household consumption impact;
-   solar-production impact; and
-   future monthly and longer-term trends.

The architecture should distinguish between quantities that need
prediction and quantities that can be deterministically derived.

For example:

``` text
Predicted Solar
      +
Predicted Consumption
      ↓
Deterministic Energy Balance
      ↓
Potential Surplus / Potential Deficit
```

Surplus and deficit should not require unnecessary independent ML models
when they can be derived from validated predictions.

------------------------------------------------------------------------

# 14. Hybrid Adaptive Prediction

SPMS will provide predictions even when limited historical household
data exists, but confidence must reflect the available evidence.

## 14.1 New household

The system may combine:

-   household information;
-   general patterns;
-   seasonal information;
-   weather/solar information;
-   available solar history; and
-   other validated evidence.

The resulting prediction should have lower confidence when evidence is
limited.

## 14.2 More historical data

As verified data accumulates, SPMS may increasingly rely on:

-   household-specific history;
-   recent trends;
-   seasonal behavior;
-   weather;
-   solar history; and
-   other relevant information.

Data quantity and quality should both influence confidence.

SPMS should consider:

-   amount of history;
-   bill coverage;
-   recency;
-   missingness;
-   consistency;
-   solar history availability; and
-   weather availability.

The exact ML model strategy and retraining process are implementation
decisions for later phases.

### Principle

> SPMS should start with an informed estimate, communicate uncertainty,
> and progressively replace assumptions with household-specific
> evidence.

------------------------------------------------------------------------

# 15. Energy Balance

SPMS will use both time-based and aggregate energy balance analysis.

## 15.1 Time-based foundation

Solar generation and household consumption occur at different times.

Therefore, SPMS should compare predicted or estimated solar and
consumption over supported time intervals.

Example:

``` text
Solar > Consumption
→ Potential Surplus

Solar ≈ Consumption
→ Approximately Balanced

Consumption > Solar
→ Potential Deficit
```

## 15.2 Aggregation

Time-based results may be aggregated into:

-   daily;
-   weekly;
-   monthly; and
-   longer-term summaries.

## 15.3 Measurement limitation

Without direct grid, smart-meter, battery, or export/import
measurements:

-   potential deficit must not be presented as confirmed grid import;
-   potential surplus must not be presented as confirmed grid export.

Backend analytical components perform the energy-balance calculations.

AI/Bedrock may interpret and explain validated results, but must not
invent measurements.

### Principle

> Calculate energy balance at the time scale where energy occurs, then
> aggregate it into useful higher-level views.

------------------------------------------------------------------------

# 16. Measurement-Aware Energy Model

The MVP may not have direct household consumption or grid-flow
measurements.

Therefore:

``` text
GoodWe Solar Generation
        +
Verified Electricity-Bill History
        +
Household Information
        +
Consumption Prediction
        ↓
Potential Energy Balance
```

Predicted consumption must never be presented as measured consumption.

Potential surplus/deficit must not be presented as confirmed
export/import without reliable measurement data.

The architecture remains measurement-ready so that future smart-meter or
equivalent measurements can be incorporated without redesigning the
overall analytical/provenance framework.

Each important analytical result should retain appropriate:

-   data nature;
-   source;
-   measurement context;
-   confidence;
-   quality information; and
-   provenance.

------------------------------------------------------------------------

# 17. Time Granularity

SPMS will use a hybrid adaptive time model.

Supported analytical levels include:

-   short-term;
-   daily;
-   weekly;
-   monthly; and
-   long-term.

Where sufficiently detailed and reliable data exists, short-term
analysis may use hourly or finer supported intervals.

Where household information is available only at daily or monthly
resolution, SPMS must operate at the appropriate coarser resolution.

The system must not manufacture unsupported fine-grained household
consumption values.

Lower-resolution summaries may be created by aggregating valid
lower-level observations.

### Principle

> SPMS should use the finest time resolution that the available evidence
> can reliably support---not the finest resolution the software can
> display.

------------------------------------------------------------------------

# 18. Prediction Horizons

SPMS will use an adaptive multi-horizon forecasting model.

Possible horizons include:

-   next few hours;
-   following day;
-   several days;
-   weekly;
-   monthly; and
-   longer-term.

Short-term forecasts primarily support:

-   near-term solar generation;
-   household consumption;
-   energy balance;
-   surplus/deficit analysis; and
-   recommendations.

Medium-term forecasts support:

-   planning;
-   several-day trends; and
-   weekly analysis.

Monthly and longer-term forecasts focus more on:

-   consumption trends;
-   solar trends;
-   cost trends; and
-   seasonal patterns.

Prediction confidence should generally vary by horizon and evidence
quality.

Longer horizons may be broad, low-confidence, or unavailable when
evidence is insufficient.

Amazon Bedrock remains separate from numerical prediction generation.

------------------------------------------------------------------------

# 19. Cost Impact

SPMS will use a hybrid adaptive cost model.

## 19.1 Strong tariff information

When sufficient tariff information exists, SPMS may use the applicable
tariff structure to estimate future energy costs.

## 19.2 Incomplete tariff information

The system may use:

-   verified historical bills;
-   historical consumption-to-cost relationships;
-   a reliable applicable unit rate; or
-   a cost range.

Fixed charges and other non-variable charges must not automatically be
treated as proportional to energy consumption.

Potential grid-dependent energy derived from predicted solar and
consumption must not become confirmed grid-import cost without reliable
measurement data.

When billing evidence is insufficient:

-   provide a range;
-   reduce confidence; or
-   provide a qualitative cost impact.

### Principle

> SPMS should become less precise---not less honest---when billing or
> tariff evidence is incomplete.

------------------------------------------------------------------------

# 20. Household-Level Recommendations

SPMS will not directly manage or control individual appliances as a core
feature.

Instead, it will provide household-level recommendations.

## 20.1 Recommendation pipeline

``` text
Validated Data
      ↓
Prediction
      ↓
Energy Balance
      ↓
Pattern Detection
      ↓
Impact Analysis
      ↓
Recommendation
      ↓
Bedrock Explanation
      ↓
Notification Decision
```

## 20.2 Recommendation categories

Potential situations include:

-   solar-rich periods;
-   potential solar surplus;
-   potential deficit;
-   recurring timing mismatch;
-   increasing consumption;
-   changing solar utilization; and
-   meaningful cost impacts.

Recommendations may include:

-   shifting suitable flexible electricity usage toward solar-rich
    periods;
-   reducing or deferring non-essential consumption during expected
    deficit periods;
-   broader household energy-management insights.

Recommendations should be practical and should not encourage unnecessary
electricity consumption merely because solar is available.

------------------------------------------------------------------------

# 21. Recommendation Confidence and Guardrails

Recommendation strength should adapt to:

-   prediction confidence;
-   impact;
-   data quality;
-   source availability;
-   relevance; and
-   recurrence.

General behavior:

``` text
High Confidence
→ Stronger actionable recommendation

Moderate Confidence
→ Cautious recommendation

Low Confidence
→ Mainly informational insight

Very Low Confidence
→ Avoid strong actionable notification by default
```

Uncertainty changes recommendation strength rather than automatically
eliminating useful information.

Amazon Bedrock may explain validated recommendations but must not
independently invent analytical facts.

------------------------------------------------------------------------

# 22. Recommendation Feedback and Learning

Users may provide lightweight recommendation feedback, such as:

-   useful/not useful;
-   swipe/dismiss;
-   optionally acted on;
-   optionally worked well.

SPMS may use this feedback to improve:

-   recommendation relevance;
-   recommendation types;
-   timing;
-   frequency;
-   repetition control; and
-   personalization.

However:

> Dismissed recommendation does not automatically mean the underlying
> prediction was wrong.

Feedback is additional evidence and should be considered alongside:

-   prediction outcomes;
-   energy data;
-   recommendation context;
-   user behavior.

Feedback must not blindly modify prediction confidence or analytical
truth.

------------------------------------------------------------------------

# 23. Recommendation Explainability

Important recommendations should provide a concise expandable
explanation.

The explanation may include:

-   relevant time period;
-   predicted solar conditions;
-   expected household demand;
-   potential surplus/deficit;
-   confidence; and
-   other validated factors relevant to the decision.

The system should explain the decision rather than expose unnecessary
database records or technical internals.

Amazon Bedrock may convert validated analytical facts into natural
language.

### Principle

> Explain the decision, not the entire database.

------------------------------------------------------------------------

# 24. Data Quality

SPMS will use a hybrid adaptive data-quality system.

Each relevant data source should be assessed for:

-   reliability;
-   freshness;
-   completeness;
-   plausibility;
-   consistency; and
-   verification status.

When conflicting or suspicious data is detected, SPMS should:

1.  preserve the original information where appropriate;
2.  flag the issue;
3.  assess trust;
4.  downgrade or temporarily exclude unreliable data when necessary; and
5.  allow downstream confidence and capability decisions to reflect the
    problem.

Source priority remains useful:

-   GoodWe is the primary actual solar source;
-   verified electricity bills are the primary historical
    household-consumption source.

However, source priority does not override obvious quality problems.

------------------------------------------------------------------------

# 25. Data Provenance

Important SPMS values must have a clear meaning, source, and reliability
context.

Internally, provenance should capture appropriate information such as:

-   source;
-   timestamp;
-   forecast period;
-   data nature;
-   freshness;
-   quality status;
-   confidence;
-   model version where applicable;
-   measurement context where applicable; and
-   relevant processing context.

Data nature includes:

-   Actual;
-   Predicted;
-   Estimated;
-   Simulated.

Freshness and quality are separate concepts.

For example:

-   Actual data can be stale.
-   Predicted data can have high or low confidence.
-   Estimated data can be valid but less precise.

The metadata should travel through:

``` text
Source
 ↓
Validation
 ↓
Analytics
 ↓
Prediction
 ↓
Recommendation
 ↓
Explanation
```

The user interface should expose only useful provenance indicators such
as:

-   Actual;
-   Predicted;
-   Estimated;
-   Stale;
-   Demo; and
-   Confidence.

Detailed provenance remains available internally for auditing,
debugging, evaluation, and explainability.

------------------------------------------------------------------------

# 26. Historical Data Retention

SPMS will retain long-term historical information for:

-   seasonal analysis;
-   long-term trends;
-   household-specific prediction improvement;
-   recommendation personalization;
-   cost analysis;
-   model evaluation; and
-   reports.

Retention should be intelligent rather than indiscriminately storing
every high-volume raw observation forever.

A suitable conceptual strategy is:

``` text
Recent high-volume observations
→ higher resolution

Older high-volume observations
→ aggregated / archived analytical summaries

Important low-volume records
→ retained carefully
```

Important records include:

-   verified bills;
-   prediction history;
-   recommendation history;
-   user feedback;
-   essential provenance;
-   model information; and
-   evaluation records.

Historical predictions should remain available for comparison against
later actual outcomes.

Historical records should not be silently overwritten merely because an
anomaly is later discovered. Original information and validation/quality
status should remain traceable.

------------------------------------------------------------------------

# 27. Prediction Failure and Fallback

Prediction is treated as a validated service with multiple fallback
levels.

If a primary prediction model:

-   fails to execute;
-   produces invalid results;
-   lacks sufficient input data;
-   exceeds acceptable uncertainty;
-   fails validation;

SPMS should evaluate whether a previous successful prediction remains
sufficiently useful and fresh.

If not, it may use validated:

-   statistical methods;
-   historical methods;
-   seasonal methods;
-   trend-based methods;
-   persistence methods; or
-   simpler baseline estimation.

Every fallback must itself be validated.

Fallback results must not be presented as equivalent to the failed
primary model.

Outputs should retain appropriate status such as:

-   Predicted;
-   Stale Predicted;
-   Estimated;
-   Simulated; or
-   Unavailable.

Downstream energy-balance and recommendation logic must apply validity
and confidence gates.

Amazon Bedrock is not the emergency numerical fallback for failed
prediction models.

### Principle

> When the prediction engine fails, SPMS should degrade from
> intelligence to simpler evidence-based estimation---not from
> uncertainty to fabricated certainty.

------------------------------------------------------------------------

# 28. AI/ML Model Lifecycle and Versioning

SPMS will use explicit model versioning and controlled model lifecycle
management.

Each prediction should retain, where applicable:

-   model type;
-   model version;
-   generation timestamp;
-   input-data context;
-   forecast horizon;
-   time resolution;
-   confidence;
-   uncertainty;
-   provenance; and
-   validation status.

Historical predictions are immutable analytical records.

A new model version must not rewrite old predictions as if the new model
produced them.

## 28.1 Model lifecycle

``` text
Development
 ↓
Validation
 ↓
Evaluation
 ↓
Controlled Deployment
 ↓
Monitoring
 ↓
Continue / Restrict / Rollback
```

A newer version is not automatically considered better.

Model performance should be evaluated using:

-   prediction-error metrics;
-   actual outcomes;
-   confidence calibration;
-   relevant household conditions;
-   seasonal/weather conditions; and
-   data-quality conditions.

Household-consumption and solar-generation prediction models may
maintain independent versions.

Amazon Bedrock does not determine numerical model validity.

------------------------------------------------------------------------

# 29. Prediction Accuracy Evaluation

SPMS will compare historical predictions against later reliable and
comparable outcome evidence.

Before an outcome is treated as evaluation evidence, the system should
assess:

-   quality;
-   freshness;
-   completeness;
-   provenance; and
-   comparability.

Unreliable, stale, estimated, suspicious, or otherwise unsuitable
outcomes must not automatically become ground truth.

Possible metrics include:

-   absolute error;
-   Mean Absolute Error (MAE);
-   Root Mean Squared Error (RMSE);
-   bias;
-   percentage-based error where mathematically meaningful;
-   uncertainty interval coverage; and
-   confidence calibration.

Evaluation may be performed at:

-   model-version level;
-   household level where sufficient data exists;
-   prediction horizon;
-   time resolution;
-   seasonal conditions;
-   weather conditions; and
-   data-quality conditions.

Solar predictions may be evaluated against later reliable GoodWe
generation data.

Household-consumption and cost predictions may be evaluated against
reliable and comparable verified consumption or billing evidence.

Prediction accuracy evaluation remains separate from recommendation
usefulness feedback.

------------------------------------------------------------------------

# 30. GoodWe Solar-Plant Lifecycle

SPMS will use a hybrid solar-plant lifecycle.

When GoodWe is disconnected:

``` text
GoodWe Connection
      ↓
Disconnect
      ↓
Connection becomes inactive
      ↓
Future synchronization stops
      ↓
Historical synchronized solar data remains
```

Historical capabilities that do not require an active GoodWe connection
may continue.

Current/future GoodWe-dependent capabilities may become:

-   unavailable;
-   degraded; or
-   lower-confidence estimates where trustworthy fallback evidence
    exists.

When GoodWe is reconnected:

1.  validate the connection;
2.  resume synchronization;
3.  reconcile newly retrieved data with existing records;
4.  prevent duplicates; and
5.  restore supported capabilities.

Disconnecting a GoodWe connection is not data deletion.

### Principle

> Disconnect the source, not the history.

------------------------------------------------------------------------

# 31. Account and Household Deletion

SPMS will use a controlled hybrid account-deletion lifecycle.

When deletion is requested:

1.  disable account access;
2.  revoke active GoodWe/API credentials or tokens;
3.  delete or anonymize personal/account information according to
    retention policy;
4.  remove or anonymize associated household-specific data as defined by
    policy;
5.  stop deleted household data from participating in active processing;
    and
6.  retain only minimal non-personal technical/audit information when
    legitimately required for system integrity or proof of deletion.

Account deletion is different from:

-   temporary deactivation; and
-   GoodWe disconnection.

For Demo/Test accounts, the same deletion lifecycle should be
demonstrable.

Demo accounts can later be recreated from predefined simulated scenarios
without retaining deleted household information.

### Principle

> Disconnecting removes a data source; deleting an account removes the
> user's SPMS data relationship.

------------------------------------------------------------------------

# 32. Battery Scope

The MVP targets households without battery storage.

Battery intelligence is therefore outside MVP scope.

If GoodWe indicates that a battery is present, SPMS must not silently
ignore it and calculate potentially misleading energy flows.

Instead, the system should:

-   detect the presence of battery storage;
-   communicate that battery-aware analysis is unsupported in the MVP;
-   mark battery-dependent calculations as unsupported/degraded; and
-   continue supported capabilities where safe.

The architecture should keep these concepts logically separate:

-   Solar Plant;
-   Household Consumption; and
-   Energy Storage.

This enables future battery-aware energy management without fundamental
redesign.

------------------------------------------------------------------------

# 33. Account Roles and Modes

SPMS will use a hybrid authorization model.

## 33.1 Formal roles

### Household User

May access their own:

-   household information;
-   electricity bills;
-   solar information;
-   predictions;
-   recommendations;
-   feedback;
-   analytics; and
-   reports.

### Admin

May access authorized system-management capabilities such as:

-   user/account management;
-   service-health monitoring;
-   configuration;
-   controlled Demo/Test management.

## 33.2 Demo/Test mode

Demo/Test is not a separate authorization role.

It is an account mode/environment attribute.

A Demo/Test account can behave mainly as a household user while its data
is explicitly marked as simulated/demo information.

Backend authorization must enforce both:

-   role permissions; and
-   household ownership.

The frontend is not a security boundary.

------------------------------------------------------------------------

# 34. Privacy by Design

Household energy information is treated as sensitive throughout its
lifecycle.

SPMS should:

-   collect only information required for supported capabilities;
-   avoid unnecessary exposure;
-   separate identity and household information logically;
-   enforce backend authorization and ownership;
-   protect credentials and secrets;
-   minimize AI-service data exposure;
-   avoid sensitive data in logs unless required;
-   retain only appropriate information; and
-   follow controlled deletion procedures.

GoodWe credentials, API credentials, tokens, and other secrets must not
be unnecessarily exposed through:

-   frontend code;
-   API responses;
-   source code;
-   logs.

AI services such as Amazon Bedrock should receive only the validated
information required for their specific task.

AI services must not become the permanent storage location for household
information.

Privacy controls must coexist with:

-   provenance;
-   confidence;
-   data quality;
-   model versioning;
-   evaluation; and
-   audit requirements.

------------------------------------------------------------------------

# 35. Notifications and Alerts

SPMS will distinguish between:

1.  informational insights;
2.  actionable recommendations; and
3.  active notifications.

Not every analytical event should become a notification.

## 35.1 Notification decision

An event should be evaluated using:

-   significance;
-   impact;
-   prediction confidence;
-   data quality;
-   actionability;
-   urgency;
-   recurrence;
-   duplicate history; and
-   whether the user has already been informed about a substantially
    similar condition.

Only meaningful events should actively draw the user's attention.

## 35.2 Notification preferences

Users may configure normal categories such as:

-   significant solar opportunities;
-   expected energy deficits;
-   meaningful cost impacts;
-   important consumption trends;
-   report availability; and
-   other supported energy insights.

Security and important system/service-status information may require
appropriate communication regardless of normal notification preferences.

## 35.3 Delivery

Supported delivery mechanisms may include:

-   in-application notifications;
-   push notifications where available;
-   optional notification sounds.

Sound ON/OFF is a delivery preference, not part of significance
evaluation.

Duplicate and recurrence controls should prevent repeated identical
notifications.

Amazon Bedrock may generate understandable notification wording from
validated facts, but the deterministic notification policy decides
whether the event deserves notification.

------------------------------------------------------------------------

# 36. User Control and Personalization

SPMS will use:

``` text
Intelligent Defaults
      +
Explicit User Preferences
      +
Learned Personalization
```

Complex analytical integrity decisions remain system-controlled,
including:

-   prediction methodology;
-   confidence calculation;
-   uncertainty handling;
-   data-quality evaluation;
-   source reliability;
-   fallback behavior;
-   energy-balance calculations;
-   model validation;
-   provenance; and
-   core notification-significance logic.

Users can control presentation, communication, and personalization.

Examples:

-   notification categories;
-   notification sound;
-   display/unit preferences;
-   privacy settings;
-   recommendation categories;
-   dashboard customization.

Explicit preferences take precedence over learned preferences.

Learned personalization must never modify analytical truth or system
integrity.

Users should be able to reset learned personalization where appropriate.

------------------------------------------------------------------------

# 37. Dashboard and Graph Requirements

Graphs are a required SPMS capability, not an optional visual
enhancement.

The default dashboard should present important information such as:

-   solar generation;
-   household consumption;
-   energy balance;
-   forecasts;
-   cost outlook;
-   recommendations; and
-   important notifications.

Required analytical visualizations include, where supported by the
available data:

-   solar generation over time;
-   household consumption over time;
-   solar-versus-consumption comparison;
-   energy balance;
-   historical trends;
-   forecast visualization; and
-   other supported analytical patterns.

The UI must clearly distinguish:

-   actual;
-   predicted;
-   estimated;
-   simulated; and
-   stale information.

Users may customize supported dashboard cards and graphs and, where
supported, arrange their layout.

Dashboard customization affects presentation only. It must not modify:

-   analytical calculations;
-   predictions;
-   confidence;
-   data-quality decisions;
-   provenance; or
-   other system-integrity mechanisms.

A reset-to-default option should be available.

------------------------------------------------------------------------

# 38. Historical Analytics and Reports

Historical analytics and report generation are MVP capabilities.

Users should be able to explore supported historical information
through:

-   configurable time ranges;
-   graphs;
-   comparisons;
-   summary metrics; and
-   relevant detailed views.

Historical solar-generation information should come from GoodWe where
available.

Household consumption, energy balance, cost, and other SPMS-specific
analytics should be derived from their corresponding validated data
sources.

## 38.1 Intelligent summaries

SPMS may provide lightweight intelligent summaries of important
historical patterns.

These summaries must be generated from validated analytical results.

Amazon Bedrock may convert validated findings into understandable
language.

## 38.2 Reports

Reports may contain:

-   graphs;
-   validated metrics;
-   historical trends;
-   potential surplus/deficit analysis;
-   supported cost information;
-   important insights;
-   recommendations;
-   confidence;
-   data-quality context; and
-   clear actual/predicted/estimated distinctions.

Reports must not present potential surplus or deficit as confirmed grid
export/import when direct measurement is unavailable.

For MVP, reporting should focus primarily on useful periodic/monthly
energy reports rather than a highly complex reporting platform.

Reports may be:

-   viewed inside SPMS; and
-   generated as downloadable documents.

------------------------------------------------------------------------

# 39. Operational, Security, and Audit Lifecycle

SPMS will maintain separate but connected categories of historical
records.

## 39.1 Operational events

May include:

-   service health;
-   synchronization;
-   processing;
-   failure;
-   recovery;
-   important system operations.

## 39.2 Security and audit events

May include:

-   authentication;
-   authorization;
-   account actions;
-   data actions;
-   GoodWe connection lifecycle;
-   configuration changes;
-   administrative actions;
-   deletion lifecycle.

## 39.3 Analytical history

May include:

-   predictions;
-   recommendations;
-   notifications;
-   prediction evaluations;
-   provenance;
-   model-related events.

Important events should contain enough structured information to
identify:

-   what happened;
-   when it happened;
-   which component or actor was involved;
-   which relevant entity was affected; and
-   whether the operation succeeded, failed, or produced a particular
    result.

Logs must not become a second uncontrolled copy of household data.

The system should avoid logging:

-   passwords;
-   API credentials;
-   GoodWe tokens;
-   complete electricity bills;
-   unnecessary household information;
-   complete energy datasets.

MVP audit/operational logging should support troubleshooting and system
traceability without attempting to implement an enterprise observability
platform.

------------------------------------------------------------------------

# 40. MVP Boundary

SPMS MVP is an **end-to-end demonstrable system**.

The primary journey is:

``` text
Login
  ↓
Household Setup
  ↓
Electricity Bill
  ↓
Bill Extraction + Verification
  ↓
GoodWe / Solar Data
  ↓
Weather Data
  ↓
Prediction
  ↓
Energy Balance
  ↓
Impact Analysis
  ↓
Recommendation
  ↓
Dashboard
  ↓
Graphs + Analytics
  ↓
Report
```

The MVP should also contain appropriate foundational support for:

-   confidence;
-   provenance;
-   data quality;
-   graceful fallback;
-   notifications;
-   audit records;
-   personalization; and
-   model lifecycle concepts.

The objective is a complete working SPMS journey rather than a large
collection of disconnected features.

------------------------------------------------------------------------

# 41. Demo/Test Environment

SPMS will provide a controlled Demo/Test environment.

The Demo/Test environment should use the same core analytical pipeline
as real household accounts wherever practical.

It should not consist of hardcoded presentation screens that bypass the
real analytical system.

Instead:

``` text
Simulated Inputs
      ↓
Validation
      ↓
Prediction / Analysis
      ↓
Energy Balance
      ↓
Recommendation
      ↓
Visualization
      ↓
Notification
      ↓
Report
```

## 41.1 Example scenarios

The Demo/Test environment may include controlled scenarios for:

-   high solar generation;
-   low solar generation;
-   balanced energy;
-   high household consumption;
-   poor weather;
-   GoodWe unavailability;
-   prediction failure;
-   limited historical data;
-   cost changes;
-   important notifications; and
-   historical analytical trends.

Each scenario should have a defined demonstration purpose.

## 41.2 Demo provenance

All simulated information must be explicitly identified as:

-   Demo;
-   Simulated; and
-   associated with the relevant scenario and Demo Engine.

The UI should have a persistent Demo/Test indicator.

Reports must also identify simulated information.

## 41.3 Failure demonstrations

Demo scenarios should intentionally exercise graceful-degradation
behavior.

Examples:

``` text
GoodWe Unavailable
→ Fallback Solar Data
→ Reduced Confidence
```

and:

``` text
Prediction Failure
→ Validated Fallback Method
→ Estimated / Reduced-Confidence Output
```

### Principle

> SPMS Demo/Test mode should simulate the conditions needed to
> demonstrate the complete system---not simulate the system itself.

------------------------------------------------------------------------

# 42. System-Wide Principles

The Foundation decisions establish the following global rules.

## 42.1 Evidence before certainty

SPMS must not claim greater accuracy, temporal precision, or measurement
capability than the available evidence supports.

## 42.2 Actual versus predicted

Actual, predicted, estimated, and simulated information must remain
distinguishable.

## 42.3 Graceful degradation

A failed external service or model should reduce capability or precision
rather than unnecessarily disabling the entire application.

## 42.4 Backend security

Authorization and household ownership are enforced by the backend.

## 42.5 AI responsibility separation

Numerical analytical components generate and validate numerical results.
Amazon Bedrock primarily interprets, explains, and personalizes
validated information.

## 42.6 User-level intelligence

SPMS provides household-level recommendations rather than direct
appliance control.

## 42.7 Historical traceability

Important historical predictions, recommendations, reports, model
information, and provenance should remain traceable.

## 42.8 Privacy by design

Household energy data must be minimized, protected, appropriately
separated, and controlled throughout its lifecycle.

## 42.9 Explainability

Users should understand why a meaningful recommendation or insight was
produced.

## 42.10 Graphs are core functionality

Graphs and analytical visualizations are part of the core product
capability.

------------------------------------------------------------------------

# 43. Locked Foundation Decision Register

  -----------------------------------------------------------------------------------------------------
  ID                      Topic                   Locked Decision
  ----------------------- ----------------------- -----------------------------------------------------
  Q1                      Main purpose / identity SPMS is a solar power management and household energy
                                                  decision-support system.

  Q2                      Target user             Household solar owner without compensation for excess
                                                  generation and without battery storage as the target
                                                  MVP scenario.

  Q3                      Core problem            Help households understand and align actual
                                                  electricity requirements with solar generation.

  Q4                      Recommendation          Recommendations should align household electricity
                          principle               usage with available/predicted solar without
                                                  encouraging unnecessary consumption.

  Q5                      User-provided inputs    Electricity-bill/usage information and supporting
                                                  household/appliance information may be provided by
                                                  the user.

  Q6                      Best-time logic         Intelligent scheduling principles were established
                                                  initially, but appliance scheduling is no longer a
                                                  core feature after Q7.

  Q7                      Appliance management    SPMS does not directly control or schedule individual
                                                  appliances; intelligence operates at household level.

  Q8                      AI prediction scope     Broader household consumption, solar generation,
                                                  surplus/deficit, cost, impact, trends, and
                                                  utilization analysis.

  Q9                      Insufficient prediction Hybrid adaptive prediction with confidence that
                          data                    reflects evidence quantity and quality.

  Q10                     Solar data source       GoodWe/SEMS is the primary actual source; external
                                                  weather/solar information supports prediction.

  Q11                     GoodWe unavailable      Hybrid fallback using cached/history and validated
                                                  estimates/predictions with explicit degraded state.

  Q12                     Electricity bill role   Full household energy profile input covering
                                                  consumption and cost information.

  Q13                     Bill entry              Hybrid AI-assisted extraction plus manual fallback,
                                                  with user verification before storage/use.

  Q14                     Household profile       Hybrid adaptive profile using minimal onboarding data
                                                  plus progressive learning from actual behavior.

  Q15                     Location                GoodWe-first location with simple manual city/area
                                                  fallback.

  Q16                     Initial consumption     Hybrid adaptive baseline with informed low-confidence
                          baseline                estimates when history is limited.

  Q17                     Energy balance          Time-based energy balance as the foundation,
                                                  aggregated into daily/long-term views; potential
                                                  surplus/deficit must not be misrepresented as
                                                  measured grid flow.

  Q18                     Recommendations         Broader household recommendations based on validated
                                                  predictions, energy balance, patterns, impact, and
                                                  data quality.

  Q19                     Recommendation          Confidence and impact determine recommendation
                          uncertainty             strength; low-confidence situations receive weaker or
                                                  informational treatment.

  Q20                     Recommendation feedback Adaptive recommendation learning using feedback as
                                                  additional evidence, not as unquestioned truth.

  Q21                     Explainability          Concise expandable explanation showing the validated
                                                  factors behind important recommendations.

  Q22                     Data quality/conflicts  Hybrid adaptive data-quality evaluation before data
                                                  is allowed to influence downstream intelligence.

  Q23                     Service unavailability  Capability-aware graceful degradation with explicit
                                                  actual/cached/estimated/predicted/stale/unavailable
                                                  states.

  Q24                     Data nature/provenance  Formal internal provenance with selective user-facing
                                                  indicators.

  Q25                     Historical data         Long-term history with intelligent retention,
                                                  aggregation, and archival.

  Q26                     Roles/account modes     Household User and Admin roles; Demo/Test is an
                                                  account mode, not a separate role.

  Q27                     Solar plants            Single-plant MVP with multi-plant-ready entity
                                                  architecture.

  Q28                     Battery                 No-battery intelligence in MVP, but battery-ready
                                                  architecture and explicit unsupported handling.

  Q29                     GoodWe disconnection    Disconnect the source while preserving historical
                                                  solar data.

  Q30                     Account deletion        Controlled deletion lifecycle with access
                                                  disablement, credential revocation, data
                                                  deletion/anonymization, and limited technical
                                                  retention where required.

  Q31                     Measurement model       Hybrid measurement-aware model that distinguishes
                                                  predictions from measured consumption and potential
                                                  flows from confirmed grid flow.

  Q32                     Time granularity        Adaptive resolution based on the evidence-supported
                                                  temporal resolution.

  Q33                     Prediction horizons     Adaptive multi-horizon forecasting with confidence
                                                  and capability based on available evidence.

  Q34                     Cost impact             Hybrid adaptive cost calculation using tariff
                                                  information, verified bill history, and honest
                                                  fallback estimates/ranges.

  Q35                     Weather                 Weather is supporting predictive evidence, not actual
                                                  solar measurement; no single weather provider should
                                                  be a point of failure.

  Q36                     Prediction failure      Hybrid fallback lifecycle from primary model to
                                                  validated simpler methods, with explicit provenance
                                                  and confidence.

  Q37                     Model lifecycle         Explicit model versioning, validation, evaluation,
                                                  controlled deployment, monitoring, and rollback.

  Q38                     Prediction evaluation   Compare predictions with reliable comparable outcomes
                                                  using appropriate metrics and uncertainty evaluation.

  Q39                     Privacy                 Privacy-by-design lifecycle covering collection,
                                                  storage, processing, AI use, logging, retention, and
                                                  deletion.

  Q40                     Notifications           Adaptive significance-based notification lifecycle
                                                  with configurable normal preferences and controlled
                                                  delivery.

  Q41                     User control            Intelligent defaults + explicit preferences + learned
                                                  personalization, without compromising analytical
                                                  integrity.

  Q42                     Analytics/reports       Interactive historical analytics and useful
                                                  periodic/monthly reports with validated graphs and
                                                  insights.

  Q43                     Operational/audit       Controlled operational, security/audit, and
                                                  analytical history with privacy-aware traceability.

  Q44                     MVP boundary            Complete end-to-end demonstrable journey from login
                                                  and inputs through prediction, analysis,
                                                  recommendation, visualization, and reporting.

  Q45                     Demo/Test               Controlled simulated scenarios using the real SPMS
                                                  pipeline wherever practical, with explicit simulated
                                                  provenance.
  -----------------------------------------------------------------------------------------------------

------------------------------------------------------------------------

# 44. Foundation Completion Status

**Q1--Q45: LOCKED**

Phase 1 establishes the conceptual rules of SPMS.

The next phase does not redefine the foundation unless a genuine
architectural contradiction is discovered and explicitly reviewed.

------------------------------------------------------------------------

# 45. Transition to Phase 2

## Phase 2 --- Functional Blueprint

The next objective is to convert the Foundation decisions into the
actual functional structure of SPMS.

The Phase 2 process should be:

``` text
Q1–Q45 Foundation
        ↓
Identify Functional Responsibilities
        ↓
Derive Final SPMS Modules
        ↓
Define Module Boundaries
        ↓
Define Each Module's:
    • Purpose
    • Inputs
    • Processing
    • Outputs
    • Dependencies
    • Data Responsibilities
    • Failure / Fallback Behavior
    • MVP / Future Scope
        ↓
Define End-to-End Functional Data Flow
        ↓
Functional Blueprint Complete
```

The module structure should be derived from the locked Foundation
decisions rather than assumed in advance.

No implementation code should be produced merely because a functional
module has been identified. Implementation begins only after the
required design and architecture phases are completed.

------------------------------------------------------------------------

# 46. Final Foundation Statement

SPMS is designed as a household solar-energy decision-support system
that combines real solar-generation information, verified household
electricity information, environmental evidence, adaptive prediction,
deterministic energy analysis, and explainable recommendations.

Its core responsibility is not to control appliances or claim
measurements it does not possess.

Its responsibility is to:

``` text
Understand
    ↓
Predict
    ↓
Compare
    ↓
Analyze
    ↓
Explain
    ↓
Recommend
    ↓
Visualize
    ↓
Learn
```

while preserving:

``` text
Reliability
Confidence
Provenance
Privacy
Traceability
Graceful Degradation
```

The system should become more useful as reliable household and solar
history accumulates, while remaining honest about uncertainty when
evidence is limited.

> **SPMS should calculate what the available evidence supports, explain
> what it means, and help the household make better energy decisions
> without pretending to know more than it actually does.**
