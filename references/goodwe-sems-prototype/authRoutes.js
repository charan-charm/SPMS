const express = require("express");

const router = express.Router();

const authController = require("../controllers/authController");
const { requireAuth } = require("../middleware/authMiddleware");
const { csrfProtection } = require("../middleware/csrfMiddleware");
const { loginLimiter, registerLimiter } = require("../middleware/rateLimitMiddleware");

// Public endpoints (rate-limited + CSRF-protected for state changes).
router.post("/register", csrfProtection, registerLimiter, authController.register);
router.post("/login", csrfProtection, loginLimiter, authController.login);

// Session-aware endpoints (safe without a session, still CSRF-protected).
router.post("/logout", csrfProtection, authController.logout);

// Returns the current authenticated user (401 when unauthenticated).
router.get("/me", authController.me);

// Authenticated account-management endpoints (Phase 3.9).
router.post("/change-password", requireAuth, csrfProtection, authController.changePassword);
router.get("/sessions", requireAuth, authController.listSessions);
router.delete("/sessions/:id", requireAuth, csrfProtection, authController.revokeSession);
router.post("/sessions/logout-others", requireAuth, csrfProtection, authController.revokeOtherSessions);

module.exports = router;
