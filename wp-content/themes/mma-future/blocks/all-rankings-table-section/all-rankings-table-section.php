<style>
#rankings {
  background: #f8fafc;
  color: #0f172a;
}
#rk-wrap {
  max-height: 72vh;
  overflow: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(0,71,168,.12) transparent;
}
#rk-wrap::-webkit-scrollbar { width: 4px; height: 4px; }
#rk-wrap::-webkit-scrollbar-thumb { background: rgba(0,71,168,.12); border-radius: 2px; }
#rk-wrap thead tr { position: sticky; top: 0; z-index: 2; background: #f8fafc; }
.rk-th-rank { background: #f8fafc; z-index: 3; }
.rk-td-rank { background: #fff; }
.rk-td-rank-z { background: #fafbfc; }
.rk-td-rank, .rk-th-rank { position: sticky; left: 0; z-index: 1; box-shadow: 4px 0 12px rgba(0,0,0,.04); }
.rk-main-row { transition: background .1s; }
.rk-main-row:hover { background: #f1f5f9 !important; }
.rk-main-row:hover .rk-td-rank,
.rk-main-row:hover .rk-td-rank-z { background: #f1f5f9 !important; }
.rk-detail-row { display: none; }
.rk-detail-row.is-open { display: table-row; }
.rk-expand { cursor: pointer; background: #fff; transition: background .15s, border-color .15s, color .15s; }
.rk-expand:hover { background: #f8fafc !important; border-color: #cbd5e1 !important; color: #334155 !important; }
.rk-expand[aria-expanded="true"] { background: #eff6ff !important; border-color: #bfdbfe !important; color: #0047A8 !important; }
.rk-expand[aria-expanded="true"] .rk-chev { transform: rotate(180deg); color: #0047A8; }
.rk-chev { transition: transform .2s ease; }
#rankings .rk-expand svg.rk-chev {
  display: block !important;
  width: 1rem !important;
  height: 1rem !important;
  min-width: 1rem !important;
  min-height: 1rem !important;
  stroke: currentColor !important;
  fill: none !important;
  opacity: 1 !important;
  visibility: visible !important;
  overflow: visible !important;
}
#rankings .rk-expand svg.rk-chev path {
  stroke: currentColor !important;
  stroke-width: 2.5 !important;
  fill: none !important;
}
.rk-sort-btn {
  background: none; border: none; cursor: pointer;
  display: inline-flex; align-items: center; gap: 4px; padding: 0;
  font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
  color: #94a3b8; transition: color .15s;
}
.rk-sort-btn:hover { color: #475569; }
.rk-sort-btn.sk-asc, .rk-sort-btn.sk-desc { color: #0047A8; }
.rk-sort-icon { opacity: .35; transition: transform .2s, opacity .2s; flex-shrink: 0; }
.rk-sort-btn.sk-asc .rk-sort-icon { opacity: 1; transform: rotate(180deg); }
.rk-sort-btn.sk-desc .rk-sort-icon { opacity: 1; transform: rotate(0deg); }
.rk-chip { cursor: pointer; transition: background .15s, border-color .15s, color .15s; }
.rk-chip:hover:not([aria-pressed="true"]) { background: #f8fafc !important; border-color: #cbd5e1 !important; }
.rk-chip:focus-visible, .rk-chip:focus,
#rk-top15:focus-visible, #rk-top15:focus,
#rk-reset:focus-visible, .rk-expand:focus-visible,
.rk-g-btn:focus-visible, .rk-g-btn:focus,
.rk-sort-btn:focus-visible { outline: none; box-shadow: none; }
#rk-top15 .rk-top15-track { justify-content: flex-start; background: #e2e8f0; }
#rk-top15 .rk-top15-knob { background: #94a3b8; }
#rk-top15.rk-top15-on { background: #eff6ff; border-color: #93c5fd; color: #0047A8; }
#rk-top15.rk-top15-on .rk-top15-track { justify-content: flex-end; background: #bfdbfe; }
#rk-top15.rk-top15-on .rk-top15-knob { background: #0047A8; }
.rk-g-btn:focus, .rk-g-btn:focus-visible { outline: none; box-shadow: none; }
.rk-g-btn { cursor: pointer; transition: background .15s, color .15s; padding: 0 16px; border: none; background: #fff; font: inherit; }
.rk-g-btn[aria-pressed="false"] { background: #fff; color: #475569; }
.rk-g-btn[aria-pressed="false"]:hover { color: #0f172a; }
.rk-g-btn[aria-pressed="true"] { background: #0047A8; color: #fff; }
#rk-search { padding-left: 2.5rem; }
#rk-search:focus { border-color: rgba(0,71,168,.35); }
#rk-search:focus-visible { outline: none; }
#rk-search::-webkit-search-cancel-button { -webkit-appearance: none; width: 14px; height: 14px; background: #cbd5e1 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2.5' stroke-linecap='round'%3E%3Cpath d='M18 6L6 18M6 6l12 12'/%3E%3C/svg%3E") center/9px no-repeat; border-radius: 50%; cursor: pointer; opacity: .7; }
#rk-search::-webkit-search-cancel-button:hover { opacity: 1; background-color: #94a3b8; }
details > summary { list-style: none; cursor: pointer; }
details > summary::-webkit-details-marker { display: none; }
details[open] .rk-det-arr { transform: rotate(180deg); }
.rk-det-arr { display: inline-block; transition: transform .2s; }
.rk-main-row[hidden], .rk-card[hidden], .rk-chip[hidden] { display: none !important; }
.rk-rank-move { font-size: .8rem; font-weight: 700; }
.rk-rank-move .rk-rank-arr { font-size: .95rem; }
</style>

<section id="rankings" data-rk-updated="2026-02-20">
  <div class="container mx-auto px-4 py-12">

    <!-- Header -->
    <div class="flex flex-wrap items-end justify-between gap-3 mb-7">
      <div>
        <h2 class="text-4xl font-black" style="font-family:'Sora',sans-serif;letter-spacing:-0.025em;line-height:1;color:#0f172a">Rankings</h2>
        <p id="rk-updated" class="text-xs mt-2 font-medium tracking-wide" style="color:#94a3b8">Last updated: February 20, 2026</p>
      </div>
    </div>

    <!-- Controls toolbar -->
    <div class="mt-6 space-y-3 mb-5">

      <!-- Row 1: Gender + Top 15 | Search -->
      <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">

        <!-- Left group -->
        <div class="flex flex-wrap items-center gap-3">

          <!-- Men / Women segmented control -->
          <div class="inline-flex items-center rounded-xl bg-slate-100 py-1.5 px-2 gap-2">
            <button type="button" class="rk-g-btn h-8 px-4 py-0 rounded-lg text-sm font-semibold transition-colors focus:outline-none focus-visible:outline-none" data-gender="male" aria-pressed="true">Men</button>
            <button type="button" class="rk-g-btn h-8 px-4 py-0 rounded-lg text-sm font-semibold transition-colors focus:outline-none focus-visible:outline-none" data-gender="female" aria-pressed="false">Women</button>
          </div>

          <!-- Top 15 toggle -->
          <button type="button" id="rk-top15" class="inline-flex items-center gap-3 h-10 px-4 rounded-xl border border-slate-200 bg-white text-sm font-medium text-slate-700 transition-all duration-150 focus:outline-none focus-visible:outline-none" aria-pressed="false" aria-label="Show top 15 only">
            <span class="rk-top15-track inline-flex items-center w-8 h-[1.15rem] rounded-full px-0.5 shrink-0 transition-all duration-200">
              <span class="rk-top15-knob block w-[0.85rem] h-[0.85rem] rounded-full shrink-0 transition-all duration-200"></span>
            </span>
            Top&nbsp;15
          </button>

          <!-- Sort select (mobile only) -->
          <select id="rk-sort-m" class="sm:hidden h-10 px-3 text-sm rounded-xl border border-slate-200 bg-white text-slate-900 ml-auto focus:outline-none" style="cursor:pointer">
            <option value="rank|asc">Rank &#8593;</option>
            <option value="score|desc">Score &#8595;</option>
          </select>

        </div>

        <!-- Right group: Search -->
        <div class="relative w-full lg:w-[360px]">
          <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
          </svg>
          <input id="rk-search" type="search" placeholder="Search fighter…" class="w-full h-10 pr-4 text-sm rounded-xl border border-slate-200 bg-white text-slate-700 placeholder:text-slate-400 transition-colors focus:outline-none focus-visible:outline-none">
        </div>

      </div>

      <!-- Row 2: Division filter pills (desktop) -->
      <div class="hidden sm:flex flex-wrap items-center gap-2" id="rk-chips">
        <button class="rk-chip inline-flex items-center h-9 px-4 rounded-xl text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:outline-none" data-wc="all" data-wc-gender="both" aria-pressed="true" style="border:1px solid #0047A8;color:#fff;background:#0047A8">All Divisions</button>
        <button class="rk-chip inline-flex items-center h-9 px-4 rounded-xl text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:outline-none" data-wc="heavyweight" data-wc-gender="male" aria-pressed="false" style="border:1px solid #e2e8f0;color:#334155;background:#fff">Heavyweight</button>
        <button class="rk-chip inline-flex items-center h-9 px-4 rounded-xl text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:outline-none" data-wc="light-heavyweight" data-wc-gender="male" aria-pressed="false" style="border:1px solid #e2e8f0;color:#334155;background:#fff">Light Heavyweight</button>
        <button class="rk-chip inline-flex items-center h-9 px-4 rounded-xl text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:outline-none" data-wc="lightweight" data-wc-gender="male" aria-pressed="false" style="border:1px solid #e2e8f0;color:#334155;background:#fff">Lightweight</button>
        <button class="rk-chip inline-flex items-center h-9 px-4 rounded-xl text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:outline-none" data-wc="strawweight" data-wc-gender="female" aria-pressed="false" style="border:1px solid #e2e8f0;color:#334155;background:#fff">Strawweight</button>
        <button class="rk-chip inline-flex items-center h-9 px-4 rounded-xl text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:outline-none" data-wc="flyweight" data-wc-gender="female" aria-pressed="false" style="border:1px solid #e2e8f0;color:#334155;background:#fff">Flyweight</button>
      </div>

      <!-- Weight class select (mobile) -->
      <select id="rk-wc-m" class="sm:hidden w-full h-10 px-4 text-sm rounded-xl border border-slate-200 bg-white text-slate-900 focus:outline-none">
        <option value="all" data-wc-gender="both" selected>All Divisions</option>
        <option value="heavyweight" data-wc-gender="male">Heavyweight</option>
        <option value="light-heavyweight" data-wc-gender="male">Light Heavyweight</option>
        <option value="lightweight" data-wc-gender="male">Lightweight</option>
        <option value="strawweight" data-wc-gender="female">Strawweight</option>
        <option value="flyweight" data-wc-gender="female">Flyweight</option>
      </select>

    </div>

    <div id="rk-content">

      <div id="rk-wrap" class="hidden sm:block rounded-2xl" style="border:1px solid rgba(226,232,240,0.7)">
        <table class="w-full border-collapse text-sm" style="background:#fff">
          <thead>
            <tr>
              <th class="rk-th-rank px-4 py-3.5 text-left" style="width:5.5rem">
                <button class="rk-sort-btn" data-key="rank">
                  Rank
                  <svg class="rk-sort-icon w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Fighter</th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Division</th>
              <th class="px-4 py-3.5 text-right">
                <button class="rk-sort-btn ml-auto" data-key="score">
                  Score
                  <svg class="rk-sort-icon w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Record</th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Streak</th>
              <th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Last Fight</th>
              <th class="px-4 py-3.5" style="width:3rem"></th>
            </tr>
          </thead>
          <tbody id="rk-tbody">

            <!-- Fighter 1: Jon Jones — Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid rgba(226,232,240,0.7)" data-rk-id="1" data-rk-slug="jon-jones" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="jon jones" data-rk-nick="bones" data-rk-country="us" data-rk-rank="1" data-rk-score="950" data-rk-search="jon jones bones us heavyweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a">1</div>
                <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 3</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/jon-jones" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" alt="Jon Jones" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/jon-jones" class="font-semibold leading-tight block truncate" style="color:#0f172a;transition:color .15s;text-decoration:none" onmouseover="this.style.color='#0047A8'" onmouseout="this.style.color='#0f172a'"> Jon <span style="font-weight:900">Jones</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:#94a3b8;font-style:italic">&ldquo;Bones&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">US</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8">Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">950</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569">27<span style="color:#cbd5e1">&ndash;</span>1<span style="color:#cbd5e1">&ndash;</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W1</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(KO)</span> <span style="color:#64748b">vs Miocic</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Nov 16</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="1" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid #e2e8f0;background:#fff;color:#64748b">
                  <svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="1">
              <td colspan="8" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
                <div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)">
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">11</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">7</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">9</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:#94a3b8">Event: <span style="color:#64748b">UFC 309</span></div>
                  <div class="ml-auto"><a href="/fighter/jon-jones" class="inline-flex items-center gap-1.5 h-10 px-4 text-sm font-semibold rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 2: Tom Aspinall — Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid rgba(226,232,240,0.7);background:#fafbfc" data-rk-id="2" data-rk-slug="tom-aspinall" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="tom aspinall" data-rk-nick="" data-rk-country="gb" data-rk-rank="2" data-rk-score="948" data-rk-search="tom aspinall gb heavyweight">
              <td class="rk-td-rank rk-td-rank-z px-4 py-3">
                <div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a">2</div>
                <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 2</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/tom-aspinall" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" alt="Tom Aspinall" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/tom-aspinall" class="font-semibold leading-tight block truncate" style="color:#0f172a;transition:color .15s;text-decoration:none" onmouseover="this.style.color='#0047A8'" onmouseout="this.style.color='#0f172a'"> Tom <span style="font-weight:900">Aspinall</span></a>
                    <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">GB</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8">Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">948</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569">15<span style="color:#cbd5e1">&ndash;</span>3</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W4</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(TKO)</span> <span style="color:#64748b">vs Blaydes</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Jul 27</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="2" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid #e2e8f0;background:#fff;color:#64748b">
                  <svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="2">
              <td colspan="8" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
                <div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)">
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">11</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">1</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">3</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:#94a3b8">Event: <span style="color:#64748b">UFC 304</span></div>
                  <div class="ml-auto"><a href="/fighter/tom-aspinall" class="inline-flex items-center gap-1.5 h-10 px-4 text-sm font-semibold rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 3: Ciryl Gane — Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid rgba(226,232,240,0.7)" data-rk-id="3" data-rk-slug="ciryl-gane" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="ciryl gane" data-rk-nick="bon gamin" data-rk-country="fr" data-rk-rank="3" data-rk-score="943" data-rk-search="ciryl gane bon gamin fr heavyweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a">3</div>
                <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 1</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/ciryl-gane" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abe_Alsaghir_cropped.jpg" alt="Ciryl Gane" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/ciryl-gane" class="font-semibold leading-tight block truncate" style="color:#0f172a;transition:color .15s;text-decoration:none" onmouseover="this.style.color='#0047A8'" onmouseout="this.style.color='#0f172a'"> Ciryl <span style="font-weight:900">Gane</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:#94a3b8;font-style:italic">&ldquo;Bon Gamin&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">FR</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8">Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">943</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569">12<span style="color:#cbd5e1">&ndash;</span>2</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W3</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(DEC)</span> <span style="color:#64748b">vs Volkov</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Sep 2</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="3" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid #e2e8f0;background:#fff;color:#64748b">
                  <svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="3">
              <td colspan="8" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
                <div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)">
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">5</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">3</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">4</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:#94a3b8">Event: <span style="color:#64748b">UFC Fight Night</span></div>
                  <div class="ml-auto"><a href="/fighter/ciryl-gane" class="inline-flex items-center gap-1.5 h-10 px-4 text-sm font-semibold rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 4: Alex Pereira — Light Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid rgba(226,232,240,0.7);background:#fafbfc" data-rk-id="4" data-rk-slug="alex-pereira" data-rk-gender="male" data-rk-division="light-heavyweight" data-rk-division-label="Light Heavyweight" data-rk-name="alex pereira" data-rk-nick="poatan" data-rk-country="br" data-rk-rank="1" data-rk-score="944" data-rk-search="alex pereira poatan br light heavyweight">
              <td class="rk-td-rank rk-td-rank-z px-4 py-3">
                <div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a">1</div>
                <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 3</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/alex-pereira" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdullo_Khodzhaev-hero-1200x1165-1_cropped.jpg" alt="Alex Pereira" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/alex-pereira" class="font-semibold leading-tight block truncate" style="color:#0f172a;transition:color .15s;text-decoration:none" onmouseover="this.style.color='#0047A8'" onmouseout="this.style.color='#0f172a'"> Alex <span style="font-weight:900">Pereira</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:#94a3b8;font-style:italic">&ldquo;Poatan&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">BR</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8">Light Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">944</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569">12<span style="color:#cbd5e1">&ndash;</span>2</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W4</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(KO)</span> <span style="color:#64748b">vs Prochazka</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Jun 29</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="4" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid #e2e8f0;background:#fff;color:#64748b">
                  <svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="4">
              <td colspan="8" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
                <div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)">
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">10</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">0</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">2</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:#94a3b8">Event: <span style="color:#64748b">UFC 303</span></div>
                  <div class="ml-auto"><a href="/fighter/alex-pereira" class="inline-flex items-center gap-1.5 h-10 px-4 text-sm font-semibold rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 5: Jiri Prochazka — Light Heavyweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid rgba(226,232,240,0.7)" data-rk-id="5" data-rk-slug="jiri-prochazka" data-rk-gender="male" data-rk-division="light-heavyweight" data-rk-division-label="Light Heavyweight" data-rk-name="jiri prochazka" data-rk-nick="denisa" data-rk-country="cz" data-rk-rank="2" data-rk-score="903" data-rk-search="jiri prochazka denisa cz light heavyweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a">2</div>
                <div class="mt-1"><span class="rk-rank-move" style="color:#dc2626"><span class="rk-rank-arr">&#8595;</span> 1</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/jiri-prochazka" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/abdulaziz-datsilaev_500x500.jpg" alt="Jiri Prochazka" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/jiri-prochazka" class="font-semibold leading-tight block truncate" style="color:#0f172a;transition:color .15s;text-decoration:none" onmouseover="this.style.color='#0047A8'" onmouseout="this.style.color='#0f172a'"> Jiri <span style="font-weight:900">Prochazka</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:#94a3b8;font-style:italic">&ldquo;Denisa&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">CZ</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8">Light Heavyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">903</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569">30<span style="color:#cbd5e1">&ndash;</span>5<span style="color:#cbd5e1">&ndash;</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(220,38,38,.08);color:#dc2626;font-size:.7rem;font-weight:800;letter-spacing:.04em">L2</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#dc2626;font-weight:800">L</span> <span style="color:#94a3b8;font-size:.8em">(KO)</span> <span style="color:#64748b">vs Pereira</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Jun 29</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="5" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid #e2e8f0;background:#fff;color:#64748b">
                  <svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="5">
              <td colspan="8" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
                <div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)">
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">26</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">1</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">3</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:#94a3b8">Event: <span style="color:#64748b">UFC 303</span></div>
                  <div class="ml-auto"><a href="/fighter/jiri-prochazka" class="inline-flex items-center gap-1.5 h-10 px-4 text-sm font-semibold rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 6: Islam Makhachev — Lightweight (male) -->
            <tr class="rk-main-row" style="border-bottom:1px solid rgba(226,232,240,0.7);background:#fafbfc" data-rk-id="6" data-rk-slug="islam-makhachev" data-rk-gender="male" data-rk-division="lightweight" data-rk-division-label="Lightweight" data-rk-name="islam makhachev" data-rk-nick="" data-rk-country="ru" data-rk-rank="1" data-rk-score="890" data-rk-search="islam makhachev ru lightweight">
              <td class="rk-td-rank rk-td-rank-z px-4 py-3">
                <div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a">1</div>
                <div class="mt-1"><span style="color:#cbd5e1;font-size:.7rem">&mdash;</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/islam-makhachev" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Aaron-Beltran.png" alt="Islam Makhachev" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/islam-makhachev" class="font-semibold leading-tight block truncate" style="color:#0f172a;transition:color .15s;text-decoration:none" onmouseover="this.style.color='#0047A8'" onmouseout="this.style.color='#0f172a'"> Islam <span style="font-weight:900">Makhachev</span></a>
                    <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">RU</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8">Lightweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">890</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569">26<span style="color:#cbd5e1">&ndash;</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W3</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(SUB)</span> <span style="color:#64748b">vs Poirier</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Jun 1</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="6" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid #e2e8f0;background:#fff;color:#64748b">
                  <svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="6">
              <td colspan="8" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
                <div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)">
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">5</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">11</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">10</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:#94a3b8">Event: <span style="color:#64748b">UFC 302</span></div>
                  <div class="ml-auto"><a href="/fighter/islam-makhachev" class="inline-flex items-center gap-1.5 h-10 px-4 text-sm font-semibold rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 7: Zhang Weili — Strawweight (female) -->
            <tr class="rk-main-row" style="border-bottom:1px solid rgba(226,232,240,0.7)" data-rk-id="7" data-rk-slug="zhang-weili" data-rk-gender="female" data-rk-division="strawweight" data-rk-division-label="Strawweight" data-rk-name="zhang weili" data-rk-nick="magnum" data-rk-country="cn" data-rk-rank="1" data-rk-score="870" data-rk-search="zhang weili magnum cn strawweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a">1</div>
                <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 2</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/zhang-weili" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" alt="Zhang Weili" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/zhang-weili" class="font-semibold leading-tight block truncate" style="color:#0f172a;transition:color .15s;text-decoration:none" onmouseover="this.style.color='#0047A8'" onmouseout="this.style.color='#0f172a'"> Zhang <span style="font-weight:900">Weili</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:#94a3b8;font-style:italic">&ldquo;Magnum&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">CN</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8">Strawweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">870</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569">24<span style="color:#cbd5e1">&ndash;</span>3</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W2</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(DEC)</span> <span style="color:#64748b">vs Yan</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Apr 13</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="7" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid #e2e8f0;background:#fff;color:#64748b">
                  <svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="7">
              <td colspan="8" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
                <div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)">
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">10</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">7</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">7</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:#94a3b8">Event: <span style="color:#64748b">UFC 300</span></div>
                  <div class="ml-auto"><a href="/fighter/zhang-weili" class="inline-flex items-center gap-1.5 h-10 px-4 text-sm font-semibold rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 8: Valentina Shevchenko — Flyweight (female) -->
            <tr class="rk-main-row" style="border-bottom:1px solid rgba(226,232,240,0.7);background:#fafbfc" data-rk-id="8" data-rk-slug="valentina-shevchenko" data-rk-gender="female" data-rk-division="flyweight" data-rk-division-label="Flyweight" data-rk-name="valentina shevchenko" data-rk-nick="bullet" data-rk-country="kg" data-rk-rank="1" data-rk-score="860" data-rk-search="valentina shevchenko bullet kg flyweight">
              <td class="rk-td-rank rk-td-rank-z px-4 py-3">
                <div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a">1</div>
                <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 1</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/valentina-shevchenko" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" alt="Valentina Shevchenko" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/valentina-shevchenko" class="font-semibold leading-tight block truncate" style="color:#0f172a;transition:color .15s;text-decoration:none" onmouseover="this.style.color='#0047A8'" onmouseout="this.style.color='#0f172a'"> Valentina <span style="font-weight:900">Shevchenko</span></a>
                    <div class="text-xs mt-0.5 truncate" style="color:#94a3b8;font-style:italic">&ldquo;Bullet&rdquo;</div>
                    <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">KG</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8">Flyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">860</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569">23<span style="color:#cbd5e1">&ndash;</span>4<span style="color:#cbd5e1">&ndash;</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W1</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(DEC)</span> <span style="color:#64748b">vs Grasso</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Sep 14</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="8" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid #e2e8f0;background:#fff;color:#64748b">
                  <svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="8">
              <td colspan="8" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
                <div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)">
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">8</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">7</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">8</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:#94a3b8">Event: <span style="color:#64748b">UFC 306</span></div>
                  <div class="ml-auto"><a href="/fighter/valentina-shevchenko" class="inline-flex items-center gap-1.5 h-10 px-4 text-sm font-semibold rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

            <!-- Fighter 9: Alexa Grasso — Flyweight (female) -->
            <tr class="rk-main-row" style="border-bottom:1px solid rgba(226,232,240,0.7)" data-rk-id="9" data-rk-slug="alexa-grasso" data-rk-gender="female" data-rk-division="flyweight" data-rk-division-label="Flyweight" data-rk-name="alexa grasso" data-rk-nick="" data-rk-country="mx" data-rk-rank="2" data-rk-score="840" data-rk-search="alexa grasso mx flyweight">
              <td class="rk-td-rank px-4 py-3">
                <div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a">2</div>
                <div class="mt-1"><span class="rk-rank-move" style="color:#dc2626"><span class="rk-rank-arr">&#8595;</span> 1</span></div>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <a href="/fighter/alexa-grasso" class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
                    <img src="http://mma-future.local/wp-content/uploads/2025/10/Abe_Alsaghir_cropped.jpg" alt="Alexa Grasso" class="w-full h-full object-cover" loading="lazy">
                  </a>
                  <div class="min-w-0">
                    <a href="/fighter/alexa-grasso" class="font-semibold leading-tight block truncate" style="color:#0f172a;transition:color .15s;text-decoration:none" onmouseover="this.style.color='#0047A8'" onmouseout="this.style.color='#0f172a'"> Alexa <span style="font-weight:900">Grasso</span></a>
                    <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">MX</div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8">Flyweight</td>
              <td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">840</span></td>
              <td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569">16<span style="color:#cbd5e1">&ndash;</span>4<span style="color:#cbd5e1">&ndash;</span>1</td>
              <td class="px-4 py-3"><span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(220,38,38,.08);color:#dc2626;font-size:.7rem;font-weight:800;letter-spacing:.04em">L1</span></td>
              <td class="px-4 py-3 text-sm whitespace-nowrap"><span style="color:#dc2626;font-weight:800">L</span> <span style="color:#94a3b8;font-size:.8em">(DEC)</span> <span style="color:#64748b">vs Shevchenko</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Sep 14</span></td>
              <td class="px-4 py-3">
                <button class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="9" aria-expanded="false" aria-label="Expand fighter stats" style="border:1px solid #e2e8f0;background:#fff;color:#64748b">
                  <svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
              </td>
            </tr>
            <tr class="rk-detail-row" data-rk-detail-for="9">
              <td colspan="8" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
                <div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)">
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">3</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">KO / TKO</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">4</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Submissions</div></div>
                  <div class="text-center"><div class="text-xl font-black tabular-nums" style="color:#0f172a">9</div><div class="text-xs uppercase tracking-widest mt-1 font-semibold" style="color:#94a3b8">Decisions</div></div>
                  <div class="text-xs font-medium" style="color:#94a3b8">Event: <span style="color:#64748b">UFC 306</span></div>
                  <div class="ml-auto"><a href="/fighter/alexa-grasso" class="inline-flex items-center gap-1.5 h-10 px-4 text-sm font-semibold rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a></div>
                </div>
              </td>
            </tr>

          </tbody>
        </table>
      </div>

      <div id="rk-cards" class="sm:hidden space-y-2.5">

        <!-- Card 1: Jon Jones -->
        <div class="rk-card rounded-2xl p-4" style="background:#fff;border:1px solid rgba(226,232,240,0.7)" data-rk-id="1" data-rk-slug="jon-jones" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="jon jones" data-rk-nick="bones" data-rk-country="us" data-rk-rank="1" data-rk-score="950" data-rk-search="jon jones bones us heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a">1</div>
              <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 3</span></div>
            </div>
            <a href="/fighter/jon-jones" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" alt="Jon Jones" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/jon-jones" class="font-semibold block truncate leading-tight" style="color:#0f172a;text-decoration:none">Jon <span style="font-weight:900">Jones</span></a>
              <div class="text-xs truncate mt-0.5" style="color:#94a3b8;font-style:italic">&ldquo;Bones&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">US</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">950</div>
              <div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
            <span class="font-mono tabular-nums">27&ndash;1&ndash;1</span>
            <span style="color:#e2e8f0">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W1</span>
            <span style="color:#e2e8f0">|</span>
            <span style="color:#94a3b8">Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(KO)</span> <span style="color:#64748b">vs Miocic</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Nov 16</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">&#9662;</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">11</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">KO/TKO</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">7</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Subs</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">9</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Dec</div></div>
              </div>
              <a href="/fighter/jon-jones" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 2: Tom Aspinall -->
        <div class="rk-card rounded-2xl p-4" style="background:#fff;border:1px solid rgba(226,232,240,0.7)" data-rk-id="2" data-rk-slug="tom-aspinall" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="tom aspinall" data-rk-nick="" data-rk-country="gb" data-rk-rank="2" data-rk-score="948" data-rk-search="tom aspinall gb heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a">2</div>
              <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 2</span></div>
            </div>
            <a href="/fighter/tom-aspinall" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" alt="Tom Aspinall" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/tom-aspinall" class="font-semibold block truncate leading-tight" style="color:#0f172a;text-decoration:none">Tom <span style="font-weight:900">Aspinall</span></a>
              <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">GB</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">948</div>
              <div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
            <span class="font-mono tabular-nums">15&ndash;3</span>
            <span style="color:#e2e8f0">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W4</span>
            <span style="color:#e2e8f0">|</span>
            <span style="color:#94a3b8">Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(TKO)</span> <span style="color:#64748b">vs Blaydes</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Jul 27</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">&#9662;</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">11</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">KO/TKO</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">1</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Subs</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">3</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Dec</div></div>
              </div>
              <a href="/fighter/tom-aspinall" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 3: Ciryl Gane -->
        <div class="rk-card rounded-2xl p-4" style="background:#fff;border:1px solid rgba(226,232,240,0.7)" data-rk-id="3" data-rk-slug="ciryl-gane" data-rk-gender="male" data-rk-division="heavyweight" data-rk-division-label="Heavyweight" data-rk-name="ciryl gane" data-rk-nick="bon gamin" data-rk-country="fr" data-rk-rank="3" data-rk-score="943" data-rk-search="ciryl gane bon gamin fr heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a">3</div>
              <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 1</span></div>
            </div>
            <a href="/fighter/ciryl-gane" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abe_Alsaghir_cropped.jpg" alt="Ciryl Gane" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/ciryl-gane" class="font-semibold block truncate leading-tight" style="color:#0f172a;text-decoration:none">Ciryl <span style="font-weight:900">Gane</span></a>
              <div class="text-xs truncate mt-0.5" style="color:#94a3b8;font-style:italic">&ldquo;Bon Gamin&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">FR</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">943</div>
              <div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
            <span class="font-mono tabular-nums">12&ndash;2</span>
            <span style="color:#e2e8f0">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W3</span>
            <span style="color:#e2e8f0">|</span>
            <span style="color:#94a3b8">Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(DEC)</span> <span style="color:#64748b">vs Volkov</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Sep 2</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">&#9662;</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">5</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">KO/TKO</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">3</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Subs</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">4</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Dec</div></div>
              </div>
              <a href="/fighter/ciryl-gane" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 4: Alex Pereira -->
        <div class="rk-card rounded-2xl p-4" style="background:#fff;border:1px solid rgba(226,232,240,0.7)" data-rk-id="4" data-rk-slug="alex-pereira" data-rk-gender="male" data-rk-division="light-heavyweight" data-rk-division-label="Light Heavyweight" data-rk-name="alex pereira" data-rk-nick="poatan" data-rk-country="br" data-rk-rank="1" data-rk-score="944" data-rk-search="alex pereira poatan br light heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a">1</div>
              <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 3</span></div>
            </div>
            <a href="/fighter/alex-pereira" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdullo_Khodzhaev-hero-1200x1165-1_cropped.jpg" alt="Alex Pereira" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/alex-pereira" class="font-semibold block truncate leading-tight" style="color:#0f172a;text-decoration:none">Alex <span style="font-weight:900">Pereira</span></a>
              <div class="text-xs truncate mt-0.5" style="color:#94a3b8;font-style:italic">&ldquo;Poatan&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">BR</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">944</div>
              <div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
            <span class="font-mono tabular-nums">12&ndash;2</span>
            <span style="color:#e2e8f0">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W4</span>
            <span style="color:#e2e8f0">|</span>
            <span style="color:#94a3b8">Light Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(KO)</span> <span style="color:#64748b">vs Prochazka</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Jun 29</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">&#9662;</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">10</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">KO/TKO</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">0</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Subs</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">2</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Dec</div></div>
              </div>
              <a href="/fighter/alex-pereira" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 5: Jiri Prochazka -->
        <div class="rk-card rounded-2xl p-4" style="background:#fff;border:1px solid rgba(226,232,240,0.7)" data-rk-id="5" data-rk-slug="jiri-prochazka" data-rk-gender="male" data-rk-division="light-heavyweight" data-rk-division-label="Light Heavyweight" data-rk-name="jiri prochazka" data-rk-nick="denisa" data-rk-country="cz" data-rk-rank="2" data-rk-score="903" data-rk-search="jiri prochazka denisa cz light heavyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a">2</div>
              <div class="mt-1"><span class="rk-rank-move" style="color:#dc2626"><span class="rk-rank-arr">&#8595;</span> 1</span></div>
            </div>
            <a href="/fighter/jiri-prochazka" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/abdulaziz-datsilaev_500x500.jpg" alt="Jiri Prochazka" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/jiri-prochazka" class="font-semibold block truncate leading-tight" style="color:#0f172a;text-decoration:none">Jiri <span style="font-weight:900">Prochazka</span></a>
              <div class="text-xs truncate mt-0.5" style="color:#94a3b8;font-style:italic">&ldquo;Denisa&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">CZ</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">903</div>
              <div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
            <span class="font-mono tabular-nums">30&ndash;5&ndash;1</span>
            <span style="color:#e2e8f0">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(220,38,38,.08);color:#dc2626;font-size:.7rem;font-weight:800;letter-spacing:.04em">L2</span>
            <span style="color:#e2e8f0">|</span>
            <span style="color:#94a3b8">Light Heavyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#dc2626;font-weight:800">L</span> <span style="color:#94a3b8;font-size:.8em">(KO)</span> <span style="color:#64748b">vs Pereira</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Jun 29</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">&#9662;</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">26</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">KO/TKO</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">1</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Subs</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">3</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Dec</div></div>
              </div>
              <a href="/fighter/jiri-prochazka" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 6: Islam Makhachev -->
        <div class="rk-card rounded-2xl p-4" style="background:#fff;border:1px solid rgba(226,232,240,0.7)" data-rk-id="6" data-rk-slug="islam-makhachev" data-rk-gender="male" data-rk-division="lightweight" data-rk-division-label="Lightweight" data-rk-name="islam makhachev" data-rk-nick="" data-rk-country="ru" data-rk-rank="1" data-rk-score="890" data-rk-search="islam makhachev ru lightweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a">1</div>
              <div class="mt-1"><span style="color:#cbd5e1;font-size:.7rem">&mdash;</span></div>
            </div>
            <a href="/fighter/islam-makhachev" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Aaron-Beltran.png" alt="Islam Makhachev" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/islam-makhachev" class="font-semibold block truncate leading-tight" style="color:#0f172a;text-decoration:none">Islam <span style="font-weight:900">Makhachev</span></a>
              <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">RU</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">890</div>
              <div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
            <span class="font-mono tabular-nums">26&ndash;1</span>
            <span style="color:#e2e8f0">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W3</span>
            <span style="color:#e2e8f0">|</span>
            <span style="color:#94a3b8">Lightweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(SUB)</span> <span style="color:#64748b">vs Poirier</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Jun 1</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">&#9662;</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">5</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">KO/TKO</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">11</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Subs</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">10</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Dec</div></div>
              </div>
              <a href="/fighter/islam-makhachev" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 7: Zhang Weili -->
        <div class="rk-card rounded-2xl p-4" style="background:#fff;border:1px solid rgba(226,232,240,0.7)" data-rk-id="7" data-rk-slug="zhang-weili" data-rk-gender="female" data-rk-division="strawweight" data-rk-division-label="Strawweight" data-rk-name="zhang weili" data-rk-nick="magnum" data-rk-country="cn" data-rk-rank="1" data-rk-score="870" data-rk-search="zhang weili magnum cn strawweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a">1</div>
              <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 2</span></div>
            </div>
            <a href="/fighter/zhang-weili" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abdulgadzhi_Gaziev-Hero-1200x1165-1-600x583_cropped.jpg" alt="Zhang Weili" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/zhang-weili" class="font-semibold block truncate leading-tight" style="color:#0f172a;text-decoration:none">Zhang <span style="font-weight:900">Weili</span></a>
              <div class="text-xs truncate mt-0.5" style="color:#94a3b8;font-style:italic">&ldquo;Magnum&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">CN</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">870</div>
              <div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
            <span class="font-mono tabular-nums">24&ndash;3</span>
            <span style="color:#e2e8f0">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W2</span>
            <span style="color:#e2e8f0">|</span>
            <span style="color:#94a3b8">Strawweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(DEC)</span> <span style="color:#64748b">vs Yan</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Apr 13</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">&#9662;</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">10</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">KO/TKO</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">7</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Subs</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">7</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Dec</div></div>
              </div>
              <a href="/fighter/zhang-weili" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 8: Valentina Shevchenko -->
        <div class="rk-card rounded-2xl p-4" style="background:#fff;border:1px solid rgba(226,232,240,0.7)" data-rk-id="8" data-rk-slug="valentina-shevchenko" data-rk-gender="female" data-rk-division="flyweight" data-rk-division-label="Flyweight" data-rk-name="valentina shevchenko" data-rk-nick="bullet" data-rk-country="kg" data-rk-rank="1" data-rk-score="860" data-rk-search="valentina shevchenko bullet kg flyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a">1</div>
              <div class="mt-1"><span class="rk-rank-move" style="color:#16a34a"><span class="rk-rank-arr">&#8593;</span> 1</span></div>
            </div>
            <a href="/fighter/valentina-shevchenko" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Aboubakar-Younusov.jpg" alt="Valentina Shevchenko" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/valentina-shevchenko" class="font-semibold block truncate leading-tight" style="color:#0f172a;text-decoration:none">Valentina <span style="font-weight:900">Shevchenko</span></a>
              <div class="text-xs truncate mt-0.5" style="color:#94a3b8;font-style:italic">&ldquo;Bullet&rdquo;</div>
              <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">KG</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">860</div>
              <div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
            <span class="font-mono tabular-nums">23&ndash;4&ndash;1</span>
            <span style="color:#e2e8f0">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(22,163,74,.08);color:#16a34a;font-size:.7rem;font-weight:800;letter-spacing:.04em">W1</span>
            <span style="color:#e2e8f0">|</span>
            <span style="color:#94a3b8">Flyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#16a34a;font-weight:800">W</span> <span style="color:#94a3b8;font-size:.8em">(DEC)</span> <span style="color:#64748b">vs Grasso</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Sep 14</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">&#9662;</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">8</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">KO/TKO</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">7</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Subs</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">8</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Dec</div></div>
              </div>
              <a href="/fighter/valentina-shevchenko" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

        <!-- Card 9: Alexa Grasso -->
        <div class="rk-card rounded-2xl p-4" style="background:#fff;border:1px solid rgba(226,232,240,0.7)" data-rk-id="9" data-rk-slug="alexa-grasso" data-rk-gender="female" data-rk-division="flyweight" data-rk-division-label="Flyweight" data-rk-name="alexa grasso" data-rk-nick="" data-rk-country="mx" data-rk-rank="2" data-rk-score="840" data-rk-search="alexa grasso mx flyweight">
          <div class="flex items-center gap-3">
            <div class="text-center shrink-0" style="min-width:2rem">
              <div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a">2</div>
              <div class="mt-1"><span class="rk-rank-move" style="color:#dc2626"><span class="rk-rank-arr">&#8595;</span> 1</span></div>
            </div>
            <a href="/fighter/alexa-grasso" class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" style="border:1px solid #e2e8f0">
              <img src="http://mma-future.local/wp-content/uploads/2025/10/Abe_Alsaghir_cropped.jpg" alt="Alexa Grasso" class="w-full h-full object-cover" loading="lazy">
            </a>
            <div class="flex-1 min-w-0">
              <a href="/fighter/alexa-grasso" class="font-semibold block truncate leading-tight" style="color:#0f172a;text-decoration:none">Alexa <span style="font-weight:900">Grasso</span></a>
              <div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em">MX</div>
            </div>
            <div class="shrink-0 text-right">
              <div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8">840</div>
              <div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
            <span class="font-mono tabular-nums">16&ndash;4&ndash;1</span>
            <span style="color:#e2e8f0">|</span>
            <span style="display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:9999px;background:rgba(220,38,38,.08);color:#dc2626;font-size:.7rem;font-weight:800;letter-spacing:.04em">L1</span>
            <span style="color:#e2e8f0">|</span>
            <span style="color:#94a3b8">Flyweight</span>
          </div>
          <div class="mt-2 text-xs"><span style="color:#dc2626;font-weight:800">L</span> <span style="color:#94a3b8;font-size:.8em">(DEC)</span> <span style="color:#64748b">vs Shevchenko</span> <span style="color:#e2e8f0">&middot;</span> <span style="color:#94a3b8">Sep 14</span></div>
          <details class="mt-3">
            <summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">&#9662;</span></summary>
            <div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)">
              <div class="grid grid-cols-3 gap-3 text-center mb-4">
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">3</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">KO/TKO</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">4</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Subs</div></div>
                <div><div class="text-lg font-black tabular-nums" style="color:#0f172a">9</div><div class="text-xs mt-0.5 font-semibold uppercase tracking-wider" style="color:#94a3b8">Dec</div></div>
              </div>
              <a href="/fighter/alexa-grasso" class="flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg></a>
            </div>
          </details>
        </div>

      </div>

    </div>

    <div id="rk-empty" class="hidden text-center py-20">
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl mb-5" style="background:#f1f5f9;border:1px solid rgba(226,232,240,0.7)">
        <svg class="w-7 h-7" style="color:#cbd5e1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>
      <p class="text-base font-bold mb-1" style="color:#0f172a">No fighters found</p>
      <p class="text-sm mb-6" style="color:#94a3b8">Try adjusting your filters or search.</p>
      <button id="rk-reset" class="px-5 py-2.5 text-sm font-semibold rounded-xl transition-colors" style="border:1px solid #bfdbfe;color:#0047A8;background:#eff6ff;cursor:pointer">Reset filters</button>
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
      r.style.background = z ? '#fafbfc' : '';
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
      ch.style.borderColor = on ? '#0047A8' : '#e2e8f0';
      ch.style.color = on ? '#fff' : '#334155';
      ch.style.background = on ? '#0047A8' : '#fff';
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
      btn.style.background = on ? '#0047A8' : '';
      btn.style.color = on ? '#fff' : '';
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

  function updateRowNumbers() {
    var idx = 1;
    Array.from(tbody.querySelectorAll('tr.rk-main-row')).forEach(function (r) {
      if (r.hidden) return;
      var el = r.querySelector('.rk-rank-num');
      if (el) el.textContent = idx;
      idx++;
    });
    var cidx = 1;
    cards.forEach(function (c) {
      if (c.hidden) return;
      var el = c.querySelector('.rk-rank-num');
      if (el) el.textContent = cidx;
      cidx++;
    });
  }

  function renderAll() {
    applyFilters();
    applySort();
    applyZebra();
    updateRowNumbers();
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
