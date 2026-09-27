<?php

use App\Support\View;
?>
<section class="section placeholder-section" aria-labelledby="placeholder-heading">
    <div class="container">
        <div class="placeholder-panel">
            <span class="placeholder-icon" aria-hidden="true">
                <?php if (($placeholderIcon ?? '') === 'user-plus'): ?>
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 21a8 8 0 0 1 13.292-6"/><circle cx="10" cy="8" r="5"/><path d="M19 16v6"/><path d="M22 19h-6"/></svg>
                <?php else: ?>
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg>
                <?php endif; ?>
            </span>

            <h1 class="placeholder-title" id="placeholder-heading"><?= View::e($placeholderTitle ?? 'Coming soon') ?></h1>
            <p class="placeholder-text"><?= View::e($placeholderMessage ?? '') ?></p>

            <a class="btn btn-primary" href="/"><?= View::e($placeholderActionLabel ?? 'Back to the home page') ?></a>
        </div>
    </div>
</section>
