<section class="how-it-works-section py-12 sm:py-16 lg:py-20 !bg-white">
    <div class="how-it-works-section__container max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- A) Header -->
        <div class="how-it-works-section__header">
            <h2 class="how-it-works-section__title text-3xl sm:text-4xl font-extrabold tracking-tight !text-slate-900">
                How the scoring works
            </h2>
            <p class="how-it-works-section__intro mt-3 max-w-3xl text-base sm:text-lg !text-slate-600 leading-relaxed">
                The MMA Future Score is a composite metric that evaluates a fighter's recent performance relative to competition quality. It is <strong class="font-semibold !text-slate-900">not a prediction tool</strong> — it's a retrospective measure of demonstrated ability.
            </p>
        </div>

        <!-- B) Flow diagram -->
        <div class="how-it-works-section__flow mt-10 grid grid-cols-1 lg:grid-cols-[1fr_auto_1fr_auto_1fr] gap-4 items-stretch">

            <!-- Card 1: Inputs -->
            <div class="how-it-works-section__flow-card how-it-works-section__flow-card--inputs !rounded-xl !border !border-slate-200/70 !bg-white p-5"
                 data-flow-step="inputs"
                 tabindex="-1">
                <h3 class="how-it-works-section__flow-card-title text-xs font-semibold uppercase tracking-wider !text-slate-400">Inputs</h3>
                <div class="how-it-works-section__flow-chiplist mt-3 flex flex-wrap gap-2">
                    <span class="how-it-works-section__flow-chip inline-flex items-center h-7 px-3 rounded-full !bg-slate-50 !border !border-slate-200/70 text-xs font-medium !text-slate-700">Opponent quality</span>
                    <span class="how-it-works-section__flow-chip inline-flex items-center h-7 px-3 rounded-full !bg-slate-50 !border !border-slate-200/70 text-xs font-medium !text-slate-700">Recency weighting</span>
                    <span class="how-it-works-section__flow-chip inline-flex items-center h-7 px-3 rounded-full !bg-slate-50 !border !border-slate-200/70 text-xs font-medium !text-slate-700">Finish type &amp; round</span>
                    <span class="how-it-works-section__flow-chip inline-flex items-center h-7 px-3 rounded-full !bg-slate-50 !border !border-slate-200/70 text-xs font-medium !text-slate-700">Activity frequency</span>
                    <span class="how-it-works-section__flow-chip inline-flex items-center h-7 px-3 rounded-full !bg-slate-50 !border !border-slate-200/70 text-xs font-medium !text-slate-700">Round dominance</span>
                    <span class="how-it-works-section__flow-chip inline-flex items-center h-7 px-3 rounded-full !bg-slate-50 !border !border-slate-200/70 text-xs font-medium !text-slate-700">Promotion strength</span>
                </div>
            </div>

            <!-- Arrow 1 -->
            <div class="how-it-works-section__flow-arrow hidden lg:flex items-center justify-center !text-slate-400"
                 data-flow-arrow="1">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
            </div>
            <div class="how-it-works-section__flow-arrow flex lg:hidden items-center justify-center !text-slate-400 py-1"
                 data-flow-arrow="1-mobile">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5 5m0 0l5-5m-5 5V6" />
                </svg>
            </div>

            <!-- Card 2: Adjustments -->
            <div class="how-it-works-section__flow-card how-it-works-section__flow-card--adjustments !rounded-xl !border !border-slate-200/70 !bg-white p-5"
                 data-flow-step="adjustments"
                 tabindex="-1">
                <h3 class="how-it-works-section__flow-card-title text-xs font-semibold uppercase tracking-wider !text-slate-400">Adjustments</h3>
                <p class="mt-3 text-sm !text-slate-600 leading-relaxed">
                    Recency decay, opponent calibration, activity modifiers, and promotion-depth factor are applied.
                </p>
            </div>

            <!-- Arrow 2 -->
            <div class="how-it-works-section__flow-arrow hidden lg:flex items-center justify-center !text-slate-400"
                 data-flow-arrow="2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
            </div>
            <div class="how-it-works-section__flow-arrow flex lg:hidden items-center justify-center !text-slate-400 py-1"
                 data-flow-arrow="2-mobile">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5 5m0 0l5-5m-5 5V6" />
                </svg>
            </div>

            <!-- Card 3: Final Score (highlighted) -->
            <div class="how-it-works-section__flow-card how-it-works-section__flow-card--final !rounded-xl !border-2 !border-[#0047A8]/70 p-5"
                 style="background: rgba(0,71,168,0.06);"
                 data-flow-step="final"
                 tabindex="-1">
                <h3 class="how-it-works-section__flow-card-title text-xs font-semibold uppercase tracking-wider !text-slate-400">Final Score</h3>
                <p class="how-it-works-section__final-score mt-3 text-4xl font-extrabold tracking-tight !text-slate-900 tabular-nums">0 – 100</p>
                <p class="mt-1 text-sm !text-slate-500">Per-fighter composite</p>
            </div>
        </div>

        <!-- C) Key Principles -->
        <div class="how-it-works-section__principles mt-8 mb-10 !rounded-xl !border !border-[#0047A8]/25 p-6" style="background: rgba(0,71,168,0.06);">
            <h3 class="how-it-works-section__principles-title text-xs font-semibold uppercase tracking-wider !text-slate-500 mb-2">Key Principles</h3>
            <ul class="how-it-works-section__principles-list mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <li class="how-it-works-section__principle flex items-start gap-3 text-sm !text-slate-700">
                    <svg class="how-it-works-section__principle-icon mt-0.5 h-5 w-5 !text-[#0047A8] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                    Recent fights matter more than older ones
                </li>
                <li class="how-it-works-section__principle flex items-start gap-3 text-sm !text-slate-700">
                    <svg class="how-it-works-section__principle-icon mt-0.5 h-5 w-5 !text-[#0047A8] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                    Quality of opponent is weighted heavily
                </li>
                <li class="how-it-works-section__principle flex items-start gap-3 text-sm !text-slate-700">
                    <svg class="how-it-works-section__principle-icon mt-0.5 h-5 w-5 !text-[#0047A8] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                    Finishes are scored higher than decisions
                </li>
                <li class="how-it-works-section__principle flex items-start gap-3 text-sm !text-slate-700">
                    <svg class="how-it-works-section__principle-icon mt-0.5 h-5 w-5 !text-[#0047A8] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                    Inactivity gradually reduces a fighter's score
                </li>
                <li class="how-it-works-section__principle flex items-start gap-3 text-sm !text-slate-700">
                    <svg class="how-it-works-section__principle-icon mt-0.5 h-5 w-5 !text-[#0047A8] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                    Consistency across fights reduces volatility
                </li>
                <li class="how-it-works-section__principle flex items-start gap-3 text-sm !text-slate-700">
                    <svg class="how-it-works-section__principle-icon mt-0.5 h-5 w-5 !text-[#0047A8] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                    Short-notice fights carry adjusted weighting
                </li>
            </ul>
        </div>

        <!-- D) Example Score Breakdown -->
        <div class="how-it-works-section__breakdown mt-10">
            <div class="how-it-works-section__breakdown-title flex flex-wrap items-center gap-3">
                <h3 class="text-xl font-bold !text-slate-900">Example Score Breakdown</h3>
                <span class="how-it-works-section__badge inline-flex items-center h-6 px-2.5 rounded-full !bg-slate-100 text-[11px] font-semibold uppercase tracking-wider !text-slate-500">Sample Data</span>
            </div>

            <div class="mt-6 space-y-3">

                <!-- Base performance points: +82 -->
                <div class="how-it-works-section__row grid grid-cols-1 sm:grid-cols-[180px_1fr_60px] gap-2 sm:gap-4 items-center">
                    <span class="how-it-works-section__row-label text-sm !text-slate-700">Base performance points</span>
                    <div class="how-it-works-section__bar h-3 rounded-full !bg-slate-100 overflow-hidden">
                        <div class="how-it-works-section__bar-fill h-full rounded-full !bg-[#0047A8]" style="width: 82%"></div>
                    </div>
                    <span class="how-it-works-section__delta text-sm font-semibold tabular-nums !text-slate-700 text-right">+82</span>
                </div>

                <!-- Finish bonus: +15 -->
                <div class="how-it-works-section__row grid grid-cols-1 sm:grid-cols-[180px_1fr_60px] gap-2 sm:gap-4 items-center">
                    <span class="how-it-works-section__row-label text-sm !text-slate-700">Finish bonus</span>
                    <div class="how-it-works-section__bar h-3 rounded-full !bg-slate-100 overflow-hidden">
                        <div class="how-it-works-section__bar-fill h-full rounded-full !bg-[#0047A8]" style="width: 15%"></div>
                    </div>
                    <span class="how-it-works-section__delta text-sm font-semibold tabular-nums !text-slate-700 text-right">+15</span>
                </div>

                <!-- Opponent differential: +12 -->
                <div class="how-it-works-section__row grid grid-cols-1 sm:grid-cols-[180px_1fr_60px] gap-2 sm:gap-4 items-center">
                    <span class="how-it-works-section__row-label text-sm !text-slate-700">Opponent differential</span>
                    <div class="how-it-works-section__bar h-3 rounded-full !bg-slate-100 overflow-hidden">
                        <div class="how-it-works-section__bar-fill h-full rounded-full !bg-[#0047A8]" style="width: 12%"></div>
                    </div>
                    <span class="how-it-works-section__delta text-sm font-semibold tabular-nums !text-slate-700 text-right">+12</span>
                </div>

                <!-- Recency multiplier: +8 -->
                <div class="how-it-works-section__row grid grid-cols-1 sm:grid-cols-[180px_1fr_60px] gap-2 sm:gap-4 items-center">
                    <span class="how-it-works-section__row-label text-sm !text-slate-700">Recency multiplier</span>
                    <div class="how-it-works-section__bar h-3 rounded-full !bg-slate-100 overflow-hidden">
                        <div class="how-it-works-section__bar-fill h-full rounded-full !bg-[#0047A8]" style="width: 8%"></div>
                    </div>
                    <span class="how-it-works-section__delta text-sm font-semibold tabular-nums !text-slate-700 text-right">+8</span>
                </div>

                <!-- Activity adjustment: -4 (negative / red) -->
                <div class="how-it-works-section__row grid grid-cols-1 sm:grid-cols-[180px_1fr_60px] gap-2 sm:gap-4 items-center">
                    <span class="how-it-works-section__row-label text-sm !text-slate-700">Activity adjustment</span>
                    <div class="how-it-works-section__bar h-3 rounded-full !bg-slate-100 overflow-hidden">
                        <div class="how-it-works-section__bar-fill h-full rounded-full !bg-red-600" style="width: 4%"></div>
                    </div>
                    <span class="how-it-works-section__delta text-sm font-semibold tabular-nums !text-red-600 text-right">-4</span>
                </div>

                <!-- Division depth factor: +3 -->
                <div class="how-it-works-section__row grid grid-cols-1 sm:grid-cols-[180px_1fr_60px] gap-2 sm:gap-4 items-center">
                    <span class="how-it-works-section__row-label text-sm !text-slate-700">Division depth factor</span>
                    <div class="how-it-works-section__bar h-3 rounded-full !bg-slate-100 overflow-hidden">
                        <div class="how-it-works-section__bar-fill h-full rounded-full !bg-[#0047A8]" style="width: 3%"></div>
                    </div>
                    <span class="how-it-works-section__delta text-sm font-semibold tabular-nums !text-slate-700 text-right">+3</span>
                </div>

                <!-- Quality penalty: -6 (negative / red) -->
                <div class="how-it-works-section__row grid grid-cols-1 sm:grid-cols-[180px_1fr_60px] gap-2 sm:gap-4 items-center">
                    <span class="how-it-works-section__row-label text-sm !text-slate-700">Quality penalty (weak schedule)</span>
                    <div class="how-it-works-section__bar h-3 rounded-full !bg-slate-100 overflow-hidden">
                        <div class="how-it-works-section__bar-fill h-full rounded-full !bg-red-600" style="width: 6%"></div>
                    </div>
                    <span class="how-it-works-section__delta text-sm font-semibold tabular-nums !text-red-600 text-right">-6</span>
                </div>
            </div>

            <!-- Final score summary strip -->
            <div class="how-it-works-section__finalline mt-8 pt-0">
                <div class="how-it-works-section__final-strip flex flex-wrap items-center justify-between gap-4 !rounded-xl !border !border-[#0047A8]/20 px-6 py-4" style="background: rgba(0,71,168,0.04);">
                    <div class="flex items-baseline gap-2">
                        <span class="text-sm font-semibold !text-slate-600">Final Score:</span>
                        <span class="how-it-works-section__finalvalue text-3xl font-extrabold !text-[#0047A8] tabular-nums">110</span>
                        <span class="text-sm !text-slate-400 font-medium">/ 100</span>
                    </div>
                    <span class="how-it-works-section__badge inline-flex items-center h-6 px-2.5 rounded-full !bg-[#0047A8]/10 text-[11px] font-semibold uppercase tracking-wider !text-[#0047A8]/70">Sample output</span>
                </div>
            </div>
        </div>

    </div>
</section>

<script>
(function () {
    var section = document.querySelector('.how-it-works-section');
    if (!section) return;

    var cards = section.querySelectorAll('[data-flow-step]');
    var arrows = section.querySelectorAll('[data-flow-arrow]');

    var nextMap = { inputs: 'adjustments', adjustments: 'final' };
    var arrowMap = { inputs: '1', adjustments: '2' };

    function clearAll() {
        cards.forEach(function (c) {
            c.classList.remove('is-active', 'is-next-active');
        });
        arrows.forEach(function (a) {
            a.classList.remove('is-arrow-active');
        });
    }

    cards.forEach(function (card) {
        card.addEventListener('mouseenter', function () {
            clearAll();
            var step = card.getAttribute('data-flow-step');
            card.classList.add('is-active');

            var nextStep = nextMap[step];
            if (nextStep) {
                var nextCard = section.querySelector('[data-flow-step="' + nextStep + '"]');
                if (nextCard) nextCard.classList.add('is-next-active');
            }

            var arrowId = arrowMap[step];
            if (arrowId) {
                arrows.forEach(function (a) {
                    var aid = a.getAttribute('data-flow-arrow');
                    if (aid === arrowId || aid === arrowId + '-mobile') {
                        a.classList.add('is-arrow-active');
                    }
                });
            }
        });

        card.addEventListener('mouseleave', function () {
            clearAll();
        });
    });
})();
</script>
