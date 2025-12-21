import { test } from './functions/test.js';

test('test');

/**
 * ============================================================
 * HEADER SCROLL EFFECT
 * ============================================================
 */
(function() {
	const header = document.getElementById('masthead');
	if (!header) return;

	let lastScroll = 0;
	const scrollThreshold = 50;

	function handleScroll() {
		const currentScroll = window.pageYOffset || document.documentElement.scrollTop;

		if (currentScroll > scrollThreshold) {
			header.classList.add('scrolled');
		} else {
			header.classList.remove('scrolled');
		}

		lastScroll = currentScroll;
	}

	// Throttle scroll event
	let ticking = false;
	window.addEventListener('scroll', function() {
		if (!ticking) {
			window.requestAnimationFrame(function() {
				handleScroll();
				ticking = false;
			});
			ticking = true;
		}
	});

	// Check initial scroll position
	handleScroll();
})();

/**
 * ============================================================
 * MOBILE MENU FUNCTIONALITY
 * ============================================================
 */
(function() {
	const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
	const mobileMenuClose = document.getElementById('mobile-menu-close');
	const mobileMenu = document.getElementById('mobile-menu');

	if (!mobileMenuToggle || !mobileMenu || !mobileMenuClose) {
		return;
	}

	function openMobileMenu() {
		mobileMenu.classList.remove('hidden');
		mobileMenuToggle.setAttribute('aria-expanded', 'true');
		mobileMenuToggle.classList.add('is-active');
		document.body.style.overflow = 'hidden';
		
		// Trigger animation
		setTimeout(() => {
			const panel = mobileMenu.querySelector('.transform');
			if (panel) {
				panel.classList.add('translate-x-0');
				panel.classList.remove('translate-x-full');
			}
		}, 10);
	}

	function closeMobileMenu() {
		const panel = mobileMenu.querySelector('.transform');
		mobileMenuToggle.classList.remove('is-active');
		
		// Reset all submenus
		const submenus = mobileMenu.querySelectorAll('[data-submenu]');
		submenus.forEach(submenu => {
			submenu.classList.add('hidden');
			submenu.style.maxHeight = '0';
		});
		
		// Reset all icons
		const icons = mobileMenu.querySelectorAll('[data-toggle-submenu] svg');
		icons.forEach(icon => {
			icon.classList.remove('rotate-180');
		});
		
		if (panel) {
			panel.classList.remove('translate-x-0');
			panel.classList.add('translate-x-full');
		}
		
		setTimeout(() => {
			mobileMenu.classList.add('hidden');
			mobileMenuToggle.setAttribute('aria-expanded', 'false');
			document.body.style.overflow = '';
		}, 300);
	}

	// Event Listeners
	mobileMenuToggle.addEventListener('click', openMobileMenu);
	mobileMenuClose.addEventListener('click', closeMobileMenu);

	// Close on backdrop click
	mobileMenu.addEventListener('click', function(event) {
		if (event.target === mobileMenu || event.target.classList.contains('backdrop-blur-sm')) {
			closeMobileMenu();
		}
	});

	// Close on Escape key
	document.addEventListener('keydown', function(event) {
		if (event.key === 'Escape' && !mobileMenu.classList.contains('hidden')) {
			closeMobileMenu();
		}
	});
})();

/**
 * ============================================================
 * MOBILE SUBMENU ACCORDION FUNCTIONALITY
 * ============================================================
 */
(function() {
	const submenuToggles = document.querySelectorAll('[data-toggle-submenu]');
	
	submenuToggles.forEach(toggle => {
		toggle.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			
			const parentLi = this.closest('li');
			if (!parentLi) return;
			
			const submenu = parentLi.querySelector('[data-submenu]');
			const icon = this.querySelector('svg');
			
			if (!submenu) return;

			const isHidden = submenu.classList.contains('hidden');
			
			// Close all other submenus
			submenuToggles.forEach(otherToggle => {
				if (otherToggle !== toggle) {
					const otherParentLi = otherToggle.closest('li');
					if (otherParentLi) {
						const otherSubmenu = otherParentLi.querySelector('[data-submenu]');
						const otherIcon = otherToggle.querySelector('svg');
						
						if (otherSubmenu) {
							otherSubmenu.classList.add('hidden');
							otherSubmenu.style.maxHeight = '0';
						}
						if (otherIcon) {
							otherIcon.classList.remove('rotate-180');
						}
					}
				}
			});

			// Toggle current submenu with smooth animation
			if (isHidden) {
				submenu.classList.remove('hidden');
				submenu.style.maxHeight = '0';
				// Force reflow
				submenu.offsetHeight;
				submenu.style.maxHeight = submenu.scrollHeight + 'px';
				if (icon) {
					icon.classList.add('rotate-180');
				}
			} else {
				submenu.style.maxHeight = submenu.scrollHeight + 'px';
				// Force reflow
				submenu.offsetHeight;
				submenu.style.maxHeight = '0';
				setTimeout(() => {
					submenu.classList.add('hidden');
				}, 300);
				if (icon) {
					icon.classList.remove('rotate-180');
				}
			}
		});
	});
})();

/**
 * ============================================================
 * DESKTOP DROPDOWN (HOVER & CLICK SUPPORT)
 * ============================================================
 */
(function() {
	const dropdownGroups = document.querySelectorAll('.group');
	
	dropdownGroups.forEach(group => {
		const trigger = group.querySelector('button[aria-expanded], a[aria-expanded]');
		const dropdown = group.querySelector('[data-dropdown]');
		
		if (!trigger || !dropdown) return;

		// Click toggle
		trigger.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			
			const isExpanded = trigger.getAttribute('aria-expanded') === 'true';
			
			// Close all other dropdowns
			dropdownGroups.forEach(otherGroup => {
				if (otherGroup !== group) {
					const otherTrigger = otherGroup.querySelector('button[aria-expanded], a[aria-expanded]');
					const otherDropdown = otherGroup.querySelector('[data-dropdown]');
					
					if (otherTrigger) {
						otherTrigger.setAttribute('aria-expanded', 'false');
					}
					if (otherDropdown) {
						otherDropdown.classList.add('hidden');
						otherDropdown.classList.remove('opacity-100');
						otherDropdown.classList.add('opacity-0');
					}
				}
			});
			
			// Toggle current dropdown
			trigger.setAttribute('aria-expanded', !isExpanded);
			
			if (isExpanded) {
				dropdown.classList.add('hidden');
				dropdown.classList.remove('opacity-100');
				dropdown.classList.add('opacity-0');
			} else {
				dropdown.classList.remove('hidden');
				setTimeout(() => {
					dropdown.classList.remove('opacity-0');
					dropdown.classList.add('opacity-100');
				}, 10);
			}
		});
	});

	// Close dropdowns when clicking outside
	document.addEventListener('click', function(event) {
		if (!event.target.closest('.group')) {
			dropdownGroups.forEach(group => {
				const trigger = group.querySelector('button[aria-expanded], a[aria-expanded]');
				const dropdown = group.querySelector('[data-dropdown]');
				
				if (trigger) {
					trigger.setAttribute('aria-expanded', 'false');
				}
				if (dropdown) {
					dropdown.classList.add('hidden');
					dropdown.classList.remove('opacity-100');
					dropdown.classList.add('opacity-0');
				}
			});
		}
	});

	// Close on Escape key
	document.addEventListener('keydown', function(event) {
		if (event.key === 'Escape') {
			dropdownGroups.forEach(group => {
				const trigger = group.querySelector('button[aria-expanded], a[aria-expanded]');
				const dropdown = group.querySelector('[data-dropdown]');
				
				if (trigger && trigger.getAttribute('aria-expanded') === 'true') {
					trigger.setAttribute('aria-expanded', 'false');
					
					if (dropdown) {
						dropdown.classList.add('hidden');
						dropdown.classList.remove('opacity-100');
						dropdown.classList.add('opacity-0');
					}
				}
			});
		}
	});
})();
