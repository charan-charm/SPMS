// SolarService — orchestrates provider interaction for the SPMS backend.
//
// Responsibilities:
//   - Choose provider (mock vs real) based on configuration.
//   - Request data from the provider adapter.
//   - Expose normalized results.
//   - Expose connection status.
//   - Handle provider errors.
//
// Provider-specific logic is confined to the adapter layer. This service
// only works with normalized SPMS data shapes.

const { createProvider } = require("../providers");

let provider = null;

function getProvider() {
    if (!provider) {
        provider = createProvider();
    }
    return provider;
}

// Reset the provider (useful for tests or reconfiguration).
function resetProvider() {
    provider = null;
}

async function getStations() {
    const p = getProvider();
    return p.getPowerStations();
}

async function getInverters() {
    const p = getProvider();
    return p.getInverters();
}

async function getCurrentSnapshot() {
    const p = getProvider();
    return p.getCurrentSnapshot();
}

async function getEnergySummary(startDate, endDate) {
    const p = getProvider();
    return p.getEnergySummary(startDate, endDate);
}

async function getConnectionStatus() {
    const p = getProvider();
    return p.getConnectionStatus();
}

async function refresh() {
    // Force a fresh connection by re-initializing the provider.
    const p = createProvider();
    provider = p;
    const stations = await p.getPowerStations();
    const snapshot = await p.getCurrentSnapshot();
    return { stations, snapshot };
}

module.exports = {
    getProvider,
    resetProvider,
    getStations,
    getInverters,
    getCurrentSnapshot,
    getEnergySummary,
    getConnectionStatus,
    refresh,
};