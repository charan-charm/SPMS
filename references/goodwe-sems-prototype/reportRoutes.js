const express = require("express");

const router = express.Router();

const reportController = require("../controllers/reportController");

// Report endpoints — all require an authenticated SPMS session
// (ownership is enforced inside the reused services; single-owner model).
router.get("/", reportController.getReport);
router.get("/export", reportController.exportCsv);

module.exports = router;