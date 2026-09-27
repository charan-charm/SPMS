// ReportController — HTTP concerns for the reports module (Phase 3.8).
// Delegates to ReportService; maps errors to existing SPMS conventions.
// Never leaks SQL errors, stack traces, or provider details.

const reportService = require("../services/reportService");
const { SolarError } = require("../utils/solarErrors");
const { InsightsValidationError } = require("../services/insightService");

function handleError(error, res) {
    if (error instanceof InsightsValidationError) {
        return res.status(error.statusCode).json(error.toJSON());
    }
    if (error instanceof SolarError) {
        console.error(`[reports] ${error.category}`);
        return res.status(error.httpStatus).json(error.toJSON());
    }
    console.error(`[reports] unexpected error (${error?.name || "Error"})`);
    return res.status(500).json({
        success: false,
        message: "Internal server error.",
        code: "INTERNAL_ERROR",
    });
}

async function getReport(req, res) {
    try {
        const result = await reportService.composeReport(req.userId, req.query, { includeAi: true });
        return res.json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

async function exportCsv(req, res) {
    try {
        const result = await reportService.composeReport(req.userId, req.query, { includeAi: false });
        const csv = reportService.buildCsv(result.report);
        const filename = reportService.csvFilename(result.report.range && result.report.range.rangeKey);
        res.setHeader("Content-Type", "text/csv;charset=utf-8");
        res.setHeader("Content-Disposition", `attachment; filename="${filename}"`);
        // BOM for UTF-8 in Excel (optional but harmless).
        res.send("\uFEFF" + csv);
    } catch (error) {
        return handleError(error, res);
    }
}

module.exports = { getReport, exportCsv };