<section class="contact-main-section py-10 sm:py-14">
    <div class="contact-main-section__container max-w-6xl mx-auto px-2">

        <!-- Top header -->
        <div class="contact-main-section__top">
            <a href="/blog" class="contact-main-section__back inline-flex items-center gap-2 text-sm !text-slate-500 hover:!text-slate-700 transition-colors focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                Back to blog
            </a>

            <h1 class="contact-main-section__title mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight !text-slate-900">
                Contact
            </h1>

            <p class="contact-main-section__subtitle mt-4 max-w-2xl text-base sm:text-lg !text-slate-600 leading-relaxed">
                Have feedback on the rankings, a correction to suggest, or an idea for collaboration? Send a message — we read everything.
            </p>

            <p class="contact-main-section__note mt-2 text-sm !text-slate-500">
                We typically reply within 2–3 business days.
            </p>
        </div>

        <!-- Main layout -->
        <div class="contact-main-section__layout mt-10 grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-8 items-start">

            <!-- Left: Form card -->
            <form class="contact-main-section__form !rounded-2xl !border !border-slate-200 !bg-white p-6 sm:p-8" method="post" novalidate>

                <!-- Reason selection -->
                <div class="contact-main-section__card">
                    <h2 class="contact-main-section__card-title text-lg font-bold !text-slate-900">
                        Why are you reaching out?
                    </h2>

                    <div class="contact-main-section__reasons mt-4 space-y-3">

                        <label class="contact-main-section__reason block cursor-pointer !rounded-xl !border !border-slate-200 !bg-white transition-all hover:!bg-slate-50 hover:!border-slate-300 has-[:checked]:!border-[#0047A8]/50 has-[:checked]:!bg-[rgba(0,71,168,0.06)]">
                            <span class="flex items-center gap-4 px-4 py-4">
                                <input type="radio" name="contact_reason" value="fan-message" class="contact-main-section__reason-radio appearance-none flex-shrink-0 h-[18px] w-[18px] !rounded-full !border-2 !border-slate-300 !bg-white cursor-pointer transition-all checked:!border-[#0047A8] checked:!bg-[#0047A8] checked:!shadow-[inset_0_0_0_3px_white] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                                <span class="contact-main-section__reason-content">
                                    <span class="contact-main-section__reason-title block text-sm font-semibold !text-slate-900">Fan message / thank you</span>
                                    <span class="contact-main-section__reason-text block text-xs !text-slate-500 mt-0.5">Just want to say something nice.</span>
                                </span>
                            </span>
                        </label>

                        <label class="contact-main-section__reason block cursor-pointer !rounded-xl !border !border-slate-200 !bg-white transition-all hover:!bg-slate-50 hover:!border-slate-300 has-[:checked]:!border-[#0047A8]/50 has-[:checked]:!bg-[rgba(0,71,168,0.06)]">
                            <span class="flex items-center gap-4 px-4 py-4">
                                <input type="radio" name="contact_reason" value="correction" class="contact-main-section__reason-radio appearance-none flex-shrink-0 h-[18px] w-[18px] !rounded-full !border-2 !border-slate-300 !bg-white cursor-pointer transition-all checked:!border-[#0047A8] checked:!bg-[#0047A8] checked:!shadow-[inset_0_0_0_3px_white] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                                <span class="contact-main-section__reason-content">
                                    <span class="contact-main-section__reason-title block text-sm font-semibold !text-slate-900">Correction (data issue)</span>
                                    <span class="contact-main-section__reason-text block text-xs !text-slate-500 mt-0.5">Spotted a wrong stat or missing fight.</span>
                                </span>
                            </span>
                        </label>

                        <label class="contact-main-section__reason block cursor-pointer !rounded-xl !border !border-slate-200 !bg-white transition-all hover:!bg-slate-50 hover:!border-slate-300 has-[:checked]:!border-[#0047A8]/50 has-[:checked]:!bg-[rgba(0,71,168,0.06)]">
                            <span class="flex items-center gap-4 px-4 py-4">
                                <input type="radio" name="contact_reason" value="critique" class="contact-main-section__reason-radio appearance-none flex-shrink-0 h-[18px] w-[18px] !rounded-full !border-2 !border-slate-300 !bg-white cursor-pointer transition-all checked:!border-[#0047A8] checked:!bg-[#0047A8] checked:!shadow-[inset_0_0_0_3px_white] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                                <span class="contact-main-section__reason-content">
                                    <span class="contact-main-section__reason-title block text-sm font-semibold !text-slate-900">Critique / feedback on methodology</span>
                                    <span class="contact-main-section__reason-text block text-xs !text-slate-500 mt-0.5">Thoughts on how the scoring works.</span>
                                </span>
                            </span>
                        </label>

                        <label class="contact-main-section__reason block cursor-pointer !rounded-xl !border !border-slate-200 !bg-white transition-all hover:!bg-slate-50 hover:!border-slate-300 has-[:checked]:!border-[#0047A8]/50 has-[:checked]:!bg-[rgba(0,71,168,0.06)]">
                            <span class="flex items-center gap-4 px-4 py-4">
                                <input type="radio" name="contact_reason" value="collaboration" class="contact-main-section__reason-radio appearance-none flex-shrink-0 h-[18px] w-[18px] !rounded-full !border-2 !border-slate-300 !bg-white cursor-pointer transition-all checked:!border-[#0047A8] checked:!bg-[#0047A8] checked:!shadow-[inset_0_0_0_3px_white] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                                <span class="contact-main-section__reason-content">
                                    <span class="contact-main-section__reason-title block text-sm font-semibold !text-slate-900">Professional collaboration</span>
                                    <span class="contact-main-section__reason-text block text-xs !text-slate-500 mt-0.5">Partnership, sponsorship, or joint project.</span>
                                </span>
                            </span>
                        </label>

                        <label class="contact-main-section__reason block cursor-pointer !rounded-xl !border !border-slate-200 !bg-white transition-all hover:!bg-slate-50 hover:!border-slate-300 has-[:checked]:!border-[#0047A8]/50 has-[:checked]:!bg-[rgba(0,71,168,0.06)]">
                            <span class="flex items-center gap-4 px-4 py-4">
                                <input type="radio" name="contact_reason" value="press" class="contact-main-section__reason-radio appearance-none flex-shrink-0 h-[18px] w-[18px] !rounded-full !border-2 !border-slate-300 !bg-white cursor-pointer transition-all checked:!border-[#0047A8] checked:!bg-[#0047A8] checked:!shadow-[inset_0_0_0_3px_white] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                                <span class="contact-main-section__reason-content">
                                    <span class="contact-main-section__reason-title block text-sm font-semibold !text-slate-900">Press / media inquiry</span>
                                    <span class="contact-main-section__reason-text block text-xs !text-slate-500 mt-0.5">Interview, quote, or feature request.</span>
                                </span>
                            </span>
                        </label>

                        <label class="contact-main-section__reason block cursor-pointer !rounded-xl !border !border-slate-200 !bg-white transition-all hover:!bg-slate-50 hover:!border-slate-300 has-[:checked]:!border-[#0047A8]/50 has-[:checked]:!bg-[rgba(0,71,168,0.06)]">
                            <span class="flex items-center gap-4 px-4 py-4">
                                <input type="radio" name="contact_reason" value="other" class="contact-main-section__reason-radio appearance-none flex-shrink-0 h-[18px] w-[18px] !rounded-full !border-2 !border-slate-300 !bg-white cursor-pointer transition-all checked:!border-[#0047A8] checked:!bg-[#0047A8] checked:!shadow-[inset_0_0_0_3px_white] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                                <span class="contact-main-section__reason-content">
                                    <span class="contact-main-section__reason-title block text-sm font-semibold !text-slate-900">Other</span>
                                    <span class="contact-main-section__reason-text block text-xs !text-slate-500 mt-0.5">Anything else on your mind.</span>
                                </span>
                            </span>
                        </label>

                    </div>
                </div>

                <!-- Your details -->
                <div class="mt-6">
                    <h2 class="contact-main-section__card-title text-lg font-bold !text-slate-900">
                        Your details
                    </h2>

                    <div class="contact-main-section__fields mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="contact-main-section__field">
                            <label for="contact_name" class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700">Full name *</label>
                            <input type="text" id="contact_name" name="contact_name" required placeholder="Your full name" class="contact-main-section__input h-11 w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 text-sm !text-slate-900 placeholder:!text-slate-400 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                        </div>

                        <div class="contact-main-section__field">
                            <label for="contact_email" class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700">Email *</label>
                            <input type="email" id="contact_email" name="contact_email" required placeholder="you@example.com" class="contact-main-section__input h-11 w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 text-sm !text-slate-900 placeholder:!text-slate-400 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                        </div>

                        <div class="contact-main-section__field">
                            <label for="contact_org" class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700">Organization</label>
                            <input type="text" id="contact_org" name="contact_org" placeholder="Company or team name" class="contact-main-section__input h-11 w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 text-sm !text-slate-900 placeholder:!text-slate-400 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                        </div>

                        <div class="contact-main-section__field">
                            <label for="contact_website" class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700">Website / LinkedIn</label>
                            <input type="url" id="contact_website" name="contact_website" placeholder="https://" class="contact-main-section__input h-11 w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 text-sm !text-slate-900 placeholder:!text-slate-400 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                        </div>
                    </div>
                </div>

                <!-- Message -->
                <div class="mt-6">
                    <label for="contact_message" class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700">Message *</label>
                    <textarea id="contact_message" name="contact_message" required placeholder="Write your message here…" class="contact-main-section__textarea w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 py-3 text-sm !text-slate-900 placeholder:!text-slate-400 min-h-[140px] resize-y focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0"></textarea>
                </div>

                <!-- Privacy -->
                <p class="contact-main-section__privacy mt-3 text-xs !text-slate-500 leading-relaxed">
                    By submitting you agree we can use your message to improve the site. We never sell your data.
                </p>

                <!-- Actions -->
                <div class="contact-main-section__actions mt-6 flex flex-wrap items-center gap-4">
                    <button type="submit" class="contact-main-section__submit group inline-flex items-center gap-2 h-11 px-5 !rounded-xl !bg-[#0047A8] !text-white text-sm font-semibold hover:!bg-[#003a8a] transition-colors active:translate-y-[1px] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                        Send message
                        <svg class="w-4 h-4 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/>
                        </svg>
                    </button>

                    <span class="contact-main-section__alt text-sm !text-slate-500">
                        Or email us directly:
                        <a href="mailto:info@mmafuture.com" class="!text-slate-700 hover:!text-slate-900 underline underline-offset-2 transition-colors focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">info@mmafuture.com</a>
                    </span>
                </div>

            </form>

            <!-- Right: Sidebar -->
            <div class="contact-main-section__sidebar space-y-6">

                <!-- Other ways to reach us -->
                <div class="contact-main-section__sidebar-card !rounded-2xl !border !border-slate-200/70 !bg-white p-5 transition-all duration-200 hover:shadow-sm hover:!border-slate-300">
                    <h3 class="contact-main-section__sidebar-title text-sm font-bold !text-slate-900">
                        Other ways to reach us
                    </h3>

                    <div class="contact-main-section__sidebar-items mt-3 space-y-2">
                        <a href="mailto:info@mmafuture.com" class="contact-main-section__sidebar-link contact-main-section__sidebar-item text-sm !text-slate-600 hover:!text-[#0047A8] inline-flex items-center gap-2 transition-all duration-200 hover:translate-x-0.5 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                            </svg>
                            info@mmafuture.com
                        </a>

                        <a href="https://x.com/mmafuture" target="_blank" rel="noopener noreferrer" class="contact-main-section__sidebar-link contact-main-section__sidebar-item text-sm !text-slate-600 hover:!text-[#0047A8] inline-flex items-center gap-2 transition-all duration-200 hover:translate-x-0.5 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                            </svg>
                            X / Twitter
                        </a>

                        <a href="https://linkedin.com/company/mmafuture" target="_blank" rel="noopener noreferrer" class="contact-main-section__sidebar-link contact-main-section__sidebar-item text-sm !text-slate-600 hover:!text-[#0047A8] inline-flex items-center gap-2 transition-all duration-200 hover:translate-x-0.5 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                            </svg>
                            LinkedIn
                        </a>
                    </div>
                </div>

                <!-- Quick links -->
                <div class="contact-main-section__sidebar-card !rounded-2xl !border !border-slate-200/70 !bg-white p-5 transition-all duration-200 hover:shadow-sm hover:!border-slate-300">
                    <h3 class="contact-main-section__sidebar-title text-sm font-bold !text-slate-900">
                        Quick links
                    </h3>

                    <div class="contact-main-section__sidebar-items mt-3 space-y-2">
                        <a href="/about" class="contact-main-section__sidebar-link contact-main-section__sidebar-item text-sm !text-slate-600 hover:!text-[#0047A8] inline-flex items-center gap-2 transition-all duration-200 hover:translate-x-0.5 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                            </svg>
                            About &amp; Methodology
                        </a>

                        <a href="/rankings" class="contact-main-section__sidebar-link contact-main-section__sidebar-item text-sm !text-slate-600 hover:!text-[#0047A8] inline-flex items-center gap-2 transition-all duration-200 hover:translate-x-0.5 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                            </svg>
                            Explore Rankings
                        </a>
                    </div>
                </div>

            </div>

        </div>
    </div>
</section>
