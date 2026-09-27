<?php

declare(strict_types=1);

namespace Tests\Workflow;

use App\Support\View;
use Tests\Support\Assert;
use Tests\TestResult;

/**
 * M1.0 (redesign) public page rendering checks (server-side, no HTTP).
 * Verifies structure, all redesigned sections, illustrative markings,
 * data-trust language, branding, theme, accessibility and responsiveness.
 */
final class PublicPagesTest
{
    private static ?string $landing = null;
    private static ?string $layout = null;
    private static ?string $placeholder = null;

    private static function landing(): string
    {
        if (self::$landing === null) {
            self::$landing = View::render('layouts/public', 'public/landing', [
                'pageTitle' => 'SPMS — Solar Power Management System',
                'pageDescription' => 'test description',
                'activeNav' => 'home',
            ]);
        }
        return self::$landing;
    }

    private static function layout(): string
    {
        if (self::$layout === null) {
            self::$layout = self::landing();
        }
        return self::$layout;
    }

    private static function placeholder(): string
    {
        if (self::$placeholder === null) {
            self::$placeholder = View::render('layouts/public', 'public/auth-placeholder', [
                'pageTitle' => 'Test — SPMS',
                'placeholderIcon' => 'log-in',
                'placeholderTitle' => 'Placeholder title <script>alert(1)</script>',
                'placeholderMessage' => 'message',
                'placeholderActionLabel' => 'back',
            ]);
        }
        return self::$placeholder;
    }

    public static function run(): array
    {
        return [
            TestResult::check('Landing renders with semantic structure and all sections', function (): void {
                $html = self::landing();

                Assert::contains('<main id="main-content">', $html);
                Assert::contains('<h1', $html);
                Assert::contains('<footer class="public-footer"', $html);

                foreach ([
                    'id="how-it-works"', 'id="bills"', 'id="analytics"',
                    'id="ai-recommendations"', 'id="energy-balance"', 'id="cost"',
                    'id="features"', 'id="data-trust"', 'id="personalization"',
                    'id="about"', 'id="final-cta-heading"',
                ] as $id) {
                    Assert::contains($id, $html, "missing section {$id}");
                }
            }),

            TestResult::check('Hero highlights "right time" and shows both CTAs plus concepts', function (): void {
                $html = self::landing();

                Assert::contains('Use your solar energy at the <span class="hero-hl">right time.</span>', $html);
                Assert::contains('Get Started', $html);
                Assert::contains('/register', $html);
                Assert::contains('/login', $html);
                Assert::contains('Understand', $html);
                Assert::contains('Predict', $html);
                Assert::contains('Optimize', $html);
            }),

            TestResult::check('Hero floating cards show illustrative values only', function (): void {
                $html = self::landing();

                Assert::contains('12.4 kWh', $html);
                Assert::contains('8.1 kWh', $html);
                Assert::contains('Potential surplus', $html);
                Assert::true(substr_count($html, 'Illustrative') >= 8, 'expected repeated illustrative markings');
            }),

            TestResult::check('Bill section shows pipeline, verification and honest marking', function (): void {
                $html = self::landing();

                Assert::contains('Illustrative bill processing', $html);
                foreach (['Upload', 'Process', 'Extract', 'Understand', 'Verify', 'Store'] as $step) {
                    Assert::contains($step, $html, "missing pipeline step {$step}");
                }
                foreach (['Billing period', 'Electricity usage', 'Amount', 'Tariff', 'Fixed charges'] as $field) {
                    Assert::contains($field, $html, "missing extracted field {$field}");
                }
                Assert::contains('verify', strtolower($html));
            }),

            TestResult::check('Analytics graph uses SPMS semantic chart language', function (): void {
                $html = self::landing();

                Assert::contains('graph-line--solar-h', $html);
                Assert::contains('graph-line--solar-fc', $html);
                Assert::contains('pathLength="1"', $html);
                Assert::contains('FORECAST', $html);
                Assert::contains('Solar generation — Actual', $html);
                Assert::contains('Solar forecast — Predicted', $html);
                Assert::contains('Confidence band', $html);
                Assert::contains('graph-tooltip', $html);
                Assert::contains('not real household data', $html);
            }),

            TestResult::check('AI section is a separate major section with impact/confidence badges', function (): void {
                $html = self::landing();

                Assert::contains('Your personal solar assistant.', $html);
                Assert::contains('High impact', $html);
                Assert::contains('Medium impact', $html);
                Assert::contains('Informational', $html);
                Assert::contains('High confidence', $html);
                Assert::contains('Moderate confidence', $html);
                Assert::contains('Understand', $html);
            }),

            TestResult::check('AI explanation card separates validated facts from AI wording', function (): void {
                $html = self::landing();

                Assert::contains('Why this recommendation?', $html);
                foreach (['Solar forecast', 'Household demand', 'Potential energy situation', 'Confidence', 'Data quality'] as $fact) {
                    Assert::contains($fact, $html, "missing explanation fact {$fact}");
                }
                Assert::contains('never invents numerical predictions', $html);
            }),

            TestResult::check('Energy balance uses POTENTIAL wording and disclaims grid flow', function (): void {
                $html = self::landing();

                Assert::contains('Potential surplus', $html);
                Assert::contains('Balanced', $html);
                Assert::contains('Potential deficit', $html);
                Assert::contains('never claims confirmed', strtolower($html));
            }),

            TestResult::check('Cost section separates verified history from estimated future', function (): void {
                $html = self::landing();

                Assert::contains('Historical · Verified', $html);
                Assert::contains('Future · Estimated', $html);
                Assert::contains('Fixed charges are kept separate', $html);
                Assert::contains('does not promise exact savings', strtolower($html));
            }),

            TestResult::check('Data trust section distinguishes all five data natures', function (): void {
                $html = self::landing();

                foreach (['Actual', 'Predicted', 'Estimated', 'Stale', 'Simulated'] as $nature) {
                    Assert::contains($nature, $html, "missing data nature {$nature}");
                }
                Assert::contains('trust-line--predicted', $html);
                Assert::contains('Colour is never the only signal', $html);
            }),

            TestResult::check('Personalization flow keeps feedback away from analytical truth', function (): void {
                $html = self::landing();

                Assert::contains('Useful / Not useful', $html);
                Assert::contains('never rewrites objective analytical truth', strtolower($html));
            }),

            TestResult::check('Header is public-only and links all product sections', function (): void {
                $html = self::layout();

                foreach (['/#how-it-works', '/#features', '/#analytics', '/#ai-recommendations', '/#bills', '/#about'] as $nav) {
                    Assert::contains('href="' . $nav . '"', $html, "missing nav link {$nav}");
                }

                foreach (['Dashboard', '/solar"', '/energy"', '/forecasts"', '/analytics"', '/bills"', '/reports"', '/notifications"', '/settings"'] as $forbidden) {
                    Assert::notContains($forbidden, $html, "authenticated nav leaked: {$forbidden}");
                }
            }),

            TestResult::check('Official wording, footer links and 2026 copyright present', function (): void {
                $html = self::layout();

                Assert::contains('SOLAR POWER MANAGEMENT SYSTEM', $html);
                Assert::contains('© 2026 SPMS. All rights reserved.', $html);
                Assert::contains('href="/privacy"', $html);
                Assert::contains('href="/contact"', $html);
            }),

            TestResult::check('No forbidden claims anywhere on the page', function (): void {
                $html = strtolower(self::landing());

                foreach (['guaranteed savings', 'guaranteed prediction', '100% accurate', 'eliminate your bill', 'zero grid dependence'] as $claim) {
                    Assert::notContains($claim, $html, "forbidden claim: {$claim}");
                }
            }),

            TestResult::check('Branding uses finalized logo assets and wordmark', function (): void {
                $html = self::layout();

                Assert::contains('/assets/images/branding/logos/logo-light.png', $html);
                Assert::contains('/assets/images/branding/logos/logo-dark.png', $html);

                foreach (['logo-light.png', 'logo-dark.png', 'logo-compact.png'] as $asset) {
                    Assert::true(
                        is_file(dirname(__DIR__, 2) . '/public/assets/images/branding/logos/' . $asset),
                        "missing logo asset {$asset}"
                    );
                }
            }),

            TestResult::check('Theme infrastructure present (data-theme, orbit, dark tokens)', function (): void {
                $html = self::layout();
                $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/tokens.css');
                $landingCss = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/landing.css');

                Assert::contains('data-theme', $html);
                Assert::contains('id="theme-toggle"', $html);
                Assert::contains('theme-orbit', $html);
                Assert::contains('prefers-color-scheme: dark', $html);
                Assert::contains("[data-theme='dark']", $css);
                Assert::contains('#22C55E', $css);
                Assert::contains('#34D399', $css);
                Assert::contains('#064E3B', $css);
                Assert::contains('is-theme-transition', $landingCss);
            }),

            TestResult::check('Graph draw animation is scroll-triggered and reduced-motion safe', function (): void {
                $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/public.js');
                $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/landing.css');

                Assert::contains('IntersectionObserver', $js);
                Assert::contains('prefers-reduced-motion', $js);
                Assert::contains('drawLine', $css);
                Assert::contains('prefers-reduced-motion', $css);
            }),

            TestResult::check('Responsive rules present (drawer, stacking breakpoints)', function (): void {
                $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/public.css');

                Assert::contains('@media (max-width: 1024px)', $css);
                Assert::contains('@media (max-width: 720px)', $css);
                Assert::contains('.mobile-drawer', $css);
            }),

            TestResult::check('Views escape dynamic values (XSS safety)', function (): void {
                $html = self::placeholder();

                Assert::notContains('<script>alert(1)</script>', $html);
                Assert::contains('&lt;script&gt;', $html);
            }),
        ];
    }
}
