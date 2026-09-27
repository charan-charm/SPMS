// ElectricityBillController — HTTP concerns for /api/electricity-bills.
// Delegates to the service; maps errors to the established SPMS conventions.
// Never leaks SQL errors, stack traces, provider details, or document paths.

const billService = require("../services/electricityBillService");
const { uploadDocument, upload, handleMulterError } = require("../services/documentUploadService");
const { SolarError } = require("../utils/solarErrors");

function handleError(error, res) {
    if (error instanceof billService.BillValidationError) {
        return res.status(error.statusCode).json(error.toJSON());
    }
    if (error instanceof billService.BillNotFoundError) {
        return res.status(error.statusCode).json(error.toJSON());
    }
    if (error instanceof SolarError) {
        console.error(`[electricity-bills] ${error.category}`);
        return res.status(error.httpStatus).json(error.toJSON());
    }
    console.error(`[electricity-bills] unexpected error (${error?.name || "Error"})`);
    return res.status(500).json({
        success: false,
        message: "Internal server error.",
        code: "INTERNAL_ERROR",
    });
}

function parseId(value) {
    const num = Number(value);
    return Number.isInteger(num) && num > 0 ? num : null;
}

function create(req, res) {
    try {
        const bill = billService.createBill(req.userId, req.body);
        return res.status(201).json({ bill });
    } catch (error) {
        return handleError(error, res);
    }
}

function list(req, res) {
    try {
        const bills = billService.listBills(req.userId);
        return res.json({ bills });
    } catch (error) {
        return handleError(error, res);
    }
}

function getOne(req, res) {
    try {
        const id = parseId(req.params.id);
        if (!id) throw new billService.BillNotFoundError();
        const bill = billService.getBill(req.userId, id);
        return res.json({ bill });
    } catch (error) {
        return handleError(error, res);
    }
}

function update(req, res) {
    try {
        const id = parseId(req.params.id);
        if (!id) throw new billService.BillNotFoundError();
        const bill = billService.updateBill(req.userId, id, req.body);
        return res.json({ bill });
    } catch (error) {
        return handleError(error, res);
    }
}

function remove(req, res) {
    try {
        const id = parseId(req.params.id);
        if (!id) throw new billService.BillNotFoundError();
        const result = billService.deleteBill(req.userId, id);
        return res.json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

function summary(req, res) {
    try {
        const result = billService.getSummary(req.userId);
        return res.json({ summary: result });
    } catch (error) {
        return handleError(error, res);
    }
}

function compare(req, res) {
    try {
        const id = parseId(req.params.id);
        if (!id) throw new billService.BillNotFoundError();
        const result = billService.getComparison(req.userId, id);
        return res.json({ comparison: result });
    } catch (error) {
        return handleError(error, res);
    }
}

// POST /api/electricity-bills/:id/document — multer upload then ownership-scoped
// metadata update. A missing file → 400; missing bill → 404 (no existence leak).
async function uploadDoc(req, res) {
    const id = parseId(req.params.id);
    if (!id) {
        return handleError(new billService.BillNotFoundError(), res);
    }
    if (!req.file) {
        return res.status(400).json({
            success: false,
            message: "No file provided.",
            code: "UPLOAD_ERROR",
        });
    }
    try {
        const document = await uploadDocument(req.userId, id, req.file);
        return res.json({ document });
    } catch (error) {
        return handleError(error, res);
    }
}

module.exports = { create, list, getOne, update, remove, summary, compare, uploadDoc, handleMulterError };