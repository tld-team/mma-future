(() => {
  'use strict';
  const PAGE_SIZE = 3;
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const link = (url, label, external = false) => url ? `<a href="${esc(url)}"${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>${esc(label)}</a>` : esc(label);
  const isoToday = timezone => new Intl.DateTimeFormat('en-CA', {timeZone: timezone, year:'numeric', month:'2-digit', day:'2-digit'}).format(new Date());
  const shiftMonth = (month, delta) => {
    const [year, value] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, value - 1 + delta, 1));
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
  };
  const dateLabel = date => new Intl.DateTimeFormat('en-US', {weekday:'long', day:'numeric', month:'long', year:'numeric', timeZone:'UTC'}).format(new Date(`${date}T12:00:00Z`));
  const eventCount = total => `${total} ${total === 1 ? 'event' : 'events'}`;

  class Calendar {
    constructor(root) {
      this.root = root;
      this.dayRoot = root.querySelector('[data-calendar-day]');
      this.gridRoot = root.querySelector('[data-calendar-grid]');
      this.month = root.dataset.month;
      this.selected = root.dataset.selected;
      this.timezone = root.dataset.timezone;
      this.page = this.startPage = Number(this.dayRoot.dataset.page) || 1;
      this.total = Number(this.dayRoot.dataset.total) || 0;
      this.generation = 0;
      this.dayRequestId = 0;
      this.dayLoading = false;
      this.monthLoading = false;
      const url = new URL(window.location.href);
      let saved;
      try { saved = localStorage.getItem('mmafCalendarTimezone'); } catch (_) { /* Storage is optional. */ }
      const timezoneSelect = root.querySelector('[data-calendar-timezone]');
      if (!url.searchParams.has('calendar_tz') && [...timezoneSelect.options].some(option => option.value === saved)) this.timezone = saved;
      timezoneSelect.value = this.timezone;
      root.addEventListener('click', event => this.click(event));
      root.addEventListener('keydown', event => this.keydown(event));
      root.addEventListener('toggle', event => {
        const details = event.target;
        if (!details.matches('[data-calendar-event]') || !details.open) return;
        this.dayRoot.querySelectorAll('[data-calendar-event][open]').forEach(other => { if (other !== details) other.open = false; });
      }, true);
      timezoneSelect.addEventListener('change', event => {
        this.timezone = event.target.value;
        try { localStorage.setItem('mmafCalendarTimezone', this.timezone); } catch (_) { /* Storage is optional. */ }
        this.selected = isoToday(this.timezone);
        this.month = this.selected.slice(0, 7);
        this.load();
      });
      this.renderFooter();
      if (this.timezone !== root.dataset.timezone) this.load();
    }
    announce(message) { this.root.querySelector('[data-calendar-status]').textContent = message; }
    resetRequests() {
      this.generation++;
      this.monthRequest?.abort();
      this.dayRequest?.abort();
      this.dayLoading = this.monthLoading = false;
      this.gridRoot.inert = false;
      this.gridRoot.removeAttribute('aria-busy');
      this.dayRoot.removeAttribute('aria-busy');
    }
    click(event) {
      const date = event.target.closest('[data-calendar-date]');
      if (date && !this.monthLoading) {
        this.resetRequests();
        this.selected = date.dataset.calendarDate;
        this.markSelected();
        this.updateUrl();
        this.loadDay();
        return;
      }
      const action = event.target.closest('[data-calendar-action]')?.dataset.calendarAction;
      if (!action) return;
      if (action === 'more') { if (!this.dayLoading) this.loadDay({append:true, page:this.page + 1}); return; }
      if (action === 'fewer' || action === 'first') {
        this.resetRequests();
        this.updateUrl();
        if (this.startPage === 1 && action === 'fewer') {
          this.dayRoot.querySelectorAll('[data-calendar-event]').forEach((item, index) => { if (index >= PAGE_SIZE) item.remove(); else item.open = false; });
          this.page = 1;
          this.dayRoot.querySelector('[data-calendar-feedback]').textContent = '';
          this.renderFooter();
          this.focusFirst();
          this.announce(`Showing ${this.count()} of ${this.total} events.`);
        } else this.loadDay({focusResults:true});
        return;
      }
      if (action === 'retry-day') { this.loadDay(); return; }
      if (action === 'retry-month') { this.load(); return; }
      if (action === 'previous') this.month = shiftMonth(this.month, -1);
      else if (action === 'next') this.month = shiftMonth(this.month, 1);
      else if (action === 'today') { this.selected = isoToday(this.timezone); this.month = this.selected.slice(0, 7); }
      else return;
      this.load();
    }
    keydown(event) {
      const button = event.target.closest('[data-calendar-date]');
      if (!button || !['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)) return;
      event.preventDefault();
      const buttons = [...this.root.querySelectorAll('[data-calendar-date]')];
      const step = {ArrowLeft:-1, ArrowRight:1, ArrowUp:-7, ArrowDown:7}[event.key];
      buttons[buttons.indexOf(button) + step]?.focus();
    }
    async load() {
      this.resetRequests();
      const generation = this.generation;
      this.monthRequest = new AbortController();
      this.monthLoading = true;
      this.gridRoot.inert = true;
      this.gridRoot.setAttribute('aria-busy', 'true');
      this.dayRoot.innerHTML = '<p class="mmaf-calendar__empty">Loading schedule…</p>';
      this.announce('Loading calendar.');
      this.updateUrl();
      try {
        const url = new URL(this.root.dataset.endpoint);
        url.searchParams.set('month', this.month);
        url.searchParams.set('timezone', this.timezone);
        const response = await fetch(url, {headers:{Accept:'application/json'}, signal:this.monthRequest.signal});
        if (!response.ok) throw new Error('The calendar is currently unavailable.');
        const data = await response.json();
        if (generation !== this.generation) return;
        this.root.querySelector('.mmaf-calendar__month').textContent = data.label;
        this.gridRoot.innerHTML = this.grid(data);
        this.root.querySelector('[data-calendar-refreshed]').textContent = data.coverage?.last_refreshed ? `Last updated: ${new Intl.DateTimeFormat('en-US', {dateStyle:'medium', timeStyle:'short'}).format(new Date(data.coverage.last_refreshed))}` : 'No successful schedule import yet.';
        this.monthLoading = false;
        this.gridRoot.inert = false;
        this.gridRoot.removeAttribute('aria-busy');
        if (this.selected.slice(0, 7) === this.month) await this.loadDay();
        else {
          this.dayRoot.innerHTML = '<p class="mmaf-calendar__empty">Select an available day.</p>';
          this.announce(`${data.label}. Select an available day.`);
        }
      } catch (error) {
        if (generation !== this.generation || error.name === 'AbortError') return;
        this.dayRoot.innerHTML = this.error('The calendar is currently unavailable.', 'retry-month');
        this.announce('Calendar could not be loaded. Please try again.');
        // The old grid does not belong to the requested month/time zone.
        this.gridRoot.innerHTML = '<p class="mmaf-calendar__empty">Schedule unavailable.</p>';
      } finally {
        if (generation === this.generation) {
          this.monthLoading = false;
          this.gridRoot.inert = false;
          this.gridRoot.removeAttribute('aria-busy');
        }
      }
    }
    async loadDay({append = false, page = 1, focusResults = false} = {}) {
      this.dayRequest?.abort();
      const requestId = ++this.dayRequestId;
      const generation = this.generation;
      this.dayRequest = new AbortController();
      this.dayLoading = true;
      const date = this.selected;
      const timezone = this.timezone;
      const oldCount = append ? this.count() : 0;
      let failed = false;
      if (!append) this.dayRoot.innerHTML = this.heading(date) + '<p class="mmaf-calendar__empty">Loading events…</p>';
      else {
        this.dayRoot.querySelector('[data-calendar-feedback]').textContent = '';
        this.renderFooter();
      }
      this.dayRoot.setAttribute('aria-busy', 'true');
      this.announce(append ? 'Loading more events.' : `Loading events for ${dateLabel(date)}.`);
      const current = () => generation === this.generation && requestId === this.dayRequestId;
      try {
        const url = new URL(this.root.dataset.dayEndpoint);
        url.searchParams.set('date', date);
        url.searchParams.set('timezone', timezone);
        url.searchParams.set('page', page);
        url.searchParams.set('per_page', PAGE_SIZE);
        const response = await fetch(url, {headers:{Accept:'application/json'}, signal:this.dayRequest.signal});
        if (!response.ok) throw new Error('The daily schedule is currently unavailable.');
        const data = await response.json();
        if (!current()) return;
        this.total = data.total;
        this.page = data.page;
        if (append) {
          const ids = new Set([...this.dayRoot.querySelectorAll('[data-calendar-event]')].map(item => item.dataset.calendarEvent));
          const events = data.events.filter(event => !ids.has(String(event.id)));
          this.dayRoot.querySelector('[data-calendar-list]').insertAdjacentHTML('beforeend', events.map(event => this.event(event)).join(''));
          this.dayRoot.querySelector('.mmaf-calendar__total').textContent = eventCount(this.total);
        } else {
          this.startPage = data.page;
          this.dayRoot.innerHTML = this.day(data);
        }
        this.dayLoading = false;
        this.renderFooter();
        if (append && this.count() > oldCount) this.dayRoot.querySelectorAll('summary')[oldCount]?.focus({preventScroll:true});
        else if (focusResults) this.focusFirst();
        this.announce(`${dateLabel(date)}: showing ${this.count()} of ${this.total} events.`);
      } catch (error) {
        if (!current() || error.name === 'AbortError') return;
        failed = true;
        if (append) {
          this.dayRoot.querySelector('[data-calendar-feedback]').innerHTML = '<p class="mmaf-calendar__empty">More events could not be loaded. Use “Show more” to try again.</p>';
        } else this.dayRoot.innerHTML = this.heading(date) + this.error('The daily schedule is currently unavailable.', 'retry-day');
        this.announce(append ? 'More events could not be loaded. Please try again.' : 'Events could not be loaded. Please try again.');
      } finally {
        if (current()) {
          this.dayLoading = false;
          this.dayRoot.removeAttribute('aria-busy');
          this.renderFooter();
          if (append && failed) this.dayRoot.querySelector('[data-calendar-action="more"]')?.focus({preventScroll:true});
        }
      }
    }
    count() { return this.dayRoot.querySelectorAll('[data-calendar-event]').length; }
    focusFirst() {
      this.dayRoot.querySelector('summary')?.focus({preventScroll:true});
      // Removing a long list can leave the viewport in the following section.
      // Respect the day panel's scroll margin below the site's sticky header.
      this.dayRoot.scrollIntoView?.({block:'start', behavior:'instant'});
    }
    error(message, action) { return `<div class="mmaf-calendar__empty"><p>${esc(message)}</p><button type="button" class="mmaf-calendar__button" data-calendar-action="${action}">Try again</button></div>`; }
    renderFooter() {
      const footer = this.dayRoot.querySelector('[data-calendar-footer]');
      if (!footer) return;
      const count = this.count();
      const remaining = Math.max(0, this.total - this.page * PAGE_SIZE);
      const first = (this.startPage - 1) * PAGE_SIZE + 1;
      const range = this.startPage === 1 ? count : `${first}–${first + count - 1}`;
      const disabled = this.dayLoading ? ' disabled' : '';
      let actions = '';
      if (remaining) actions += `<button type="button" class="mmaf-calendar__button" data-calendar-action="more"${disabled}>${this.dayLoading ? 'Loading…' : `Show ${Math.min(PAGE_SIZE, remaining)} more`}</button>`;
      if (count > PAGE_SIZE || this.startPage > 1) actions += `<button type="button" class="mmaf-calendar__button mmaf-calendar__button--quiet" data-calendar-action="${this.startPage > 1 ? 'first' : 'fewer'}"${disabled}>${this.startPage > 1 ? 'Show first 3' : 'Show fewer'}</button>`;
      footer.innerHTML = this.total ? `<p class="mmaf-calendar__count">Showing ${range} of ${this.total}</p><div class="mmaf-calendar__actions">${actions}</div>` : '';
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
      url.searchParams.delete('calendar_page');
      history.replaceState({}, '', url);
    }
    grid(data) {
      const offset = (data.days[0]?.weekday || 1) - 1;
      const cells = Array(offset).fill('<td class="mmaf-calendar__blank" aria-hidden="true"></td>');
      for (const day of data.days) {
        const selectable = day.state !== 'past' && (day.state !== 'uncovered' || day.event_count > 0);
        const classes = ['mmaf-calendar__cell', `is-${day.state}`, day.is_today ? 'is-today' : '', day.date === this.selected ? 'is-selected' : ''].filter(Boolean).join(' ');
        const content = `<span class="mmaf-calendar__number">${day.day}</span><span class="mmaf-calendar__mobile-count" aria-hidden="true">${day.event_count || ''}</span>`;
        const label = `${dateLabel(day.date)}, ${eventCount(day.event_count)}`;
        cells.push(`<td class="${classes}" data-date="${day.date}">${selectable ? `<button type="button" data-calendar-date="${day.date}" aria-label="${esc(label)}" aria-controls="${esc(this.dayRoot.id)}" aria-pressed="${day.date === this.selected}"${day.is_today ? ' aria-current="date"' : ''}>${content}</button>` : `<div class="mmaf-calendar__disabled">${content}</div>`}</td>`);
      }
      while (cells.length % 7) cells.push('<td class="mmaf-calendar__blank" aria-hidden="true"></td>');
      let rows = '';
      for (let i = 0; i < cells.length; i += 7) rows += `<tr>${cells.slice(i, i + 7).join('')}</tr>`;
      return `<table class="mmaf-calendar__grid"><caption class="screen-reader-text">${esc(data.label)}</caption><thead><tr>${['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map(value => `<th scope="col">${value}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table>`;
    }
    heading(date, total) {
      return `<div class="mmaf-calendar__day-heading"><p>Selected day</p><h3>${esc(dateLabel(date))}</h3>${total === undefined ? '' : `<span class="mmaf-calendar__total">${eventCount(total)}</span>`}</div>`;
    }
    day(data) {
      return this.heading(data.date, data.total) + (data.message ? `<p class="mmaf-calendar__empty">${esc(data.message)}</p>` : '') + `<div class="mmaf-calendar__list" data-calendar-list>${data.events.map(event => this.event(event)).join('')}</div><div class="mmaf-calendar__footer" data-calendar-footer></div><div data-calendar-feedback></div>`;
    }
    event(event) {
      const place = [event.venue, event.location].filter(Boolean).join(' · ') || 'Location TBC';
      const count = event.bouts.length;
      const bouts = event.bouts.map(bout => `<li><span>${link(bout.fighter_a.profile_url || bout.fighter_a.source_url, bout.fighter_a.name, !bout.fighter_a.profile_url)}</span><b>vs</b><span>${link(bout.fighter_b.profile_url || bout.fighter_b.source_url, bout.fighter_b.name, !bout.fighter_b.profile_url)}</span>${bout.weight_class ? `<small>${esc(bout.weight_class)}</small>` : ''}</li>`).join('');
      const datetime = event.date + (/^\d{2}:\d{2}$/.test(event.time || '') ? `T${event.time}` : '');
      return `<details class="mmaf-calendar__event" name="${esc(this.root.id)}-events" data-calendar-event="${esc(event.id)}"><summary class="mmaf-calendar__summary"><span class="mmaf-calendar__event-head"><span><span class="mmaf-calendar__promotion">${esc(event.promotion || 'MMA event')}</span><span class="mmaf-calendar__event-title">${esc(event.title)}</span></span><time datetime="${esc(datetime)}">${esc(event.time || 'Time TBC')}</time></span><span class="mmaf-calendar__meta">${esc(event.location || event.venue || 'Location TBC')}</span><span class="mmaf-calendar__summary-bottom"><span>${count ? `${count} ${count === 1 ? 'bout' : 'bouts'}` : 'Fight card TBC'}</span><span class="mmaf-calendar__disclosure"><span class="mmaf-calendar__when-closed">View fight card</span><span class="mmaf-calendar__when-open">Hide fight card</span></span></span></summary><div class="mmaf-calendar__event-body"><p class="mmaf-calendar__meta">${esc(place)}</p>${event.time_note ? `<p class="mmaf-calendar__note">${esc(event.time_note)}</p>` : ''}${count ? '' : '<p class="mmaf-calendar__note">Fight card to be confirmed.</p>'}<ol class="mmaf-calendar__bouts">${bouts}</ol>${event.source_url ? `<a class="mmaf-calendar__source" href="${esc(event.source_url)}" target="_blank" rel="noopener noreferrer">View on Tapology ↗</a>` : ''}</div></details>`;
    }
  }
  document.querySelectorAll('.mmaf-calendar').forEach(root => new Calendar(root));
})();
