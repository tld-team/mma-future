/* Each block owns its filters, pagination and requests. Initial content is PHP rendered. */
(() => {
  'use strict';
  document.querySelectorAll('[data-mmaf-browser]').forEach(root => {
    const form = root.querySelector('form'), results = root.querySelector('.mmaf-browser-results');
    const status = root.querySelector('.mmaf-browser-status');
    if (!form || !results) return;
    let request;
    const load = async page => {
      if (request) request.abort();
      request = new AbortController();
      const params = new URLSearchParams();
      const prefix = root.dataset.prefix;
      new FormData(form).forEach((value, key) => params.set(key.slice(prefix.length + 1), value));
      params.set('page', String(page)); params.set('per_page', root.dataset.perPage);
      params.set('prefix', prefix); params.set('include_breakdown', root.dataset.breakdown || '0');
      const kind = root.dataset.mmafBrowser;
      if (kind === 'history') params.set('fighter_id', root.dataset.endpoint.split('/')[1]);
      results.setAttribute('aria-busy', 'true'); status.textContent = 'Loading results…';
      try {
        const response = await fetch(mmafSite.rest + 'mma-future-site/v1/views/' + kind + '?' + params, {signal:request.signal});
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to load results.');
        // This fragment comes exclusively from our escaped PHP public renderers.
        results.innerHTML = data.html;
        status.textContent = data.total + ' results. Page ' + data.page + ' of ' + Math.max(1, data.total_pages) + '.';
      } catch (error) {
        if (error.name !== 'AbortError') status.textContent = error.message + ' Please try again.';
      } finally { results.removeAttribute('aria-busy'); }
    };
    form.addEventListener('submit', event => { event.preventDefault(); load(1); });
    root.addEventListener('click', event => {
      const button = event.target.closest('[data-page]');
      if (button && root.contains(button)) { event.preventDefault(); load(Number(button.dataset.page)); }
    });
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
            const input = form.elements.namedItem(name), error = form.querySelector('[data-error-for="' + name + '"]');
            if (input) input.setAttribute('aria-invalid','true'); if (error) error.textContent = message;
          });
          throw new Error(data.message || 'Unable to send your message.');
        }
        status.textContent = data.message; form.reset();
      } catch (error) { status.textContent = error.message; status.dataset.error = 'true'; }
      finally { button.disabled = false; status.focus(); }
    });
  });
})();
