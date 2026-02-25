<!-- Full Fight History Section Styles -->
<style>
/* Section-scoped overrides to prevent global style conflicts */
.mf-fight-history {
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
    
    /* Reset button and select styles */
    button, select {
        background: transparent;
        border: none;
        cursor: pointer;
    }
    
    /* SVG sizing */
    svg {
        flex-shrink: 0;
    }
}

/* Filter Controls */
.mf-fight-history__select {
    appearance: none;
    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="%230047A8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>');
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 16px;
    padding-right: 2.5rem;
    border: 1.5px solid #CBD5E1 !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    transition: all 0.2s ease;
    
    &:hover {
        border-color: rgba(0, 71, 168, 0.4) !important;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
    }
    
    &:focus {
        outline: none !important;
        outline-offset: 0 !important;
        border-color: #CBD5E1 !important;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05) !important;
    }
}

/* Fight Item Base Styles */
.mf-fight-item {
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    
    /* Left edge accent - default neutral */
    &::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 5px;
        background: #94A3B8;
        transition: all 0.2s ease;
    }
    
    &:hover {
        border-color: rgba(0, 71, 168, 0.3);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        transform: translateX(2px);
    }
}

/* Fight Item State Modifiers */
.mf-fight-item--win {
    &::before {
        background: #16A34A;
    }
    
    .mf-fight-item__badge {
        background: rgba(22, 163, 74, 0.15);
        border-color: rgba(22, 163, 74, 0.4);
        color: #16A34A;
    }
}

.mf-fight-item--loss {
    &::before {
        background: #DC2626;
    }
    
    .mf-fight-item__badge {
        background: rgba(220, 38, 38, 0.15);
        border-color: rgba(220, 38, 38, 0.4);
        color: #DC2626;
    }
}

.mf-fight-item--draw {
    &::before {
        background: #F59E0B;
    }
    
    .mf-fight-item__badge {
        background: rgba(245, 158, 11, 0.15);
        border-color: rgba(245, 158, 11, 0.4);
        color: #F59E0B;
    }
}

.mf-fight-item--nc {
    &::before {
        background: #6B7280;
    }
    
    .mf-fight-item__badge {
        background: rgba(107, 114, 128, 0.15);
        border-color: rgba(107, 114, 128, 0.4);
        color: #6B7280;
    }
}

/* Opponent Avatar Hover */
.mf-fight-item__avatar {
    transition: all 0.2s ease;
    
    &:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.15);
    }
}

/* CTA Button Hover */
.mf-fight-item__cta {
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
    
    &:visited {
        color: #ffffff;
    }
}

/* CTA Icon Animation */
.mf-fight-item__cta:hover svg {
    transform: translateX(2px);
}

/* Organization Tag */
.mf-fight-item__org {
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Divider Line */
.mf-fight-item__divider {
    height: 1px;
    background: #E2E8F0;
    margin: 1rem 0;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .mf-fight-history__controls {
        flex-direction: column;
        gap: 0.75rem;
    }
    
    .mf-fight-history__control {
        width: 100%;
    }
    
    .mf-fight-history__select {
        width: 100%;
    }
    
    .mf-fight-item__header {
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    
    .mf-fight-item__meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .mf-fight-item__cta {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 640px) {
    .mf-fight-item__method {
        font-size: 1.25rem;
    }
}
</style>

<!-- Full Fight History Section -->
<section class="mf-fight-history bg-gradient-to-br from-slate-50 to-slate-100 py-12 lg:py-16">
    <div class="container mx-auto px-4">
        
        <!-- Section Title -->
        <h2 class="mf-fight-history__title font-heading font-bold text-xl md:text-2xl text-heading uppercase tracking-wide mb-6">
            Kompletna Istorija Borbi
        </h2>

        <!-- Filters Bar -->
        <div class="mf-fight-history__filters bg-white rounded-xl border border-slate-200 p-4 lg:p-5 mb-6 shadow-sm">
            
            <!-- Filters Label Row -->
            <div class="flex items-center justify-between gap-4 mb-4">
                <div class="mf-fight-history__filters-label flex items-center gap-2 text-sm font-semibold text-muted uppercase tracking-wide">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                    </svg>
                    <span>Filteri:</span>
                </div>
            </div>

            <!-- Filter Controls -->
            <div class="mf-fight-history__controls flex flex-wrap items-center gap-3">
                
                <!-- Outcome Filter -->
                <div class="mf-fight-history__control flex-1 min-w-[180px]">
                    <select class="mf-fight-history__select w-full px-4 py-2.5 bg-slate-50 border border-slate-300 rounded-lg text-sm text-body font-medium"
                            data-filter="outcome">
                        <option value="all">Svi Rezultati</option>
                        <option value="win">Pobede (WIN)</option>
                        <option value="loss">Porazi (LOSS)</option>
                        <option value="draw">Nerešeno (DRAW)</option>
                        <option value="nc">Bez Rezultata (NC)</option>
                    </select>
                </div>

                <!-- Method Filter -->
                <div class="mf-fight-history__control flex-1 min-w-[180px]">
                    <select class="mf-fight-history__select w-full px-4 py-2.5 bg-slate-50 border border-slate-300 rounded-lg text-sm text-body font-medium"
                            data-filter="method">
                        <option value="all">Svi Načini</option>
                        <option value="ko">KO / TKO</option>
                        <option value="sub">Submision</option>
                        <option value="dec">Odluka (DEC)</option>
                        <option value="nc">Bez Rezultata (NC)</option>
                    </select>
                </div>

                <!-- Sort Filter -->
                <div class="mf-fight-history__control flex-1 min-w-[180px]">
                    <select class="mf-fight-history__select w-full px-4 py-2.5 bg-slate-50 border border-slate-300 rounded-lg text-sm text-body font-medium"
                            data-filter="sort">
                        <option value="newest">Najnovije prvo</option>
                        <option value="oldest">Najstarije prvo</option>
                    </select>
                </div>

            </div>

        </div>

        <!-- Summary Line -->
        <div class="mf-fight-history__summary text-sm text-muted mb-4 px-1">
            Prikazano <span class="font-semibold text-heading">10</span> od <span class="font-semibold text-heading">12</span> borbi
        </div>

        <!-- Fight List -->
        <div class="mf-fight-history__list flex flex-col gap-4">

            <!-- Fight Item 1: WIN -->
            <article class="mf-fight-item mf-fight-item--win bg-white rounded-xl border border-slate-200 p-5 lg:p-6"
                data-fight-id="1"
                data-outcome="win"
                data-competition="ufc"
                data-year="2025"
                data-date="2025-12-15"
                data-location="Las Vegas, USA"
                data-method="KO/TKO"
                data-round="3"
                data-org="UFC"
                data-cta-url="#fight-1">
                
                <!-- Header: Badge + Opponent Info -->
                <div class="mf-fight-item__header flex items-start justify-between gap-4 mb-4">
                    <!-- Outcome Badge -->
                    <div class="mf-fight-item__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide">
                        WIN
                    </div>
                    
                    <!-- Opponent Block -->
                    <div class="mf-fight-item__opponent flex items-center gap-3 flex-1 min-w-0">
                        <!-- Avatar -->
                        <a href="#opponent-1" class="mf-fight-item__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200">
                            <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" 
                                alt="Conor McGregor" 
                                class="w-full h-full object-cover">
                        </a>
                        
                        <!-- Name + Record -->
                        <div class="flex-1 min-w-0">
                            <a href="#opponent-1" class="mf-fight-item__name block font-heading font-semibold text-base lg:text-lg text-heading truncate hover:text-primary-500 transition-colors">
                                Conor McGregor
                            </a>
                            <div class="mf-fight-item__record text-xs text-muted mt-0.5">
                                22-6-0
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Method Row -->
                <div class="mf-fight-item__method font-heading font-bold text-xl lg:text-2xl text-heading mb-3">
                    KO/TKO <span class="text-muted">•</span> R3
                </div>

                <!-- Meta Row -->
                <div class="mf-fight-item__meta flex flex-wrap items-center gap-3 mb-4">
                    <!-- Date -->
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>15 Dec 2025</span>
                    </div>
                    
                    <!-- Organization Tag -->
                    <div class="mf-fight-item__meta-item">
                        <span class="mf-fight-item__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200">
                            UFC
                        </span>
                    </div>
                    
                    <!-- Location -->
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Las Vegas, USA</span>
                    </div>
                </div>

                <!-- Divider -->
                <div class="mf-fight-item__divider"></div>

                <!-- Footer: CTA Button -->
                <div class="mf-fight-item__footer">
                    <a href="#fight-1" 
                        class="mf-fight-item__cta inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 text-white font-semibold text-sm">
                        <span>Pogledaj Borbu</span>
                        <svg class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                </div>
            </article>

            <!-- Fight Item 2: WIN -->
            <article class="mf-fight-item mf-fight-item--win bg-white rounded-xl border border-slate-200 p-5 lg:p-6"
                data-fight-id="2"
                data-outcome="win"
                data-competition="ufc"
                data-year="2025"
                data-date="2025-10-22"
                data-location="Abu Dhabi, UAE"
                data-method="SUB"
                data-round="2"
                data-org="UFC"
                data-cta-url="#fight-2">
                
                <div class="mf-fight-item__header flex items-start justify-between gap-4 mb-4">
                    <div class="mf-fight-item__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide">
                        WIN
                    </div>
                    
                    <div class="mf-fight-item__opponent flex items-center gap-3 flex-1 min-w-0">
                        <a href="#opponent-2" class="mf-fight-item__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200">
                            <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" 
                                alt="Dustin Poirier" 
                                class="w-full h-full object-cover">
                        </a>
                        
                        <div class="flex-1 min-w-0">
                            <a href="#opponent-2" class="mf-fight-item__name block font-heading font-semibold text-base lg:text-lg text-heading truncate hover:text-primary-500 transition-colors">
                                Dustin Poirier
                            </a>
                            <div class="mf-fight-item__record text-xs text-muted mt-0.5">
                                29-8-0
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mf-fight-item__method font-heading font-bold text-xl lg:text-2xl text-heading mb-3">
                    SUB <span class="text-muted">•</span> R2
                </div>

                <div class="mf-fight-item__meta flex flex-wrap items-center gap-3 mb-4">
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>22 Oct 2025</span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item">
                        <span class="mf-fight-item__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200">
                            UFC
                        </span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Abu Dhabi, UAE</span>
                    </div>
                </div>

                <div class="mf-fight-item__divider"></div>

                <div class="mf-fight-item__footer">
                    <a href="#fight-2" 
                        class="mf-fight-item__cta inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 text-white font-semibold text-sm">
                        <span>Gledaj Highlights</span>
                        <svg class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                </div>
            </article>

            <!-- Fight Item 3: LOSS -->
            <article class="mf-fight-item mf-fight-item--loss bg-white rounded-xl border border-slate-200 p-5 lg:p-6"
                data-fight-id="3"
                data-outcome="loss"
                data-competition="ufc"
                data-year="2025"
                data-date="2025-06-08"
                data-location="Singapore"
                data-method="SUB"
                data-round="3"
                data-org="UFC"
                data-cta-url="#fight-3">
                
                <div class="mf-fight-item__header flex items-start justify-between gap-4 mb-4">
                    <div class="mf-fight-item__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide">
                        LOSS
                    </div>
                    
                    <div class="mf-fight-item__opponent flex items-center gap-3 flex-1 min-w-0">
                        <a href="#opponent-3" class="mf-fight-item__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200">
                            <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aaron-Beltran.png" 
                                alt="Islam Makhachev" 
                                class="w-full h-full object-cover">
                        </a>
                        
                        <div class="flex-1 min-w-0">
                            <a href="#opponent-3" class="mf-fight-item__name block font-heading font-semibold text-base lg:text-lg text-heading truncate hover:text-primary-500 transition-colors">
                                Islam Makhachev
                            </a>
                            <div class="mf-fight-item__record text-xs text-muted mt-0.5">
                                26-1-0
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mf-fight-item__method font-heading font-bold text-xl lg:text-2xl text-heading mb-3">
                    SUB <span class="text-muted">•</span> R3
                </div>

                <div class="mf-fight-item__meta flex flex-wrap items-center gap-3 mb-4">
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>8 Jun 2025</span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item">
                        <span class="mf-fight-item__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200">
                            UFC
                        </span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Singapore</span>
                    </div>
                </div>

                <div class="mf-fight-item__divider"></div>

                <div class="mf-fight-item__footer">
                    <a href="#fight-3" 
                        class="mf-fight-item__cta inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 text-white font-semibold text-sm">
                        <span>Pogledaj Detalje</span>
                        <svg class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                </div>
            </article>

            <!-- Fight Item 4: DRAW -->
            <article class="mf-fight-item mf-fight-item--draw bg-white rounded-xl border border-slate-200 p-5 lg:p-6"
                data-fight-id="4"
                data-outcome="draw"
                data-competition="bellator"
                data-year="2024"
                data-date="2024-11-12"
                data-location="Dublin, Ireland"
                data-method="DEC"
                data-round="5"
                data-org="Bellator"
                data-cta-url="#fight-4">
                
                <div class="mf-fight-item__header flex items-start justify-between gap-4 mb-4">
                    <div class="mf-fight-item__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide">
                        DRAW
                    </div>
                    
                    <div class="mf-fight-item__opponent flex items-center gap-3 flex-1 min-w-0">
                        <a href="#opponent-4" class="mf-fight-item__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200">
                            <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" 
                                alt="Tony Ferguson" 
                                class="w-full h-full object-cover">
                        </a>
                        
                        <div class="flex-1 min-w-0">
                            <a href="#opponent-4" class="mf-fight-item__name block font-heading font-semibold text-base lg:text-lg text-heading truncate hover:text-primary-500 transition-colors">
                                Tony Ferguson
                            </a>
                            <div class="mf-fight-item__record text-xs text-muted mt-0.5">
                                25-9-0
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mf-fight-item__method font-heading font-bold text-xl lg:text-2xl text-heading mb-3">
                    DEC <span class="text-muted">•</span> R5
                </div>

                <div class="mf-fight-item__meta flex flex-wrap items-center gap-3 mb-4">
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>12 Nov 2024</span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item">
                        <span class="mf-fight-item__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200">
                            Bellator
                        </span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Dublin, Ireland</span>
                    </div>
                </div>

                <div class="mf-fight-item__divider"></div>

                <div class="mf-fight-item__footer">
                    <a href="#fight-4" 
                        class="mf-fight-item__cta inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 text-white font-semibold text-sm">
                        <span>Gledaj Highlights</span>
                        <svg class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                </div>
            </article>

            <!-- Fight Item 5: WIN (No CTA) -->
            <article class="mf-fight-item mf-fight-item--win bg-white rounded-xl border border-slate-200 p-5 lg:p-6"
                data-fight-id="5"
                data-outcome="win"
                data-competition="pfl"
                data-year="2024"
                data-date="2024-08-19"
                data-location="New York, USA"
                data-method="DEC"
                data-round="3"
                data-org="PFL"
                data-cta-url="">
                
                <div class="mf-fight-item__header flex items-start justify-between gap-4 mb-4">
                    <div class="mf-fight-item__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide">
                        WIN
                    </div>
                    
                    <div class="mf-fight-item__opponent flex items-center gap-3 flex-1 min-w-0">
                        <a href="#opponent-5" class="mf-fight-item__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200">
                            <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" 
                                alt="Michael Chandler" 
                                class="w-full h-full object-cover">
                        </a>
                        
                        <div class="flex-1 min-w-0">
                            <a href="#opponent-5" class="mf-fight-item__name block font-heading font-semibold text-base lg:text-lg text-heading truncate hover:text-primary-500 transition-colors">
                                Michael Chandler
                            </a>
                            <div class="mf-fight-item__record text-xs text-muted mt-0.5">
                                23-8-0
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mf-fight-item__method font-heading font-bold text-xl lg:text-2xl text-heading mb-3">
                    DEC <span class="text-muted">•</span> R3
                </div>

                <div class="mf-fight-item__meta flex flex-wrap items-center gap-3">
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>19 Aug 2024</span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item">
                        <span class="mf-fight-item__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200">
                            PFL
                        </span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>New York, USA</span>
                    </div>
                </div>

                <!-- Note: No CTA footer for this fight (no link available) -->
            </article>

            <!-- Fight Item 6: NC -->
            <article class="mf-fight-item mf-fight-item--nc bg-white rounded-xl border border-slate-200 p-5 lg:p-6"
                data-fight-id="6"
                data-outcome="nc"
                data-competition="ufc"
                data-year="2024"
                data-date="2024-05-14"
                data-location="Rio de Janeiro, Brazil"
                data-method="NC"
                data-round="1"
                data-org="UFC"
                data-cta-url="#fight-6">
                
                <div class="mf-fight-item__header flex items-start justify-between gap-4 mb-4">
                    <div class="mf-fight-item__badge inline-flex items-center justify-center px-3 py-1.5 rounded-lg border-2 font-heading font-bold text-sm uppercase tracking-wide">
                        NC
                    </div>
                    
                    <div class="mf-fight-item__opponent flex items-center gap-3 flex-1 min-w-0">
                        <a href="#opponent-6" class="mf-fight-item__avatar block flex-shrink-0 w-12 h-12 rounded-full overflow-hidden ring-2 ring-slate-200">
                            <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Aaron-Beltran.png" 
                                alt="Charles Oliveira" 
                                class="w-full h-full object-cover">
                        </a>
                        
                        <div class="flex-1 min-w-0">
                            <a href="#opponent-6" class="mf-fight-item__name block font-heading font-semibold text-base lg:text-lg text-heading truncate hover:text-primary-500 transition-colors">
                                Charles Oliveira
                            </a>
                            <div class="mf-fight-item__record text-xs text-muted mt-0.5">
                                34-10-0
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mf-fight-item__method font-heading font-bold text-xl lg:text-2xl text-heading mb-3">
                    NC <span class="text-muted">•</span> R1
                </div>

                <div class="mf-fight-item__meta flex flex-wrap items-center gap-3 mb-4">
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>14 May 2024</span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item">
                        <span class="mf-fight-item__org inline-flex items-center px-2.5 py-1 rounded-full bg-primary-50 text-primary-600 text-xs border border-primary-200">
                            UFC
                        </span>
                    </div>
                    
                    <div class="mf-fight-item__meta-item flex items-center gap-1.5 text-sm text-body">
                        <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Rio de Janeiro, Brazil</span>
                    </div>
                </div>

                <div class="mf-fight-item__divider"></div>

                <div class="mf-fight-item__footer">
                    <a href="#fight-6" 
                        class="mf-fight-item__cta inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary-500 text-white font-semibold text-sm">
                        <span>Pogledaj Detalje</span>
                        <svg class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                </div>
            </article>

        </div>

    </div>
</section>

<!-- Optional: Client-side filtering JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const filters = {
        outcome: document.querySelector('[data-filter="outcome"]'),
        method: document.querySelector('[data-filter="method"]'),
        sort: document.querySelector('[data-filter="sort"]')
    };
    
    const fightItems = document.querySelectorAll('.mf-fight-item');
    const summary = document.querySelector('.mf-fight-history__summary');
    
    function updateDisplay() {
        const outcomeValue = filters.outcome.value;
        const methodValue = filters.method.value;
        const sortValue = filters.sort.value;
        
        let visibleCount = 0;
        let itemsArray = Array.from(fightItems);
        
        // Filter items
        itemsArray.forEach(item => {
            const itemOutcome = item.dataset.outcome;
            const itemMethod = (item.dataset.method || '').toLowerCase();
            
            let visible = true;
            
            if (outcomeValue !== 'all' && itemOutcome !== outcomeValue) {
                visible = false;
            }
            
            if (methodValue !== 'all') {
                if (methodValue === 'ko' && itemMethod !== 'ko/tko') {
                    visible = false;
                } else if (methodValue === 'sub' && itemMethod !== 'sub') {
                    visible = false;
                } else if (methodValue === 'dec' && itemMethod !== 'dec') {
                    visible = false;
                } else if (methodValue === 'nc' && itemMethod !== 'nc') {
                    visible = false;
                }
            }
            
            if (visible) {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
        
        // Sort visible items
        const container = document.querySelector('.mf-fight-history__list');
        itemsArray = itemsArray.filter(item => item.style.display !== 'none');
        
        itemsArray.sort((a, b) => {
            const dateA = new Date(a.dataset.date);
            const dateB = new Date(b.dataset.date);
            
            if (sortValue === 'newest') {
                return dateB - dateA;
            } else {
                return dateA - dateB;
            }
        });
        
        // Reorder in DOM
        itemsArray.forEach(item => container.appendChild(item));
        
        // Update summary
        const totalCount = fightItems.length;
        summary.innerHTML = `Prikazano <span class="font-semibold text-heading">${visibleCount}</span> od <span class="font-semibold text-heading">${totalCount}</span> borbi`;
    }
    
    // Attach event listeners
    Object.values(filters).forEach(filter => {
        if (filter) {
            filter.addEventListener('change', updateDisplay);
        }
    });
    
    // Initial display update
    updateDisplay();
});
</script>