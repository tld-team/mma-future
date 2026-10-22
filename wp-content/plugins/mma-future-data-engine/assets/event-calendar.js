(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const link = (url, label, external = false) => url ? `<a href="${esc(url)}"${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>${esc(label)}</a>` : esc(label);
  const isoToday = timezone => new Intl.DateTimeFormat('en-CA', {timeZone: timezone, year:'numeric', month:'2-digit', day:'2-digit'}).format(new Date());
  const shiftMonth = (month, delta) => {
    const [year, value] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, value - 1 + delta, 1));
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
  };
  const dateLabel = date => new Intl.DateTimeFormat('sr-Latn-RS', {weekday:'long', day:'2-digit', month:'long', year:'numeric', timeZone:'UTC'}).format(new Date(`${date}T12:00:00Z`));

  class Calendar {
    constructor(root) {
      this.root = root;
      this.month = root.dataset.month;
      this.selected = root.dataset.selected;
      this.timezone = root.dataset.timezone;
      const url = new URL(window.location.href);
      const saved = localStorage.getItem('mmafCalendarTimezone');
      if (!url.searchParams.has('calendar_tz') && saved && root.querySelector(`[data-calendar-timezone] option[value="${CSS.escape(saved)}"]`)) this.timezone = saved;
      root.querySelector('[data-calendar-timezone]').value = this.timezone;
      root.addEventListener('click', event => this.click(event));
      root.addEventListener('keydown', event => this.keydown(event));
      root.querySelector('[data-calendar-timezone]').addEventListener('change', event => {
        this.timezone = event.target.value;
        localStorage.setItem('mmafCalendarTimezone', this.timezone);
        this.month = isoToday(this.timezone).slice(0, 7);
        this.selected = isoToday(this.timezone);
        this.load(true);
      });
      if (this.timezone !== root.dataset.timezone) this.load(false);
    }
    click(event) {
      const date = event.target.closest('[data-calendar-date]');
      if (date) { this.selected = date.dataset.calendarDate; this.loadDay(); this.markSelected(); this.updateUrl(); return; }
      const action = event.target.closest('[data-calendar-action]')?.dataset.calendarAction;
      if (!action) return;
      if (action === 'previous') this.month = shiftMonth(this.month, -1);
      if (action === 'next') this.month = shiftMonth(this.month, 1);
      if (action === 'today') { this.selected = isoToday(this.timezone); this.month = this.selected.slice(0, 7); }
      this.load(true);
    }
    keydown(event) {
      const button = event.target.closest('[data-calendar-date]');
      if (!button || !['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)) return;
      event.preventDefault();
      const buttons = [...this.root.querySelectorAll('[data-calendar-date]')];
      const step = {ArrowLeft:-1, ArrowRight:1, ArrowUp:-7, ArrowDown:7}[event.key];
      const target = buttons[buttons.indexOf(button) + step];
      if (target) target.focus();
    }
    async load(focusGrid) {
      this.root.classList.add('is-loading');
      try {
        const monthUrl = new URL(this.root.dataset.endpoint);
        monthUrl.searchParams.set('month', this.month);
        monthUrl.searchParams.set('timezone', this.timezone);
        const response = await fetch(monthUrl, {headers:{Accept:'application/json'}});
        if (!response.ok) throw new Error('Kalendar trenutno nije dostupan.');
        const data = await response.json();
        this.root.querySelector('.mmaf-calendar__month').textContent = data.label;
        this.root.querySelector('[data-calendar-grid]').innerHTML = this.grid(data);
        this.root.querySelector('[data-calendar-refreshed]').textContent = data.coverage?.last_refreshed ? `Poslednje osvežavanje: ${new Intl.DateTimeFormat('sr-Latn-RS', {dateStyle:'medium', timeStyle:'short'}).format(new Date(data.coverage.last_refreshed))}` : 'Još nema uspešnog importa rasporeda.';
        this.updateUrl();
        if (this.selected.slice(0, 7) === this.month) await this.loadDay();
        else this.root.querySelector('[data-calendar-day]').innerHTML = '<p class="mmaf-calendar__empty">Izaberite dostupan dan.</p>';
        if (focusGrid) this.root.querySelector('[data-calendar-date]')?.focus();
      } catch (error) {
        this.root.querySelector('[data-calendar-day]').innerHTML = `<p class="mmaf-calendar__empty">${esc(error.message)}</p>`;
      } finally { this.root.classList.remove('is-loading'); }
    }
    async loadDay() {
      const url = new URL(this.root.dataset.dayEndpoint);
      url.searchParams.set('date', this.selected);
      url.searchParams.set('timezone', this.timezone);
      const response = await fetch(url, {headers:{Accept:'application/json'}});
      if (!response.ok) throw new Error('Dnevni program trenutno nije dostupan.');
      this.root.querySelector('[data-calendar-day]').innerHTML = this.day(await response.json());
    }
    markSelected() {
      this.root.querySelectorAll('[data-calendar-date]').forEach(button => {
        const selected = button.dataset.calendarDate === this.selected;
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        button.closest('td').classList.toggle('is-selected', selected);
      });
    }
    updateUrl() {
      const url = new URL(window.location.href);
      url.searchParams.set('calendar_month', this.month);
      url.searchParams.set('calendar_date', this.selected);
      url.searchParams.set('calendar_tz', this.timezone);
      history.replaceState({}, '', url);
    }
    grid(data) {
      const offset = (data.days[0]?.weekday || 1) - 1;
      const cells = Array(offset).fill('<td class="mmaf-calendar__blank" aria-hidden="true"></td>');
      for (const day of data.days) {
        const selectable = day.state !== 'past' && (day.state !== 'uncovered' || day.event_count > 0);
        const classes = ['mmaf-calendar__cell', `is-${day.state}`, day.is_today ? 'is-today' : '', day.date === this.selected ? 'is-selected' : ''].filter(Boolean).join(' ');
        const names = day.events.map(event => `<span>${esc(event.title)}</span>`).join('') + (day.event_count > 2 ? `<em>+ još ${day.event_count - 2}</em>` : '');
        const content = `<span class="mmaf-calendar__number">${day.day}</span><span class="mmaf-calendar__mobile-count">${day.event_count || ''}</span><span class="mmaf-calendar__events">${names}</span>`;
        cells.push(`<td class="${classes}" data-date="${day.date}">${selectable ? `<button type="button" data-calendar-date="${day.date}" aria-pressed="${day.date === this.selected}"${day.is_today ? ' aria-current="date"' : ''}>${content}</button>` : `<div class="mmaf-calendar__disabled">${content}</div>`}</td>`);
      }
      while (cells.length % 7) cells.push('<td class="mmaf-calendar__blank" aria-hidden="true"></td>');
      let rows = '';
      for (let i = 0; i < cells.length; i += 7) rows += `<tr>${cells.slice(i, i + 7).join('')}</tr>`;
      return `<table class="mmaf-calendar__grid"><caption class="screen-reader-text">${esc(data.label)}</caption><thead><tr>${['Pon','Uto','Sre','Čet','Pet','Sub','Ned'].map(value => `<th scope="col">${value}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table>`;
    }
    day(data) {
      let html = `<div class="mmaf-calendar__day-heading"><p>Izabrani dan</p><h3>${esc(dateLabel(data.date))}</h3></div>`;
      if (data.message) html += `<p class="mmaf-calendar__empty">${esc(data.message)}</p>`;
      for (const event of data.events) {
        const place = [event.venue, event.location].filter(Boolean).join(' · ') || 'Mesto nije potvrđeno';
        const bouts = event.bouts.map(bout => `<li><span>${link(bout.fighter_a.profile_url || bout.fighter_a.source_url, bout.fighter_a.name, !bout.fighter_a.profile_url)}</span><b>vs</b><span>${link(bout.fighter_b.profile_url || bout.fighter_b.source_url, bout.fighter_b.name, !bout.fighter_b.profile_url)}</span>${bout.weight_class ? `<small>${esc(bout.weight_class)}</small>` : ''}</li>`).join('');
        html += `<article class="mmaf-calendar__event"><div class="mmaf-calendar__event-head"><div><p>${esc(event.promotion || 'MMA događaj')}</p><h4>${esc(event.title)}</h4></div><time datetime="${esc(event.date + (event.time ? `T${event.time}` : ''))}">${esc(event.time || 'Vreme nije poznato')}</time></div><p class="mmaf-calendar__meta">${esc(place)}</p>${event.time_note ? `<p class="mmaf-calendar__note">${esc(event.time_note)}</p>` : ''}<ol class="mmaf-calendar__bouts">${bouts}</ol>${event.source_url ? `<a class="mmaf-calendar__source" href="${esc(event.source_url)}" target="_blank" rel="noopener noreferrer">Tapology događaj ↗</a>` : ''}</article>`;
      }
      return html;
    }
  }
  document.querySelectorAll('.mmaf-calendar').forEach(root => new Calendar(root));
})();
