const express = require("express");

const router = express.Router();

const { getCurve } = require("../controllers/curveController");

router.get("/", getCurve);

module.exports = router;