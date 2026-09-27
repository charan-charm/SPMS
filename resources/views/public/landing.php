<?php

use App\Support\View;

/**
 * Public landing page — high-fidelity product presentation.
 * Presentation only: no data, no API calls, no business logic.
 * Every numeric value on this page is explicitly marked illustrative.
 */
?>

<!-- 1. Hero -->
<section class="hero" aria-labelledby="hero-heading">
    <div class="container hero-inner">
        <div class="hero-copy">
            <p class="eyebrow">Solar power management for households</p>

            <h1 id="hero-heading" class="hero-heading">
                Use your solar energy at the <span class="hero-hl">right time.</span>
            </h1>

            <p class="hero-lead">
                SPMS helps households understand their solar generation, electricity
                consumption, future energy situations, and opportunities to use
                solar energy more effectively.
            </p>

            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="/register">
                    Get Started
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
                <a class="btn btn-outline btn-lg" href="/login">Log in</a>
            </div>

            <ul class="hero-concepts" aria-label="What SPMS does">
                <li class="hero-concept">
                    <span class="hero-concept-icon" aria-hidden="true">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="1"/><path d="m20.2 20.2-4.2-4.2"/><path d="m9.5 9.5-4.3-4.3"/><path d="M7 12H3"/><path d="M21 12h-4"/></svg>
                    </span>
                    <span class="hero-concept-label">Understand</span>
                    <span class="hero-concept-sub">Your energy</span>
                </li>
                <li class="hero-concept">
                    <span class="hero-concept-icon" aria-hidden="true">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    </span>
                    <span class="hero-concept-label">Predict</span>
                    <span class="hero-concept-sub">What's ahead</span>
                </li>
                <li class="hero-concept">
                    <span class="hero-concept-icon" aria-hidden="true">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    </span>
                    <span class="hero-concept-label">Optimize</span>
                    <span class="hero-concept-sub">Your usage</span>
                </li>
            </ul>
        </div>

        <div class="hero-visual" aria-hidden="true">
            <svg class="scene" viewBox="0 0 520 360" role="presentation" focusable="false">
                <defs>
                    <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#DBEAFE" stop-opacity="0.55"/>
                        <stop offset="100%" stop-color="#F9FAFB" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <rect class="scene-sky" x="0" y="0" width="520" height="360" fill="url(#sky)"/>
                <circle class="scene-sun-glow" cx="398" cy="86" r="66"/>
                <circle class="scene-sun" cx="398" cy="86" r="32"/>
                <path class="scene-hill scene-hill--far" d="M0 292 Q140 252 270 280 T520 272 L520 360 L0 360 Z"/>
                <path class="scene-hill scene-hill--near" d="M0 314 Q180 284 330 304 T520 298 L520 360 L0 360 Z"/>
                <path class="scene-ground" d="M0 336 Q260 322 520 332 L520 360 L0 360 Z"/>
                <rect class="scene-wall" x="160" y="222" width="160" height="82" rx="3"/>
                <polygon class="scene-roof" points="132,226 240,148 348,226"/>
                <g class="scene-panels" transform="translate(243,159) rotate(35.4)">
                    <rect x="0" y="0" width="27" height="19" rx="2"/>
                    <rect x="31" y="0" width="27" height="19" rx="2"/>
                    <rect x="62" y="0" width="27" height="19" rx="2"/>
                </g>
                <rect class="scene-window" x="180" y="242" width="30" height="26" rx="2"/>
                <rect class="scene-window" x="270" y="242" width="30" height="26" rx="2"/>
                <rect class="scene-door" x="222" y="252" width="28" height="52" rx="2"/>
                <path class="scene-flow" d="M285 182 C 292 214, 272 232, 262 252"/>
                <circle class="scene-flow-dot scene-flow-dot--a" r="4"/>
                <circle class="scene-flow-dot scene-flow-dot--b" r="4"/>
            </svg>

            <div class="float-card fc-solar">
                <span class="fc-icon fc-icon--solar">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                </span>
                <span class="fc-body">
                    <span class="fc-label">Solar generation</span>
                    <span class="fc-value">12.4 kWh</span>
                    <span class="fc-meta"><span class="fc-nature">ACTUAL</span> · Illustrative</span>
                </span>
            </div>

            <div class="float-card fc-usage">
                <span class="fc-icon fc-icon--usage">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </span>
                <span class="fc-body">
                    <span class="fc-label">Household usage</span>
                    <span class="fc-value">8.1 kWh</span>
                    <span class="fc-meta"><span class="fc-nature">ACTUAL</span> · Illustrative</span>
                </span>
            </div>

            <div class="float-card fc-outlook">
                <span class="fc-icon fc-icon--outlook">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                </span>
                <span class="fc-body">
                    <span class="fc-label">Energy outlook</span>
                    <span class="fc-value">Potential surplus</span>
                    <span class="fc-meta"><span class="fc-nature">PREDICTED</span> · Illustrative</span>
                </span>
            </div>

            <div class="float-pill fc-opportunity">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                Opportunity: shift flexible usage to midday · Illustrative
            </div>
        </div>
    </div>
</section>

<!-- 2. How SPMS Works -->
<section class="section section--alt" id="how-it-works" aria-labelledby="how-heading">
    <div class="container">
        <header class="section-header">
            <h2 class="section-heading" id="how-heading">From data to meaningful decisions.</h2>
            <p class="section-lead">
                SPMS builds its understanding step by step, from the information
                your household already has.
            </p>
        </header>

        <div class="workflow" role="list">
            <div class="workflow-line" aria-hidden="true"><span class="workflow-line-fill"></span></div>

            <article class="step" role="listitem">
                <p class="step-number">01</p>
                <h3 class="step-title">Connect</h3>
                <p class="step-text">Bring together the information your household already has.</p>
                <ul class="step-chips">
                    <li>Solar</li>
                    <li>Household</li>
                    <li>Electricity bills</li>
                    <li>Environment</li>
                </ul>
            </article>
            <article class="step" role="listitem">
                <p class="step-number">02</p>
                <h3 class="step-title">Understand</h3>
                <p class="step-text">Build a progressively clearer profile of your household energy behaviour.</p>
            </article>
            <article class="step" role="listitem">
                <p class="step-number">03</p>
                <h3 class="step-title">Predict</h3>
                <p class="step-text">Estimate future solar availability and household electricity consumption.</p>
            </article>
            <article class="step" role="listitem">
                <p class="step-number">04</p>
                <h3 class="step-title">Evaluate</h3>
                <p class="step-text">Compare expected solar availability with expected household demand.</p>
            </article>
            <article class="step" role="listitem">
                <p class="step-number">05</p>
                <h3 class="step-title">Recommend</h3>
                <p class="step-text">Identify meaningful opportunities and actions, with confidence shown.</p>
            </article>
        </div>
    </div>
</section>

<!-- 3. Electricity Bills -->
<section class="section" id="bills" aria-labelledby="bills-heading">
    <div class="container">
        <div class="split">
            <div class="split-copy">
                <h2 class="section-heading" id="bills-heading">Easily add your home electricity bills.</h2>
                <p class="section-lead split-lead">
                    Upload your electricity bill and let SPMS extract, understand,
                    verify, and organize your household energy information.
                </p>

                <ol class="pipe" aria-label="Bill processing pipeline">
                    <li class="pipe-step">Upload</li>
                    <li class="pipe-arrow" aria-hidden="true">→</li>
                    <li class="pipe-step">Process</li>
                    <li class="pipe-arrow" aria-hidden="true">→</li>
                    <li class="pipe-step">Extract</li>
                    <li class="pipe-arrow" aria-hidden="true">→</li>
                    <li class="pipe-step">Understand</li>
                    <li class="pipe-arrow" aria-hidden="true">→</li>
                    <li class="pipe-step">Verify</li>
                    <li class="pipe-arrow" aria-hidden="true">→</li>
                    <li class="pipe-step">Store</li>
                </ol>

                <p class="bill-note">
                    SPMS always shows you the extracted values first. Information becomes
                    trusted household data only after you verify it — and manual entry
                    always remains available.
                </p>

                <a class="btn btn-primary" href="/register">
                    Add your electricity bill
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
            </div>

            <div class="split-visual">
                <div class="card bill-card">
                    <div class="bill-badges">
                        <span class="badge badge-illustrative">Illustrative bill processing</span>
                    </div>

                    <div class="bill-doc" aria-hidden="true">
                        <div class="bill-doc-head">
                            <span class="bill-doc-lines bill-doc-lines--w1"></span>
                            <span class="bill-doc-chip">PDF</span>
                        </div>
                        <span class="bill-doc-lines bill-doc-lines--w2"></span>
                        <span class="bill-doc-lines bill-doc-lines--w3"></span>
                        <span class="bill-doc-lines bill-doc-lines--w4"></span>
                        <span class="bill-doc-lines bill-doc-lines--w5"></span>
                    </div>

                    <div class="dropzone" aria-hidden="true">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 8 5-5 5 5"/><path d="M5 21h14"/></svg>
                        <p class="dropzone-title">Drop your bill here</p>
                        <p class="dropzone-sub">PDF · JPG · PNG</p>
                    </div>

                    <div class="extract" role="group" aria-label="Extracted bill information, illustrative">
                        <p class="extract-title">Extracted information <span class="badge badge-illustrative">Illustrative</span></p>
                        <dl class="extract-fields">
                            <div class="extract-row"><dt>Billing period</dt><dd>Monthly cycle</dd></div>
                            <div class="extract-row"><dt>Electricity usage</dt><dd>12.4 kWh</dd></div>
                            <div class="extract-row"><dt>Amount</dt><dd>₹2,437</dd></div>
                            <div class="extract-row"><dt>Tariff</dt><dd>Example slab tariff</dd></div>
                            <div class="extract-row"><dt>Fixed charges</dt><dd>₹120</dd></div>
                        </dl>
                        <p class="extract-foot">Example values shown for presentation only.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 4. Energy Analytics -->
<section class="section section--alt" id="analytics" aria-labelledby="analytics-heading">
    <div class="container">
        <header class="section-header">
            <h2 class="section-heading" id="analytics-heading">Visualise your energy story.</h2>
            <p class="section-lead">
                Explore solar generation, household consumption, costs, and future
                energy outlook through clear interactive analytics.
            </p>
        </header>

        <div class="card graph-card" id="graph-card">
            <div class="graph-head">
                <div>
                    <h3 class="graph-title">Solar &amp; household energy — day view</h3>
                    <p class="graph-sub">Historical values continue into a predicted future.</p>
                </div>

                <div class="graph-controls" role="group" aria-label="Illustrative range controls">
                    <div class="segmented" aria-label="Granularity">
                        <button type="button" class="range-btn is-active" aria-pressed="true" title="Illustrative preview">Day</button>
                        <button type="button" class="range-btn" aria-pressed="false" title="Illustrative preview">Week</button>
                        <button type="button" class="range-btn" aria-pressed="false" title="Illustrative preview">Month</button>
                        <button type="button" class="range-btn" aria-pressed="false" title="Illustrative preview">Year</button>
                    </div>
                    <span class="range-pill" title="Illustrative preview">Last 7 days</span>
                </div>
            </div>

            <div class="graph-legend" aria-label="Chart legend">
                <span class="legend-item"><span class="legend-line legend-line--solar" aria-hidden="true"></span>Solar generation — Actual</span>
                <span class="legend-item"><span class="legend-line legend-line--usage" aria-hidden="true"></span>Household usage — Actual</span>
                <span class="legend-item"><span class="legend-line legend-line--solar-fc" aria-hidden="true"></span>Solar forecast — Predicted</span>
                <span class="legend-item"><span class="legend-line legend-line--usage-fc" aria-hidden="true"></span>Usage forecast — Predicted</span>
                <span class="legend-item"><span class="legend-band" aria-hidden="true"></span>Confidence band</span>
            </div>

            <div class="graph-body">
                <svg class="graph-svg" id="graph-svg" viewBox="0 0 720 320" role="img" tabindex="0" aria-label="Illustrative chart: solar generation and household usage across the day, with predicted forecast continuation and confidence band">
                    <g class="graph-grid" aria-hidden="true">
                        <line x1="48" y1="284" x2="704" y2="284"/>
                        <line x1="48" y1="219" x2="704" y2="219"/>
                        <line x1="48" y1="154" x2="704" y2="154"/>
                        <line x1="48" y1="89"  x2="704" y2="89"/>
                        <line x1="48" y1="24"  x2="704" y2="24"/>
                    </g>
                    <g class="graph-labels" aria-hidden="true">
                        <text x="40" y="288" text-anchor="end">0</text>
                        <text x="40" y="223" text-anchor="end">4</text>
                        <text x="40" y="158" text-anchor="end">8</text>
                        <text x="40" y="93"  text-anchor="end">12</text>
                        <text x="40" y="28"  text-anchor="end">16</text>
                        <text x="14" y="20"  text-anchor="start">kWh</text>
                        <text x="48"  y="304" text-anchor="middle">6 AM</text>
                        <text x="179" y="304" text-anchor="middle">9 AM</text>
                        <text x="310" y="304" text-anchor="middle">12 PM</text>
                        <text x="441" y="304" text-anchor="middle">3 PM</text>
                        <text x="572" y="304" text-anchor="middle">6 PM</text>
                        <text x="702" y="304" text-anchor="middle">9 PM</text>
                        <text x="56"  y="38"  class="graph-region-label">HISTORY</text>
                        <text x="404" y="38"  class="graph-region-label graph-region-label--fc">FORECAST</text>
                    </g>

                    <path class="graph-band" d="M397 28.8 C413 31 429 34 440.7 37 C471 56 503 89 527.9 115 C556 141 590 187 615.2 206 C644 224 679 240 702.4 246.6 L702.4 282 C679 279 644 275 615.2 271 C586 264 551 216 527.9 176.8 C504 143 470 108 440.7 98.8 C426 94 411 90 397 87.5 Z"/>

                    <line class="graph-divider" x1="397" y1="24" x2="397" y2="284"/>

                    <path class="graph-line graph-line--usage-h" pathLength="1" d="M48 248.3 C82 222 110 202 135.5 196.3 C170 190 192 216 222.7 222.3 C254 229 281 180 309.9 173.5 C336 168 372 165 397 168.6"/>
                    <path class="graph-line graph-line--solar-h" pathLength="1" d="M48 275.9 C92 244 116 213 135.5 193 C163 165 196 134 222.7 118.3 C251 102 283 72 309.9 63 C328 56.5 343 53.3 353.4 53.3 C369 53.3 385 55.6 397 58.1"/>

                    <path class="graph-line graph-line--usage-fc" pathLength="1" d="M397 168.6 C425 175 458 187 484.3 183.3 C512 179 546 158 571.5 149.1 C597 141 636 172 658.8 180 C676 186 692 197 702.4 204.4"/>
                    <path class="graph-line graph-line--solar-fc" pathLength="1" d="M397 58.1 C413 60.8 429 63.4 440.7 66.3 C471 84 503 118 527.9 144.3 C556 171 590 219 615.2 238.5 C644 258 679 273 702.4 279.1"/>

                    <circle class="graph-dot graph-dot--solar" cx="397" cy="58.1" r="4"/>
                    <circle class="graph-dot graph-dot--usage" cx="397" cy="168.6" r="4"/>

                    <line class="graph-cursor" id="graph-cursor" x1="0" y1="24" x2="0" y2="284" aria-hidden="true"/>
                </svg>

                <div class="graph-tooltip" id="graph-tooltip" role="status" aria-live="polite">
                    <p class="gt-time" id="gt-time">12:00 PM</p>
                    <p class="gt-row"><span class="gt-swatch gt-swatch--solar" aria-hidden="true"></span>Solar: <strong id="gt-solar">13.6 kWh</strong></p>
                    <p class="gt-row"><span class="gt-swatch gt-swatch--usage" aria-hidden="true"></span>Usage: <strong id="gt-usage">6.8 kWh</strong></p>
                    <p class="gt-nature" id="gt-nature">ACTUAL · Illustrative</p>
                </div>
            </div>

            <div class="graph-foot">
                <p class="graph-note">Illustrative data — shown to demonstrate SPMS analytics presentation. It is not real household data.</p>
                <a class="text-link" href="/register">Explore Analytics →</a>
            </div>
        </div>
    </div>
</section>

<!-- 5. AI Recommendations -->
<section class="section ai-section" id="ai-recommendations" aria-labelledby="ai-heading">
    <div class="container">
        <header class="section-header">
            <h2 class="section-heading" id="ai-heading">Your personal solar assistant.</h2>
            <p class="section-lead">
                SPMS uses validated energy information and AI-assisted reasoning to
                turn complex energy patterns into clear, household-specific recommendations.
            </p>
        </header>

        <div class="ai-grid">
            <div class="ai-visual" aria-hidden="true">
                <div class="ai-core">
                    <span class="ai-core-ring"></span>
                    <span class="ai-core-halo"></span>
                    <span class="ai-core-center">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                    </span>
                </div>
                <span class="ai-node ai-node--u" >Understand</span>
                <span class="ai-node ai-node--p">Predict</span>
                <span class="ai-node ai-node--e">Explain</span>
                <span class="ai-node ai-node--r">Recommend</span>
            </div>

            <div class="ai-cards">
                <article class="card rec-card">
                    <div class="rec-badges">
                        <span class="badge badge-impact badge-impact--high">High impact</span>
                        <span class="badge badge-confidence">High confidence</span>
                    </div>
                    <h3 class="card-title">Shift usage to solar hours</h3>
                    <p class="card-text">Higher solar availability is expected during the middle of the day. Consider shifting flexible household usage toward this period.</p>
                </article>

                <article class="card rec-card">
                    <div class="rec-badges">
                        <span class="badge badge-impact badge-impact--medium">Medium impact</span>
                        <span class="badge badge-confidence">Moderate confidence</span>
                    </div>
                    <h3 class="card-title">Potential cost opportunity</h3>
                    <p class="card-text">Your energy pattern may offer an opportunity to use more solar-generated energy.</p>
                </article>

                <article class="card rec-card">
                    <div class="rec-badges">
                        <span class="badge badge-impact badge-impact--info">Informational</span>
                        <span class="badge badge-confidence">Moderate confidence</span>
                    </div>
                    <h3 class="card-title">Possible surplus tomorrow</h3>
                    <p class="card-text">Forecast conditions indicate a potential period of excess solar availability.</p>
                </article>

                <details class="card why-card">
                    <summary class="why-summary">
                        Why this recommendation?
                        <svg class="icon why-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <div class="why-body">
                        <dl class="why-facts">
                            <div><dt>Solar forecast</dt><dd>Higher availability expected around midday <span class="why-ill">· illustrative</span></dd></div>
                            <div><dt>Household demand</dt><dd>Comparatively lower around midday <span class="why-ill">· illustrative</span></dd></div>
                            <div><dt>Potential energy situation</dt><dd>Potential surplus</dd></div>
                            <div><dt>Confidence</dt><dd>High — supported by consistent patterns</dd></div>
                            <div><dt>Data quality</dt><dd>Verified recent bill and solar information</dd></div>
                        </dl>
                        <p class="why-explain">Higher solar availability is expected around midday while household demand is comparatively lower — creating a potential opportunity to shift flexible usage.</p>
                        <p class="why-foot">SPMS AI explains validated analytical results. It never invents numerical predictions. This example is illustrative and is not real household data.</p>
                    </div>
                </details>
            </div>
        </div>
    </div>
</section>

<!-- 6. Energy Balance -->
<section class="section" id="energy-balance" aria-labelledby="balance-heading">
    <div class="container">
        <header class="section-header">
            <h2 class="section-heading" id="balance-heading">See when your energy is likely to match.</h2>
            <p class="section-lead">
                SPMS compares expected solar availability with expected household
                demand and describes the potential energy situation honestly.
            </p>
        </header>

        <div class="balance-grid">
            <article class="card balance-card balance-card--surplus">
                <span class="balance-icon" aria-hidden="true">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                </span>
                <h3 class="balance-title">Potential surplus</h3>
                <p class="balance-text">Solar availability may exceed expected household demand.</p>
                <p class="balance-formula">Solar &gt; Usage</p>
            </article>

            <article class="card balance-card balance-card--balanced">
                <span class="balance-icon" aria-hidden="true">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16h6l-3-8-3 8z"/><path d="M2 16h6l-3-8-3 8z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
                </span>
                <h3 class="balance-title">Balanced</h3>
                <p class="balance-text">Solar availability and expected demand are relatively aligned.</p>
                <p class="balance-formula">Solar ≈ Usage</p>
            </article>

            <article class="card balance-card balance-card--deficit">
                <span class="balance-icon" aria-hidden="true">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </span>
                <h3 class="balance-title">Potential deficit</h3>
                <p class="balance-text">Expected household demand may exceed available solar.</p>
                <p class="balance-formula">Usage &gt; Solar</p>
            </article>
        </div>

        <p class="balance-note">
            These are analytical, potential outcomes. SPMS never claims confirmed
            grid import or export without direct measurement.
        </p>
    </div>
</section>

<!-- 7. Cost Impact -->
<section class="section section--alt" id="cost" aria-labelledby="cost-heading">
    <div class="container">
        <header class="section-header">
            <h2 class="section-heading" id="cost-heading">Understand the cost impact.</h2>
            <p class="section-lead">
                Verified bill history, household energy understanding, and solar
                availability come together into a better view of future cost opportunities.
            </p>
        </header>

        <div class="cost-card card">
            <div class="cost-flow" role="list" aria-label="Cost understanding inputs">
                <div class="cost-input" role="listitem">
                    <span class="cost-tag cost-tag--verified">Historical · Verified</span>
                    <p class="cost-input-title">Bill history</p>
                    <p class="cost-input-text">Verified electricity bills build the trusted baseline.</p>
                </div>
                <span class="cost-plus" aria-hidden="true">+</span>
                <div class="cost-input" role="listitem">
                    <span class="cost-tag cost-tag--verified">Household · Understood</span>
                    <p class="cost-input-title">Energy usage</p>
                    <p class="cost-input-text">Consumption patterns from validated information.</p>
                </div>
                <span class="cost-plus" aria-hidden="true">+</span>
                <div class="cost-input" role="listitem">
                    <span class="cost-tag cost-tag--verified">Solar · Known</span>
                    <p class="cost-input-title">Solar availability</p>
                    <p class="cost-input-text">Actual and predicted solar from your connected system.</p>
                </div>
                <span class="cost-equals" aria-hidden="true">=</span>
                <div class="cost-result" role="listitem">
                    <span class="cost-tag cost-tag--estimated">Future · Estimated</span>
                    <p class="cost-input-title">Cost opportunities</p>
                    <p class="cost-input-text">An estimated view of how timing affects future cost. <span class="why-ill">Illustrative presentation.</span></p>
                </div>
            </div>

            <div class="cost-notes">
                <p>Fixed charges are kept separate from usage-proportional energy costs.</p>
                <p>Future amounts are estimates — SPMS does not promise exact savings.</p>
            </div>
        </div>
    </div>
</section>

<!-- 8. Key Features -->
<section class="section" id="features" aria-labelledby="features-heading">
    <div class="container">
        <header class="section-header">
            <h2 class="section-heading" id="features-heading">Everything you need for a smarter solar home.</h2>
            <p class="section-lead">The capabilities SPMS brings together in one calm, trustworthy workspace.</p>
        </header>

        <div class="feature-grid">
            <article class="card feature-card">
                <span class="card-icon card-icon--solar" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg></span>
                <h3 class="card-title">Solar</h3>
                <p class="card-text">Understand solar generation.</p>
            </article>
            <article class="card feature-card">
                <span class="card-icon card-icon--usage" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg></span>
                <h3 class="card-title">Energy</h3>
                <p class="card-text">Understand household electricity consumption.</p>
            </article>
            <article class="card feature-card feature-card--featured">
                <span class="card-icon card-icon--bills" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg></span>
                <h3 class="card-title">Electricity bills</h3>
                <p class="card-text">Build your household energy profile from verified bill information.</p>
            </article>
            <article class="card feature-card feature-card--featured">
                <span class="card-icon card-icon--outlook" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></span>
                <h3 class="card-title">Forecasts</h3>
                <p class="card-text">See future solar and consumption outlook.</p>
            </article>
            <article class="card feature-card feature-card--featured">
                <span class="card-icon card-icon--ai" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg></span>
                <h3 class="card-title">AI recommendations</h3>
                <p class="card-text">Receive meaningful, context-aware recommendations.</p>
            </article>
            <article class="card feature-card feature-card--featured">
                <span class="card-icon card-icon--analytics" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg></span>
                <h3 class="card-title">Energy analytics</h3>
                <p class="card-text">Explore historical energy patterns.</p>
            </article>
            <article class="card feature-card">
                <span class="card-icon card-icon--reports" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg></span>
                <h3 class="card-title">Reports</h3>
                <p class="card-text">Understand your energy journey through clear reports.</p>
            </article>
            <article class="card feature-card">
                <span class="card-icon card-icon--quality" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg></span>
                <h3 class="card-title">Data quality</h3>
                <p class="card-text">Understand how reliable your information is.</p>
            </article>
            <article class="card feature-card">
                <span class="card-icon card-icon--confidence" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 4 3 5-6"/></svg></span>
                <h3 class="card-title">Confidence</h3>
                <p class="card-text">Know how strongly SPMS supports a prediction or recommendation.</p>
            </article>
            <article class="card feature-card">
                <span class="card-icon card-icon--provenance" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></span>
                <h3 class="card-title">Provenance</h3>
                <p class="card-text">Know whether information is actual, predicted, estimated, stale, or simulated.</p>
            </article>
        </div>
    </div>
</section>

<!-- 9. Data Trust -->
<section class="section section--alt" id="data-trust" aria-labelledby="trust-heading">
    <div class="container">
        <header class="section-header">
            <h2 class="section-heading" id="trust-heading">Know what your data means.</h2>
            <p class="section-lead">
                SPMS clearly distinguishes analytical data types — so a prediction
                never masquerades as a measurement.
            </p>
        </header>

        <div class="trust-grid">
            <div class="card trust-tile">
                <span class="trust-line trust-line--actual" aria-hidden="true"></span>
                <h3 class="trust-title">Actual</h3>
                <p class="trust-text">Measured values from a validated source.</p>
            </div>
            <div class="card trust-tile">
                <span class="trust-line trust-line--predicted" aria-hidden="true"></span>
                <h3 class="trust-title">Predicted</h3>
                <p class="trust-text">Future estimates produced by a prediction model.</p>
            </div>
            <div class="card trust-tile">
                <span class="trust-line trust-line--estimated" aria-hidden="true"></span>
                <h3 class="trust-title">Estimated</h3>
                <p class="trust-text">Approximations built from limited evidence.</p>
            </div>
            <div class="card trust-tile">
                <span class="trust-line trust-line--stale" aria-hidden="true"></span>
                <h3 class="trust-title">Stale</h3>
                <p class="trust-text">Real data that is no longer fresh — never shown as live.</p>
            </div>
            <div class="card trust-tile">
                <span class="trust-badge-demo" aria-hidden="true">DEMO</span>
                <h3 class="trust-title">Simulated</h3>
                <p class="trust-text">Controlled demonstration data, always labelled.</p>
            </div>
        </div>

        <p class="trust-note">Colour is never the only signal — line style and labels communicate the difference too.</p>
    </div>
</section>

<!-- 10. Personalization -->
<section class="section" id="personalization" aria-labelledby="personal-heading">
    <div class="container">
        <div class="split">
            <div class="split-copy">
                <h2 class="section-heading" id="personal-heading">Recommendations become more relevant over time.</h2>
                <p class="section-lead split-lead">
                    When you mark a recommendation as useful — or not — SPMS learns
                    your preferences and improves relevance, frequency, and timing.
                </p>

                <ol class="learn-flow" aria-label="Feedback learning flow">
                    <li class="learn-step">Recommendation</li>
                    <li class="pipe-arrow" aria-hidden="true">→</li>
                    <li class="learn-step">Useful / Not useful</li>
                    <li class="pipe-arrow" aria-hidden="true">→</li>
                    <li class="learn-step">Preference learning</li>
                    <li class="pipe-arrow" aria-hidden="true">→</li>
                    <li class="learn-step learn-step--result">Better timing</li>
                </ol>

                <p class="bill-note">
                    Feedback personalises presentation, relevance, and timing.
                    It never rewrites objective analytical truth.
                </p>
            </div>

            <div class="split-visual">
                <div class="card learn-card" aria-hidden="true">
                    <p class="learn-card-title">Explicit preference</p>
                    <div class="learn-bar"><span class="learn-bar-fill learn-bar-fill--explicit"></span></div>
                    <p class="learn-card-title">Intelligent default</p>
                    <div class="learn-bar"><span class="learn-bar-fill learn-bar-fill--default"></span></div>
                    <p class="learn-card-title">Learned personalization</p>
                    <div class="learn-bar"><span class="learn-bar-fill learn-bar-fill--learned"></span></div>
                    <p class="learn-foot">Priority: explicit preference wins — always.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 11. About -->
<section class="section section--alt about-section" id="about" aria-labelledby="about-heading">
    <div class="container">
        <header class="section-header">
            <h2 class="section-heading" id="about-heading">About SPMS</h2>
            <p class="section-lead about-lead">
                SPMS — the Solar Power Management System — is a household energy
                decision-support system. It combines solar generation, electricity
                bills, household context, and environmental information to help
                families understand, predict, and improve their energy use.
            </p>
            <p class="section-lead about-lead">
                SPMS advises; it never controls your appliances, and it never
                promises guaranteed outcomes.
            </p>
        </header>
    </div>
</section>

<!-- 12. Final CTA -->
<section class="section final-cta-section" aria-labelledby="final-cta-heading">
    <div class="container">
        <div class="final-cta">
            <div class="final-cta-scene" aria-hidden="true">
                <span class="final-sun"></span>
                <svg class="final-panels" viewBox="0 0 200 60" role="presentation" focusable="false">
                    <g class="final-panel">
                        <rect x="10" y="14" width="52" height="34" rx="3"/>
                        <line x1="27" y1="14" x2="27" y2="48"/><line x1="45" y1="14" x2="45" y2="48"/>
                        <line x1="10" y1="31" x2="62" y2="31"/>
                    </g>
                    <g class="final-panel">
                        <rect x="76" y="14" width="52" height="34" rx="3"/>
                        <line x1="93" y1="14" x2="93" y2="48"/><line x1="111" y1="14" x2="111" y2="48"/>
                        <line x1="76" y1="31" x2="128" y2="31"/>
                    </g>
                    <g class="final-panel">
                        <rect x="142" y="14" width="52" height="34" rx="3"/>
                        <line x1="159" y1="14" x2="159" y2="48"/><line x1="177" y1="14" x2="177" y2="48"/>
                        <line x1="142" y1="31" x2="194" y2="31"/>
                    </g>
                </svg>
            </div>

            <h2 class="final-cta-heading" id="final-cta-heading">
                Make more informed use of the energy your solar system produces.
            </h2>
            <p class="final-cta-tag">A clearer today. A brighter tomorrow.</p>

            <div class="final-cta-actions">
                <a class="btn btn-primary btn-lg" href="/register">
                    Get Started
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
                <p class="final-cta-alt">Already have an account? <a class="text-link" href="/login">Log in</a></p>
            </div>
        </div>
    </div>
</section>
