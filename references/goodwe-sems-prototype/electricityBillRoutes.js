const express = require("express");

const router = express.Router();

const billController = require("../controllers/electricityBillController");
const { upload, handleMulterError } = require("../services/documentUploadService");

// All electricity-bill endpoints require an authenticated SPMS session.
// Ownership is enforced inside the service/repository (user_id scoped).
router.post("/", billController.create);
router.get("/", billController.list);
router.get("/summary", billController.summary);
router.get("/:id", billController.getOne);
router.put("/:id", billController.update);
router.delete("/:id", billController.remove);
router.get("/:id/comparison", billController.compare);

// Document upload: multipart "document" field. Strict size/type validation.
router.post(
    "/:id/document",
    upload.single("document"),
    billController.uploadDoc,
    handleMulterError
);

module.exports = router;