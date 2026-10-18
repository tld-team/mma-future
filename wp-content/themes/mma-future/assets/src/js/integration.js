/* Requests and presentation controls belong to the individual block instance. */
(() => {
  'use strict';
  document.documentElement.classList.add('mmaf-js');
  const dismissImpactHelp = help => { help.classList.remove('is-open'); help.classList.add('is-dismissed'); };
  const positionImpactHelp = help => {
    const bounds = help.getBoundingClientRect(), height = help.querySelector('[role="tooltip"]').getBoundingClientRect().height;
    help.classList.toggle('is-above', bounds.bottom + height > window.innerHeight && bounds.top > window.innerHeight - bounds.bottom);
  };
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-rk-impact-help]');
    document.querySelectorAll('.rk-impact-help.is-open').forEach(help => { if (!help.contains(event.target)) dismissImpactHelp(help); });
    if (!button) return;
    const help = button.closest('.rk-impact-help');
    positionImpactHelp(help);
    if (help.classList.contains('is-open')) dismissImpactHelp(help);
    else { help.classList.remove('is-dismissed'); help.classList.add('is-open'); }
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') document.querySelectorAll('.rk-impact-help').forEach(dismissImpactHelp);
  });
  ['pointerover', 'focusin'].forEach(type => document.addEventListener(type, event => {
    const help = event.target.closest('.rk-impact-help');
    if (help && !help.contains(event.relatedTarget)) { help.classList.remove('is-dismissed'); positionImpactHelp(help); }
  }));
  document.addEventListener('pointerout', event => {
    const help = event.target.closest('.rk-impact-help');
    if (help && event.pointerType === 'mouse' && !help.contains(event.relatedTarget)) help.classList.remove('is-open');
  });
  document.querySelectorAll('[data-mmaf-browser]').forEach(root => {
    const form = root.querySelector('form'), results = root.querySelector('.mmaf-browser-results');
    const status = root.querySelector('.mmaf-browser-status'), prefix = root.dataset.prefix;
    if (!form || !results) return;
    let request, timer;
    const field = key => Array.from(form.elements).find(element => element.name === prefix + '_' + key && element.tagName !== 'BUTTON');
    const sync = () => {
      if (root.dataset.mmafBrowser !== 'rankings') return;
      const provisional = field('view')?.value === 'provisional';
      if (provisional && field('sort').value === 'rank') { field('sort').value = 'score'; field('order').value = 'desc'; }
      form.querySelectorAll('option[value="rank"], option[value^="rank|"]').forEach(option => { option.hidden = provisional; option.disabled = provisional; });
      const mobileSort = form.querySelector('[data-rk-mobile-sort]');
      mobileSort.value = field('sort').value + '|' + field('order').value;
      const board = field('board').value, gender = board === 'female' || board.startsWith('women_') ? 'female' : 'male';
      form.querySelectorAll('[data-board]').forEach(button => {
        button.setAttribute('aria-pressed', String(button.dataset.board === board || (button.classList.contains('rk-g-btn') && button.dataset.board === gender)));
        if (button.dataset.boardGender) button.hidden = button.dataset.boardGender !== 'both' && button.dataset.boardGender !== gender;
      });
      const top = form.querySelector('[data-top15]');
      top.hidden = provisional; top.setAttribute('aria-disabled', String(provisional));
      if (provisional) field('top15').value = '0';
      top.setAttribute('aria-pressed', String(field('top15').value === '1'));
      top.value = field('top15').value === '1' ? '0' : '1';
      results.querySelectorAll('[data-rk-sort]').forEach(link => {
        const active = link.dataset.rkSort === field('sort').value;
        link.classList.toggle('sk-asc', active && field('order').value === 'asc');
        link.classList.toggle('sk-desc', active && field('order').value === 'desc');
        link.closest('th').setAttribute('aria-sort', active ? (field('order').value === 'asc' ? 'ascending' : 'descending') : 'none');
      });
    };
    const load = async page => {
      if (request) request.abort();
      const current = new AbortController(); request = current;
      sync();
      const params = new URLSearchParams();
      new FormData(form).forEach((value, key) => params.set(key.slice(prefix.length + 1), value));
      params.set('page', String(page)); params.set('per_page', root.dataset.perPage);
      params.set('view_url', window.location.pathname + window.location.search);
      params.set('prefix', prefix); params.set('include_breakdown', root.dataset.breakdown || '0');
      const kind = root.dataset.mmafBrowser;
      if (kind === 'history') params.set('fighter_id', root.dataset.endpoint.split('/')[1]);
      results.setAttribute('aria-busy', 'true'); status.textContent = 'Loading results…';
      sync();
      try {
        const response = await fetch(mmafSite.rest + 'mma-future-site/v1/views/' + kind + '?' + params, {signal:current.signal});
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to load results.');
        if (request !== current) return;
        results.innerHTML = data.html;
        status.textContent = data.total + ' results. Page ' + data.page + ' of ' + Math.max(1, data.total_pages) + '.';
        sync();
      } catch (error) {
        if (error.name !== 'AbortError') status.textContent = error.message + ' Please try again.';
      } finally { if (request === current) results.removeAttribute('aria-busy'); }
    };
    form.addEventListener('submit', event => {
      event.preventDefault();
      const submitter = event.submitter;
      if (submitter?.dataset.board) field('board').value = submitter.dataset.board;
      if (submitter?.hasAttribute('data-top15')) field('top15').value = field('top15').value === '1' ? '0' : '1';
      load(1);
    });
    form.addEventListener('change', event => { if (event.target.matches('select')) { if (event.target.hasAttribute('data-rk-mobile-sort')) { const [sort, order] = event.target.value.split('|'); field('sort').value = sort; field('order').value = order; } load(1); } });
    form.addEventListener('input', event => { if (event.target.type === 'search') { clearTimeout(timer); timer = setTimeout(() => load(1), 300); } });
    root.addEventListener('click', event => {
      const board = event.target.closest('[data-board]'), top = event.target.closest('[data-top15]');
      if (board && root.contains(board)) { event.preventDefault(); field('board').value = board.dataset.board; load(1); }
      if (top && root.contains(top) && top.getAttribute('aria-disabled') !== 'true') { event.preventDefault(); field('top15').value = field('top15').value === '1' ? '0' : '1'; load(1); }
      const page = event.target.closest('[data-page]'), sort = event.target.closest('[data-rk-sort]'), expand = event.target.closest('[data-rk-expand]');
      if (page && root.contains(page)) { event.preventDefault(); load(Number(page.dataset.page)); }
      if (sort && root.contains(sort)) {
        event.preventDefault(); const key = sort.dataset.rkSort;
        field('order').value = field('sort').value === key && field('order').value === 'asc' ? 'desc' : 'asc'; field('sort').value = key; load(1);
      }
      if (expand && root.contains(expand)) {
        const detail = Array.from(results.querySelectorAll('[data-rk-detail]')).find(row => row.id === expand.getAttribute('aria-controls'));
        if (detail) { const open = expand.getAttribute('aria-expanded') !== 'true'; expand.setAttribute('aria-expanded', String(open)); detail.classList.toggle('is-open', open); }
      }
    });
    root.addEventListener('keydown', event => { if (event.key === ' ' && event.target.matches('a[role=button]')) { event.preventDefault(); event.target.click(); } });
    sync();
  });
  document.querySelectorAll('[data-mmaf-directory]').forEach(root => {
    const form = root.querySelector('form[role="search"]'), results = root.querySelector('[data-directory-results]');
    const status = root.querySelector('[data-directory-status]'), clear = root.querySelector('[data-directory-clear]');
    const input = form.querySelector('input[type="search"]');
    let request, timer;
    const searchUrl = () => {
      const url = new URL(form.action);
      const query = input.value.trim();
      if (query) url.searchParams.set(input.name, query);
      return url;
    };
    const load = async url => {
      clearTimeout(timer);
      if (request) request.abort();
      const current = new AbortController(); request = current;
      status.classList.remove('screen-reader-text');
      results.setAttribute('aria-busy', 'true'); status.textContent = 'Loading results…';
      try {
        const response = await fetch(url, {signal:current.signal});
        if (!response.ok) throw new Error('Unable to load fighter profiles.');
        const document = new DOMParser().parseFromString(await response.text(), 'text/html');
        const next = document.querySelector('[data-directory-results]');
        if (!next) throw new Error('Unable to load fighter profiles.');
        if (request !== current) return;
        results.innerHTML = next.innerHTML;
        ['total', 'page', 'totalPages'].forEach(key => { results.dataset[key] = next.dataset[key]; });
        status.textContent = next.dataset.total + (next.dataset.total === '1' ? ' result.' : ' results.') + ' Page ' + next.dataset.page + ' of ' + next.dataset.totalPages + '.';
        status.classList.add('screen-reader-text');
        clear.hidden = !url.searchParams.get(input.name);
        window.history.replaceState(window.history.state, '', url);
      } catch (error) {
        if (error.name !== 'AbortError') status.textContent = error.message + ' Use Search to try again.';
      } finally { if (request === current) results.removeAttribute('aria-busy'); }
    };
    form.addEventListener('submit', event => { event.preventDefault(); load(searchUrl()); });
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(searchUrl()), 300); });
    root.addEventListener('click', event => {
      const link = event.target.closest('.mmaf-pagination a, .nav-links a, [data-directory-clear]');
      if (!link || !root.contains(link) || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      if (link === clear) input.value = '';
      load(new URL(link.href));
    });
  });
  document.querySelectorAll('.mf-hero').forEach(root => {
    root.addEventListener('click', event => {
      const button = event.target.closest('[data-mmaf-toggle]');
      if (!button || !root.contains(button)) return;
      const panel = root.querySelector(button.dataset.mmafToggle);
      if (panel) { const open = button.getAttribute('aria-expanded') !== 'true'; button.setAttribute('aria-expanded', String(open)); panel.hidden = !open; panel.classList.toggle('is-open', open); }
    });
    root.querySelectorAll('[data-mmaf-toggle]').forEach(button => { const panel = root.querySelector(button.dataset.mmafToggle); if (panel) panel.hidden = true; });
  });
  document.querySelectorAll('[data-mmaf-contact]').forEach(form => {
    const status = form.querySelector('.mmaf-contact-status'), button = form.querySelector('button[type=submit]');
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      form.querySelectorAll('[aria-invalid]').forEach(field => field.removeAttribute('aria-invalid'));
      form.querySelectorAll('[data-error-for]').forEach(field => field.textContent = '');
      button.disabled = true; status.textContent = 'Sending…'; delete status.dataset.error;
      try {
        const response = await fetch(mmafSite.rest + 'mma-future-site/v1/contact', {method:'POST', headers:{'X-WP-Nonce':mmafSite.nonce}, body:new FormData(form)});
        const data = await response.json();
        if (!response.ok) {
          Object.entries(data.data?.fields || {}).forEach(([name, message]) => {
            const inputs = Array.from(form.elements).filter(field => field.name === name), error = form.querySelector('[data-error-for="' + name + '"]');
            inputs.forEach(input => input.setAttribute('aria-invalid','true')); if (error) error.textContent = message;
          });
          throw new Error(data.message || 'Unable to send your message.');
        }
        status.textContent = data.message; form.reset();
      } catch (error) { status.textContent = error.message; status.dataset.error = 'true'; }
      finally { button.disabled = false; status.focus(); }
    });
  });
})();
