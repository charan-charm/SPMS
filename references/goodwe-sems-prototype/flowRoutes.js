const express = require("express");

const router = express.Router();

const { getFlow } = require("../controllers/flowController");

router.get("/", getFlow);

module.exports = router;