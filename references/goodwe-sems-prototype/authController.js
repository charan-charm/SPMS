// Auth controller — HTTP concerns only. No business logic.
// Converts service results/errors into HTTP responses.

const authService = require("../services/authService");
const { AppError } = require("../utils/errors");
const { readCookie } = require("../utils/cookies");

const SESSION_COOKIE_NAME = require("../config/env").session.name;

function cookieOptions() {
    const config = require("../config/env");
    return {
        httpOnly: config.session.httpOnly,
        sameSite: config.session.sameSite,
        secure: config.session.secure,
        maxAge: config.session.absoluteTtlMs,
        path: "/",
    };
}

async function register(req, res) {
    try {
        const result = await authService.register(req.body);
        res.cookie(SESSION_COOKIE_NAME, result.rawToken, cookieOptions());
        return res.status(201).json({ user: result.user });
    } catch (error) {
        return handleError(error, res);
    }
}

async function login(req, res) {
    try {
        const result = await authService.login(req.body);
        res.cookie(SESSION_COOKIE_NAME, result.rawToken, cookieOptions());
        return res.status(200).json({ user: result.user });
    } catch (error) {
        return handleError(error, res);
    }
}

function logout(req, res) {
    try {
        const rawToken = readCookie(req, SESSION_COOKIE_NAME);
        authService.logout(rawToken);
        res.clearCookie(SESSION_COOKIE_NAME, cookieOptions());
        return res.status(200).json({ success: true });
    } catch (error) {
        return handleError(error, res);
    }
}

function me(req, res) {
    try {
        const rawToken = readCookie(req, SESSION_COOKIE_NAME);
        const result = authService.getCurrentUser(rawToken);
        return res.status(200).json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

// POST /api/auth/change-password — requires an authenticated session.
// req.userId is set by requireAuth.
async function changePassword(req, res) {
    try {
        const result = await authService.changePassword(req.userId, req.body);
        return res.status(200).json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

// GET /api/auth/sessions — list the authenticated user's sessions.
function listSessions(req, res) {
    try {
        const sessions = authService.listSessions(req.userId, req.sessionId);
        return res.status(200).json({ sessions });
    } catch (error) {
        return handleError(error, res);
    }
}

// DELETE /api/auth/sessions/:id — revoke one of the user's own sessions.
function revokeSession(req, res) {
    try {
        const result = authService.revokeSession(req.userId, req.params.id);
        return res.status(200).json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

// POST /api/auth/sessions/logout-others — revoke all other sessions.
function revokeOtherSessions(req, res) {
    try {
        const result = authService.revokeOtherSessions(req.userId, req.sessionId);
        return res.status(200).json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

function handleError(error, res) {
    if (error instanceof AppError) {
        return res.status(error.statusCode).json(error.toJSON());
    }
    // Never leak stack traces / SQL errors to the client.
    console.error(error);
    return res.status(500).json({
        success: false,
        message: "Internal server error.",
        code: "INTERNAL_ERROR",
    });
}

module.exports = {
    register,
    login,
    logout,
    me,
    changePassword,
    listSessions,
    revokeSession,
    revokeOtherSessions,
};
