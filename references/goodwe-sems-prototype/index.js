const auth = require("./auth");
const client = require("./client");

module.exports = {
    ...auth,
    ...client,
};