// Electricity-bill document upload service (gap closure).
// Handles file validation, storage, metadata, and replacement.
// Uses the existing documentStorage utility; no OCR, no binary in SQLite.

const path = require("path");
const multer = require("multer");
const billRepository = require("../repositories/electricityBillRepository");
const docStore = require("../utils/documentStorage");
const { createSolarError } = require("../utils/solarErrors");

// Ensure the storage directory exists on first load.
docStore.ensureStorageDir();

// Multer disk storage: server-side generated name, never user-supplied.
const storage = multer.diskStorage({
    destination(req, file, cb) {
        cb(null, docStore.dir());
    },
    filename(req, file, cb) {
        const name = docStore.storageNameFor(file);
        cb(null, name);
    },
});

// Multer file filter: extension + MIME validated.
function fileFilter(req, file, cb) {
    const result = docStore.validateUpload(file);
    if (!result.ok) {
        return cb(new Error(result.error));
    }
    cb(null, true);
}

const upload = multer({
    storage,
    fileFilter,
    limits: { fileSize: docStore.MAX_SIZE_BYTES },
});

// Upload a document for a bill (ownership-scoped).
// Returns the normalized document metadata.
async function uploadDocument(userId, billId, file) {
    if (!userId) {
        throw createSolarError("Authentication required.", "AUTHORIZATION_ERROR");
    }
    if (!file) {
        throw createSolarError("No file provided.", "VALIDATION_ERROR");
    }

    // Ownership check: bill must belong to the user.
    const bill = billRepository.findBillById({ userId, id: billId });
    if (!bill) {
        // Clean up the uploaded file (orphaned).
        docStore.deleteStoredDocument(file.filename);
        throw createSolarError("Bill not found.", "NOT_FOUND");
    }

    // If a document already exists, replace it safely: delete the old file
    // (whose name we generated, so it is safe to delete).
    if (bill.documentReference) {
        docStore.deleteStoredDocument(bill.documentReference);
    }

    // Update the bill with the safe reference.
    billRepository.updateBill({ userId, id: billId, fields: { document_reference: file.filename } });

    const validation = docStore.validateUpload(file);
    const type = validation.ok ? validation.type : "UNKNOWN";

    return {
        uploaded: true,
        type,
        size: file.size,
        reference: file.filename,
    };
}

function handleMulterError(err, req, res, next) {
    if (err instanceof multer.MulterError) {
        if (err.code === "LIMIT_FILE_SIZE") {
            return res.status(413).json({
                success: false,
                message: "File is too large. Maximum size is 10 MB.",
                code: "FILE_TOO_LARGE",
            });
        }
        return res.status(400).json({
            success: false,
            message: err.message,
            code: "UPLOAD_ERROR",
        });
    }
    if (err) {
        return res.status(400).json({
            success: false,
            message: err.message || "Upload failed.",
            code: "UPLOAD_ERROR",
        });
    }
    next();
}

module.exports = {
    upload,
    uploadDocument,
    handleMulterError,
};