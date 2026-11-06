(() => {
	'use strict';
	const config = window.mmafCalendarLinking || {};

	document.querySelectorAll('[data-mmaf-fighter-link]').forEach((root) => {
		const search = root.querySelector('[data-mmaf-fighter-search]');
		const id = root.querySelector('[data-mmaf-fighter-id]');
		const results = root.querySelector('[data-mmaf-fighter-results]');
		const clear = root.querySelector('[data-mmaf-fighter-clear]');
		let timer;
		let controller;

		const close = () => {
			results.replaceChildren();
			results.hidden = true;
		};
		close();

		search.addEventListener('input', () => {
			id.value = '';
			window.clearTimeout(timer);
			if (search.value.trim().length < 2) {
				close();
				return;
			}
			timer = window.setTimeout(async () => {
				controller?.abort();
				controller = new AbortController();
				const url = new URL(config.endpoint, window.location.href);
				url.searchParams.set('action', 'mmaf_calendar_fighter_search');
				url.searchParams.set('_ajax_nonce', config.nonce);
				url.searchParams.set('q', search.value.trim());
				try {
					const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
					const payload = await response.json();
					close();
					(payload?.data?.items || []).forEach((fighter) => {
						const option = document.createElement('button');
						option.type = 'button';
						option.className = 'button-link';
						option.setAttribute('role', 'option');
						option.textContent = String(fighter.name ?? '');
						option.addEventListener('click', () => {
							id.value = String(fighter.id);
							search.value = String(fighter.name ?? '');
							close();
						});
						results.append(option);
					});
					results.hidden = !results.childElementCount;
				} catch (error) {
					if (error.name !== 'AbortError') close();
				}
			}, 250);
		});

		clear.addEventListener('click', () => {
			id.value = '';
			search.value = '';
			close();
			search.focus();
		});
	});
})();
