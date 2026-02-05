<style>
/* Section Title */
.quick-stats-section-title {
    font-family: 'Sora', ui-sans-serif, system-ui, sans-serif;
    font-size: 1.5rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.025em;
    color: #0B1220;
    margin: 0 0 2rem 0;
}

.quick-stats-subheading {
    font-family: 'Sora', ui-sans-serif, system-ui, sans-serif;
    font-size: 1.125rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.025em;
    color: #1F2937;
    margin: 0 0 1rem 0;
}

/* Quick Stats Card Specific Styles */
.quick-stat-card {
    background: rgba(255, 255, 255, 0.95);
    border: 1px solid rgba(229, 231, 235, 1);
    border-radius: 0.75rem;
    padding: 1.25rem;
    transition: all 0.3s ease;
}

.quick-stat-card:hover {
    background: rgba(255, 255, 255, 1);
    border-color: rgba(209, 213, 219, 1);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.15);
}

/* Highlighted card (Opponent Strength) */
.quick-stat-card.highlighted {
    background: linear-gradient(135deg, rgba(230, 240, 255, 0.95) 0%, rgba(204, 224, 255, 0.95) 100%);
    border: 2px solid rgba(0, 71, 168, 0.4);
}

.quick-stat-card.highlighted:hover {
    background: linear-gradient(135deg, rgba(230, 240, 255, 1) 0%, rgba(204, 224, 255, 1) 100%);
    border-color: rgba(0, 71, 168, 0.6);
    box-shadow: 0 10px 15px -3px rgba(0, 71, 168, 0.25);
}

/* Icon styling */
.quick-stat-card .stat-icon {
    width: 2.5rem;
    height: 2.5rem;
    flex-shrink: 0;
}

.quick-stat-card .stat-icon.icon-success {
    color: #16A34A;
}

.quick-stat-card .stat-icon.icon-danger {
    color: #DC2626;
}

.quick-stat-card .stat-icon.icon-primary {
    color: #0047A8;
}

.quick-stat-card .stat-icon.icon-white {
    color: #647488;
}

/* Value styling */
.quick-stat-card .stat-value {
    font-family: 'Sora', ui-sans-serif, system-ui, sans-serif;
    font-size: 1.875rem;
    font-weight: 900;
    line-height: 1;
    color: #0B1220;
    margin: 0;
}

.quick-stat-card.highlighted .stat-value {
    color: #0047A8;
}

/* Label styling */
.quick-stat-card .stat-label {
    font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #647488;
    margin: 0;
}

.quick-stat-card.highlighted .stat-label {
    color: #003d8f;
}

/* Recent Form Badges */
.quick-stat-badge {
    width: 3rem;
    height: 3rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.quick-stat-badge:hover {
    transform: scale(1.1);
}

.quick-stat-badge.badge-win {
    background: rgba(22, 163, 74, 0.3);
    border-color: rgba(22, 163, 74, 0.5);
}

.quick-stat-badge.badge-win:hover {
    background: rgba(22, 163, 74, 0.4);
    border-color: rgba(22, 163, 74, 0.7);
    box-shadow: 0 4px 6px -1px rgba(22, 163, 74, 0.3);
}

.quick-stat-badge.badge-loss {
    background: rgba(220, 38, 38, 0.3);
    border-color: rgba(220, 38, 38, 0.5);
}

.quick-stat-badge.badge-loss:hover {
    background: rgba(220, 38, 38, 0.4);
    border-color: rgba(220, 38, 38, 0.7);
    box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.3);
}

.quick-stat-badge .badge-text {
    font-family: 'Sora', ui-sans-serif, system-ui, sans-serif;
    font-weight: 900;
    font-size: 0.875rem;
}

.quick-stat-badge.badge-win .badge-text {
    color: #16A34A;
}

.quick-stat-badge.badge-loss .badge-text {
    color: #DC2626;
}
</style>

<!-- Quick Stats Section -->
<section class="single-fighter-quick-stats-section bg-gradient-to-br from-slate-50 to-slate-100 py-12 lg:py-16">
    <div class="container mx-auto px-4">
        
        <!-- Section Title -->
        <h2 class="quick-stats-section-title">
            Brza Statistika
        </h2>

        <!-- Stats Grid -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 md:gap-5 lg:gap-6 mb-10 lg:mb-12">
            
            <!-- Stat Card: Wins -->
            <article class="quick-stat-card">
                <div class="flex flex-col items-center text-center space-y-3">
                    <!-- Icon -->
                    <div class="stat-icon icon-success" aria-hidden="true">
                        <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <!-- Value -->
                    <div class="stat-value" data-stat="wins">37</div>
                    <!-- Label -->
                    <div class="stat-label">Pobede</div>
                </div>
            </article>

            <!-- Stat Card: Losses -->
            <article class="quick-stat-card">
                <div class="flex flex-col items-center text-center space-y-3">
                    <!-- Icon -->
                    <div class="stat-icon icon-danger" aria-hidden="true">
                        <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <!-- Value -->
                    <div class="stat-value" data-stat="losses">10</div>
                    <!-- Label -->
                    <div class="stat-label">Porazi</div>
                </div>
            </article>

            <!-- Stat Card: Finishes -->
            <article class="quick-stat-card">
                <div class="flex flex-col items-center text-center space-y-3">
                    <!-- Icon -->
                    <div class="stat-icon icon-white" aria-hidden="true">
                        <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/>
                        </svg>
                    </div>
                    <!-- Value -->
                    <div class="stat-value" data-stat="finishes">24</div>
                    <!-- Label -->
                    <div class="stat-label">Završavanja</div>
                </div>
            </article>

            <!-- Stat Card: Opponent Strength (Highlighted) -->
            <article class="quick-stat-card highlighted">
                <div class="flex flex-col items-center text-center space-y-3">
                    <!-- Icon -->
                    <div class="stat-icon icon-primary" aria-hidden="true">
                        <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <!-- Value -->
                    <div class="stat-value" data-stat="oppScore">87.4</div>
                    <!-- Label with tooltip hint -->
                    <div class="stat-label" title="Aggregate strength of opponents faced">
                        Snaga Protivnika
                    </div>
                </div>
            </article>

            <!-- Stat Card: Win % -->
            <article class="quick-stat-card">
                <div class="flex flex-col items-center text-center space-y-3">
                    <!-- Icon -->
                    <div class="stat-icon icon-white" aria-hidden="true">
                        <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>
                        </svg>
                    </div>
                    <!-- Value -->
                    <div class="stat-value" data-stat="winRate">79%</div>
                    <!-- Label -->
                    <div class="stat-label">% Pobeda</div>
                </div>
            </article>

            <!-- Stat Card: Finish % -->
            <article class="quick-stat-card">
                <div class="flex flex-col items-center text-center space-y-3">
                    <!-- Icon -->
                    <div class="stat-icon icon-white" aria-hidden="true">
                        <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
                        </svg>
                    </div>
                    <!-- Value -->
                    <div class="stat-value" data-stat="finishRate">65%</div>
                    <!-- Label -->
                    <div class="stat-label">% Završavanja</div>
                </div>
            </article>

        </div>

        <!-- Recent Form Row -->
        <div class="recent-form-wrapper">
            <!-- Subheading -->
            <h3 class="quick-stats-subheading">
              Latest 5 Fights:
            </h3>
            
            <!-- Form Badges Row -->
            <div class="flex flex-wrap gap-3 md:gap-4">
                
                <!-- Badge: Win -->
                <div class="quick-stat-badge badge-win" data-form="W">
                    <span class="badge-text" aria-label="Win">W</span>
                </div>

                <!-- Badge: Win -->
                <div class="quick-stat-badge badge-win" data-form="W">
                    <span class="badge-text" aria-label="Win">W</span>
                </div>

                <!-- Badge: Loss -->
                <div class="quick-stat-badge badge-loss" data-form="L">
                    <span class="badge-text" aria-label="Loss">L</span>
                </div>

                <!-- Badge: Win -->
                <div class="quick-stat-badge badge-win" data-form="W">
                    <span class="badge-text" aria-label="Win">W</span>
                </div>

                <!-- Badge: Win -->
                <div class="quick-stat-badge badge-win" data-form="W">
                    <span class="badge-text" aria-label="Win">W</span>
                </div>

            </div>
        </div>

    </div>
</section>
