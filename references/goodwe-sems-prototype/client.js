const axios = require("axios");

function createSemsClient(session) {
    if (!session || !session.api) {
        throw new Error("Invalid session");
    }

    return axios.create({
        baseURL: session.api,
        timeout: 30000,
    });
}

module.exports = {
    createSemsClient,
};