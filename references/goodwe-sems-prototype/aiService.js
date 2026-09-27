// AIService (Phase 3.7) — AI interpretation orchestration.
//
// Responsibilities:
//   - Build a MINIMIZED prompt from normalized structured facts, deterministic
//     insights and recommendations (never raw SEMS data, never credentials).
//   - Call the provider-independent AI provider.
//   - Parse + validate the structured AI response.
//   - Throw sanitized errors so callers can fall back to deterministic
//     insights safely.
//
// AI never calculates or invents energy measurements — it only interprets
// verified facts. If AI is disabled or fails, the application keeps working on
// deterministic insights (handled by insightService).

const LIMITS = {
    summary: 600,
    observations: 8,
    observationLength: 280,
    recommendations: 8,
    limitations: 5,
};

function toTrimmedString(value, maxLength) {
    if (typeof value !== "string") return "";
    const trimmed = value.trim();
    if (trimmed.length <= maxLength) return trimmed;
    return `${trimmed.slice(0, maxLength - 1)}…`;
}

function toStringList(value, maxItems, maxLength) {
    if (!Array.isArray(value)) return [];
    return value
        .map((item) => toTrimmedString(item, maxLength))
        .filter(Boolean)
        .slice(0, maxItems);
}

// Validate + normalize the parsed AI response. Throws when the output is
// unusable so the caller can fall back to deterministic insights.
function validateAiResponse(parsed) {
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
        throw new Error("Malformed AI response: expected an object.");
    }

    const summary = toTrimmedString(parsed.summary, LIMITS.summary);
    const observations = toStringList(parsed.observations, LIMITS.observations, LIMITS.observationLength);
    const aiRecommendations = toStringList(parsed.recommendations, LIMITS.recommendations, LIMITS.observationLength);
    const limitations = toStringList(parsed.limitations, LIMITS.limitations, LIMITS.observationLength);

    if (!summary && observations.length === 0 && aiRecommendations.length === 0) {
        throw new Error("Malformed AI response: no usable content.");
    }

    return { summary, observations, recommendations: aiRecommendations, limitations };
}

// Build the minimized prompt payload. Only normalized facts leave SPMS — no
// SEMS credentials/tokens, no raw SEMS JSON, no auth cookies, no database
// credentials, no filesystem paths, no unnecessary user information.
function buildMinimizedPrompt({ facts, insights, recommendations }) {
    const caps = facts.capabilities || {};

    const factsSummary = {
        range: facts.range || null,
        capabilities: {
            generation: caps.generation || "UNAVAILABLE",
            consumption: caps.consumption || "UNAVAILABLE",
            grid: caps.grid || "UNAVAILABLE",
            battery: caps.battery || "UNAVAILABLE",
            energyBalance: caps.energyBalance || "INSUFFICIENT_DATA",
            excess: caps.excess || "NOT_DETERMINABLE",
        },
        generation: facts.generation
            ? {
                  total: facts.generation.total,
                  peakPower: facts.generation.peakPower,
                  avgPower: facts.generation.avgPower,
              }
            : null,
        generationTrend: facts.generationTrend || null,
        temperature: facts.temperature
            ? { average: facts.temperature.average, peak: facts.temperature.peak }
            : null,
        dataQuality: facts.dataQuality
            ? { completenessPercent: facts.dataQuality.completenessPercent, missing: facts.dataQuality.missing }
            : null,
        bill: facts.bill
            ? {
                  unitsConsumed: facts.bill.unitsConsumed,
                  billAmount: facts.bill.billAmount,
                  usageTrendPercent: facts.bill.usageTrendPercent,
                  solarGenerationDuringBillPeriod: facts.bill.solarGenerationDuringBillPeriod,
                  billCoveragePercent: facts.bill.billCoveragePercent,
              }
            : null,
    };

    const system = [
        "You are the SPMS solar monitoring assistant.",
        "You interpret VERIFIED structured facts only. You NEVER calculate or invent energy measurements.",
        "Bill consumption is billing-period context, not real-time household consumption.",
        "Potential excess is never claimed without required measurements.",
        "Do not invent causes (e.g. specific appliances).",
        "Reply ONLY with a JSON object shaped exactly like:",
        '{"summary": "...", "observations": ["..."], "recommendations": ["..."], "limitations": ["..."]}',
        "Keep it concise and technically credible.",
    ].join("\n");

    const user = JSON.stringify(
        {
            task: "Interpret the following verified SPMS facts and deterministic insights into plain, careful language.",
            facts: factsSummary,
            insights: (insights || []).map((i) => i.message),
            recommendations: (recommendations || []).map((r) => ({ title: r.title, reason: r.reason })),
        },
        null,
        2
    );

    return { system, user };
}

// Run the full AI interpretation. Returns a validated normalized object:
//   { summary, observations, recommendations, limitations }
// Throws on any provider/validation failure (caller falls back).
async function generateAiInterpretation({ facts, insights, recommendations }) {
    const { createAiProvider } = require("../providers/ai");
    const provider = createAiProvider();
    const prompt = buildMinimizedPrompt({ facts, insights, recommendations });

    const content = await provider.generate(prompt);
    let parsed;
    try {
        parsed = JSON.parse(content);
    } catch (_) {
        throw new Error("Malformed AI response: invalid JSON.");
    }

    return validateAiResponse(parsed);
}

module.exports = {
    generateAiInterpretation,
    buildMinimizedPrompt,
    validateAiResponse,
    LIMITS,
};
