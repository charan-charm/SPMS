// Session retention cleanup (Phase 3.10).
//
// Removes expired session rows periodically so the sessions table does not
// grow without bound. Sessions are already rejected lazily on each request
// (authMiddleware validates expiry); this timer simply purges the stale rows.
//
// Safe by design:
//   - Runs once per hour (default), first run shortly after startup.
//   - Cleanup errors are caught and logged — they never crash the server.
//   - No ownership semantics change: deleteExpired only removes rows whose
//     expires_at has passed (user_id/ownership untouched).

const sessionRepository = require("../repositories/sessionRepository");

const DEFAULT_INTERVAL_MS = 60 * 60 * 1000; // 1 hour

class SessionCleanup {
    constructor(options = {}) {
        this.intervalMs = options.intervalMs ?? DEFAULT_INTERVAL_MS;
        this.repository = options.repository ?? sessionRepository;
        this.logger = options.logger ?? console;
        this.timer = null;
        this.started = false;
        this.lastRunAt = null;
        this.lastRemoved = 0;
    }

    // Run one cleanup pass now. Never throws.
    runOnce() {
        const now = new Date();
        try {
            const removed = this.repository.deleteExpired(now);
            this.lastRunAt = new Date().toISOString();
            this.lastRemoved = removed;
            this.log(`session cleanup: removed ${removed} expired session(s)`);
            return { removed };
        } catch (error) {
            this.log(`session cleanup failed (${error?.name || "Error"})`);
            return { removed: 0, error: error?.name || "Error" };
        }
    }

    start() {
        if (this.started) return false;
        this.started = true;
        this.runOnce();
        this.timer = setInterval(() => {
            this.runOnce();
        }, this.intervalMs);
        if (typeof this.timer.unref === "function") this.timer.unref();
        this.log(`session cleanup started (interval=${this.intervalMs}ms)`);
        return true;
    }

    stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
        this.started = false;
        this.log("session cleanup stopped");
    }

    log(message) {
        this.logger.log(`[session-cleanup] ${message}`);
    }
}

// Singleton used by the backend process (server.js). Tests create isolated
// instances with injected repository/logger.
const cleanup = new SessionCleanup();

function startSessionCleanup() {
    try {
        return { started: cleanup.start() };
    } catch (error) {
        cleanup.log(`session cleanup failed to start: ${error.message}`);
        return { started: false, error: error.message };
    }
}

function stopSessionCleanup() {
    cleanup.stop();
}

module.exports = {
    SessionCleanup,
    startSessionCleanup,
    stopSessionCleanup,
    DEFAULT_INTERVAL_MS,
};