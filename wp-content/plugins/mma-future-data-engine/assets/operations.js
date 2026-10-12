/* Private operation progress only. Controls remain ordinary forms without JavaScript. */
(() => {
  document.querySelectorAll('[data-mmaf-operation]').forEach(root => {
    let timer, delay = 5000, stopped = false;
    const region = root.querySelector('[data-operation-progress]');
    const notice = root.querySelector('[data-operation-notice]');
    const poll = async () => {
      if (stopped || document.hidden) return;
      try {
        const response = await fetch(root.dataset.endpoint, {
          credentials: 'same-origin', headers: { 'X-WP-Nonce': root.dataset.nonce }
        });
        if (!response.ok) {
          if (response.status === 401 || response.status === 403) stopped = true;
          throw new Error('Progress could not be refreshed. Use Refresh progress or reload this page.');
        }
        const data = await response.json();
        if (typeof data.progress_html === 'string' && !region.contains(document.activeElement)) region.innerHTML = data.progress_html;
        // Do not replace controls/evidence while the user is working inside the region.
        notice.textContent = '';
        stopped = !['running', 'previewing'].includes(data.operation.status);
        delay = 5000;
      } catch (error) {
        notice.textContent = error.message;
        delay = Math.min(delay * 2, 60000);
      }
      if (!stopped) timer = setTimeout(poll, delay);
    };
    root.addEventListener('focusin', () => clearTimeout(timer));
    root.addEventListener('focusout', () => {
      if (!root.contains(document.activeElement) && !stopped) timer = setTimeout(poll, delay);
    });
    document.addEventListener('visibilitychange', () => {
      clearTimeout(timer);
      if (!document.hidden && !stopped) timer = setTimeout(poll, delay);
    });
    timer = setTimeout(poll, delay);
  });
})();
