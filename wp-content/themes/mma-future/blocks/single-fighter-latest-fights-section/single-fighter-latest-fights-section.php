<!-- Recent Fights Section Styles -->
<style>
/* Section-scoped overrides to prevent global style conflicts */
.mf-recent-fights {
    /* Reset list styles */
    ul, ol {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    
    /* Reset link styles */
    a {
        text-decoration: none !important;
        
        &:hover,
        &:focus,
        &:active,
        &:visited {
            text-decoration: none !important;
        }
    }
    
    /* Reset button styles */
    button {
        background: transparent;
        border: none;
        padding: 0;
        cursor: pointer;
    }
    
    /* SVG sizing */
    svg {
        flex-shrink: 0;
    }
}

/* Fight Card Overrides */
.mf-fight-card {
    /* Ensure consistent card height */
    display: flex;
    flex-direction: column;
    
    /* Transition for hover effects */
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    
    /* Hover state */
    &:hover {
        border-color: rgba(0, 71, 168, 0.3);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        transform: translateY(-2px);
    }
}

/* Badge Modifiers */
.mf-fight-card--win .mf-fight-card__badge {
    background: rgba(22, 163, 74, 0.15);
    border-color: rgba(22, 163, 74, 0.4);
    color: #16A34A;
}

.mf-fight-card--loss .mf-fight-card__badge {
    background: rgba(220, 38, 38, 0.15);
    border-color: rgba(220, 38, 38, 0.4);
    color: #DC2626;
}

/* Opponent Avatar Link */
.mf-fight-card__avatar {
    transition: all 0.2s ease;
    
    &:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.15);
    }
}

/* CTA Button Hover */
.mf-fight-card__cta {
    transition: all 0.2s ease;
    
    &:hover {
        background: rgba(0, 71, 168, 0.95);
        box-shadow: 0 4px 6px -1px rgba(0, 71, 168, 0.3);
        transform: translateX(2px);
        color: #ffffff !important;
    }
    
    &:active {
        transform: translateX(1px);
        color: #ffffff !important;
    }
    
    /* Ensure text stays white on visited state */
    &:visited {
        color: #ffffff;
    }
}

/* CTA Icon Animation */
.mf-fight-card__cta:hover .mf-fight-card__cta-icon {
    transform: translateX(2px);
}

/* Org Tag Styling */
.mf-fight-card__org {
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Responsive Adjustments */
@media (max-width: 640px) {
    .mf-fight-card__header {
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    
    .mf-fight-card__meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .mf-fight-card__cta {
        width: 100%;
        justify-content: center;
    }
}
</style>

<!-- Recent Fights Section -->
<section class="mf-recent-fights bg-gradient-to-br from-slate-50 to-slate-100 py-12 lg:py-16">
    <div class="container mx-auto px-4">
        
        <!-- Section Title -->
        <h2 class="font-heading font-bold text-xl md:text-2xl text-heading uppercase tracking-wide mb-8 lg:mb-10">
            Nedavne Borbe
        </h2>

        <!-- Fight Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
            
            <!-- Fight Card 1: WIN -->
            <article class="mf-fight-card mf-fight-card--win bg-white rounded-xl border border-slate-200 p-5 lg:p-6"
                data-outcome="win"
                data-opponent-name="Conor McGregor"
                data-opponent-record="22-6-0"
                data-fight-date="2025-12-15"
                data-org="UFC"
                data-location="Las Vegas, USA"
                data-method="KO/TKO"
                data-round="3"
                data-cta-url="#fight-1">
                
                <!-- Header: Badge + Opponent Info -->
                <div class="mf-fight-card__header flex items-start justify-between gap-4 mb-4">
                    <!-- Outcome Badge -->
                    <div class="mf-fight-card__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide">
                        WIN
                    </div>
                    
                    <!-- Opponent Block -->
                    <div class="mf-fight-card__opponent flex items-center gap-3 flex-1 min-w-0">
                        <!-- Avatar -->
                        <a href="#opponent-1" class="mf-fight-card__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200">
                            <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" 
                                alt="Conor McGregor" 
                                class="w-full h-full object-cover">
                        </a>
                        
                        <!-- Name + Record -->
                        <div class="flex-1 min-w-0">
                            <a href="#opponent-1" class="mf-fight-card__name block font-heading font-semibold text-base text-heading truncate hover:text-primary-500 transition-colors">
                                Conor McGregor
                            </a>
                            <div class="mf-fight-card__record text-xs text-muted mt-0.5">
                                22-6-0
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Method Row -->
                <div class="mf-fight-card__method font-heading font-bold text-2xl text-heading mb-4">
                    KO/TKO <span class="text-muted">•</span> R3
                </div>

                <!-- Meta Row -->
                <div class="mf-fight-card__meta flex flex-wrap items-center gap-3 mb-5">
                    <!-- Date -->
                    <div class="mf-fight-card__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>Dec 15, 2025</span>
                    </div>
                    
                    <!-- Organization Tag -->
                    <div class="mf-fight-card__meta-item">
                        <span class="mf-fight-card__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200">
                            UFC
                        </span>
                    </div>
                    
                    <!-- Location -->
                    <div class="mf-fight-card__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Las Vegas, USA</span>
                    </div>
                </div>

                <!-- Divider -->
                <div class="mf-fight-card__divider h-px bg-slate-200 mb-5"></div>

                <!-- Footer: CTA Button -->
                <div class="mf-fight-card__footer">
                    <a href="#fight-1" 
                        class="mf-fight-card__cta inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 text-white font-semibold text-sm">
                        <span>Pogledaj Borbu</span>
                        <svg class="mf-fight-card__cta-icon w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                </div>
            </article>

            <!-- Fight Card 2: WIN -->
            <article class="mf-fight-card mf-fight-card--win bg-white rounded-xl border border-slate-200 p-5 lg:p-6"
                data-outcome="win"
                data-opponent-name="Dustin Poirier"
                data-opponent-record="29-8-0"
                data-fight-date="2025-10-22"
                data-org="UFC"
                data-location="Abu Dhabi, UAE"
                data-method="SUB"
                data-round="2"
                data-cta-url="#fight-2">
                
                <!-- Header: Badge + Opponent Info -->
                <div class="mf-fight-card__header flex items-start justify-between gap-4 mb-4">
                    <!-- Outcome Badge -->
                    <div class="mf-fight-card__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide">
                        WIN
                    </div>
                    
                    <!-- Opponent Block -->
                    <div class="mf-fight-card__opponent flex items-center gap-3 flex-1 min-w-0">
                        <!-- Avatar -->
                        <a href="#opponent-2" class="mf-fight-card__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200">
                            <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" 
                                alt="Dustin Poirier" 
                                class="w-full h-full object-cover">
                        </a>
                        
                        <!-- Name + Record -->
                        <div class="flex-1 min-w-0">
                            <a href="#opponent-2" class="mf-fight-card__name block font-heading font-semibold text-base text-heading truncate hover:text-primary-500 transition-colors">
                                Dustin Poirier
                            </a>
                            <div class="mf-fight-card__record text-xs text-muted mt-0.5">
                                29-8-0
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Method Row -->
                <div class="mf-fight-card__method font-heading font-bold text-2xl text-heading mb-4">
                    SUB <span class="text-muted">•</span> R2
                </div>

                <!-- Meta Row -->
                <div class="mf-fight-card__meta flex flex-wrap items-center gap-3 mb-5">
                    <!-- Date -->
                    <div class="mf-fight-card__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>Oct 22, 2025</span>
                    </div>
                    
                    <!-- Organization Tag -->
                    <div class="mf-fight-card__meta-item">
                        <span class="mf-fight-card__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200">
                            UFC
                        </span>
                    </div>
                    
                    <!-- Location -->
                    <div class="mf-fight-card__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Abu Dhabi, UAE</span>
                    </div>
                </div>

                <!-- Divider -->
                <div class="mf-fight-card__divider h-px bg-slate-200 mb-5"></div>

                <!-- Footer: CTA Button -->
                <div class="mf-fight-card__footer">
                    <a href="#fight-2" 
                        class="mf-fight-card__cta inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 text-white font-semibold text-sm">
                        <span>Gledaj Highlights</span>
                        <svg class="mf-fight-card__cta-icon w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                </div>
            </article>

            <!-- Fight Card 3: LOSS -->
            <article class="mf-fight-card mf-fight-card--loss bg-white rounded-xl border border-slate-200 p-5 lg:p-6"
                data-outcome="loss"
                data-opponent-name="Islam Makhachev"
                data-opponent-record="26-1-0"
                data-fight-date="2025-06-08"
                data-org="UFC"
                data-location="Singapore"
                data-method="SUB"
                data-round="3"
                data-cta-url="#fight-3">
                
                <!-- Header: Badge + Opponent Info -->
                <div class="mf-fight-card__header flex items-start justify-between gap-4 mb-4">
                    <!-- Outcome Badge -->
                    <div class="mf-fight-card__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide">
                        LOSS
                    </div>
                    
                    <!-- Opponent Block -->
                    <div class="mf-fight-card__opponent flex items-center gap-3 flex-1 min-w-0">
                        <!-- Avatar -->
                        <a href="#opponent-3" class="mf-fight-card__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200">
                            <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aaron-Beltran.png" 
                                alt="Islam Makhachev" 
                                class="w-full h-full object-cover">
                        </a>
                        
                        <!-- Name + Record -->
                        <div class="flex-1 min-w-0">
                            <a href="#opponent-3" class="mf-fight-card__name block font-heading font-semibold text-base text-heading truncate hover:text-primary-500 transition-colors">
                                Islam Makhachev
                            </a>
                            <div class="mf-fight-card__record text-xs text-muted mt-0.5">
                                26-1-0
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Method Row -->
                <div class="mf-fight-card__method font-heading font-bold text-2xl text-heading mb-4">
                    SUB <span class="text-muted">•</span> R3
                </div>

                <!-- Meta Row -->
                <div class="mf-fight-card__meta flex flex-wrap items-center gap-3 mb-5">
                    <!-- Date -->
                    <div class="mf-fight-card__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>Jun 08, 2025</span>
                    </div>
                    
                    <!-- Organization Tag -->
                    <div class="mf-fight-card__meta-item">
                        <span class="mf-fight-card__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200">
                            UFC
                        </span>
                    </div>
                    
                    <!-- Location -->
                    <div class="mf-fight-card__meta-item flex items-center gap-1.5 text-sm text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Singapore</span>
                    </div>
                </div>

                <!-- Divider -->
                <div class="mf-fight-card__divider h-px bg-slate-200 mb-5"></div>

                <!-- Footer: CTA Button -->
                <div class="mf-fight-card__footer">
                    <a href="#fight-3" 
                        class="mf-fight-card__cta inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 text-white font-semibold text-sm">
                        <span>Pogledaj Detalje</span>
                        <svg class="mf-fight-card__cta-icon w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                </div>
            </article>

        </div>

    </div>
</section>
