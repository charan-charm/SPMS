const express = require("express");

const router = express.Router();

const { getProduction } = require("../controllers/productionController");

router.get("/", getProduction);

module.exports = router;