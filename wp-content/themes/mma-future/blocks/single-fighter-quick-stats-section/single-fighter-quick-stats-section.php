<!-- Quick Stats Section Styles -->
<style>
/* ============================================
   BRZA STATISTIKA (Quick Stats) Section
   Scoped under .mf-quick-stats
   ============================================ */

.mf-quick-stats {
    /* Reset nested elements */
    ul, ol {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    
    a {
        text-decoration: none;
    }
}

/* Container */
.mf-quick-stats__container {
    /* Inherits container styles from Tailwind */
}

/* Header */
.mf-quick-stats__header {
    /* Inherits from Tailwind utilities */
}

.mf-quick-stats__title {
    /* Inherits from Tailwind utilities */
}

/* Cards Grid */
.mf-quick-stats__cards {
    /* Grid handled by Tailwind, additional polish below */
}

/* Individual Card */
.mf-quick-stats__card {
    /* Base styling */
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
}

.mf-quick-stats__card:hover {
    border-color: rgba(148, 163, 184, 0.4);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    transform: translateY(-2px);
}

/* Focus state (if cards become interactive) */
.mf-quick-stats__card:focus-visible {
    outline: 2px solid rgba(59, 130, 246, 0.5);
    outline-offset: 2px;
    border-color: rgba(59, 130, 246, 0.6);
}

/* Active Card Modifier */
.mf-quick-stats__card--active {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.03) 0%, rgba(59, 130, 246, 0.06) 100%);
    border-color: rgba(59, 130, 246, 0.4);
    box-shadow: 
        0 1px 3px 0 rgba(59, 130, 246, 0.1),
        0 1px 2px 0 rgba(59, 130, 246, 0.06),
        0 0 0 1px rgba(59, 130, 246, 0.1) inset;
}

.mf-quick-stats__card--active:hover {
    border-color: rgba(59, 130, 246, 0.6);
    box-shadow: 
        0 4px 6px -1px rgba(59, 130, 246, 0.15),
        0 2px 4px -1px rgba(59, 130, 246, 0.1),
        0 0 0 1px rgba(59, 130, 246, 0.15) inset;
    transform: translateY(-2px);
}

/* Card Icon Container */
.mf-quick-stats__icon {
    transition: transform 0.2s ease;
}

.mf-quick-stats__card:hover .mf-quick-stats__icon {
    transform: scale(1.05);
}

.mf-quick-stats__card--active .mf-quick-stats__icon {
    background: rgba(59, 130, 246, 0.1);
}

.mf-quick-stats__card--active:hover .mf-quick-stats__icon {
    background: rgba(59, 130, 246, 0.15);
}

/* Card Value */
.mf-quick-stats__value {
    line-height: 1.2;
}

.mf-quick-stats__card--active .mf-quick-stats__value {
    color: rgba(59, 130, 246, 1);
}

/* Card Label */
.mf-quick-stats__label {
    line-height: 1.3;
}

/* Recent Fights Section */
.mf-quick-stats__recent {
    /* Inherits from Tailwind utilities */
}

.mf-quick-stats__recent-title {
    flex-shrink: 0;
}

/* Fight Result Chips */
.mf-quick-stats__chips {
    /* Inherits from Tailwind utilities */
}

.mf-quick-stats__chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    font-family: inherit;
    font-weight: 700;
    font-size: 0.875rem;
    border: 2px solid;
    transition: all 0.15s ease;
}

/* Win Chip */
.mf-quick-stats__chip--win {
    background: rgba(22, 163, 74, 0.1);
    border-color: rgba(22, 163, 74, 0.3);
    color: #15803D;
}

.mf-quick-stats__chip--win:hover {
    background: rgba(22, 163, 74, 0.15);
    border-color: rgba(22, 163, 74, 0.5);
    transform: scale(1.05);
}

/* Loss Chip */
.mf-quick-stats__chip--loss {
    background: rgba(220, 38, 38, 0.1);
    border-color: rgba(220, 38, 38, 0.3);
    color: #B91C1C;
}

.mf-quick-stats__chip--loss:hover {
    background: rgba(220, 38, 38, 0.15);
    border-color: rgba(220, 38, 38, 0.5);
    transform: scale(1.05);
}

/* Draw Chip (optional for future use) */
.mf-quick-stats__chip--draw {
    background: rgba(107, 114, 128, 0.1);
    border-color: rgba(107, 114, 128, 0.3);
    color: #4B5563;
}

.mf-quick-stats__chip--draw:hover {
    background: rgba(107, 114, 128, 0.15);
    border-color: rgba(107, 114, 128, 0.5);
    transform: scale(1.05);
}

/* No Contest Chip (optional for future use) */
.mf-quick-stats__chip--nc {
    background: rgba(161, 98, 7, 0.1);
    border-color: rgba(161, 98, 7, 0.3);
    color: #92400E;
}

.mf-quick-stats__chip--nc:hover {
    background: rgba(161, 98, 7, 0.15);
    border-color: rgba(161, 98, 7, 0.5);
    transform: scale(1.05);
}

/* Reduced Motion Support */
@media (prefers-reduced-motion: reduce) {
    .mf-quick-stats__card,
    .mf-quick-stats__icon,
    .mf-quick-stats__chip {
        transition: none;
    }
    
    .mf-quick-stats__card:hover,
    .mf-quick-stats__chip:hover {
        transform: none;
    }
}

/* Responsive Adjustments */
@media (max-width: 640px) {
    /* Cards: 2 per row on mobile */
    .mf-quick-stats__cards {
        /* Grid already set to grid-cols-2 on mobile via Tailwind */
    }
    
    /* Reduce card padding slightly on very small screens */
    .mf-quick-stats__card {
        padding: 0.875rem;
    }
    
    /* Adjust icon size */
    .mf-quick-stats__icon {
        width: 2.5rem;
        height: 2.5rem;
    }
    
    /* Adjust value size */
    .mf-quick-stats__value {
        font-size: 1.5rem;
    }
}

@media (min-width: 641px) and (max-width: 1023px) {
    /* Tablet: 3 cards per row */
    .mf-quick-stats__cards {
        /* Grid already set to md:grid-cols-3 via Tailwind */
    }
}

@media (min-width: 1024px) {
    /* Desktop: 6 cards in single row */
    .mf-quick-stats__cards {
        /* Grid already set to lg:grid-cols-6 via Tailwind */
    }
}
</style>

<!-- Quick Stats Section -->
<section class="mf-quick-stats bg-gradient-to-br from-slate-50 to-slate-100 py-8 lg:py-12">
    <div class="mf-quick-stats__container container mx-auto px-4">
        
        <!-- Section Header -->
        <div class="mf-quick-stats__header mb-6 lg:mb-8">
            <h2 class="mf-quick-stats__title font-heading font-bold text-xl md:text-2xl text-heading uppercase tracking-wide">
                Brza Statistika
            </h2>
        </div>

        <!-- Stats Cards Grid -->
        <div class="mf-quick-stats__cards grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 lg:gap-4 mb-6 lg:mb-8">
            
            <!-- Card 1: Wins -->
            <article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
                <div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-green-50 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1">
                    37
                </div>
                <div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">
                    Pobede
                </div>
            </article>

            <!-- Card 2: Losses -->
            <article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
                <div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-red-50 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
                <div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1">
                    10
                </div>
                <div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">
                    Porazi
                </div>
            </article>

            <!-- Card 3: Finishes -->
            <article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
                <div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-amber-50 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1">
                    24
                </div>
                <div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">
                    Završavanja
                </div>
            </article>

            <!-- Card 4: Opponent Strength (ACTIVE) -->
            <article class="mf-quick-stats__card mf-quick-stats__card--active bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
                <div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-blue-50 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>
                <div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1">
                    87.4
                </div>
                <div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">
                    Snaga Protivnika
                </div>
            </article>

            <!-- Card 5: Win Percentage -->
            <article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
                <div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-indigo-50 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1">
                    79%
                </div>
                <div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">
                    % Pobeda
                </div>
            </article>

            <!-- Card 6: Finish Percentage -->
            <article class="mf-quick-stats__card bg-white rounded-xl border border-slate-200 p-4 lg:p-5 flex flex-col items-center text-center">
                <div class="mf-quick-stats__icon w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-purple-50 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div class="mf-quick-stats__value font-heading font-bold text-2xl lg:text-3xl text-heading mb-1">
                    65%
                </div>
                <div class="mf-quick-stats__label font-semibold text-xs uppercase tracking-wide text-muted">
                    % Završavanja
                </div>
            </article>

        </div>

        <!-- Latest 5 Fights -->
        <div class="mf-quick-stats__recent flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4">
            <h3 class="mf-quick-stats__recent-title font-heading font-semibold text-sm uppercase tracking-wide text-heading">
                Latest 5 Fights:
            </h3>
            <div class="mf-quick-stats__chips flex flex-wrap items-center gap-2">
                <span class="mf-quick-stats__chip mf-quick-stats__chip--win">W</span>
                <span class="mf-quick-stats__chip mf-quick-stats__chip--win">W</span>
                <span class="mf-quick-stats__chip mf-quick-stats__chip--loss">L</span>
                <span class="mf-quick-stats__chip mf-quick-stats__chip--win">W</span>
                <span class="mf-quick-stats__chip mf-quick-stats__chip--win">W</span>
            </div>
        </div>

    </div>
</section>
