// Authentication service — orchestration of registration, login, logout,
// session creation/validation, and current-user lookup.
// Business logic only; HTTP/cookie details live in the controller + middleware.

const crypto = require("crypto");
const bcrypt = require("bcrypt");

const config = require("../config/env");
const userRepository = require("../repositories/userRepository");
const sessionRepository = require("../repositories/sessionRepository");
const {
    ValidationError,
    DuplicateEmailError,
    InvalidCredentialsError,
    AccountInactiveError,
    UnauthorizedError,
    SessionExpiredError,
} = require("../utils/errors");
const { validateRegistration, validateLogin, validateChangePassword } = require("../validators/authValidator");

const BCRYPT_COST = config.bcryptCost;
const SHA256 = "sha256";

// ------------------------------------------------------------
// Session tokens
// ------------------------------------------------------------

// Cryptographically secure random token — never Math.random()/timestamps/IDs.
function generateSessionToken() {
    return crypto.randomBytes(32).toString("hex");
}

// Only the hash is persisted; raw token only ever travels in the httpOnly cookie.
function hashSessionToken(token) {
    return crypto.createHash(SHA256).update(token).digest("hex");
}

function nowIso() {
    return new Date().toISOString();
}

function absoluteExpiryIso() {
    return new Date(Date.now() + config.session.absoluteTtlMs).toISOString();
}

// ------------------------------------------------------------
// Registration
// ------------------------------------------------------------

async function register(body) {
    const { errors, data } = validateRegistration(body);
    if (errors) throw new ValidationError(errors);

    const email = data.email;

    // Duplicate email check before creating anything.
    const existing = userRepository.findByEmail(email);
    if (existing) throw new DuplicateEmailError();

    const passwordHash = await bcrypt.hash(data.password, BCRYPT_COST);

    const user = userRepository.createUser({
        fullName: data.fullName,
        email,
        passwordHash,
    });

    // Approved Phase 2.0.1 decision: registration auto-authenticates.
    const session = createSessionForUser(user.id);

    return {
        user: toSafeUser(user),
        session: toSafeSession(session),
        rawToken: session.rawToken,
    };
}

// ------------------------------------------------------------
// Login
// ------------------------------------------------------------

async function login(body) {
    const { errors, data } = validateLogin(body);
    if (errors) throw new ValidationError(errors);

    const user = userRepository.findByEmailWithHash(data.email);

    // Generic failure path — do not reveal whether email exists or password was wrong.
    if (!user) throw new InvalidCredentialsError();
    if (!user.is_active) throw new AccountInactiveError();

    const passwordMatch = await bcrypt.compare(data.password, user.password_hash);
    if (!passwordMatch) throw new InvalidCredentialsError();

    userRepository.updateLastLogin(user.id, nowIso());

    const session = createSessionForUser(user.id);

    return {
        user: toSafeUser(user),
        session: toSafeSession(session),
        rawToken: session.rawToken,
    };
}

// ------------------------------------------------------------
// Session creation / validation
// ------------------------------------------------------------

function createSessionForUser(userId) {
    const rawToken = generateSessionToken();
    const tokenHash = hashSessionToken(rawToken);

    const session = sessionRepository.createSession({
        userId,
        tokenHash,
        expiresAt: absoluteExpiryIso(),
    });

    return {
        id: session.id,
        userId: session.user_id,
        rawToken,
        expiresAt: session.expires_at,
    };
}

// Validate a presented raw token against the session store.
// Returns { sessionId, userId } or throws.
function validateSession(rawToken) {
    if (!rawToken || typeof rawToken !== "string") {
        throw new UnauthorizedError();
    }

    const tokenHash = hashSessionToken(rawToken);
    const session = sessionRepository.findByTokenHash(tokenHash);
    if (!session) throw new UnauthorizedError();

    const now = Date.now();
    const expiresAt = new Date(session.expires_at).getTime();
    const lastSeenAt = new Date(session.last_seen_at).getTime();

    // 24-hour absolute lifetime.
    if (now > expiresAt) {
        sessionRepository.deleteById(session.id);
        throw new SessionExpiredError();
    }

    // 30-minute idle timeout.
    if (now - lastSeenAt > config.session.idleTtlMs) {
        sessionRepository.deleteById(session.id);
        throw new SessionExpiredError();
    }

    // Update last_seen_at on successful use (respects absolute expiration).
    sessionRepository.touch(session.id, nowIso());

    return { sessionId: session.id, userId: session.user_id };
}

// Resolve the current user from a raw token (used by GET /auth/me).
function getCurrentUser(rawToken) {
    const { userId } = validateSession(rawToken);
    const user = userRepository.findById(userId);
    if (!user) throw new UnauthorizedError();
    return { user: toSafeUser(user) };
}

// ------------------------------------------------------------
// Change password
// ------------------------------------------------------------

async function changePassword(userId, body) {
    if (!userId) throw new UnauthorizedError();
    const { errors, data } = validateChangePassword(body);
    if (errors) throw new ValidationError(errors);

    const user = userRepository.findByIdWithHash(userId);
    if (!user) throw new UnauthorizedError();

    // Verify the current password with the same bcrypt path used at login.
    const passwordMatch = await bcrypt.compare(data.currentPassword, user.password_hash);
    if (!passwordMatch) {
        throw new ValidationError({ current_password: "Current password is incorrect." });
    }

    // Re-hash with the existing bcrypt configuration and update only this user.
    const passwordHash = await bcrypt.hash(data.newPassword, BCRYPT_COST);
    const updated = userRepository.updatePassword(userId, passwordHash);
    if (!updated) throw new UnauthorizedError();

    return { success: true };
}

// ------------------------------------------------------------
// Session management (Phase 3.9)
// ------------------------------------------------------------

// List the authenticated user's active sessions. Safe metadata only — the
// token_hash is never returned. The current session is flagged `current: true`.
function listSessions(userId, currentSessionId) {
    if (!userId) throw new UnauthorizedError();
    return sessionRepository.findSessionsByUserId(userId).map((s) => ({
        ...s,
        current: Number(currentSessionId) > 0 && s.id === Number(currentSessionId),
    }));
}

// Revoke one of the authenticated user's sessions (ownership enforced).
function revokeSession(userId, sessionId) {
    if (!userId) throw new UnauthorizedError();
    const id = Number(sessionId);
    if (!Number.isInteger(id) || id <= 0) {
        throw new ValidationError({ sessionId: "A valid session id is required." });
    }
    // Cross-user or unknown → no leak (false).
    const revoked = sessionRepository.deleteByIdForUser(id, userId);
    return { revoked };
}

// Revoke every session belonging to the user except the current one.
function revokeOtherSessions(userId, currentSessionId) {
    if (!userId) throw new UnauthorizedError();
    const removed = sessionRepository.deleteByUserIdExcept(userId, Number(currentSessionId) || -1);
    return { removed };
}

// ------------------------------------------------------------
// Logout
// ------------------------------------------------------------

// Logout is safe even without a valid session: it best-effort deletes the
// session row and always reports success.
function logout(rawToken) {
    if (rawToken && typeof rawToken === "string") {
        const tokenHash = hashSessionToken(rawToken);
        sessionRepository.deleteByTokenHash(tokenHash);
    }
    return { success: true };
}

// ------------------------------------------------------------
// Safe shapes (never expose hashes or raw tokens)
// ------------------------------------------------------------

function toSafeUser(user) {
    if (!user) return null;
    return {
        id: user.id,
        full_name: user.full_name,
        email: user.email,
        created_at: user.created_at,
        last_login: user.last_login,
        is_active: user.is_active,
    };
}

function toSafeSession(session) {
    return {
        expires_at: session.expiresAt,
    };
}

module.exports = {
    register,
    login,
    logout,
    validateSession,
    getCurrentUser,
    changePassword,
    listSessions,
    revokeSession,
    revokeOtherSessions,
    generateSessionToken,
    hashSessionToken,
};
