// Solar Data Collector — backend-only historical measurement collection
// (Phase 3.3.1).
//
// Responsibilities:
//   - Trigger solar snapshot collection on a configured cadence
//   - Call SolarService (which owns provider selection + normalized snapshots)
//   - Determine measurement timestamp (UTC, bucket-aligned) + quality
//   - Persist via the historical repository (append-only, dedup via unique key)
//   - Handle failures without fabricating data or crashing the server
//   - Prevent overlapping collections
//   - Expose start/stop lifecycle for safe startup/shutdown
//
// The collector NEVER contains SEMS-specific logic — it consumes the same
// normalized interface as the real-time path (SolarService → adapter).

const config = require("../config/env");
const solarService = require("./solarService");
const solarMeasurementRepository = require("../repositories/solarMeasurementRepository");
const userRepository = require("../repositories/userRepository");
const { SolarError } = require("../utils/solarErrors");

const MIN_INTERVAL_SECONDS = config.solar.history.minIntervalSeconds;

// Quality model (Phase 3.3.0 §14 / Phase 3.3.1 §14).
// VALID   → snapshot received, core measurement (power) present (incl. 0)
// PARTIAL → snapshot received but some fields missing/null
// INVALID → malformed snapshot (missing identity) → NOT stored
// STALE   → snapshot is otherwise valid but provider data is older than the
//            collection threshold (Phase 3.10). Retains the row for analysis
//            but marks the data as potentially unreliable.
const QUALITY = { VALID: "VALID", PARTIAL: "PARTIAL", INVALID: "INVALID", STALE: "STALE" };

function isStaleTimestamp(snapshot, now, intervalMs) {
    const threshold = intervalMs * 2;
    if (!snapshot?.timestamp) return false;
    const ts = new Date(snapshot.timestamp).getTime();
    if (Number.isNaN(ts)) return false;
    return (now - ts) > threshold;
}

function toSafeNumber(value) {
    if (value === null || value === undefined || value === "") return null;
    const num = Number(value);
    return Number.isFinite(num) ? num : null;
}

// Bucket-aligned UTC timestamp: floor(utcMs / intervalMs) * intervalMs.
// Deterministic dedup window (Phase 3.3.0 §13).
function bucketTimestamp(timestampIso, intervalMs) {
    if (!timestampIso || typeof timestampIso !== "string") return null;
    const ms = new Date(timestampIso).getTime();
    if (Number.isNaN(ms)) return null;
    return new Date(Math.floor(ms / intervalMs) * intervalMs).toISOString();
}

// Map a normalized snapshot to a repository row (ownership already resolved).
function snapshotToRow(snapshot, { userId, intervalMs }) {
    const measurementTime = bucketTimestamp(snapshot.timestamp, intervalMs);
    if (!measurementTime) return null;

    return {
        userId,
        stationId: snapshot.powerStationId,
        inverterSn: snapshot.inverterId,
        measurementTime,
        serverReceivedAt: new Date().toISOString(),
        provider: snapshot.provider || "sems",
        power: toSafeNumber(snapshot.power),
        consumption: toSafeNumber(snapshot.consumption),
        gridPower: toSafeNumber(snapshot.gridPower),
        acPower: toSafeNumber(snapshot.acPower),
        pvPower: toSafeNumber(snapshot.pvPower),
        acVoltage: toSafeNumber(snapshot.acVoltage),
        acCurrent: toSafeNumber(snapshot.acCurrent),
        frequency: toSafeNumber(snapshot.frequency),
        powerFactor: toSafeNumber(snapshot.powerFactor),
        pvVoltage: toSafeNumber(snapshot.pvVoltage),
        pvCurrent: toSafeNumber(snapshot.pvCurrent),
        temperature: toSafeNumber(snapshot.temperature),
        dailyGeneration: toSafeNumber(snapshot.dailyGeneration),
        monthlyGeneration: toSafeNumber(snapshot.monthlyGeneration),
        totalGeneration: toSafeNumber(snapshot.totalGeneration),
        inverterStatus: snapshot.inverterStatus || null,
        connectionStatus: snapshot.connectionStatus || null,
    };
}

function determineQuality(snapshot) {
    if (!snapshot || typeof snapshot !== "object") return QUALITY.INVALID;
    if (!snapshot.powerStationId || !snapshot.inverterId || !snapshot.timestamp) {
        return QUALITY.INVALID;
    }
    // power === 0 is a valid nighttime measurement → VALID.
    return typeof snapshot.power === "number" ? QUALITY.VALID : QUALITY.PARTIAL;
}

class SolarCollector {
    constructor(options = {}) {
        this.intervalSeconds = options.intervalSeconds ?? config.solar.history.intervalSeconds;
        this.retentionDays = options.retentionDays ?? config.solar.history.retentionDays;
        this.cleanupIntervalMs = options.cleanupIntervalMs ?? config.solar.history.cleanupIntervalMs;
        this.service = options.service ?? solarService;
        this.repository = options.repository ?? solarMeasurementRepository;
        this.userRepository = options.userRepository ?? userRepository;
        this.logger = options.logger ?? console;

        this.running = false;   // true while a collection is in flight
        this.timer = null;
        this.cleanupTimer = null;
        this.lastCleanupAt = 0;
        this.lastCollectedAt = null;
        this.lastResult = null;
        this.started = false;
        this.consecutiveFailures = 0; // Phase 3.10 collector health
    }

    get intervalMs() {
        return this.intervalSeconds * 1000;
    }

    validateConfig() {
        if (!Number.isFinite(this.intervalSeconds) || this.intervalSeconds < MIN_INTERVAL_SECONDS) {
            throw new Error(
                `SOLAR_COLLECTION_INTERVAL_SECONDS must be at least ${MIN_INTERVAL_SECONDS} seconds (got ${this.intervalSeconds}).`
            );
        }
        if (!Number.isFinite(this.retentionDays) || this.retentionDays <= 0) {
            throw new Error("SOLAR_HISTORY_RETENTION_DAYS must be a positive number.");
        }
    }

    // Resolve the owning user deterministically (single-owner system):
    // configured station/inverter are owned by the SPMS user. The collector
    // never trusts frontend input. Falls back to the first active user.
    resolveOwnerUser() {
        return this.userRepository.findFirstActiveUser();
    }

    // One collection cycle. Returns a safe result object; never throws.
    async collectOnce() {
        if (this.running) {
            return { skipped: true, reason: "overlap" };
        }

        this.running = true;
        try {
            const owner = this.resolveOwnerUser();
            if (!owner) {
                this.log("collection skipped: no active owner user found");
                return { skipped: true, reason: "no-owner" };
            }

            const snapshot = await this.service.getCurrentSnapshot();

            const quality = determineQuality(snapshot);
            if (quality === QUALITY.INVALID) {
                this.log(`collection discarded: malformed snapshot (quality=${quality})`);
                return { collected: false, quality, reason: "invalid-snapshot" };
            }

            // Phase 3.10: stale detection — provider data older than 2× the
            // collection interval is marked STALE. The row is still stored so
            // analysis can account for the data gap, but the quality flag
            // signals that the measurement may be unreliable.
            const finalQuality = (quality !== QUALITY.INVALID && isStaleTimestamp(snapshot, Date.now(), this.intervalMs))
                ? QUALITY.STALE
                : quality;

            const row = snapshotToRow(snapshot, {
                userId: owner.id,
                intervalMs: this.intervalMs,
            });
            if (!row) {
                this.log("collection discarded: unparseable measurement timestamp");
                return { collected: false, quality, reason: "bad-timestamp" };
            }

            row.quality = finalQuality;
            const inserted = this.repository.insertMeasurement(row);

            this.lastCollectedAt = new Date().toISOString();
            this.lastResult = { collected: true, inserted: !!inserted, quality: finalQuality, provider: row.provider };
            this.consecutiveFailures = 0;
            return this.lastResult;
        } catch (error) {
            // Safe failure info only — category for SolarError, name otherwise.
            const category = error instanceof SolarError ? error.category : error?.name || "Error";
            this.log(`collection failed (${category})`);
            this.lastResult = { collected: false, error: category };
            this.consecutiveFailures += 1;
            return this.lastResult;
        } finally {
            this.running = false;
        }
    }

    // Periodic retention cleanup. Runs at most once per cleanupIntervalMs
    // (never on every insert). force = run regardless (e.g., at startup).
    maybeCleanup(force = false) {
        const now = Date.now();
        if (!force && now - this.lastCleanupAt < this.cleanupIntervalMs) {
            return { ran: false };
        }
        const cutoff = new Date(now - this.retentionDays * 24 * 60 * 60 * 1000).toISOString();
        try {
            const removed = this.repository.deleteExpired(cutoff);
            this.lastCleanupAt = now;
            this.log(`retention cleanup: removed ${removed} expired measurement(s)`);
            return { ran: true, removed };
        } catch (error) {
            this.log(`retention cleanup failed (${error?.name || "Error"})`);
            return { ran: false, error: error?.name || "Error" };
        }
    }

    // Start the collector: validate config, collect once immediately, then
    // schedule subsequent cycles. Cleanup runs once at startup, then at most
    // every cleanupIntervalMs.
    start() {
        if (this.started) return false;
        this.validateConfig();
        this.started = true;

        this.collectOnce();
        this.maybeCleanup(true);

        this.timer = setInterval(() => {
            this.collectOnce();
        }, this.intervalMs);
        if (typeof this.timer.unref === "function") this.timer.unref();

        // Cleanup is cheap and periodic — once per day is plenty.
        this.cleanupTimer = setInterval(() => {
            this.maybeCleanup(false);
        }, this.cleanupIntervalMs);
        if (typeof this.cleanupTimer.unref === "function") this.cleanupTimer.unref();

        this.log(
            `collector started (interval=${this.intervalSeconds}s, retention=${this.retentionDays}d)`
        );
        return true;
    }

    // Stop scheduling and cancel any timers. In-flight work is allowed to
    // finish safely (single insert, transactional).
    stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
        if (this.cleanupTimer) {
            clearInterval(this.cleanupTimer);
            this.cleanupTimer = null;
        }
        this.started = false;
        this.log("collector stopped");
    }

    log(message) {
        this.logger.log(`[solar-collector] ${message}`);
    }

    // Phase 3.10: operational health snapshot for the health endpoint.
    // Returns only safe, non-sensitive state — never provider errors/credentials.
    getHealth() {
        return {
            started: this.started,
            running: this.running,
            lastResult: this.lastResult,
            lastCollectedAt: this.lastCollectedAt,
            consecutiveFailures: this.consecutiveFailures,
            totalMeasurements: this.repository.countMeasurements(),
        };
    }
}

// Singleton used by the backend process (server.js). Tests create isolated
// instances with injected deps.
const collector = new SolarCollector();

function startCollector() {
    if (!config.solar.history.enabled) {
        collector.log("history disabled (SOLAR_HISTORY_ENABLED=false) — collector not started");
        return { started: false };
    }
    try {
        const started = collector.start();
        return { started };
    } catch (error) {
        collector.log(`collector failed to start: ${error.message}`);
        return { started: false, error: error.message };
    }
}

function stopCollector() {
    collector.stop();
}

// Phase 3.10: read the singleton collector's operational health.
// Safe for the health endpoint — returns only non-sensitive state.
function getCollectorHealth() {
    return collector.getHealth();
}

module.exports = {
    SolarCollector,
    startCollector,
    stopCollector,
    getCollectorHealth,
    bucketTimestamp,
    determineQuality,
    isStaleTimestamp,
    QUALITY,
};
