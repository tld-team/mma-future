<!-- Fighter Hero Section -->
<style>
    .opp-score-badge:hover {
        background-color: rgba(59, 130, 246, 0.15) !important;
        border-color: rgba(59, 130, 246, 0.45) !important;
        box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.25), 0 8px 32px rgba(59, 130, 246, 0.18) !important;
        color: rgba(255, 255, 255, 0.95) !important;
    }
</style>
<section class="fighter-hero-section relative min-h-[75vh] flex items-center py-12 lg:py-20 overflow-hidden">
    <!-- Parallax Background -->
    <div class="absolute inset-0 w-full h-full z-0"
        style="background-image: url('http://mma-future-dev.tldteam.com/wp-content/uploads/2026/02/background-image-scaled.png'); background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed; transform: translateZ(0); will-change: transform;">
    </div>

    <!-- Dark Overlay -->
    <div class="absolute inset-0 bg-gradient-to-br from-black/70 via-black/60 to-black/70 z-0"></div>

    <!-- Content Container -->
    <div class="container mx-auto px-4 relative z-10">
        <div class="grid grid-cols-1 lg:grid-cols-[auto_1fr] gap-8 lg:gap-12 xl:gap-16 items-center">

            <!-- Left Column: Fighter Portrait -->
            <div class="flex justify-center lg:justify-start lg:w-80">
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
            <div class="space-y-6 lg:space-y-8 text-center lg:text-left">

                <!-- Fighter Name -->
                <h1 class="font-heading font-black text-4xl md:text-5xl xl:text-6xl text-white leading-tight tracking-tight"
                    style="font-weight: 950;">
                    Abdulgadzhi Gaziev
                </h1>

                <!-- Meta Information Row -->
                <div class="flex flex-wrap items-center gap-4 md:gap-6 justify-center lg:justify-start text-white/90">
                    <!-- Nationality -->
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9">
                            </path>
                        </svg>
                        <span class="text-sm md:text-base font-medium">Russia</span>
                    </div>

                    <!-- Height -->
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z">
                            </path>
                        </svg>
                        <span class="text-sm md:text-base font-medium">6'2" / 188 cm</span>
                    </div>

                    <!-- Date of Birth -->
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z">
                            </path>
                        </svg>
                        <span class="text-sm md:text-base font-medium">May 15, 1992</span>
                    </div>
                </div>

                <!-- Category Tags -->
                <div class="flex flex-wrap gap-2 justify-center lg:justify-start">
                    <span
                        class="inline-flex items-center px-4 py-2 rounded-full bg-primary-500/20 border border-primary-400/30 text-primary-300 text-sm font-semibold uppercase tracking-wide backdrop-blur-sm">
                        Lightweight
                    </span>
                    <span
                        class="inline-flex items-center px-4 py-2 rounded-full bg-accent/20 border border-accent/30 text-white text-sm font-semibold uppercase tracking-wide backdrop-blur-sm">
                        Top 15
                    </span>
                    <span
                        class="inline-flex items-center px-4 py-2 rounded-full bg-white/10 border border-white/20 text-white/90 text-sm font-semibold uppercase tracking-wide backdrop-blur-sm">
                        Active
                    </span>
                </div>

                <!-- Stats Row -->
                <div class="grid grid-cols-3 gap-6 md:gap-8 max-w-full lg:max-w-[65%]">

                    <!-- Record (W-L) - Largest -->
                    <div class="text-center lg:text-left">
                        <div
                            class="text-xs md:text-sm text-white/60 font-medium uppercase tracking-wide mb-3 flex items-center gap-1.5 justify-center lg:justify-start">
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
                            class="text-4xl md:text-5xl xl:text-6xl font-heading font-black text-white tracking-tight leading-none mt-3">
                            37–10</div>
                    </div>

                    <!-- Finishes - Medium -->
                    <div class="text-center lg:text-left">
                        <div
                            class="text-xs md:text-sm text-white/60 font-medium uppercase tracking-wide mb-3 flex items-center gap-1.5 justify-center lg:justify-start">
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
                            class="text-3xl md:text-4xl xl:text-5xl font-heading font-bold text-white tracking-tight leading-none mt-3">
                            24</div>
                    </div>

                    <!-- Opponent Strength Score - Highlighted -->
                    <div class="text-center lg:text-left">
                        <div
                            class="text-xs md:text-sm text-white/60 font-medium uppercase tracking-wide mb-3 flex items-center gap-1.5 justify-center lg:justify-start">
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
                            class="text-3xl md:text-4xl xl:text-5xl font-heading font-bold tracking-tight leading-none mt-4">
                            <span
                                class="opp-score-badge inline-block px-3 py-1 rounded-lg text-white/90 cursor-pointer transition-all duration-300"
                                style="background-color: rgba(59,130,246,0.10); border: 1px solid rgba(59,130,246,0.35); box-shadow: 0 0 0 1px rgba(59,130,246,0.18), 0 8px 24px rgba(59,130,246,0.10);">87.4</span>
                        </div>
                    </div>

                </div>

                <!-- Social Icons Row -->
                <div class="space-y-3 mt-20">
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

        </div>
    </div>

</section>