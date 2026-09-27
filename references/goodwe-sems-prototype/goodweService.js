const path = require("path");

const { login } = require(path.join(
    __dirname,
    "../../../goodwe-client/sems-login"
));

const {
    buildClient,
    callNamedEndpoint,
} = require(path.join(
    __dirname,
    "../../../goodwe-client/sems-data"
));

async function executeEndpoint(endpointName, extraContext = {}) {

    const session = await login({
        account: process.env.SEMS_ACCOUNT,
        password: process.env.SEMS_PASSWORD,
        language: "en",
    });

    const client = buildClient(session);

    const context = {
        stationId: process.env.SEMS_STATION_ID,
        ...extraContext,
    };

    return await callNamedEndpoint(
        client,
        endpointName,
        context
    );
}

// ==========================
// Plant
// ==========================

async function getPlantInfo() {
    return executeEndpoint("basicInfo");
}

// ==========================
// Flow
// ==========================

async function getFlow() {
    return executeEndpoint("flow");
}

// ==========================
// Telemetry
// ==========================

async function getTelemetry() {
    return executeEndpoint("telemetry", {
        sn: process.env.SEMS_SN,
    });
}

async function getProduction() {
    return executeEndpoint("production");
}

async function getWeather() {
    return executeEndpoint("weatherInfo");
}

async function getStationStatistics() {
    return executeEndpoint("stationStatistic");
}

async function getCurve() {

    return executeEndpoint("timeSeriesData", {
        sn: process.env.SEMS_SN,
    });

}

async function getInverters() {
    return executeEndpoint("inverterList");
}

module.exports = {
    getPlantInfo,
    getFlow,
    getTelemetry,
    getProduction,
    getWeather,
    getStationStatistics,
    getCurve,
    getInverters,
};