<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\HtmlResponse;
use App\Support\Request;
use App\Support\View;

/**
 * Public-facing web page controller.
 *
 * Renders server-side HTML views. Contains no business logic and
 * performs no database access — public pages are presentation only.
 */
final class PageController
{
    public function landing(Request $request): HtmlResponse
    {
        return $this->page('public/landing', [
            'pageTitle' => 'SPMS — Solar Power Management System',
            'pageDescription' => 'SPMS helps households understand solar generation, household energy consumption, and the future energy outlook so they can make better use of the energy their solar system produces.',
            'activeNav' => 'home',
        ]);
    }

    public function login(Request $request): HtmlResponse
    {
        return $this->page('public/auth-placeholder', [
            'pageTitle' => 'Login — SPMS',
            'pageDescription' => 'SPMS account login is not available yet.',
            'activeNav' => 'login',
            'placeholderIcon' => 'log-in',
            'placeholderTitle' => 'Login is coming soon',
            'placeholderMessage' => 'SPMS accounts are not available yet. Account login is part of the next implementation milestone, so this page is a placeholder rather than a working sign-in form.',
            'placeholderActionLabel' => 'Back to the home page',
        ]);
    }

    public function register(Request $request): HtmlResponse
    {
        return $this->page('public/auth-placeholder', [
            'pageTitle' => 'Get Started — SPMS',
            'pageDescription' => 'SPMS account registration is not available yet.',
            'activeNav' => 'register',
            'placeholderIcon' => 'user-plus',
            'placeholderTitle' => 'Registration is coming soon',
            'placeholderMessage' => 'SPMS accounts are not available yet. Account registration is part of the next implementation milestone, so this page is a placeholder rather than a working sign-up form.',
            'placeholderActionLabel' => 'Back to the home page',
        ]);
    }

    public function privacy(Request $request): HtmlResponse
    {
        return $this->page('public/privacy', [
            'pageTitle' => 'Privacy — SPMS',
            'pageDescription' => 'How SPMS treats household energy information.',
            'activeNav' => 'privacy',
        ]);
    }

    public function contact(Request $request): HtmlResponse
    {
        return $this->page('public/contact', [
            'pageTitle' => 'Contact — SPMS',
            'pageDescription' => 'How to reach the SPMS project.',
            'activeNav' => 'contact',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(string $template, array $data): HtmlResponse
    {
        return HtmlResponse::html(
            View::render('layouts/public', $template, $data)
        );
    }
}
