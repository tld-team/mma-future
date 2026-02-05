<!-- Fighter Bio Section Styles -->
<style>
/* ========================================================
   ROOT SCOPING - All overrides under .mf-bio-section
   ======================================================== */

.mf-bio-section {
    padding-top: 2rem;
    padding-bottom: 2rem;
    padding-left: 0.5rem;
    padding-right: 0.5rem;
}

@media (min-width: 1024px) {
    .mf-bio-section {
        padding-top: 3rem;
        padding-bottom: 3rem;
    }
}

/* Reset list styles */
.mf-bio-section ul, 
.mf-bio-section ol, 
.mf-bio-section dl {
    list-style: none;
    margin: 0;
    padding: 0;
}

/* Reset link styles */
.mf-bio-section a {
    text-decoration: none !important;
}

.mf-bio-section a:hover,
.mf-bio-section a:focus {
    text-decoration: none !important;
}

/* Reset button styles (exclude specific styled buttons) */
.mf-bio-section button:not(.mf-about__toggle) {
    background: transparent;
    border: none;
    padding: 0;
    cursor: pointer;
}

/* SVG sizing */
.mf-bio-section svg {
    flex-shrink: 0;
}

/* Reset dt/dd margins */
.mf-bio-section dt, 
.mf-bio-section dd {
    margin: 0;
}

/* Paragraph spacing within bio text */
.mf-bio-section p {
    margin-bottom: 1rem;
}

.mf-bio-section p:last-child {
    margin-bottom: 0;
}

/* ========================================================
   ABOUT SECTION - Title + Anchors + Grid
   ======================================================== */

.mf-about__header {
    margin-bottom: 1.5rem;
}

@media (min-width: 1024px) {
    .mf-about__header {
        margin-bottom: 2rem;
    }
}

.mf-about__title {
    font-family: var(--font-heading, inherit);
    font-weight: 700;
    font-size: 1.25rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
    margin-bottom: 0.75rem;
    color: #1E293B;
}

@media (min-width: 768px) {
    .mf-about__title {
        font-size: 1.5rem;
    }
}

/* Anchor Links */
.mf-about__anchors {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
}

.mf-about__anchor {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.875rem;
    color: #0047A8;
    font-weight: 500;
    transition: color 0.2s;
}

.mf-about__anchor:hover {
    color: #003580;
}

.mf-about__anchor svg {
    width: 0.875rem;
    height: 0.875rem;
}

/* About Grid - 12 column system */
.mf-about__grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 2rem;
    margin-bottom: 2rem;
}

@media (min-width: 1024px) {
    .mf-about__grid {
        grid-template-columns: repeat(12, 1fr);
        gap: 2rem;
        margin-bottom: 2.5rem;
    }
}

@media (min-width: 1024px) {
    .mf-about__text {
        grid-column: span 8;
    }
}

@media (min-width: 1024px) {
    .mf-about__sidebar {
        grid-column: span 4;
    }
}

/* Bio Text */
.mf-about__lead {
    font-family: var(--font-heading, inherit);
    font-weight: 600;
    font-size: 1.125rem;
    line-height: 1.7;
    margin-bottom: 1rem;
    color: #1E293B;
}

@media (min-width: 768px) {
    .mf-about__lead {
        font-size: 1.25rem;
    }
}

/* Bio Body Wrapper */
.mf-about__body-wrapper {
    position: relative;
}

.mf-about__body-wrapper.is-collapsed {
    margin-bottom: 0.5rem;
}

.mf-about__body-wrapper.is-collapsed::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 4rem;
    background: linear-gradient(to bottom, transparent, rgba(255, 255, 255, 0.95));
    pointer-events: none;
}

.mf-about__body {
    font-size: 1rem;
    line-height: 1.7;
    color: #475569;
}

.mf-about__body.is-collapsed {
    max-height: 8.5rem;
    overflow: hidden;
    position: relative;
}

.mf-about__body.is-expanded {
    max-height: none;
}

/* Toggle Button - Subtle */
.mf-about__toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    margin-top: 0.75rem;
    padding: 0.4rem 0.875rem;
    border-radius: 0.375rem;
    background-color: #3B82F6;
    color: white;
    font-weight: 500;
    font-size: 0.8125rem;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(59, 130, 246, 0.15);
    cursor: pointer;
}

.mf-about__toggle:hover {
    background-color: #2563EB;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
}

.mf-about__toggle:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(59, 130, 246, 0.15);
}

.mf-about__toggle:focus {
    outline: none;
}

.mf-about__toggle:focus-visible {
    outline: none;
}

.mf-about__toggle svg {
    width: 1rem;
    height: 1rem;
    transition: transform 0.3s ease;
}

.mf-about__toggle[aria-expanded="true"] svg {
    transform: rotate(180deg);
}

/* ========================================================
   KEY FACTS CARD - Compact + Aligned
   ======================================================== */

.mf-keyfacts {
    background-color: white;
    border-radius: 0.75rem;
    border: 1px solid #E2E8F0;
    padding: 1.5rem;
    height: fit-content;
}

.mf-keyfacts__title {
    font-family: var(--font-heading, inherit);
    font-weight: 700;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 1.25rem;
    color: #1E293B;
}

.mf-keyfacts__row {
    display: grid;
    grid-template-columns: 1.25rem 1fr;
    gap: 0.75rem;
    padding-top: 0.625rem;
    padding-bottom: 0.625rem;
}

.mf-keyfacts__row:first-child {
    padding-top: 0;
}

.mf-keyfacts__row:last-child {
    padding-bottom: 0;
}

.mf-keyfacts__icon {
    flex-shrink: 0;
    width: 1.25rem;
    height: 1.25rem;
    color: #0047A8;
    margin-top: 0.125rem;
}

.mf-keyfacts__content {
    min-width: 0;
}

.mf-keyfacts__label {
    font-size: 0.6875rem;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.25rem;
    font-weight: 600;
    display: block;
}

.mf-keyfacts__value {
    font-size: 0.875rem;
    font-weight: 600;
    color: #1E293B;
    display: block;
}

.mf-keyfacts__divider {
    grid-column: 1 / -1;
    height: 1px;
    background-color: #E2E8F0;
    opacity: 0.6;
    margin: 0.25rem 0;
}

/* ========================================================
   BIOGRAPHICAL DATA SECTION
   ======================================================== */

.mf-bio-data {
    padding-top: 2.5rem;
    padding-bottom: 2.5rem;
    background: linear-gradient(to bottom right, #F8FAFC, #F1F5F9);
    margin-left: -1rem;
    margin-right: -1rem;
    padding-left: 1rem;
    padding-right: 1rem;
}

@media (min-width: 768px) {
    .mf-bio-data {
        margin-left: -2rem;
        margin-right: -2rem;
        padding-left: 2rem;
        padding-right: 2rem;
    }
}

@media (min-width: 1024px) {
    .mf-bio-data {
        padding-top: 3rem;
        padding-bottom: 3rem;
        margin-left: 0;
        margin-right: 0;
        padding-left: 0;
        padding-right: 0;
    }
}

.mf-bio-data__header {
    margin-bottom: 1.5rem;
}

@media (min-width: 1024px) {
    .mf-bio-data__header {
        margin-bottom: 2rem;
    }
}

.mf-bio-data__title {
    font-family: var(--font-heading, inherit);
    font-weight: 700;
    font-size: 1.25rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
    margin-bottom: 0.5rem;
    color: #1E293B;
}

@media (min-width: 768px) {
    .mf-bio-data__title {
        font-size: 1.5rem;
    }
}

.mf-bio-data__subtitle {
    font-size: 0.875rem;
    color: #64748B;
}

/* Bio Cards Grid */
.mf-bio-cards-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

@media (min-width: 768px) {
    .mf-bio-cards-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 1280px) {
    .mf-bio-cards-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 2rem;
    }
}

/* ========================================================
   BIO CARD - Unified Structure
   ======================================================== */

.mf-bio-card {
    background-color: white;
    border-radius: 0.75rem;
    border: 1px solid #E2E8F0;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    height: 100%;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.mf-bio-card:hover {
    border-color: #93C5FD;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    transform: translateY(-2px);
}

.mf-bio-card__title {
    font-family: var(--font-heading, inherit);
    font-weight: 700;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 1.25rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #E2E8F0;
    color: #1E293B;
}

.mf-bio-card__list {
    flex: 1;
}

.mf-bio-card__row {
    display: grid;
    grid-template-columns: 1.25rem 1fr;
    gap: 0.75rem;
    padding-top: 0.625rem;
    padding-bottom: 0.625rem;
}

.mf-bio-card__row:first-child {
    padding-top: 0;
}

.mf-bio-card__row:last-child {
    padding-bottom: 0;
}

.mf-bio-card__icon {
    flex-shrink: 0;
    width: 1.25rem;
    height: 1.25rem;
    color: #0047A8;
    margin-top: 0.125rem;
}

.mf-bio-card__content {
    min-width: 0;
}

.mf-bio-card__label {
    font-size: 0.6875rem;
    color: #64748B;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.25rem;
    font-weight: 600;
    display: block;
}

.mf-bio-card__value {
    font-size: 0.875rem;
    font-weight: 600;
    color: #1E293B;
    display: block;
}

.mf-bio-card__divider {
    grid-column: 1 / -1;
    height: 1px;
    background-color: #E2E8F0;
    opacity: 0.6;
    margin: 0.25rem 0;
}
</style>

<!-- Fighter Bio Section -->
<section class="mf-bio-section">
    <div class="container mx-auto px-4">
        
        <!-- ===================== ABOUT SECTION ===================== -->
        <div class="mf-about">
            
            <!-- Header with Title + Anchor Links -->
            <header class="mf-about__header">
                <h2 class="mf-about__title">O Borcu</h2>
                
                <nav class="mf-about__anchors" aria-label="Quick navigation">
                    <a href="#biografski-podaci" class="mf-about__anchor">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Biografski podaci</span>
                    </a>
                </nav>
            </header>
            
            <!-- About Grid: Bio Text (8 cols) + Key Facts (4 cols) -->
            <div class="mf-about__grid">
                
                <!-- Bio Text Block -->
                <div class="mf-about__text">
                    <p class="mf-about__lead" data-slot="bio-lead">
                        Khabib Nurmagomedov je bivši šampion UFC-a u lakoj kategoriji i jedna od najvećih legendi MMA sporta.
                    </p>
                    
                    <!-- Bio Body Wrapper with Fade Effect -->
                    <div class="mf-about__body-wrapper is-collapsed" id="bioWrapper">
                        <div class="mf-about__body is-collapsed" id="bioText" data-slot="bio">
                            <p>
                                Rođen u planinskom selu Sildi u Dagestanu, Rusija, Khabib je od malih nogu bio izložen borilačkim veštinama kroz porodičnu tradiciju. Njegov otac, Abdulmanap Nurmagomedov, bio je poznati trener i ključna figura u njegovom razvoju kao borca.
                            </p>
                            <p>
                                Khabib je poznat po svom dominantnom wrestling stilu i nepokolebljivoj kontroli na zemlji. Tokom svoje karijere, zadržao je neverovatan rekord od 29-0, nikada ne dozvolivši da bilo koji protivnik osvoji rundu jednoglasnom odlukom. Njegova sposobnost da potpuno neutrališe protivnike i nametne svoj tempo borbe postala je njegova prepoznatljiva oznaka.
                            </p>
                            <p>
                                Pored svojih sportskih dostignuća, Khabib je takođe poznat po svom skromnom ponašanju van oktogona, dubokoj posvećenosti svojoj porodici i veri, kao i po ulozi mentora mladim borcima iz Dagestana. Njegov uticaj na MMA sport transcenduje same borbe, inspirišući novu generaciju sportista širom sveta.
                            </p>
                            <p>
                                Nakon povlačenja 2020. godine, Khabib je nastavio da gradi svoj nasleđe kao trener i promoter, pomažući da se MMA sport razvija u regionima poput Rusije, Srednje Azije i Bliskog Istoka.
                            </p>
                        </div>
                    </div>
                    
                    <button 
                        class="mf-about__toggle" 
                        id="bioToggle"
                        aria-expanded="false"  
                        aria-controls="bioText"
                        type="button">
                        <span class="mf-about__toggle-text">Prikaži više</span>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                </div>
                
                <!-- Key Facts Sidebar -->
                <aside class="mf-about__sidebar">
                    <div class="mf-keyfacts">
                        <h3 class="mf-keyfacts__title">Ključne Činjenice</h3>
                        
                        <dl class="mf-keyfacts__list">
                            
                            <!-- Nationality -->
                            <div class="mf-keyfacts__row">
                                <svg class="mf-keyfacts__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <div class="mf-keyfacts__content">
                                    <dt class="mf-keyfacts__label">Nacionalnost</dt>
                                    <dd class="mf-keyfacts__value" data-slot="keyfacts-nationality">Rusija (Dagestan)</dd>
                                </div>
                            </div>
                            
                            <div class="mf-keyfacts__divider"></div>
                            
                            <!-- Height -->
                            <div class="mf-keyfacts__row">
                                <svg class="mf-keyfacts__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
                                </svg>
                                <div class="mf-keyfacts__content">
                                    <dt class="mf-keyfacts__label">Visina</dt>
                                    <dd class="mf-keyfacts__value" data-slot="keyfacts-height">178 cm</dd>
                                </div>
                            </div>
                            
                            <div class="mf-keyfacts__divider"></div>
                            
                            <!-- Date of Birth -->
                            <div class="mf-keyfacts__row">
                                <svg class="mf-keyfacts__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <div class="mf-keyfacts__content">
                                    <dt class="mf-keyfacts__label">Datum rođenja</dt>
                                    <dd class="mf-keyfacts__value" data-slot="keyfacts-dob">20. septembar 1988.</dd>
                                </div>
                            </div>
                            
                            <div class="mf-keyfacts__divider"></div>
                            
                            <!-- Weight Category -->
                            <div class="mf-keyfacts__row">
                                <svg class="mf-keyfacts__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/>
                                </svg>
                                <div class="mf-keyfacts__content">
                                    <dt class="mf-keyfacts__label">Kategorija</dt>
                                    <dd class="mf-keyfacts__value" data-slot="keyfacts-category">Laka (155 lbs)</dd>
                                </div>
                            </div>
                            
                            <div class="mf-keyfacts__divider"></div>
                            
                            <!-- Stance -->
                            <div class="mf-keyfacts__row">
                                <svg class="mf-keyfacts__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                <div class="mf-keyfacts__content">
                                    <dt class="mf-keyfacts__label">Stav</dt>
                                    <dd class="mf-keyfacts__value" data-slot="keyfacts-stance">Ortodoksni</dd>
                                </div>
                            </div>
                            
                        </dl>
                    </div>
                </aside>
                
            </div>
            
        </div>
        
        <!-- ===================== BIOGRAPHICAL DATA SECTION ===================== -->
        <div class="mf-bio-data" id="biografski-podaci">
            <div class="container mx-auto px-4">
                
                <!-- Header -->
                <header class="mf-bio-data__header">
                    <h2 class="mf-bio-data__title">Biografski Podaci</h2>
                    <p class="mf-bio-data__subtitle">Osnovni podaci i karakteristike</p>
                </header>
                
                <!-- Bio Cards Grid -->
                <div class="mf-bio-cards-grid">
                    
                    <!-- Card 1: Basic Info -->
                    <article class="mf-bio-card">
                        <h3 class="mf-bio-card__title">Osnovni Podaci</h3>
                        
                        <dl class="mf-bio-card__list">
                            
                            <!-- Nickname -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Nadimak</dt>
                                    <dd class="mf-bio-card__value" data-slot="nickname">"The Eagle"</dd>
                                </div>
                            </div>
                            
                            <div class="mf-bio-card__divider"></div>
                            
                            <!-- Date of Birth -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Datum rođenja</dt>
                                    <dd class="mf-bio-card__value" data-slot="dob">20. septembar 1988.</dd>
                                </div>
                            </div>
                            
                            <div class="mf-bio-card__divider"></div>
                            
                            <!-- Nationality -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Nacionalnost</dt>
                                    <dd class="mf-bio-card__value" data-slot="nationality">Rusija</dd>
                                </div>
                            </div>
                            
                            <div class="mf-bio-card__divider"></div>
                            
                            <!-- Place of Birth -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Mesto rođenja</dt>
                                    <dd class="mf-bio-card__value" data-slot="birthplace">Sildi, Dagestan</dd>
                                </div>
                            </div>
                            
                            <div class="mf-bio-card__divider"></div>
                            
                            <!-- Residence -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Prebivalište</dt>
                                    <dd class="mf-bio-card__value" data-slot="residence">Makhachkala, Dagestan</dd>
                                </div>
                            </div>
                            
                        </dl>
                    </article>
                    
                    <!-- Card 2: Physical Characteristics -->
                    <article class="mf-bio-card">
                        <h3 class="mf-bio-card__title">Fizičke Karakteristike</h3>
                        
                        <dl class="mf-bio-card__list">
                            
                            <!-- Height -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Visina</dt>
                                    <dd class="mf-bio-card__value" data-slot="height">178 cm (5'10")</dd>
                                </div>
                            </div>
                            
                            <div class="mf-bio-card__divider"></div>
                            
                            <!-- Reach -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11.5V14m0-2.5v-6a1.5 1.5 0 113 0m-3 6a1.5 1.5 0 00-3 0v2a7.5 7.5 0 0015 0v-5a1.5 1.5 0 00-3 0m-6-3V11m0-5.5v-1a1.5 1.5 0 013 0v1m0 0V11m0-5.5a1.5 1.5 0 013 0v3m0 0V11"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Raspon ruku</dt>
                                    <dd class="mf-bio-card__value" data-slot="reach">178 cm (70")</dd>
                                </div>
                            </div>
                            
                            <div class="mf-bio-card__divider"></div>
                            
                            <!-- Weight Class -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Težinska kategorija</dt>
                                    <dd class="mf-bio-card__value" data-slot="weightclass">Laka (155 lbs / 70 kg)</dd>
                                </div>
                            </div>
                            
                            <div class="mf-bio-card__divider"></div>
                            
                            <!-- Stance -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Stav</dt>
                                    <dd class="mf-bio-card__value" data-slot="stance">Ortodoksni</dd>
                                </div>
                            </div>
                            
                        </dl>
                    </article>
                    
                    <!-- Card 3: Career -->
                    <article class="mf-bio-card">
                        <h3 class="mf-bio-card__title">Karijera</h3>
                        
                        <dl class="mf-bio-card__list">
                            
                            <!-- Team -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Tim</dt>
                                    <dd class="mf-bio-card__value" data-slot="team">Eagles MMA</dd>
                                </div>
                            </div>
                            
                            <div class="mf-bio-card__divider"></div>
                            
                            <!-- Coach -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Trener</dt>
                                    <dd class="mf-bio-card__value" data-slot="coach">Abdulmanap Nurmagomedov, Javier Mendez</dd>
                                </div>
                            </div>
                            
                            <div class="mf-bio-card__divider"></div>
                            
                            <!-- Professional Debut -->
                            <div class="mf-bio-card__row">
                                <svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                                <div class="mf-bio-card__content">
                                    <dt class="mf-bio-card__label">Profesionalni debi</dt>
                                    <dd class="mf-bio-card__value" data-slot="prodebut">20. septembar 2008.</dd>
                                </div>
                            </div>
                            
                        </dl>
                    </article>
                    
                </div>
                
            </div>
        </div>
        
    </div>
</section>

<!-- Bio Section Scripts -->
<script>
(function() {
    'use strict';
    
    // ========================================================
    // 1) Bio Toggle (Show More / Show Less)
    // ========================================================
    const toggle = document.getElementById('bioToggle');
    const bioText = document.getElementById('bioText');
    const bioWrapper = document.getElementById('bioWrapper');
    
    if (toggle && bioText && bioWrapper) {
        const toggleText = toggle.querySelector('.mf-about__toggle-text');
        
        toggle.addEventListener('click', function() {
            const isExpanded = this.getAttribute('aria-expanded') === 'true';
            
            if (isExpanded) {
                // Collapse
                this.setAttribute('aria-expanded', 'false');
                bioText.classList.remove('is-expanded');
                bioText.classList.add('is-collapsed');
                bioWrapper.classList.add('is-collapsed');
                bioWrapper.classList.remove('is-expanded');
                if (toggleText) toggleText.textContent = 'Prikaži više';
                
                // Smooth scroll to top of bio section
                bioWrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                // Expand
                this.setAttribute('aria-expanded', 'true');
                bioText.classList.remove('is-collapsed');
                bioText.classList.add('is-expanded');
                bioWrapper.classList.remove('is-collapsed');
                bioWrapper.classList.add('is-expanded');
                if (toggleText) toggleText.textContent = 'Prikaži manje';
            }
        });
    }
    
    // ========================================================
    // 2) Smooth Scroll for Anchor Links
    // ========================================================
    const anchorLinks = document.querySelectorAll('.mf-about__anchor[href^="#"]');
    
    anchorLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            const targetId = this.getAttribute('href');
            const targetElement = document.querySelector(targetId);
            
            if (targetElement) {
                e.preventDefault();
                
                const offsetTop = targetElement.getBoundingClientRect().top + window.pageYOffset - 80;
                
                window.scrollTo({
                    top: offsetTop,
                    behavior: 'smooth'
                });
            }
        });
    });
})();
</script>