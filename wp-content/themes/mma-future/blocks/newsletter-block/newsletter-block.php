<section id="newsletter" class="py-20 sm:py-24 bg-white">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="js-newsletter-panel relative bg-gradient-to-br from-white via-white to-slate-50/80 rounded-3xl ring-1 ring-black/5 shadow-sm p-8 sm:p-12 overflow-hidden group/spotlight">
            <!-- Inner highlight (top-left soft white blob) -->
            <div class="absolute top-[-60px] left-[-60px] w-[200px] h-[200px] bg-white/40 blur-3xl rounded-full pointer-events-none z-0"></div>
            
            <!-- Glow blobs behind content -->
            <div class="absolute bottom-[-120px] left-1/2 -translate-x-1/2 w-[560px] h-[280px] bg-[rgba(var(--brand-rgb),0.10)] blur-3xl rounded-full pointer-events-none z-0"></div>
            <div class="absolute bottom-[-80px] right-[-100px] w-[420px] h-[240px] bg-[rgba(var(--brand-rgb),0.07)] blur-3xl rounded-full pointer-events-none z-0"></div>

            <!-- Spotlight cursor-follow overlay (desktop only, respects reduced-motion) -->
            <div 
                class="newsletter-spotlight pointer-events-none absolute inset-0 z-[1] opacity-0 transition-opacity duration-300 group-hover/spotlight:opacity-100"
                style="background: radial-gradient(600px circle at var(--mx, 50%) var(--my, 50%), rgba(var(--brand-rgb), 0.12), rgba(var(--brand-rgb), 0.05) 40%, transparent 70%);"
                aria-hidden="true"
            ></div>
            
            <div class="relative z-10 text-center">
                <h2 class="text-[38px] sm:text-4xl font-extrabold text-slate-900 leading-tight mb-4">
                    Get weekly ranking updates
                </h2>
                <p class="text-slate-600 text-base sm:text-lg max-w-2xl mx-auto mt-3 font-normal">
                    Join our newsletter for concise updates on the biggest movers, methodology notes, and new rankings.
                </p>

                <!-- Trust pills -->
                <div class="flex flex-wrap items-center justify-center gap-3 mt-5">
                    <span class="inline-flex items-center gap-1.5 text-sm font-medium px-3 py-1 rounded-full bg-white/70 ring-1 ring-black/5 text-slate-600">
                        <span class="w-1 h-1 rounded-full bg-[var(--brand)]"></span>
                        Weekly digest
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-sm font-medium px-3 py-1 rounded-full bg-white/70 ring-1 ring-black/5 text-slate-600">
                        <span class="w-1 h-1 rounded-full bg-[var(--brand)]"></span>
                        No spam
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-sm font-medium px-3 py-1 rounded-full bg-white/70 ring-1 ring-black/5 text-slate-600">
                        <span class="w-1 h-1 rounded-full bg-[var(--brand)]"></span>
                        Unsubscribe anytime
                    </span>
                </div>

                <!-- Unified Form Control -->
                <form class="mx-auto mt-8 max-w-xl rounded-2xl p-1 transition-all duration-200 focus-within:ring-2 focus-within:ring-[rgba(var(--brand-rgb),0.35)] focus-within:ring-offset-2">
                    <div class="flex flex-col sm:flex-row items-stretch gap-2">
                        <div class="flex-1 min-w-0">
                            <label for="email-address" class="sr-only">Email address</label>
                            <input 
                                id="email-address"
                                name="email"
                                type="email" 
                                required 
                                autocomplete="email" 
                                placeholder="Enter your email"
                                class="w-full h-12 pl-[12px] pr-4 bg-white rounded-[8px] border-0 ring-0 shadow-none outline-none text-slate-900 placeholder:text-slate-400"
                            >
                        </div>
                        <button 
                            type="submit"
                            class="h-12 px-6 rounded-[8px] font-semibold bg-[var(--brand)] text-white shadow-sm hover:bg-[var(--brand-hover)] hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[rgba(var(--brand-rgb),0.45)] focus-visible:ring-offset-2 visited:text-white mb-3"
                        >
                            Subscribe
                        </button>
                    </div>
                </form>
                
                <p class="mt-3 text-sm text-slate-500 text-center font-normal">
                    No spam. Unsubscribe anytime.
                </p>
            </div>
        </div>
    </div>
</section>
