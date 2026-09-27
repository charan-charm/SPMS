<?php

use App\Support\View;
?>
<section class="section placeholder-section" aria-labelledby="privacy-heading">
    <div class="container">
        <div class="placeholder-panel placeholder-panel--wide">
            <span class="placeholder-icon" aria-hidden="true">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
            </span>

            <h1 class="placeholder-title" id="privacy-heading">Privacy commitment</h1>

            <div class="privacy-body">
                <p>
                    SPMS treats household energy information as sensitive. The system is
                    designed to collect only the information required to provide its
                    capabilities, to keep credentials and secrets server-side, and to
                    give users control over presentation, communication, and
                    personalization.
                </p>
                <p>
                    A complete privacy policy will be published as the product matures.
                    In summary, the current design commitments are:
                </p>
                <ul>
                    <li>Household energy data is minimised, protected, and controlled throughout its lifecycle.</li>
                    <li>Credentials and secrets are never exposed to the frontend.</li>
                    <li>AI services receive only the minimum validated information needed for their task.</li>
                    <li>Accounts support a controlled deletion lifecycle.</li>
                </ul>
            </div>

            <a class="btn btn-primary" href="/">Back to the home page</a>
        </div>
    </div>
</section>
