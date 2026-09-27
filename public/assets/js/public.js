/*
 * SPMS public pages — presentation/interactivity only.
 * No business logic, no API calls, no data handling.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    root.classList.add('js');

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Theme control (with sun orbit transition) ---------- */
    var themeToggle = document.getElementById('theme-toggle');
    var orbit = document.querySelector('.theme-orbit');

    function currentTheme() {
        return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
        try {
            localStorage.setItem('spms-theme', theme);
        } catch (e) {
            /* storage unavailable — session-only theme */
        }
        if (themeToggle) {
            themeToggle.setAttribute(
                'aria-label',
                theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'
            );
        }
    }

    if (themeToggle) {
        applyTheme(currentTheme());
        themeToggle.addEventListener('click', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';

            if (!reduceMotion && orbit) {
                root.classList.add('is-theme-transition');
                if (orbit.querySelector('.theme-orbit-sun') === null) {
                    var sun = document.createElement('span');
                    sun.className = 'theme-orbit-sun';
                    orbit.appendChild(sun);
                }
                window.setTimeout(function () {
                    root.classList.remove('is-theme-transition');
                }, 650);
            }

            applyTheme(next);
        });
    }

    /* ---------- Mobile drawer ---------- */
    var navToggle = document.getElementById('nav-toggle');
    var drawerClose = document.getElementById('drawer-close');
    var drawer = document.getElementById('mobile-drawer');
    var backdrop = document.getElementById('drawer-backdrop');

    function openDrawer() {
        if (!drawer) return;
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        if (backdrop) backdrop.hidden = false;
        if (navToggle) navToggle.setAttribute('aria-expanded', 'true');
        document.body.classList.add('drawer-locked');
        if (drawerClose) drawerClose.focus();
    }

    function closeDrawer() {
        if (!drawer) return;
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        if (backdrop) backdrop.hidden = true;
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.focus();
        }
        document.body.classList.remove('drawer-locked');
    }

    if (navToggle) navToggle.addEventListener('click', openDrawer);
    if (drawerClose) drawerClose.addEventListener('click', closeDrawer);
    if (backdrop) backdrop.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drawer && drawer.classList.contains('is-open')) {
            closeDrawer();
        }
    });

    if (drawer) {
        drawer.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeDrawer);
        });
    }

    /* ---------- Section reveal (motion-aware) ---------- */
    if (!reduceMotion && 'IntersectionObserver' in window) {
        var targets = document.querySelectorAll('.section, .hero-copy, .hero-visual');
        targets.forEach(function (element) {
            element.classList.add('reveal');
        });

        var revealObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        targets.forEach(function (element) {
            revealObserver.observe(element);
        });
    }

    /* ---------- Illustrative analytics graph ----------
       Presentation-only demo chart. Draws once on scroll; hover shows
       an illustrative tooltip. No backend, no real data. */
    var graphCard = document.getElementById('graph-card');
    var graphSvg = document.getElementById('graph-svg');

    if (graphCard && graphSvg) {
        // Non-fatal guard for very old browsers without pathLength support.
        var supportsDraw = 'pathLength' in document.createElementNS('http://www.w3.org/2000/svg', 'path');

        if (reduceMotion || !supportsDraw || !('IntersectionObserver' in window)) {
            graphCard.classList.add('is-drawn');
        } else if ('IntersectionObserver' in window) {
            var graphObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        graphCard.classList.add('is-drawn');
                        graphObserver.disconnect();
                    }
                });
            }, { threshold: 0.3 });

            graphObserver.observe(graphCard);
        } else {
            graphCard.classList.add('is-drawn');
        }

        /* Hover tooltip — sampled illustrative series (kWh values are demo data) */
        var cursor = document.getElementById('graph-cursor');
        var tooltip = document.getElementById('graph-tooltip');
        var gtTime = document.getElementById('gt-time');
        var gtSolar = document.getElementById('gt-solar');
        var gtUsage = document.getElementById('gt-usage');
        var gtNature = document.getElementById('gt-nature');

        // Time labels 6:00–21:00; solar/usage series sampled from the drawn curves.
        var samples = [
            { x: 60,  t: '6:00 AM',  s: 0.2, u: 1.2 },
            { x: 106, t: '7:00 AM',  s: 0.6, u: 2.4 },
            { x: 152, t: '8:00 AM',  s: 1.8, u: 2.9 },
            { x: 198, t: '9:00 AM',  s: 4.2, u: 3.4 },
            { x: 244, t: '10:00 AM', s: 7.4, u: 2.2 },
            { x: 290, t: '11:00 AM', s: 11.0, u: 3.1 },
            { x: 336, t: '12:00 PM', s: 13.6, u: 3.6 },
            { x: 382, t: '1:00 PM',  s: 14.9, u: 4.6, fc: false },
            { x: 428, t: '2:00 PM',  s: 14.1, u: 4.1, fc: true },
            { x: 474, t: '3:00 PM',  s: 11.6, u: 5.2, fc: true },
            { x: 520, t: '4:00 PM',  s: 8.2,  u: 5.6, fc: true },
            { x: 566, t: '5:00 PM',  s: 4.6,  u: 6.4, fc: true },
            { x: 612, t: '6:00 PM',  s: 2.1,  u: 5.8, fc: true },
            { x: 658, t: '7:00 PM',  s: 0.7,  u: 4.9, fc: true },
            { x: 696, t: '8:00 PM',  s: 0.2,  u: 4.2, fc: true }
        ];

        var svgPoint = function (clientX) {
            var rect = graphSvg.getBoundingClientRect();
            var ratio = 720 / rect.width;
            return (clientX - rect.left) * ratio;
        };

        var nearestSample = function (x) {
            var best = samples[0];
            var bestDist = Infinity;
            samples.forEach(function (s) {
                var d = Math.abs(s.x - x);
                if (d < bestDist) { bestDist = d; best = s; }
            });
            return best;
        };

        var formatKwh = function (value) {
            return (Math.round(value * 10) / 10).toFixed(1) + ' kWh';
        };

        var showTooltip = function (clientX) {
            if (!tooltip) return;
            var x = svgPoint(clientX);
            if (x < 48 || x > 704) {
                hideTooltip();
                return;
            }

            var sample = nearestSample(x);
            if (gtTime) gtTime.textContent = sample.t;
            if (gtSolar) gtSolar.textContent = formatKwh(sample.s);
            if (gtUsage) gtUsage.textContent = formatKwh(sample.u);
            if (gtNature) {
                gtNature.textContent = (sample.fc ? 'PREDICTED · Illustrative' : 'ACTUAL · Illustrative');
            }

            if (cursor) {
                cursor.setAttribute('x1', String(sample.x));
                cursor.setAttribute('x2', String(sample.x));
                cursor.style.opacity = '1';
            }

            var rect = graphSvg.getBoundingClientRect();
            var px = (sample.x / 720) * rect.width;
            tooltip.classList.add('is-visible');
            var tooltipWidth = tooltip.offsetWidth || 160;
            var left = px - tooltipWidth / 2;
            left = Math.max(8, Math.min(left, rect.width - tooltipWidth - 8));
            tooltip.style.left = left + 'px';
            tooltip.style.top = '8px';
        };

        var hideTooltip = function () {
            if (tooltip) tooltip.classList.remove('is-visible');
            if (cursor) cursor.style.opacity = '0';
        };

        graphSvg.addEventListener('mousemove', showTooltip);
        graphSvg.addEventListener('mouseleave', hideTooltip);

        graphSvg.addEventListener('focus', showTooltip.bind(null, 336));
        graphSvg.addEventListener('blur', hideTooltip);
    }
})();
