<!-- Fighter Hero Section -->
<style>
/* ============================================
   MMA Future - Fighter Hero Section Styles
   Scoped under .mf-hero only
   ============================================ */

/* Opp. Score Badge - Premium Stat Pill */
.mf-hero .opp-score-badge {
    position: relative;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(59, 130, 246, 0.08));
    border: 1.5px solid rgba(59, 130, 246, 0.40);
    box-shadow: 
        0 0 0 1px rgba(59, 130, 246, 0.20) inset,
        0 0 0 1px rgba(59, 130, 246, 0.15),
        0 6px 20px rgba(59, 130, 246, 0.12),
        0 2px 8px rgba(0, 0, 0, 0.15);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.mf-hero .opp-score-badge::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.10), transparent);
    border-radius: inherit;
    pointer-events: none;
}

.mf-hero .opp-score-badge:hover {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.18), rgba(59, 130, 246, 0.12));
    border-color: rgba(59, 130, 246, 0.55);
    box-shadow: 
        0 0 0 1px rgba(59, 130, 246, 0.30) inset,
        0 0 0 1px rgba(59, 130, 246, 0.25),
        0 8px 28px rgba(59, 130, 246, 0.20),
        0 4px 12px rgba(0, 0, 0, 0.20);
    color: rgba(255, 255, 255, 0.98);
}

.mf-hero .opp-score-badge:focus {
    outline: none;
}

@media (prefers-reduced-motion: reduce) {
    .mf-hero .opp-score-badge {
        transition: none;
    }
}

/* Glassmorphism Info Bubble - Scoped */
.mf-hero .mf-hero-bubble {
    max-width: 100%;
}

@media (min-width: 1024px) {
    .mf-hero .mf-hero-bubble {
        max-width: 340px;
        align-self: center;
    }
}

.mf-hero .mf-hero-bubble__card {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.05));
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.15);
    box-shadow: 
        0 0 0 1px rgba(255, 255, 255, 0.08) inset,
        0 8px 32px rgba(0, 0, 0, 0.25),
        0 1px 0 rgba(255, 255, 255, 0.10) inset;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.mf-hero .mf-hero-bubble__card:hover {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.10), rgba(255, 255, 255, 0.06));
    border-color: rgba(59, 130, 246, 0.30);
    box-shadow: 
        0 0 0 1px rgba(255, 255, 255, 0.12) inset,
        0 12px 48px rgba(0, 0, 0, 0.30),
        0 0 0 1px rgba(59, 130, 246, 0.20),
        0 1px 0 rgba(255, 255, 255, 0.15) inset;
}

.mf-hero .mf-hero-bubble__badge {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 0.75rem;
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: rgba(59, 130, 246, 0.98);
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(59, 130, 246, 0.10));
    border: 1px solid rgba(59, 130, 246, 0.30);
    border-radius: 9999px;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.12);
}

.mf-hero .mf-hero-bubble__title {
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1.4;
    color: rgba(255, 255, 255, 0.98);
    margin: 0;
}

.mf-hero .mf-hero-bubble__text {
    font-size: 0.875rem;
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.85);
}

.mf-hero .mf-hero-bubble__text--muted {
    color: rgba(255, 255, 255, 0.70);
}

.mf-hero .mf-hero-bubble__cta {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
    padding: 0.625rem 1.25rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: white;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.18), rgba(59, 130, 246, 0.12));
    border: 1px solid rgba(59, 130, 246, 0.40);
    border-radius: 0.5rem;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    text-decoration: none;
    outline: none;
}

.mf-hero .mf-hero-bubble__cta:hover,
.mf-hero .mf-hero-bubble__cta:active {
    color: white;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.28), rgba(59, 130, 246, 0.20));
    border-color: rgba(59, 130, 246, 0.55);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.25);
    transform: translateY(-1px);
}

.mf-hero .mf-hero-bubble__toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    white-space: nowrap;
    padding: 0.625rem 1.25rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.75);
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    outline: none;
}

.mf-hero .mf-hero-bubble__toggle:hover {
    color: rgba(255, 255, 255, 0.95);
    background: rgba(255, 255, 255, 0.10);
    border-color: rgba(255, 255, 255, 0.20);
}

.mf-hero .mf-hero-bubble__toggle svg {
    width: 1rem;
    height: 1rem;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.mf-hero .mf-hero-bubble__toggle[aria-expanded="true"] svg {
    transform: rotate(180deg);
}

.mf-hero .mf-hero-bubble__more {
    max-height: 0;
    overflow: hidden;
    opacity: 0;
    transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                margin-top 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.mf-hero .mf-hero-bubble__more.is-expanded {
    max-height: 200px;
    opacity: 1;
    margin-top: 0.75rem;
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .mf-hero .mf-hero-bubble__card,
    .mf-hero .mf-hero-bubble__cta,
    .mf-hero .mf-hero-bubble__toggle,
    .mf-hero .mf-hero-bubble__toggle svg,
    .mf-hero .mf-hero-bubble__more {
        transition: none;
    }
    
    .mf-hero .mf-hero-bubble__cta:hover,
    .mf-hero .mf-hero-bubble__cta:active {
        transform: none;
    }
}
</style>
<section class="mf-hero fighter-hero-section relative min-h-[75vh] flex items-center py-12 lg:py-20 overflow-hidden">
    <!-- Parallax Background -->
    <div class="absolute inset-0 w-full h-full z-0"
        style="background-image: url('http://mma-future-dev.tldteam.com/wp-content/uploads/2026/02/background-image-scaled.png'); background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed; transform: translateZ(0); will-change: transform;">
    </div>

    <!-- Dark Overlay -->
    <div class="absolute inset-0 bg-gradient-to-br from-black/70 via-black/60 to-black/70 z-0"></div>

    <!-- Content Container -->
    <div class="container mx-auto px-4 xl:px-6 relative z-10">
        <div class="grid grid-cols-1 lg:grid-cols-[auto_1fr_auto] gap-8 lg:gap-8 xl:gap-12 2xl:gap-16 items-start lg:items-center">

            <!-- Left Column: Fighter Portrait -->
            <div class="flex justify-center lg:justify-start lg:w-56 xl:w-72 2xl:w-80">
                <div class="group relative w-full max-w-xs lg:max-w-none aspect-square cursor-pointer">
                    <!-- Multi-layer border frame -->
                    <div
                        class="absolute inset-0 rounded-2xl bg-gradient-to-br from-primary-500/30 to-accent/30 group-hover:from-primary-400/50 group-hover:to-accent/50 p-1 transition-all duration-500 group-hover:shadow-lg group-hover:shadow-primary-500/30">
                        <div
                            class="w-full h-full rounded-2xl bg-gradient-to-br from-secondary-900/90 to-secondary-800/90 p-2">
                            <div
                                class="w-full h-full rounded-xl overflow-hidden shadow-fighter group-hover:shadow-xl transition-shadow duration-500">
                                <img src="http://mma-future-dev.tldteam.com/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg"
                                    alt="Fighter portrait"
                                    class="w-full h-full object-cover transition-all duration-500 group-hover:scale-110 group-hover:brightness-110" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Fighter Info -->
            <div class="space-y-6 lg:space-y-5 xl:space-y-6 2xl:space-y-8 text-center lg:text-left">

                <!-- Fighter Name -->
                <h1 class="font-heading font-black text-3xl xs:text-4xl md:text-5xl lg:text-4xl xl:text-5xl 2xl:text-6xl text-white leading-tight tracking-tight"
                    style="font-weight: 950;">
                    Abdulgadzhi Gaziev
                </h1>

                <!-- Meta Information Row -->
                <div class="flex flex-wrap items-center gap-3 xs:gap-4 md:gap-6 lg:gap-4 xl:gap-6 justify-center lg:justify-start text-white/90">
                    <!-- Nationality -->
                    <div class="flex items-center gap-1.5 xs:gap-2">
                        <svg class="w-4 xs:w-5 h-4 xs:h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9">
                            </path>
                        </svg>
                        <span class="text-xs xs:text-sm md:text-base lg:text-sm xl:text-base font-medium">Russia</span>
                    </div>

                    <!-- Height -->
                    <div class="flex items-center gap-1.5 xs:gap-2">
                        <svg class="w-4 xs:w-5 h-4 xs:h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z">
                            </path>
                        </svg>
                        <span class="text-xs xs:text-sm md:text-base lg:text-sm xl:text-base font-medium">6'2" / 188 cm</span>
                    </div>

                    <!-- Date of Birth -->
                    <div class="flex items-center gap-1.5 xs:gap-2">
                        <svg class="w-4 xs:w-5 h-4 xs:h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z">
                            </path>
                        </svg>
                        <span class="text-xs xs:text-sm md:text-base lg:text-sm xl:text-base font-medium">May 15, 1992</span>
                    </div>
                </div>

                <!-- Category Tags -->
                <div class="flex flex-wrap gap-2 justify-center lg:justify-start">
                    <span
                        class="inline-flex items-center px-3 xs:px-4 py-1.5 xs:py-2 rounded-full bg-primary-500/20 border border-primary-400/30 text-primary-300 text-xs xs:text-sm font-semibold uppercase tracking-wide backdrop-blur-sm">
                        Lightweight
                    </span>
                    <span
                        class="inline-flex items-center px-3 xs:px-4 py-1.5 xs:py-2 rounded-full bg-accent/20 border border-accent/30 text-white text-xs xs:text-sm font-semibold uppercase tracking-wide backdrop-blur-sm">
                        Top 15
                    </span>
                    <span
                        class="inline-flex items-center px-3 xs:px-4 py-1.5 xs:py-2 rounded-full bg-white/10 border border-white/20 text-white/90 text-xs xs:text-sm font-semibold uppercase tracking-wide backdrop-blur-sm">
                        Active
                    </span>
                </div>

                <!-- Stats Row -->
                <div class="grid grid-cols-[1.8fr_1.3fr_1.4fr] gap-6 sm:gap-8 md:gap-10 lg:gap-6 xl:gap-8 2xl:gap-12 max-w-full 2xl:max-w-[85%]">

                    <!-- Record (W-L) - Largest -->
                    <div class="text-center lg:text-left">
                        <div
                            class="text-[0.65rem] xs:text-xs md:text-sm text-white/60 font-medium uppercase tracking-wide mb-2 xs:mb-3 flex items-center gap-1 xs:gap-1.5 justify-center lg:justify-start">
                            Record
                            <!-- Tooltip -->
                            <div class="group relative inline-flex">
                                <div class="tooltip-trigger inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/5 hover:bg-white/10 border border-white/20 transition-all duration-200 cursor-pointer"
                                    role="img" aria-label="Record information">
                                    <svg class="w-3.5 h-3.5 text-white/60" width="16" height="16" fill="currentColor"
                                        viewBox="0 0 20 20" aria-hidden="true">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 bg-black/50 text-white/90 text-xs rounded-lg shadow-2xl border border-white/10 whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible group-focus:opacity-100 group-focus:visible transition-all duration-200 pointer-events-none z-50"
                                    style="backdrop-filter: blur(12px);">
                                    Ukupan broj pobeda i poraza u karijeri
                                    <div class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 w-2 h-2 bg-black/50 border-r border-b border-white/10 rotate-45"
                                        style="backdrop-filter: blur(12px);"></div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="text-3xl xs:text-4xl md:text-5xl lg:text-3xl xl:text-4xl 2xl:text-6xl font-heading font-black text-white tracking-tight leading-none mt-3">
                            37–10</div>
                    </div>

                    <!-- Finishes - Medium -->
                    <div class="text-center lg:text-left">
                        <div
                            class="text-[0.65rem] xs:text-xs md:text-sm text-white/60 font-medium uppercase tracking-wide mb-2 xs:mb-3 flex items-center gap-1 xs:gap-1.5 justify-center lg:justify-start">
                            Finishes
                            <!-- Tooltip -->
                            <div class="group relative inline-flex">
                                <div class="tooltip-trigger inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/5 hover:bg-white/10 border border-white/20 transition-all duration-200 cursor-pointer"
                                    role="img" aria-label="Finishes information">
                                    <svg class="w-3.5 h-3.5 text-white/60" width="16" height="16" fill="currentColor"
                                        viewBox="0 0 20 20" aria-hidden="true">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 bg-black/50 text-white/90 text-xs rounded-lg shadow-2xl border border-white/10 whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible group-focus:opacity-100 group-focus:visible transition-all duration-200 pointer-events-none z-50"
                                    style="backdrop-filter: blur(12px);">
                                    Broj borbi završenih pre vremenske granice (KO, TKO, submission)
                                    <div class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 w-2 h-2 bg-black/50 border-r border-b border-white/10 rotate-45"
                                        style="backdrop-filter: blur(12px);"></div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="text-2xl xs:text-3xl md:text-4xl lg:text-2xl xl:text-3xl 2xl:text-5xl font-heading font-bold text-white tracking-tight leading-none mt-3">
                            24</div>
                    </div>

                    <!-- Opponent Strength Score - Highlighted -->
                    <div class="text-center lg:text-left">
                        <div
                            class="text-[0.65rem] xs:text-xs md:text-sm text-white/60 font-medium uppercase tracking-wide mb-2 xs:mb-3 flex items-center gap-1 xs:gap-1.5 justify-center lg:justify-start">
                            Opp. Score
                            <!-- Tooltip -->
                            <div class="group relative inline-flex">
                                <div class="tooltip-trigger inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/5 hover:bg-white/10 border border-white/20 transition-all duration-200 cursor-pointer"
                                    role="img" aria-label="Opponent Strength Score information">
                                    <svg class="w-3.5 h-3.5 text-white/60" width="16" height="16" fill="currentColor"
                                        viewBox="0 0 20 20" aria-hidden="true">
                                        <path fill-rule="evenodd"
                                            d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 bg-black/50 text-white/90 text-xs rounded-lg shadow-2xl border border-white/10 whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible group-focus:opacity-100 group-focus:visible transition-all duration-200 pointer-events-none z-50"
                                    style="backdrop-filter: blur(12px);">
                                    Agregatna ocena snage protivnika sa kojima se borac suočio
                                    <div class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 w-2 h-2 bg-black/50 border-r border-b border-white/10 rotate-45"
                                        style="backdrop-filter: blur(12px);"></div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="text-2xl xs:text-3xl md:text-4xl lg:text-2xl xl:text-3xl 2xl:text-5xl font-heading font-bold tracking-tight leading-none mt-4">
                            <span
                                class="opp-score-badge inline-block px-3 xs:px-4 py-1.5 rounded-lg text-white/90 cursor-pointer transition-all duration-300 text-[0.9em]"
                                tabindex="0"
                                role="button"
                                aria-label="Opponent Strength Score: 87.4">87.4</span>
                        </div>
                    </div>

                </div>

                <!-- Social Icons Row -->
                <div class="space-y-3 mt-8 sm:mt-10 lg:mt-12 xl:mt-14">
                    <div
                        class="text-xs md:text-sm text-white/70 font-medium uppercase tracking-wide text-center lg:text-left mb-5">
                        Follow Abdulgadzhi Gaziev on:
                    </div>
                    <div class="flex items-center gap-3 justify-center lg:justify-start">
                        <!-- Instagram -->
                        <a href="#"
                            class="group flex items-center justify-center w-10 h-10 rounded-full bg-white/5 hover:bg-white/15 border border-white/10 hover:border-white/30 transition-all duration-300 hover:scale-110"
                            aria-label="Follow on Instagram" data-social="instagram">
                            <svg class="w-4 h-4 transition-all duration-300 group-hover:scale-110" fill="none"
                                stroke="rgba(255, 255, 255, 0.6)" viewBox="0 0 24 24" stroke-width="1.5"
                                aria-hidden="true">
                                <rect x="2" y="2" width="20" height="20" rx="5" ry="5"
                                    class="group-hover:stroke-white transition-all duration-300" />
                                <path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"
                                    class="group-hover:stroke-white transition-all duration-300" />
                                <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" stroke-linecap="round"
                                    class="group-hover:stroke-white transition-all duration-300" />
                            </svg>
                        </a>

                        <!-- Twitter/X -->
                        <a href="#"
                            class="group flex items-center justify-center w-10 h-10 rounded-full bg-white/5 hover:bg-white/15 border border-white/10 hover:border-white/30 transition-all duration-300 hover:scale-110"
                            aria-label="Follow on Twitter" data-social="twitter">
                            <svg class="w-4 h-4 transition-all duration-300 group-hover:scale-110"
                                fill="rgba(255, 255, 255, 0.6)" viewBox="0 0 24 24" aria-hidden="true">
                                <path
                                    d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"
                                    class="group-hover:fill-white transition-all duration-300" />
                            </svg>
                        </a>

                        <!-- Facebook -->
                        <a href="#"
                            class="group flex items-center justify-center w-10 h-10 rounded-full bg-white/5 hover:bg-white/15 border border-white/10 hover:border-white/30 transition-all duration-300 hover:scale-110"
                            aria-label="Follow on Facebook" data-social="facebook">
                            <svg class="w-4 h-4 transition-all duration-300 group-hover:scale-110"
                                fill="rgba(255, 255, 255, 0.6)" viewBox="0 0 24 24" aria-hidden="true">
                                <path
                                    d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"
                                    class="group-hover:fill-white transition-all duration-300" />
                            </svg>
                        </a>

                    </div>
                </div>

            </div>

            <!-- Right Column: Methodology Bubble -->
            <aside class="mf-hero-bubble w-full lg:w-auto">
                <div class="mf-hero-bubble__card rounded-2xl p-5 lg:p-6 space-y-3 lg:space-y-4">
                    
                    <!-- Header -->
                    <div class="mf-hero-bubble__header space-y-3">
                        <span class="mf-hero-bubble__badge">MMA Future</span>
                        <h3 class="mf-hero-bubble__title">How do we calculate Opp. Score?</h3>
                    </div>

                    <!-- Description -->
                    <p class="mf-hero-bubble__text">
                        Opponent Score represents the aggregate strength of all opponents a fighter has faced, weighted by recency and result impact.
                    </p>

                    <!-- Expandable More Info -->
                    <div class="mf-hero-bubble__more" data-expandable>
                        <p class="mf-hero-bubble__text mf-hero-bubble__text--muted">
                            Our algorithm considers each opponent's ranking, win rate, and finish percentage at the time of the fight, giving you insight into the quality of competition.
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="mf-hero-bubble__actions flex items-center gap-3">
                        <a href="#" class="mf-hero-bubble__cta">
                            Read more
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                            </svg>
                        </a>
                        <button 
                            type="button"
                            class="mf-hero-bubble__toggle"
                            aria-expanded="false"
                            aria-controls="mfHeroBubbleMore">
                            Learn more
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                    </div>

                </div>
            </aside>

        </div>
    </div>

</section>

<script>
(function() {
    'use strict';
    
    // Scope all queries to the hero section
    const heroSection = document.querySelector('.mf-hero');
    if (!heroSection) return;
    
    const toggleBtn = heroSection.querySelector('.mf-hero-bubble__toggle');
    const moreContent = heroSection.querySelector('.mf-hero-bubble__more');
    
    if (!toggleBtn || !moreContent) return;
    
    // Initialize collapsed state
    moreContent.setAttribute('id', 'mfHeroBubbleMore');
    moreContent.setAttribute('aria-hidden', 'true');
    
    toggleBtn.addEventListener('click', function() {
        const isExpanded = this.getAttribute('aria-expanded') === 'true';
        const newState = !isExpanded;
        
        // Update button state
        this.setAttribute('aria-expanded', newState);
        
        // Update content visibility with animation
        if (newState) {
            // Expanding
            moreContent.classList.add('is-expanded');
            moreContent.setAttribute('aria-hidden', 'false');
            
            // Make content focusable
            const focusableElements = moreContent.querySelectorAll('a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
            focusableElements.forEach(el => el.removeAttribute('tabindex'));
        } else {
            // Collapsing
            moreContent.classList.remove('is-expanded');
            moreContent.setAttribute('aria-hidden', 'true');
            
            // Make content not focusable
            const focusableElements = moreContent.querySelectorAll('a, button, input, select, textarea');
            focusableElements.forEach(el => el.setAttribute('tabindex', '-1'));
        }
    });
    
    // Keyboard accessibility for opp-score-badge
    const oppScoreBadge = heroSection.querySelector('.opp-score-badge');
    if (oppScoreBadge) {
        oppScoreBadge.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                // Optional: Add click behavior if needed
            }
        });
    }
})();
</script>