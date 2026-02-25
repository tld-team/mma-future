<style>
#rankings {
  --rk-surface: #0b0f17;
  --rk-surface2: #0e1522;
  --rk-text: #e5e7eb;
  --rk-muted: rgba(255,255,255,.5);
  --rk-border: rgba(255,255,255,.08);
  --rk-accent: #60a5fa;
  background: var(--rk-surface);
  color: var(--rk-text);
}
#rk-wrap {
  max-height: 72vh;
  overflow: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(96,165,250,.2) transparent;
}
#rk-wrap::-webkit-scrollbar { width: 4px; height: 4px; }
#rk-wrap::-webkit-scrollbar-thumb { background: rgba(96,165,250,.2); border-radius: 2px; }
#rk-wrap thead tr { position: sticky; top: 0; z-index: 2; background: #0c1120; }
.rk-th-rank { background: #0c1120; z-index: 3; }
.rk-td-rank { background: var(--rk-surface); }
.rk-td-rank-z { background: rgba(255,255,255,.02); }
.rk-td-rank, .rk-th-rank { position: sticky; left: 0; z-index: 1; box-shadow: 4px 0 16px rgba(0,0,0,.7); }
.rk-main-row { transition: background .1s; }
.rk-main-row:hover { background: rgba(96,165,250,.06) !important; }
.rk-main-row:hover .rk-td-rank,
.rk-main-row:hover .rk-td-rank-z { background: #0c1827 !important; }
.rk-detail-row { display: none; }
.rk-detail-row.is-open { display: table-row; }
.rk-expand { cursor: pointer; background: transparent; transition: background .15s, border-color .15s; }
.rk-expand:hover { background: rgba(96,165,250,.12) !important; border-color: rgba(96,165,250,.3) !important; }
.rk-expand[aria-expanded="true"] { background: rgba(96,165,250,.1) !important; border-color: rgba(96,165,250,.4) !important; }
.rk-expand[aria-expanded="true"] .rk-chev { transform: rotate(180deg); color: var(--rk-accent); }
.rk-chev { transition: transform .2s ease; }
.rk-sort-btn {
  background: none; border: none; cursor: pointer;
  display: inline-flex; align-items: center; gap: 4px; padding: 0;
  font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
  color: rgba(229,231,235,.4); transition: color .15s;
}
.rk-sort-btn:hover { color: rgba(229,231,235,.85); }
.rk-sort-btn.sk-asc, .rk-sort-btn.sk-desc { color: var(--rk-accent); }
.rk-sort-icon { opacity: .2; transition: transform .2s, opacity .2s; flex-shrink: 0; }
.rk-sort-btn.sk-asc .rk-sort-icon { opacity: 1; transform: rotate(180deg); }
.rk-sort-btn.sk-desc .rk-sort-icon { opacity: 1; transform: rotate(0deg); }
.rk-chip { cursor: pointer; transition: background .15s, border-color .15s, color .15s; }
.rk-chip:hover { background: rgba(255,255,255,.05) !important; }
.rk-chip:focus-visible, #rk-top15:focus-visible,
#rk-reset:focus-visible, .rk-expand:focus-visible { outline: 1px solid rgba(96,165,250,.4); outline-offset: 1px; }
#rk-top15 { border: 1px solid var(--rk-border); background: transparent; color: rgba(229,231,235,.5); }
#rk-top15.rk-top15-on { background: rgba(96,165,250,.15); border-color: rgba(96,165,250,.5); color: #93c5fd; }
#rk-top15 .rk-top15-track { justify-content: flex-start; }
#rk-top15.rk-top15-on .rk-top15-track { justify-content: flex-end; }
#rk-top15 .rk-top15-knob { background: rgba(229,231,235,.3); }
#rk-top15.rk-top15-on .rk-top15-knob { background: var(--rk-accent); }
.rk-g-btn:focus, .rk-g-btn:focus-visible { outline: none; box-shadow: none; }
.rk-g-btn { cursor: pointer; transition: background .15s, color .15s; }
#rk-search { padding-left: 38px; }
#rk-search:focus { border-color: rgba(96,165,250,.38); }
#rk-search:focus-visible { outline: none; }
#rk-search::-webkit-search-cancel-button { -webkit-appearance: none; width: 14px; height: 14px; background: rgba(229,231,235,.25) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23e5e7eb' stroke-width='2.5' stroke-linecap='round'%3E%3Cpath d='M18 6L6 18M6 6l12 12'/%3E%3C/svg%3E") center/9px no-repeat; border-radius: 50%; cursor: pointer; opacity: .7; }
#rk-search::-webkit-search-cancel-button:hover { opacity: 1; background-color: rgba(229,231,235,.35); }
.rk-toolbar-sep { width: 1px; height: 1.25rem; background: var(--rk-border); flex-shrink: 0; align-self: center; }
#rk-chips { scrollbar-width: none; }
#rk-chips::-webkit-scrollbar { display: none; }
.rk-chips-fade { position: relative; }
.rk-chips-fade::after {
  content: ''; pointer-events: none; position: absolute;
  right: 0; top: 0; bottom: 4px; width: 3rem;
  background: linear-gradient(to right, transparent, var(--rk-surface));
}
details > summary { list-style: none; cursor: pointer; }
details > summary::-webkit-details-marker { display: none; }
details[open] .rk-det-arr { transform: rotate(180deg); }
.rk-det-arr { display: inline-block; transition: transform .2s; }
.rk-main-row[hidden], .rk-card[hidden], .rk-chip[hidden] { display: none !important; }
</style>

<section id="rankings" data-rk-updated="2026-02-20">
  <div class="container mx-auto px-4 py-12">

    <!-- Header -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-7">
      <div>
        <h2 class="text-4xl font-black text-white" style="font-family:'Sora',sans-serif;letter-spacing:-0.025em;line-height:1">Rankings</h2>
        <p id="rk-updated" class="text-xs mt-2 font-medium tracking-wide" style="color:rgba(229,231,235,.3)">Last updated: February 20, 2026</p>
      </div>
    </div>

    <!-- Controls toolbar -->
    <div class="flex flex-col gap-2.5 mb-6">

      <div class="flex flex-wrap items-center gap-2">

        <!-- Segmented control: Men / Women -->
        <div class="flex items-center gap-1 rounded-xl p-1.5 shrink-0">
          <button class="rk-g-btn flex items-center justify-center min-w-[5rem] p-0 h-9 text-sm font-semibold rounded-[10px] transition-all duration-150" data-gender="male">Men</button>
          <button class="rk-g-btn flex items-center justify-center min-w-[5rem] p-0 h-9 text-sm font-semibold rounded-[10px] transition-all duration-150" data-gender="female">Women</button>
        </div>

        <!-- Separator -->
        <span class="rk-toolbar-sep hidden sm:block"></span>

        <!-- Top 15 toggle -->
        <button type="button" id="rk-top15" class="inline-flex items-center gap-2 px-3.5 h-9 text-sm font-semibold rounded-xl shrink-0 transition-all duration-150" aria-pressed="false" aria-label="Show top 15 only">
          <span class="rk-top15-track inline-flex items-center w-7 h-[1.05rem] rounded-full px-px shrink-0 transition-all duration-200" style="background:rgba(255,255,255,.09);border:1px solid var(--rk-border)">
            <span class="rk-top15-knob block w-[0.8rem] h-[0.8rem] rounded-full shrink-0 transition-all duration-200"></span>
          </span>
          Top&nbsp;15
        </button>

        <!-- Sort select (mobile only) -->
        <select id="rk-sort-m" class="sm:hidden h-9 px-3 text-sm rounded-xl ml-auto" style="border:1px solid var(--rk-border);background:var(--rk-surface);color:var(--rk-text);cursor:pointer">
          <option value="rank|asc">Rank ↑</option>
          <option value="score|desc">Score ↓</option>
        </select>

        <!-- Search input -->
        <div class="relative flex-1" style="min-width:200px">
          <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 pointer-events-none" style="color:rgba(229,231,235,.28)" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
          </svg>
          <input id="rk-search" type="search" placeholder="Search fighter…" class="w-full h-9 pl-[38px] pr-4 text-sm rounded-xl outline-none" style="border:1px solid var(--rk-border);background:rgba(255,255,255,.04);color:var(--rk-text);transition:border-color .15s">
        </div>

      </div>

      <!-- Weight class chips (desktop) -->
      <div class="rk-chips-fade hidden sm:block">
        <div id="rk-chips" class="flex gap-1.5 overflow-x-auto pb-0.5 pr-10">
          <button class="rk-chip px-3 py-1.5 text-xs font-semibold rounded-full shrink-0 whitespace-nowrap" data-wc="all" data-wc-gender="both" aria-pressed="true" style="border:1px solid rgba(96,165,250,.4);color:#93c5fd;background:rgba(96,165,250,.15)">All Divisions</button>
          <button class="rk-chip px-3 py-1.5 text-xs font-semibold rounded-full shrink-0 whitespace-nowrap" data-wc="heavyweight" data-wc-gender="male" aria-pressed="false" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.5);background:transparent">Heavyweight</button>
          <button class="rk-chip px-3 py-1.5 text-xs font-semibold rounded-full shrink-0 whitespace-nowrap" data-wc="light-heavyweight" data-wc-gender="male" aria-pressed="false" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.5);background:transparent">Light Heavyweight</button>
          <button class="rk-chip px-3 py-1.5 text-xs font-semibold rounded-full shrink-0 whitespace-nowrap" data-wc="lightweight" data-wc-gender="male" aria-pressed="false" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.5);background:transparent">Lightweight</button>
          <button class="rk-chip px-3 py-1.5 text-xs font-semibold rounded-full shrink-0 whitespace-nowrap" data-wc="strawweight" data-wc-gender="female" aria-pressed="false" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.5);background:transparent">Strawweight</button>
          <button class="rk-chip px-3 py-1.5 text-xs font-semibold rounded-full shrink-0 whitespace-nowrap" data-wc="flyweight" data-wc-gender="female" aria-pressed="false" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.5);background:transparent">Flyweight</button>
        </div>
      </div>

      <!-- Weight class select (mobile) -->
      <select id="rk-wc-m" class="sm:hidden w-full h-9 px-3 text-sm rounded-xl" style="border:1px solid var(--rk-border);background:var(--rk-surface);color:var(--rk-text)">
        <option value="all" data-wc-gender="both" selected>All Divisions</option>
        <option value="heavyweight" data-wc-gender="male">Heavyweight</option>
        <option value="light-heavyweight" data-wc-gender="male">Light Heavyweight</option>
        <option value="lightweight" data-wc-gender="male">Lightweight</option>
        <option value="strawweight" data-wc-gender="female">Strawweight</option>
        <option value="flyweight" data-wc-gender="female">Flyweight</option>
      </select>

    </div>

    <div id="rk-content">

      <div id="rk-wrap" class="hidden sm:block rounded-2xl" style="border:1px solid var(--rk-border)">
        <table class="w-full border-collapse text-sm" style="background:var(--rk-surface)">
          <thead>
            <tr>
              <th class="rk-th-rank px-4 py-3.5 text-left" style="width:5.5rem">
                <button class="rk-sort-btn" data-key="rank">
                  Rank
                  <svg class="rk-sort-icon w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:rgba(229,231,235,.38)">Fighter</th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:rgba(229,231,235,.38)">Division</th>
              <th class="px-4 py-3.5 text-right">
                <button class="rk-sort-btn ml-auto" data-key="score">
                  Score
                  <svg class="rk-sort-icon w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:rgba(229,231,235,.38)">Record</th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:rgba(229,231,235,.38)">Streak</th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:rgba(229,231,235,.38)">Last Fight</th>
              <th class="px-4 py-3.5" style="width:3rem"></th>
            </tr>
          </thead>
          <tbody id="rk-tbody">

            <!-- Fighter 1: Jon Jones — Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid var(--rk-border)" data-rk-id="1" data-rk-slug="jon-jones" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="jon jones" data-rk-nick="bones" data-rk-country="us" data-rk-rank="1" data-rk-score="950" data-rk-search="jon jones bones us heavyweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="font-black text-white tabular-nums" style="font-size:1.15rem;line-height:1">1</div>
                <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 3</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/jon-jones" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" alt="Jon Jones" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/jon-jones" class="font-semibold text-white leading-tight block truncate" style="transition:color .15s;text-decoration:none" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color=''"> Jon <span style="font-weight:900">Jones</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Bones&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">US</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:rgba(229,231,235,.5)">Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">950</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:rgba(229,231,235,.75)">27<span style="color:rgba(255,255,255,.2)">–</span>1<span style="color:rgba(255,255,255,.2)">–</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W1</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(KO)</span> <span style="color:rgba(229,231,235,.55)">vs Miocic</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Nov 16</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-7 h-7 rounded-lg" data-rk-expand="1" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.35)">
                  <svg class="rk-chev w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="1">
              <td colspan="8" style="padding:0 1.25rem 1.25rem;background:rgba(255,255,255,.015);border-bottom:1px solid var(--rk-border)">
                <div class="flex flex-wrap items-center gap-8 py-4" style="border-top:1px solid var(--rk-border)">
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">11</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">7</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">9</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:rgba(229,231,235,.28)">Event: <span style="color:rgba(229,231,235,.5)">UFC 309</span></div>
                  <div class="ml-auto"><a href="/fighter/jon-jones" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl" style="background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 2: Tom Aspinall — Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid var(--rk-border);background:rgba(255,255,255,.02)" data-rk-id="2" data-rk-slug="tom-aspinall" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="tom aspinall" data-rk-nick="" data-rk-country="gb" data-rk-rank="2" data-rk-score="948" data-rk-search="tom aspinall gb heavyweight">
              <td class="rk-td-rank rk-td-rank-z px-4 py-3">
                <div class="font-black text-white tabular-nums" style="font-size:1.15rem;line-height:1">2</div>
                <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 2</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/tom-aspinall" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" alt="Tom Aspinall" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/tom-aspinall" class="font-semibold text-white leading-tight block truncate" style="transition:color .15s;text-decoration:none" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color=''"> Tom <span style="font-weight:900">Aspinall</span></a>
                    <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">GB</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:rgba(229,231,235,.5)">Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">948</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:rgba(229,231,235,.75)">15<span style="color:rgba(255,255,255,.2)">–</span>3</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W4</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(TKO)</span> <span style="color:rgba(229,231,235,.55)">vs Blaydes</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Jul 27</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-7 h-7 rounded-lg" data-rk-expand="2" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.35)">
                  <svg class="rk-chev w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="2">
              <td colspan="8" style="padding:0 1.25rem 1.25rem;background:rgba(255,255,255,.015);border-bottom:1px solid var(--rk-border)">
                <div class="flex flex-wrap items-center gap-8 py-4" style="border-top:1px solid var(--rk-border)">
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">11</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">1</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">3</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:rgba(229,231,235,.28)">Event: <span style="color:rgba(229,231,235,.5)">UFC 304</span></div>
                  <div class="ml-auto"><a href="/fighter/tom-aspinall" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl" style="background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 3: Ciryl Gane — Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid var(--rk-border)" data-rk-id="3" data-rk-slug="ciryl-gane" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="ciryl gane" data-rk-nick="bon gamin" data-rk-country="fr" data-rk-rank="3" data-rk-score="943" data-rk-search="ciryl gane bon gamin fr heavyweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="font-black text-white tabular-nums" style="font-size:1.15rem;line-height:1">3</div>
                <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 1</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/ciryl-gane" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abe_Alsaghir_cropped.jpg" alt="Ciryl Gane" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/ciryl-gane" class="font-semibold text-white leading-tight block truncate" style="transition:color .15s;text-decoration:none" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color=''"> Ciryl <span style="font-weight:900">Gane</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Bon Gamin&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">FR</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:rgba(229,231,235,.5)">Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">943</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:rgba(229,231,235,.75)">12<span style="color:rgba(255,255,255,.2)">–</span>2</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W3</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(DEC)</span> <span style="color:rgba(229,231,235,.55)">vs Volkov</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Sep 2</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-7 h-7 rounded-lg" data-rk-expand="3" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.35)">
                  <svg class="rk-chev w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="3">
              <td colspan="8" style="padding:0 1.25rem 1.25rem;background:rgba(255,255,255,.015);border-bottom:1px solid var(--rk-border)">
                <div class="flex flex-wrap items-center gap-8 py-4" style="border-top:1px solid var(--rk-border)">
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">5</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">3</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">4</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:rgba(229,231,235,.28)">Event: <span style="color:rgba(229,231,235,.5)">UFC Fight Night</span></div>
                  <div class="ml-auto"><a href="/fighter/ciryl-gane" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl" style="background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 4: Alex Pereira — Light Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid var(--rk-border);background:rgba(255,255,255,.02)" data-rk-id="4" data-rk-slug="alex-pereira" data-rk-gender="male" data-rk-division="light-heavyweight" data-rk-division-label="Light Heavyweight" data-rk-name="alex pereira" data-rk-nick="poatan" data-rk-country="br" data-rk-rank="1" data-rk-score="944" data-rk-search="alex pereira poatan br light heavyweight">
              <td class="rk-td-rank rk-td-rank-z px-4 py-3">
                <div class="font-black text-white tabular-nums" style="font-size:1.15rem;line-height:1">1</div>
                <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 3</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/alex-pereira" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdullo_Khodzhaev-hero-1200x1165-1_cropped.jpg" alt="Alex Pereira" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/alex-pereira" class="font-semibold text-white leading-tight block truncate" style="transition:color .15s;text-decoration:none" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color=''"> Alex <span style="font-weight:900">Pereira</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Poatan&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">BR</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:rgba(229,231,235,.5)">Light Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">944</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:rgba(229,231,235,.75)">12<span style="color:rgba(255,255,255,.2)">–</span>2</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W4</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(KO)</span> <span style="color:rgba(229,231,235,.55)">vs Prochazka</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Jun 29</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-7 h-7 rounded-lg" data-rk-expand="4" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.35)">
                  <svg class="rk-chev w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="4">
              <td colspan="8" style="padding:0 1.25rem 1.25rem;background:rgba(255,255,255,.015);border-bottom:1px solid var(--rk-border)">
                <div class="flex flex-wrap items-center gap-8 py-4" style="border-top:1px solid var(--rk-border)">
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">10</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">0</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">2</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:rgba(229,231,235,.28)">Event: <span style="color:rgba(229,231,235,.5)">UFC 303</span></div>
                  <div class="ml-auto"><a href="/fighter/alex-pereira" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl" style="background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 5: Jiri Prochazka — Light Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid var(--rk-border)" data-rk-id="5" data-rk-slug="jiri-prochazka" data-rk-gender="male" data-rk-division="light-heavyweight" data-rk-division-label="Light Heavyweight" data-rk-name="jiri prochazka" data-rk-nick="denisa" data-rk-country="cz" data-rk-rank="2" data-rk-score="903" data-rk-search="jiri prochazka denisa cz light heavyweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="font-black text-white tabular-nums" style="font-size:1.15rem;line-height:1">2</div>
                <div class="mt-1"><span style="color:#f87171;font-size:.7rem;font-weight:700">↓ 1</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/jiri-prochazka" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/abdulaziz-datsilaev_500x500.jpg" alt="Jiri Prochazka" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/jiri-prochazka" class="font-semibold text-white leading-tight block truncate" style="transition:color .15s;text-decoration:none" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color=''"> Jiri <span style="font-weight:900">Prochazka</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Denisa&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">CZ</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:rgba(229,231,235,.5)">Light Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">903</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:rgba(229,231,235,.75)">30<span style="color:rgba(255,255,255,.2)">–</span>5<span style="color:rgba(255,255,255,.2)">–</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(248,113,113,.12);color:#f87171;font-size:.7rem;font-weight:800;letter-spacing:.04em">L2</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#f87171;font-weight:800">L</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(KO)</span> <span style="color:rgba(229,231,235,.55)">vs Pereira</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Jun 29</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-7 h-7 rounded-lg" data-rk-expand="5" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.35)">
                  <svg class="rk-chev w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="5">
              <td colspan="8" style="padding:0 1.25rem 1.25rem;background:rgba(255,255,255,.015);border-bottom:1px solid var(--rk-border)">
                <div class="flex flex-wrap items-center gap-8 py-4" style="border-top:1px solid var(--rk-border)">
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">26</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">1</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">3</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:rgba(229,231,235,.28)">Event: <span style="color:rgba(229,231,235,.5)">UFC 303</span></div>
                  <div class="ml-auto"><a href="/fighter/jiri-prochazka" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl" style="background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 6: Islam Makhachev — Lightweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid var(--rk-border);background:rgba(255,255,255,.02)" data-rk-id="6" data-rk-slug="islam-makhachev" data-rk-gender="male" data-rk-division="lightweight" data-rk-division-label="Lightweight" data-rk-name="islam makhachev" data-rk-nick="" data-rk-country="ru" data-rk-rank="1" data-rk-score="890" data-rk-search="islam makhachev ru lightweight">
              <td class="rk-td-rank rk-td-rank-z px-4 py-3">
                <div class="font-black text-white tabular-nums" style="font-size:1.15rem;line-height:1">1</div>
                <div class="mt-1"><span style="color:rgba(229,231,235,.2);font-size:.7rem">—</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/islam-makhachev" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Aaron-Beltran.png" alt="Islam Makhachev" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/islam-makhachev" class="font-semibold text-white leading-tight block truncate" style="transition:color .15s;text-decoration:none" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color=''"> Islam <span style="font-weight:900">Makhachev</span></a>
                    <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">RU</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:rgba(229,231,235,.5)">Lightweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">890</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:rgba(229,231,235,.75)">26<span style="color:rgba(255,255,255,.2)">–</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W3</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(SUB)</span> <span style="color:rgba(229,231,235,.55)">vs Poirier</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Jun 1</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-7 h-7 rounded-lg" data-rk-expand="6" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.35)">
                  <svg class="rk-chev w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="6">
              <td colspan="8" style="padding:0 1.25rem 1.25rem;background:rgba(255,255,255,.015);border-bottom:1px solid var(--rk-border)">
                <div class="flex flex-wrap items-center gap-8 py-4" style="border-top:1px solid var(--rk-border)">
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">5</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">11</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">10</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:rgba(229,231,235,.28)">Event: <span style="color:rgba(229,231,235,.5)">UFC 302</span></div>
                  <div class="ml-auto"><a href="/fighter/islam-makhachev" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl" style="background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 7: Zhang Weili — Strawweight (female) -->
            <tr class="rk-main-row" style="border-bottom:1px solid var(--rk-border)" data-rk-id="7" data-rk-slug="zhang-weili" data-rk-gender="female" data-rk-division="strawweight" data-rk-division-label="Strawweight" data-rk-name="zhang weili" data-rk-nick="magnum" data-rk-country="cn" data-rk-rank="1" data-rk-score="870" data-rk-search="zhang weili magnum cn strawweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="font-black text-white tabular-nums" style="font-size:1.15rem;line-height:1">1</div>
                <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 2</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/zhang-weili" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" alt="Zhang Weili" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/zhang-weili" class="font-semibold text-white leading-tight block truncate" style="transition:color .15s;text-decoration:none" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color=''"> Zhang <span style="font-weight:900">Weili</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Magnum&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">CN</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:rgba(229,231,235,.5)">Strawweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">870</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:rgba(229,231,235,.75)">24<span style="color:rgba(255,255,255,.2)">–</span>3</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W2</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(DEC)</span> <span style="color:rgba(229,231,235,.55)">vs Yan</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Apr 13</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-7 h-7 rounded-lg" data-rk-expand="7" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.35)">
                  <svg class="rk-chev w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="7">
              <td colspan="8" style="padding:0 1.25rem 1.25rem;background:rgba(255,255,255,.015);border-bottom:1px solid var(--rk-border)">
                <div class="flex flex-wrap items-center gap-8 py-4" style="border-top:1px solid var(--rk-border)">
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">10</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">7</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">7</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:rgba(229,231,235,.28)">Event: <span style="color:rgba(229,231,235,.5)">UFC 300</span></div>
                  <div class="ml-auto"><a href="/fighter/zhang-weili" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl" style="background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 8: Valentina Shevchenko — Flyweight (female) -->
            <tr class="rk-main-row" style="border-bottom:1px solid var(--rk-border);background:rgba(255,255,255,.02)" data-rk-id="8" data-rk-slug="valentina-shevchenko" data-rk-gender="female" data-rk-division="flyweight" data-rk-division-label="Flyweight" data-rk-name="valentina shevchenko" data-rk-nick="bullet" data-rk-country="kg" data-rk-rank="1" data-rk-score="860" data-rk-search="valentina shevchenko bullet kg flyweight">
              <td class="rk-td-rank rk-td-rank-z px-4 py-3">
                <div class="font-black text-white tabular-nums" style="font-size:1.15rem;line-height:1">1</div>
                <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 1</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/valentina-shevchenko" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" alt="Valentina Shevchenko" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/valentina-shevchenko" class="font-semibold text-white leading-tight block truncate" style="transition:color .15s;text-decoration:none" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color=''"> Valentina <span style="font-weight:900">Shevchenko</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Bullet&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">KG</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:rgba(229,231,235,.5)">Flyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">860</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:rgba(229,231,235,.75)">23<span style="color:rgba(255,255,255,.2)">–</span>4<span style="color:rgba(255,255,255,.2)">–</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W1</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(DEC)</span> <span style="color:rgba(229,231,235,.55)">vs Grasso</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Sep 14</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-7 h-7 rounded-lg" data-rk-expand="8" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.35)">
                  <svg class="rk-chev w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="8">
              <td colspan="8" style="padding:0 1.25rem 1.25rem;background:rgba(255,255,255,.015);border-bottom:1px solid var(--rk-border)">
                <div class="flex flex-wrap items-center gap-8 py-4" style="border-top:1px solid var(--rk-border)">
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">8</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">7</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">8</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:rgba(229,231,235,.28)">Event: <span style="color:rgba(229,231,235,.5)">UFC 306</span></div>
                  <div class="ml-auto"><a href="/fighter/valentina-shevchenko" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl" style="background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 9: Alexa Grasso — Flyweight (female) -->
            <tr class="rk-main-row" style="border-bottom:1px solid var(--rk-border)" data-rk-id="9" data-rk-slug="alexa-grasso" data-rk-gender="female" data-rk-division="flyweight" data-rk-division-label="Flyweight" data-rk-name="alexa grasso" data-rk-nick="" data-rk-country="mx" data-rk-rank="2" data-rk-score="840" data-rk-search="alexa grasso mx flyweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="font-black text-white tabular-nums" style="font-size:1.15rem;line-height:1">2</div>
                <div class="mt-1"><span style="color:#f87171;font-size:.7rem;font-weight:700">↓ 1</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/alexa-grasso" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abe_Alsaghir_cropped.jpg" alt="Alexa Grasso" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/alexa-grasso" class="font-semibold text-white leading-tight block truncate" style="transition:color .15s;text-decoration:none" onmouseover="this.style.color='#93c5fd'" onmouseout="this.style.color=''"> Alexa <span style="font-weight:900">Grasso</span></a>
                    <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">MX</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:rgba(229,231,235,.5)">Flyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">840</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:rgba(229,231,235,.75)">16<span style="color:rgba(255,255,255,.2)">–</span>4<span style="color:rgba(255,255,255,.2)">–</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(248,113,113,.12);color:#f87171;font-size:.7rem;font-weight:800;letter-spacing:.04em">L1</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#f87171;font-weight:800">L</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(DEC)</span> <span style="color:rgba(229,231,235,.55)">vs Shevchenko</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Sep 14</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-7 h-7 rounded-lg" data-rk-expand="9" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid var(--rk-border);color:rgba(229,231,235,.35)">
                  <svg class="rk-chev w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="9">
              <td colspan="8" style="padding:0 1.25rem 1.25rem;background:rgba(255,255,255,.015);border-bottom:1px solid var(--rk-border)">
                <div class="flex flex-wrap items-center gap-8 py-4" style="border-top:1px solid var(--rk-border)">
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">3</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">4</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black text-white tabular-nums">9</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:rgba(229,231,235,.35)">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:rgba(229,231,235,.28)">Event: <span style="color:rgba(229,231,235,.5)">UFC 306</span></div>
                  <div class="ml-auto"><a href="/fighter/alexa-grasso" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl" style="background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

          </tbody>
        </table>
      </div>

      <div id="rk-cards" class="sm:hidden space-y-2.5">

        <!-- Card 1: Jon Jones -->
        <div class="rk-card rounded-2xl p-4" style="background:rgba(255,255,255,.03);border:1px solid var(--rk-border)" data-rk-id="1" data-rk-slug="jon-jones" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="jon jones" data-rk-nick="bones" data-rk-country="us" data-rk-rank="1" data-rk-score="950" data-rk-search="jon jones bones us heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="font-black text-white tabular-nums" style="font-size:1.2rem;line-height:1">1</div>
              <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 3</span></div>
            </div>
            <a href="/fighter/jon-jones" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" alt="Jon Jones" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/jon-jones" class="font-semibold text-white block truncate leading-tight" style="text-decoration:none">Jon <span style="font-weight:900">Jones</span></a>
              <div class="text-xs truncate mt-0.5" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Bones&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">US</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">950</div>
              <div class="text-xs mt-0.5" style="color:rgba(229,231,235,.3);letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:rgba(229,231,235,.55)">
            <span class="font-mono tabular-nums">27–1–1</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W1</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="color:rgba(229,231,235,.45)">Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(KO)</span> <span style="color:rgba(229,231,235,.55)">vs Miocic</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Nov 16</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:var(--rk-accent);letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--rk-border)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black text-white tabular-nums">11</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">KO/TKO</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">7</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Subs</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">9</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Dec</div></div>
              </div>
              <a href="/fighter/jon-jones" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.22);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 2: Tom Aspinall -->
        <div class="rk-card rounded-2xl p-4" style="background:rgba(255,255,255,.03);border:1px solid var(--rk-border)" data-rk-id="2" data-rk-slug="tom-aspinall" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="tom aspinall" data-rk-nick="" data-rk-country="gb" data-rk-rank="2" data-rk-score="948" data-rk-search="tom aspinall gb heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="font-black text-white tabular-nums" style="font-size:1.2rem;line-height:1">2</div>
              <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 2</span></div>
            </div>
            <a href="/fighter/tom-aspinall" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" alt="Tom Aspinall" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/tom-aspinall" class="font-semibold text-white block truncate leading-tight" style="text-decoration:none">Tom <span style="font-weight:900">Aspinall</span></a>
              <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">GB</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">948</div>
              <div class="text-xs mt-0.5" style="color:rgba(229,231,235,.3);letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:rgba(229,231,235,.55)">
            <span class="font-mono tabular-nums">15–3</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W4</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="color:rgba(229,231,235,.45)">Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(TKO)</span> <span style="color:rgba(229,231,235,.55)">vs Blaydes</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Jul 27</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:var(--rk-accent);letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--rk-border)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black text-white tabular-nums">11</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">KO/TKO</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">1</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Subs</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">3</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Dec</div></div>
              </div>
              <a href="/fighter/tom-aspinall" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.22);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 3: Ciryl Gane -->
        <div class="rk-card rounded-2xl p-4" style="background:rgba(255,255,255,.03);border:1px solid var(--rk-border)" data-rk-id="3" data-rk-slug="ciryl-gane" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="ciryl gane" data-rk-nick="bon gamin" data-rk-country="fr" data-rk-rank="3" data-rk-score="943" data-rk-search="ciryl gane bon gamin fr heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="font-black text-white tabular-nums" style="font-size:1.2rem;line-height:1">3</div>
              <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 1</span></div>
            </div>
            <a href="/fighter/ciryl-gane" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abe_Alsaghir_cropped.jpg" alt="Ciryl Gane" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/ciryl-gane" class="font-semibold text-white block truncate leading-tight" style="text-decoration:none">Ciryl <span style="font-weight:900">Gane</span></a>
              <div class="text-xs truncate mt-0.5" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Bon Gamin&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">FR</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">943</div>
              <div class="text-xs mt-0.5" style="color:rgba(229,231,235,.3);letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:rgba(229,231,235,.55)">
            <span class="font-mono tabular-nums">12–2</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W3</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="color:rgba(229,231,235,.45)">Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(DEC)</span> <span style="color:rgba(229,231,235,.55)">vs Volkov</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Sep 2</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:var(--rk-accent);letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--rk-border)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black text-white tabular-nums">5</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">KO/TKO</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">3</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Subs</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">4</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Dec</div></div>
              </div>
              <a href="/fighter/ciryl-gane" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.22);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 4: Alex Pereira -->
        <div class="rk-card rounded-2xl p-4" style="background:rgba(255,255,255,.03);border:1px solid var(--rk-border)" data-rk-id="4" data-rk-slug="alex-pereira" data-rk-gender="male" data-rk-division="light-heavyweight" data-rk-division-label="Light Heavyweight" data-rk-name="alex pereira" data-rk-nick="poatan" data-rk-country="br" data-rk-rank="1" data-rk-score="944" data-rk-search="alex pereira poatan br light heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="font-black text-white tabular-nums" style="font-size:1.2rem;line-height:1">1</div>
              <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 3</span></div>
            </div>
            <a href="/fighter/alex-pereira" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdullo_Khodzhaev-hero-1200x1165-1_cropped.jpg" alt="Alex Pereira" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/alex-pereira" class="font-semibold text-white block truncate leading-tight" style="text-decoration:none">Alex <span style="font-weight:900">Pereira</span></a>
              <div class="text-xs truncate mt-0.5" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Poatan&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">BR</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">944</div>
              <div class="text-xs mt-0.5" style="color:rgba(229,231,235,.3);letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:rgba(229,231,235,.55)">
            <span class="font-mono tabular-nums">12–2</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W4</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="color:rgba(229,231,235,.45)">Light Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(KO)</span> <span style="color:rgba(229,231,235,.55)">vs Prochazka</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Jun 29</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:var(--rk-accent);letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--rk-border)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black text-white tabular-nums">10</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">KO/TKO</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">0</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Subs</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">2</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Dec</div></div>
              </div>
              <a href="/fighter/alex-pereira" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.22);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 5: Jiri Prochazka -->
        <div class="rk-card rounded-2xl p-4" style="background:rgba(255,255,255,.03);border:1px solid var(--rk-border)" data-rk-id="5" data-rk-slug="jiri-prochazka" data-rk-gender="male" data-rk-division="light-heavyweight" data-rk-division-label="Light Heavyweight" data-rk-name="jiri prochazka" data-rk-nick="denisa" data-rk-country="cz" data-rk-rank="2" data-rk-score="903" data-rk-search="jiri prochazka denisa cz light heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="font-black text-white tabular-nums" style="font-size:1.2rem;line-height:1">2</div>
              <div class="mt-1"><span style="color:#f87171;font-size:.7rem;font-weight:700">↓ 1</span></div>
            </div>
            <a href="/fighter/jiri-prochazka" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/abdulaziz-datsilaev_500x500.jpg" alt="Jiri Prochazka" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/jiri-prochazka" class="font-semibold text-white block truncate leading-tight" style="text-decoration:none">Jiri <span style="font-weight:900">Prochazka</span></a>
              <div class="text-xs truncate mt-0.5" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Denisa&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">CZ</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">903</div>
              <div class="text-xs mt-0.5" style="color:rgba(229,231,235,.3);letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:rgba(229,231,235,.55)">
            <span class="font-mono tabular-nums">30–5–1</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(248,113,113,.12);color:#f87171;font-size:.7rem;font-weight:800;letter-spacing:.04em">L2</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="color:rgba(229,231,235,.45)">Light Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#f87171;font-weight:800">L</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(KO)</span> <span style="color:rgba(229,231,235,.55)">vs Pereira</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Jun 29</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:var(--rk-accent);letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--rk-border)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black text-white tabular-nums">26</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">KO/TKO</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">1</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Subs</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">3</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Dec</div></div>
              </div>
              <a href="/fighter/jiri-prochazka" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.22);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 6: Islam Makhachev -->
        <div class="rk-card rounded-2xl p-4" style="background:rgba(255,255,255,.03);border:1px solid var(--rk-border)" data-rk-id="6" data-rk-slug="islam-makhachev" data-rk-gender="male" data-rk-division="lightweight" data-rk-division-label="Lightweight" data-rk-name="islam makhachev" data-rk-nick="" data-rk-country="ru" data-rk-rank="1" data-rk-score="890" data-rk-search="islam makhachev ru lightweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="font-black text-white tabular-nums" style="font-size:1.2rem;line-height:1">1</div>
              <div class="mt-1"><span style="color:rgba(229,231,235,.2);font-size:.7rem">—</span></div>
            </div>
            <a href="/fighter/islam-makhachev" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Aaron-Beltran.png" alt="Islam Makhachev" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/islam-makhachev" class="font-semibold text-white block truncate leading-tight" style="text-decoration:none">Islam <span style="font-weight:900">Makhachev</span></a>
              <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">RU</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">890</div>
              <div class="text-xs mt-0.5" style="color:rgba(229,231,235,.3);letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:rgba(229,231,235,.55)">
            <span class="font-mono tabular-nums">26–1</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W3</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="color:rgba(229,231,235,.45)">Lightweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(SUB)</span> <span style="color:rgba(229,231,235,.55)">vs Poirier</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Jun 1</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:var(--rk-accent);letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--rk-border)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black text-white tabular-nums">5</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">KO/TKO</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">11</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Subs</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">10</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Dec</div></div>
              </div>
              <a href="/fighter/islam-makhachev" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.22);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 7: Zhang Weili -->
        <div class="rk-card rounded-2xl p-4" style="background:rgba(255,255,255,.03);border:1px solid var(--rk-border)" data-rk-id="7" data-rk-slug="zhang-weili" data-rk-gender="female" data-rk-division="strawweight" data-rk-division-label="Strawweight" data-rk-name="zhang weili" data-rk-nick="magnum" data-rk-country="cn" data-rk-rank="1" data-rk-score="870" data-rk-search="zhang weili magnum cn strawweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="font-black text-white tabular-nums" style="font-size:1.2rem;line-height:1">1</div>
              <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 2</span></div>
            </div>
            <a href="/fighter/zhang-weili" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" alt="Zhang Weili" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/zhang-weili" class="font-semibold text-white block truncate leading-tight" style="text-decoration:none">Zhang <span style="font-weight:900">Weili</span></a>
              <div class="text-xs truncate mt-0.5" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Magnum&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">CN</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">870</div>
              <div class="text-xs mt-0.5" style="color:rgba(229,231,235,.3);letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:rgba(229,231,235,.55)">
            <span class="font-mono tabular-nums">24–3</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W2</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="color:rgba(229,231,235,.45)">Strawweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(DEC)</span> <span style="color:rgba(229,231,235,.55)">vs Yan</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Apr 13</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:var(--rk-accent);letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--rk-border)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black text-white tabular-nums">10</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">KO/TKO</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">7</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Subs</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">7</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Dec</div></div>
              </div>
              <a href="/fighter/zhang-weili" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.22);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 8: Valentina Shevchenko -->
        <div class="rk-card rounded-2xl p-4" style="background:rgba(255,255,255,.03);border:1px solid var(--rk-border)" data-rk-id="8" data-rk-slug="valentina-shevchenko" data-rk-gender="female" data-rk-division="flyweight" data-rk-division-label="Flyweight" data-rk-name="valentina shevchenko" data-rk-nick="bullet" data-rk-country="kg" data-rk-rank="1" data-rk-score="860" data-rk-search="valentina shevchenko bullet kg flyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="font-black text-white tabular-nums" style="font-size:1.2rem;line-height:1">1</div>
              <div class="mt-1"><span style="color:#4ade80;font-size:.7rem;font-weight:700">↑ 1</span></div>
            </div>
            <a href="/fighter/valentina-shevchenko" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" alt="Valentina Shevchenko" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/valentina-shevchenko" class="font-semibold text-white block truncate leading-tight" style="text-decoration:none">Valentina <span style="font-weight:900">Shevchenko</span></a>
              <div class="text-xs truncate mt-0.5" style="color:rgba(229,231,235,.32);font-style:italic">&ldquo;Bullet&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">KG</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">860</div>
              <div class="text-xs mt-0.5" style="color:rgba(229,231,235,.3);letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:rgba(229,231,235,.55)">
            <span class="font-mono tabular-nums">23–4–1</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(74,222,128,.12);color:#4ade80;font-size:.7rem;font-weight:800;letter-spacing:.04em">W1</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="color:rgba(229,231,235,.45)">Flyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#4ade80;font-weight:800">W</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(DEC)</span> <span style="color:rgba(229,231,235,.55)">vs Grasso</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Sep 14</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:var(--rk-accent);letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--rk-border)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black text-white tabular-nums">8</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">KO/TKO</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">7</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Subs</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">8</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Dec</div></div>
              </div>
              <a href="/fighter/valentina-shevchenko" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.22);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 9: Alexa Grasso -->
        <div class="rk-card rounded-2xl p-4" style="background:rgba(255,255,255,.03);border:1px solid var(--rk-border)" data-rk-id="9" data-rk-slug="alexa-grasso" data-rk-gender="female" data-rk-division="flyweight" data-rk-division-label="Flyweight" data-rk-name="alexa grasso" data-rk-nick="" data-rk-country="mx" data-rk-rank="2" data-rk-score="840" data-rk-search="alexa grasso mx flyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="font-black text-white tabular-nums" style="font-size:1.2rem;line-height:1">2</div>
              <div class="mt-1"><span style="color:#f87171;font-size:.7rem;font-weight:700">↓ 1</span></div>
            </div>
            <a href="/fighter/alexa-grasso" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid rgba(255,255,255,.1)">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abe_Alsaghir_cropped.jpg" alt="Alexa Grasso" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/alexa-grasso" class="font-semibold text-white block truncate leading-tight" style="text-decoration:none">Alexa <span style="font-weight:900">Grasso</span></a>
              <div class="text-xs font-mono mt-0.5" style="color:rgba(229,231,235,.28);letter-spacing:.06em">MX</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:var(--rk-accent)">840</div>
              <div class="text-xs mt-0.5" style="color:rgba(229,231,235,.3);letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:rgba(229,231,235,.55)">
            <span class="font-mono tabular-nums">16–4–1</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(248,113,113,.12);color:#f87171;font-size:.7rem;font-weight:800;letter-spacing:.04em">L1</span>
            <span style="color:rgba(255,255,255,.12)">|</span>
            <span style="color:rgba(229,231,235,.45)">Flyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#f87171;font-weight:800">L</span> <span style="color:rgba(229,231,235,.45);font-size:.8em">(DEC)</span> <span style="color:rgba(229,231,235,.55)">vs Shevchenko</span> <span style="color:rgba(229,231,235,.2)">·</span> <span style="color:rgba(229,231,235,.35)">Sep 14</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:var(--rk-accent);letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid var(--rk-border)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black text-white tabular-nums">3</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">KO/TKO</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">4</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Subs</div></div>
                <div><div class="text-lg font-black text-white tabular-nums">9</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:rgba(229,231,235,.35)">Dec</div></div>
              </div>
              <a href="/fighter/alexa-grasso" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.22);color:var(--rk-accent);text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

      </div>

    </div>

    <div id="rk-empty" class="hidden text-center py-20">
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl mb-5" style="background:rgba(255,255,255,.04);border:1px solid var(--rk-border)">
        <svg class="w-7 h-7" style="color:rgba(229,231,235,.2)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>
      <p class="text-base font-bold text-white mb-1">No fighters found</p>
      <p class="text-sm mb-6" style="color:rgba(229,231,235,.38)">Try adjusting your filters or search.</p>
      <button id="rk-reset" class="px-5 py-2.5 text-sm font-semibold rounded-xl transition-colors" style="border:1px solid rgba(96,165,250,.3);color:var(--rk-accent);background:rgba(96,165,250,.08);cursor:pointer">Reset filters</button>
    </div>

  </div>
</section>

<script>
(function () {
  'use strict';

  var S = { gender: 'male', wc: 'all', search: '', top15: false, sortKey: 'rank', sortDir: 'asc' };

  var tbody = document.getElementById('rk-tbody');
  var cardsContainer = document.getElementById('rk-cards');
  var chipsWrap = document.getElementById('rk-chips');
  var wcSelect = document.getElementById('rk-wc-m');
  var rows = Array.from(tbody.querySelectorAll('tr.rk-main-row'));
  var cards = Array.from(cardsContainer.querySelectorAll('.rk-card'));
  var chips = Array.from(chipsWrap.querySelectorAll('.rk-chip'));
  var wcOptions = Array.from(wcSelect.querySelectorAll('option'));
  var detailById = new Map();

  tbody.querySelectorAll('tr.rk-detail-row').forEach(function (tr) {
    detailById.set(tr.dataset.rkDetailFor, tr);
  });

  function matchItem(el) {
    if (el.dataset.rkGender !== S.gender) return false;
    if (S.wc !== 'all' && el.dataset.rkDivision !== S.wc) return false;
    if (S.search) {
      var hay = el.dataset.rkSearch || ((el.dataset.rkName || '') + ' ' + (el.dataset.rkNick || '') + ' ' + (el.dataset.rkCountry || ''));
      if (hay.indexOf(S.search) === -1) return false;
    }
    if (S.top15 && parseInt(el.dataset.rkRank, 10) > 15) return false;
    return true;
  }

  function applyFilters() {
    rows.forEach(function (r) {
      var ok = matchItem(r);
      r.hidden = !ok;
      if (!ok) {
        var d = detailById.get(r.dataset.rkId);
        if (d) d.classList.remove('is-open');
        var b = r.querySelector('.rk-expand');
        if (b) b.setAttribute('aria-expanded', 'false');
      }
    });
    cards.forEach(function (c) { c.hidden = !matchItem(c); });
  }

  function applySort() {
    var key = S.sortKey === 'rank' ? 'rkRank' : 'rkScore';
    var dir = S.sortDir;

    var visible = rows.filter(function (r) { return !r.hidden; });
    visible.sort(function (a, b) {
      var va = parseInt(a.dataset[key], 10);
      var vb = parseInt(b.dataset[key], 10);
      return dir === 'asc' ? va - vb : vb - va;
    });
    var hidden = rows.filter(function (r) { return r.hidden; });
    visible.concat(hidden).forEach(function (r) {
      tbody.appendChild(r);
      var detail = detailById.get(r.dataset.rkId);
      if (detail) tbody.appendChild(detail);
    });

    var visCards = cards.filter(function (c) { return !c.hidden; });
    visCards.sort(function (a, b) {
      var va = parseInt(a.dataset[key], 10);
      var vb = parseInt(b.dataset[key], 10);
      return dir === 'asc' ? va - vb : vb - va;
    });
    var hidCards = cards.filter(function (c) { return c.hidden; });
    visCards.concat(hidCards).forEach(function (c) { cardsContainer.appendChild(c); });
  }

  function applyZebra() {
    var idx = 0;
    Array.from(tbody.querySelectorAll('tr.rk-main-row')).forEach(function (r) {
      if (r.hidden) return;
      var z = idx % 2 === 1;
      r.style.background = z ? 'rgba(255,255,255,.02)' : '';
      var td = r.cells[0];
      if (td) {
        td.className = (z ? 'rk-td-rank rk-td-rank-z' : 'rk-td-rank') + ' px-4 py-3';
      }
      idx++;
    });
  }

  function updateEmptyState() {
    var any = rows.some(function (r) { return !r.hidden; });
    document.getElementById('rk-content').style.display = any ? '' : 'none';
    document.getElementById('rk-empty').classList.toggle('hidden', any);
  }

  function updateChips() {
    chips.forEach(function (ch) {
      var g = ch.dataset.wcGender;
      var show = g === 'both' || g === S.gender;
      ch.hidden = !show;
      var on = show && S.wc === ch.dataset.wc;
      ch.style.borderColor = on ? 'rgba(96,165,250,.4)' : '';
      ch.style.color = on ? '#93c5fd' : '';
      ch.style.background = on ? 'rgba(96,165,250,.15)' : '';
      ch.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    wcOptions.forEach(function (o) {
      var g = o.dataset.wcGender;
      var show = g === 'both' || g === S.gender;
      o.hidden = !show;
      o.disabled = !show;
    });
    wcSelect.value = S.wc;
  }

  function updateGender() {
    document.querySelectorAll('.rk-g-btn').forEach(function (btn) {
      var on = btn.dataset.gender === S.gender;
      btn.style.background = on ? 'var(--rk-accent)' : 'transparent';
      btn.style.color = on ? '#0b0f17' : 'rgba(229,231,235,.65)';
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function updateTop15() {
    var btn = document.getElementById('rk-top15');
    if (!btn) return;
    btn.classList.toggle('rk-top15-on', S.top15);
    btn.setAttribute('aria-pressed', S.top15 ? 'true' : 'false');
  }

  function updateSort() {
    document.querySelectorAll('.rk-sort-btn').forEach(function (btn) {
      btn.classList.remove('sk-asc', 'sk-desc');
      if (btn.dataset.key === S.sortKey) btn.classList.add(S.sortDir === 'asc' ? 'sk-asc' : 'sk-desc');
    });
    var sel = document.getElementById('rk-sort-m');
    if (sel) sel.value = S.sortKey + '|' + S.sortDir;
  }

  function renderAll() {
    applyFilters();
    applySort();
    applyZebra();
    updateChips();
    updateGender();
    updateTop15();
    updateSort();
    updateEmptyState();
  }

  document.querySelectorAll('.rk-g-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      S.gender = this.dataset.gender;
      var ok = chips.some(function (c) {
        var g = c.dataset.wcGender;
        return c.dataset.wc === S.wc && (g === 'both' || g === S.gender);
      });
      if (!ok) S.wc = 'all';
      renderAll();
    });
  });

  var top15Btn = document.getElementById('rk-top15');
  if (top15Btn) {
    top15Btn.addEventListener('click', function () {
      S.top15 = !S.top15;
      renderAll();
    });
  }

  document.getElementById('rk-sort-m').addEventListener('change', function () {
    var p = this.value.split('|');
    S.sortKey = p[0]; S.sortDir = p[1];
    renderAll();
  });

  var st;
  document.getElementById('rk-search').addEventListener('input', function () {
    var val = this.value;
    clearTimeout(st);
    st = setTimeout(function () { S.search = val.trim().toLowerCase(); renderAll(); }, 200);
  });

  document.getElementById('rk-chips').addEventListener('click', function (e) {
    var btn = e.target.closest('.rk-chip');
    if (!btn) return;
    S.wc = btn.dataset.wc;
    renderAll();
  });

  document.getElementById('rk-wc-m').addEventListener('change', function () {
    S.wc = this.value;
    renderAll();
  });

  var thead = document.querySelector('#rk-wrap thead');
  if (thead) {
    thead.addEventListener('click', function (e) {
      var btn = e.target.closest('.rk-sort-btn');
      if (!btn) return;
      var k = btn.dataset.key;
      if (S.sortKey === k) {
        S.sortDir = S.sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        S.sortKey = k;
        S.sortDir = k === 'rank' ? 'asc' : 'desc';
      }
      renderAll();
    });
  }

  tbody.addEventListener('click', function (e) {
    var btn = e.target.closest('.rk-expand');
    if (!btn) return;
    var id = btn.dataset.rkExpand;
    var detail = detailById.get(id);
    var isOpen = detail && detail.classList.contains('is-open');
    detailById.forEach(function (d, key) {
      d.classList.remove('is-open');
      var b = tbody.querySelector('.rk-expand[data-rk-expand="' + key + '"]');
      if (b) b.setAttribute('aria-expanded', 'false');
    });
    if (!isOpen && detail) {
      detail.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
    }
  });

  document.getElementById('rk-reset').addEventListener('click', function () {
    S.gender = 'male'; S.wc = 'all'; S.search = ''; S.top15 = false; S.sortKey = 'rank'; S.sortDir = 'asc';
    document.getElementById('rk-search').value = '';
    renderAll();
  });

  renderAll();

}());
</script>
