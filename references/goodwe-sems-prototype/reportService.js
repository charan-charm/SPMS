// ReportService (Phase 3.8) — Reports & Solar Performance Intelligence.
//
// A COMPOSER ONLY: it reuses existing verified services to build a normalized
// report. It contains no new calculation/business logic, no SQL, and no
// SEMS/provider calls. All facts come from:
//
//   - insightService.normalizeRange / buildIntelligence / buildAiBlock
//   - solarAnalyticsService (daily generation series for charts)
//   - electricityBillService (billing-period context via the intelligence facts)
//
// Data-truth rules (cumulative):
//   - Real stored SEMS data only; NULL != 0; missing != 0.
//   - Bill kWh is billing-period context, never real-time household consumption.
//   - No self-consumption claim. Potential excess stays NOT_DETERMINABLE
//     without required measurements.
//   - AI is interpretation only and stays AI_ENABLED=false by default.

const solarAnalyticsService = require("./solarAnalyticsService");
const insightService = require("./insightService");
const { createSolarError } = require("../utils/solarErrors");

// Compose the full normalized report for a user + range.
// options.includeAi (default true) controls whether an AI interpretation is
// attempted. Export always uses includeAi=false (deterministic only).
async function composeReport(userId, query, options = {}) {
    const includeAi = options.includeAi !== false;
    if (!userId) {
        throw createSolarError("Authentication required.", "AUTHORIZATION_ERROR");
    }

    const range = insightService.normalizeRange(query);

    // Deterministic facts + insights + recommendations (reuses buildIntelligence,
    // which internally runs the existing analytics + bill services).
    const { facts, insights, recommendations } = insightService.buildIntelligence(userId, range);

    // AI interpretation (reuses the Phase 3.7 AI block with safe fallback).
    const ai = await insightService.buildAiBlock(includeAi, facts, insights, recommendations);

    // Daily generation series for the charts (bounded daily aggregates).
    const analytics = solarAnalyticsService.getAnalytics(userId, {
        range: "custom",
        startTime: range.startTime,
        endTime: range.endTime,
        resolution: "daily",
        stationId: range.stationId,
        inverterSn: range.inverterSn,
    });

    const solarPerformance = {
        generation: facts.generation,
        dailyGeneration: buildDailySeries(analytics),
        powerTrend: facts.generationTrend,
        temperature: facts.temperature,
        dataQuality: facts.dataQuality,
        connection: facts.connection,
    };

    return {
        report: {
            range: facts.range,
            generatedAt: new Date().toISOString(),
            stationId: analytics.stationId,
            inverterSn: analytics.inverterSn,
            capabilities: facts.capabilities,
            solarPerformance,
            billContext: facts.bill,
            intelligence: { insights, recommendations, ai },
            meta: {
                sampleCount: Array.isArray(analytics.points) ? analytics.points.length : 0,
                resolution: analytics.resolution,
            },
        },
    };
}

// Daily generation series from daily analytics points. NULL days stay NULL
// (gaps are not fabricated). Only the final daily cumulative total is used.
function buildDailySeries(analytics) {
    if (!analytics || !Array.isArray(analytics.points)) return [];
    return analytics.points
        .map((p) => ({
            date: p.timestamp ? String(p.timestamp).slice(0, 10) : null,
            kwh: p.generation !== null && p.generation !== undefined ? p.generation : null,
        }))
        .filter((d) => d.date !== null);
}

// Escape a CSV field (RFC 4180-ish): wrap in quotes when it contains a
// comma, quote, or newline; double internal quotes.
function csvField(value) {
    const str = value === null || value === undefined ? "" : String(value);
    if (/[",\r\n]/.test(str)) {
        return `"${str.replace(/"/g, '""')}"`;
    }
    return str;
}

// Build a dependency-free CSV from a composed report. Deterministic only.
function buildCsv(report) {
    if (!report) return "";
    const lines = [];

    // Section: report metadata.
    lines.push("SPMS Solar Performance Report");
    lines.push(`GeneratedAt,${csvField(report.generatedAt)}`);
    lines.push(`RangeKey,${csvField(report.range && report.range.rangeKey)}`);
    lines.push(`RangeStart,${csvField(report.range && report.range.startTime)}`);
    lines.push(`RangeEnd,${csvField(report.range && report.range.endTime)}`);
    lines.push(`StationId,${csvField(report.stationId)}`);
    lines.push(`InverterSn,${csvField(report.inverterSn)}`);
    lines.push("");

    // Section: solar performance summary.
    lines.push("SolarPerformance");
    const gen = report.solarPerformance && report.solarPerformance.generation;
    lines.push(`TotalGeneration_kWh,${csvField(gen && gen.total)}`);
    lines.push(`PeakPower_kW,${csvField(gen && gen.peakPower)}`);
    lines.push(`PeakTime,${csvField(gen && gen.peakTime)}`);
    lines.push(`AveragePower_kW,${csvField(gen && gen.avgPower)}`);
    const trend = report.solarPerformance && report.solarPerformance.powerTrend;
    lines.push(`GenerationTrendPercent,${csvField(trend && trend.percent)}`);
    lines.push(`GenerationTrendDirection,${csvField(trend && trend.direction)}`);
    const temp = report.solarPerformance && report.solarPerformance.temperature;
    lines.push(`TemperatureAverage_C,${csvField(temp && temp.average)}`);
    lines.push(`TemperaturePeak_C,${csvField(temp && temp.peak)}`);
    const dq = report.solarPerformance && report.solarPerformance.dataQuality;
    lines.push(`DataCompleteness_pct,${csvField(dq && dq.completenessPercent)}`);
    lines.push(`DataActual,${csvField(dq && dq.actual)}`);
    lines.push(`DataExpected,${csvField(dq && dq.expected)}`);
    lines.push("");

    // Section: daily generation.
    lines.push("DailyGeneration_kWh");
    lines.push("Date,kWh");
    const days = (report.solarPerformance && report.solarPerformance.dailyGeneration) || [];
    for (const d of days) {
        lines.push(`${csvField(d.date)},${csvField(d.kwh)}`);
    }
    lines.push("");

    // Section: capabilities.
    lines.push("MeasurementCapabilities");
    const caps = report.capabilities || {};
    lines.push(`generation,${csvField(caps.generation)}`);
    lines.push(`consumption,${csvField(caps.consumption)}`);
    lines.push(`grid,${csvField(caps.grid)}`);
    lines.push(`battery,${csvField(caps.battery)}`);
    lines.push(`energyBalance,${csvField(caps.energyBalance)}`);
    lines.push(`excess,${csvField(caps.excess)}`);
    lines.push("");

    // Section: bill context.
    const bill = report.billContext;
    lines.push("BillContext");
    lines.push(`BilledUnits_kWh,${csvField(bill && bill.unitsConsumed)}`);
    lines.push(`BillAmount,${csvField(bill && bill.billAmount)}`);
    lines.push(`BillingStart,${csvField(bill && bill.period && bill.period.start)}`);
    lines.push(`BillingEnd,${csvField(bill && bill.period && bill.period.end)}`);
    lines.push(`SolarGenerationDuringBillPeriod_kWh,${csvField(bill && bill.solarGenerationDuringBillPeriod)}`);
    lines.push(`BillCoverage_pct,${csvField(bill && bill.billCoveragePercent)}`);
    lines.push(`GenerationToBilledUsageRatio,${csvField(bill && bill.generationToBilledUsageRatio)}`);
    lines.push("");

    // Section: intelligence (insights + recommendations).
    const intel = report.intelligence || {};
    lines.push("Insights");
    lines.push("Title,Message");
    for (const i of (intel.insights || [])) {
        lines.push(`${csvField(i.title)},${csvField(i.message)}`);
    }
    lines.push("");
    lines.push("Recommendations");
    lines.push("Title,Reason");
    for (const r of (intel.recommendations || [])) {
        lines.push(`${csvField(r.title)},${csvField(r.reason)}`);
    }
    lines.push("");

    return lines.join("\r\n");
}

// A safe download filename for a report range key.
function csvFilename(rangeKey) {
    const key = String(rangeKey || "report").replace(/[^a-zA-Z0-9_-]/g, "");
    return `spms-report-${key}.csv`;
}

module.exports = {
    composeReport,
    buildCsv,
    csvFilename,
    buildDailySeries,
};
