<?php

use App\Support\View;

/**
 * Public layout: header, content, footer.
 * $content is the rendered page partial; other variables come from $data.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($pageTitle ?? 'SPMS') ?></title>
    <meta name="description" content="<?= View::e($pageDescription ?? '') ?>">

    <link rel="icon" type="image/png" sizes="512x512" href="/assets/images/branding/favicon/favicon-512.png">

    <link rel="stylesheet" href="/assets/css/fonts.css">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/public.css">
    <link rel="stylesheet" href="/assets/css/landing.css">

    <script>
    (function () {
        try {
            var stored = localStorage.getItem('spms-theme');
            var theme = (stored === 'light' || stored === 'dark')
                ? stored
                : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'light');
        }
    })();
    </script>
</head>
<body class="public-page">
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <div class="atmosphere" aria-hidden="true">
        <div class="atmosphere-glow atmosphere-glow--top"></div>
        <div class="atmosphere-glow atmosphere-glow--bottom"></div>
    </div>

    <header class="public-header">
        <div class="container header-inner">
            <a class="brand" href="/" aria-label="SPMS home">
                <img
                    class="brand-logo"
                    src="/assets/images/branding/logos/logo-light.png"
                    data-logo-light="/assets/images/branding/logos/logo-light.png"
                    data-logo-dark="/assets/images/branding/logos/logo-dark.png"
                    alt="SPMS — Solar Power Management System"
                    width="117" height="45">
                <span class="brand-name">
                    <span class="brand-title">SOLAR POWER</span>
                    <span class="brand-sub">MANAGEMENT SYSTEM</span>
                </span>
            </a>

            <nav class="header-nav" aria-label="Primary">
                <a class="nav-link" href="/" data-active="<?= ($activeNav ?? '') === 'home' ? 'true' : 'false' ?>">Home</a>
                <a class="nav-link" href="/#how-it-works">How It Works</a>
                <a class="nav-link" href="/#features">Features</a>
                <a class="nav-link" href="/#analytics">Energy Analytics</a>
                <a class="nav-link" href="/#ai-recommendations">AI Recommendations</a>
                <a class="nav-link" href="/#bills">Bills</a>
                <a class="nav-link" href="/#about">About</a>
            </nav>

            <div class="header-actions">
                <span class="theme-orbit" aria-hidden="true">
                    <span class="theme-orbit-sun"></span>
                </span>
                <button class="icon-btn" id="theme-toggle" type="button" aria-label="Switch color theme" title="Switch color theme">
                    <svg class="icon icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"/>
                        <path d="M12 2v2"/><path d="M12 20v2"/>
                        <path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/>
                        <path d="M2 12h2"/><path d="M20 12h2"/>
                        <path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>
                    </svg>
                    <svg class="icon icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                    </svg>
                </button>

                <a class="btn btn-ghost" href="/login">Log in</a>
                <a class="btn btn-primary" href="/register">Get Started</a>

                <button class="icon-btn nav-toggle" id="nav-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="mobile-drawer">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <div class="drawer-backdrop" id="drawer-backdrop" hidden></div>
    <aside class="mobile-drawer" id="mobile-drawer" aria-label="Mobile navigation" aria-hidden="true">
        <div class="drawer-header">
            <span class="drawer-title">Menu</span>
            <button class="icon-btn" id="drawer-close" type="button" aria-label="Close menu">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
                </svg>
            </button>
        </div>
        <nav class="drawer-nav" aria-label="Mobile">
            <a class="drawer-link" href="/">Home</a>
            <a class="drawer-link" href="/#how-it-works">How It Works</a>
            <a class="drawer-link" href="/#features">Features</a>
            <a class="drawer-link" href="/#analytics">Energy Analytics</a>
            <a class="drawer-link" href="/#ai-recommendations">AI Recommendations</a>
            <a class="drawer-link" href="/#bills">Bills</a>
            <a class="drawer-link" href="/#about">About</a>
            <div class="drawer-separator" role="presentation"></div>
            <a class="drawer-link" href="/login">Log in</a>
            <a class="btn btn-primary drawer-cta" href="/register">Get Started</a>
        </nav>
    </aside>

    <main id="main-content">
        <?= $content ?? '' ?>
    </main>

    <footer class="public-footer">
        <div class="container footer-inner">
            <div class="footer-brand">
                <img class="footer-logo" src="/assets/images/branding/logos/logo-compact.png" alt="SPMS" width="66" height="22">
                <p class="footer-name">SPMS</p>
                <p class="footer-tagline">SOLAR POWER MANAGEMENT SYSTEM</p>
            </div>

            <nav class="footer-nav" aria-label="Footer">
                <a class="footer-link" href="/">Home</a>
                <a class="footer-link" href="/#features">Features</a>
                <a class="footer-link" href="/#how-it-works">How It Works</a>
                <a class="footer-link" href="/#analytics">Energy Analytics</a>
                <a class="footer-link" href="/#ai-recommendations">AI Recommendations</a>
                <a class="footer-link" href="/#bills">Bills</a>
                <a class="footer-link" href="/#about">About</a>
                <a class="footer-link" href="/privacy">Privacy</a>
                <a class="footer-link" href="/contact">Contact</a>
            </nav>

            <p class="footer-note">© 2026 SPMS. All rights reserved.</p>

            <p class="footer-note">SPMS is a household solar energy decision-support system. It does not control appliances and does not guarantee savings.</p>
        </div>
    </footer>

    <script src="/assets/js/public.js" defer></script>
</body>
</html>
