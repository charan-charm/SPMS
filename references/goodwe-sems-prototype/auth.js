const crypto = require("crypto");
const axios = require("axios");

const LOGIN_URL =
  "https://semsplus.goodwe.com/web/sems/sems-user/api/v1/auth/cross-login";

function base64(value) {
    return Buffer.from(String(value), "utf8").toString("base64");
}

function md5Hex(value) {
    return crypto.createHash("md5")
        .update(String(value))
        .digest("hex");
}

function encodePassword(password) {
    return base64(md5Hex(password));
}

module.exports = {
    encodePassword,
};