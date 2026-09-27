const express = require("express");
const cors = require("cors");

const config = require("./config/env");

const plantRoutes = require("./routes/plantRoutes");
const flowRoutes = require("./routes/flowRoutes");
const telemetryRoutes = require("./routes/telemetryRoutes");
const productionRoutes = require("./routes/productionRoutes");
const weatherRoutes = require("./routes/weatherRoutes");
const stationStatisticsRoutes = require("./routes/stationStatisticsRoutes");
const curveRoutes = require("./routes/curveRoutes");
const inverterRoutes = require("./routes/inverterRoutes");
const authRoutes = require("./routes/authRoutes");
const solarRoutes = require("./routes/solarRoutes");
const historicalSolarRoutes = require("./routes/historicalSolarRoutes");
const energyFlowRoutes = require("./routes/energyFlowRoutes");
const solarAnalyticsRoutes = require("./routes/solarAnalyticsRoutes");
const electricityBillRoutes = require("./routes/electricityBillRoutes");
const insightsRoutes = require("./routes/insightsRoutes");
const reportRoutes = require("./routes/reportRoutes");
const { requireAuth } = require("./middleware/authMiddleware");

const app = express();

// Restrictive CORS — never "*" for authenticated requests.
// Development frontend origin: http://localhost:3000.
app.use(
    cors({
        origin(origin, callback) {
            // Allow requests with no Origin (same-origin, curl, non-browser clients).
            if (!origin) return callback(null, true);
            if (config.cors.allowedOrigins.includes(origin)) {
                return callback(null, true);
            }
            return callback(new Error("Not allowed by CORS"));
        },
        credentials: true,
    })
);

app.use(express.json());

// API CONSOLE PROTECTION (Phase 2.2)
// The Developer → API Console frontend consumes these SEMS data routes.
// Per the Phase 2.0.1 architecture decision, the API Console requires
// authentication. Real frontend authentication is now integrated (the
// frontend sends the HttpOnly session cookie through the Vite proxy), so
// requireAuth is active here.
app.use("/api/plant", requireAuth, plantRoutes);
app.use("/api/flow", requireAuth, flowRoutes);
app.use("/api/telemetry", requireAuth, telemetryRoutes);
app.use("/api/production", requireAuth, productionRoutes);
app.use("/api/weather", requireAuth, weatherRoutes);
app.use("/api/station-statistics", requireAuth, stationStatisticsRoutes);
app.use("/api/curve", requireAuth, curveRoutes);
app.use("/api/inverters", requireAuth, inverterRoutes);
app.use("/api/auth", authRoutes);
app.use("/api/solar", requireAuth, solarRoutes);
app.use("/api/solar", requireAuth, historicalSolarRoutes);
app.use("/api/energy-flow", requireAuth, energyFlowRoutes);
app.use("/api/solar/analytics", requireAuth, solarAnalyticsRoutes);
app.use("/api/electricity-bills", requireAuth, electricityBillRoutes);
app.use("/api", requireAuth, insightsRoutes);
app.use("/api/reports", requireAuth, reportRoutes);

app.get("/", (req, res) => {
    res.json({
        message: "🚀 Welcome to SPMS Backend",
        version: "1.0.0",
        status: "Running",
    });
});

// Global error handler — never leak stack traces / SQL errors / paths.
// eslint-disable-next-line no-unused-vars
app.use((err, req, res, next) => {
    // Malformed JSON body (body-parser) → 400, not 500.
    if (err && err.type === "entity.parse.failed") {
        return res.status(400).json({
            success: false,
            message: "Malformed request body.",
            code: "INVALID_JSON",
        });
    }
    // CORS denial from the cors middleware.
    if (err && err.message === "Not allowed by CORS") {
        return res.status(403).json({
            success: false,
            message: "Origin not allowed.",
            code: "FORBIDDEN_ORIGIN",
        });
    }
    console.error(err);
    res.status(500).json({
        success: false,
        message: "Internal server error.",
        code: "INTERNAL_ERROR",
    });
});

module.exports = app;
