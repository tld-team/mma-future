/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./assets/src/js/functions/test.js":
/*!*****************************************!*\
  !*** ./assets/src/js/functions/test.js ***!
  \*****************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   test: () => (/* binding */ test)
/* harmony export */ });
function test(value) {
  console.log('this is a test: ' + value);
}

/***/ }),

/***/ "./assets/src/js/main.js":
/*!*******************************!*\
  !*** ./assets/src/js/main.js ***!
  \*******************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _functions_test_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./functions/test.js */ "./assets/src/js/functions/test.js");

(0,_functions_test_js__WEBPACK_IMPORTED_MODULE_0__.test)('test');

/**
 * ============================================================
 * NEWSLETTER SPOTLIGHT EFFECT
 * Subtle cursor-following glow on the newsletter panel.
 * Desktop only (pointer: fine), respects prefers-reduced-motion.
 * ============================================================
 */
(function () {
  // Early exit: reduced motion or non-fine pointer
  var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasFinePointer = window.matchMedia('(pointer: fine) and (hover: hover)').matches;
  if (prefersReducedMotion || !hasFinePointer) return;
  var panel = document.querySelector('.js-newsletter-panel');
  if (!panel) return;
  var spotlight = panel.querySelector('.newsletter-spotlight');
  if (!spotlight) return;

  // Lerp state
  var targetX = 50;
  var targetY = 50;
  var displayedX = 50;
  var displayedY = 50;
  var rafId = null;
  var isHovering = false;
  var LERP_FACTOR = 0.12;
  function animate() {
    // Lerp toward target
    displayedX += (targetX - displayedX) * LERP_FACTOR;
    displayedY += (targetY - displayedY) * LERP_FACTOR;
    panel.style.setProperty('--mx', displayedX + '%');
    panel.style.setProperty('--my', displayedY + '%');

    // Continue animation while hovering or still moving
    var dx = Math.abs(targetX - displayedX);
    var dy = Math.abs(targetY - displayedY);
    if (isHovering || dx > 0.1 || dy > 0.1) {
      rafId = requestAnimationFrame(animate);
    } else {
      rafId = null;
    }
  }
  function startAnimation() {
    if (!rafId) {
      rafId = requestAnimationFrame(animate);
    }
  }
  panel.addEventListener('mouseenter', function () {
    isHovering = true;
    startAnimation();
  });
  panel.addEventListener('mousemove', function (e) {
    var rect = panel.getBoundingClientRect();
    targetX = (e.clientX - rect.left) / rect.width * 100;
    targetY = (e.clientY - rect.top) / rect.height * 100;
    startAnimation();
  });
  panel.addEventListener('mouseleave', function () {
    isHovering = false;
    // Keep spotlight at last cursor position (no reset)
  });
})();

/**
 * ============================================================
 * HEADER SCROLL EFFECT
 * ============================================================
 */
(function () {
  var header = document.getElementById('masthead');
  if (!header) return;
  var lastScroll = 0;
  var scrollThreshold = 50;
  function handleScroll() {
    var currentScroll = window.pageYOffset || document.documentElement.scrollTop;
    if (currentScroll > scrollThreshold) {
      header.classList.add('scrolled');
    } else {
      header.classList.remove('scrolled');
    }
    lastScroll = currentScroll;
  }

  // Throttle scroll event
  var ticking = false;
  window.addEventListener('scroll', function () {
    if (!ticking) {
      window.requestAnimationFrame(function () {
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
(function () {
  var mobileMenuToggle = document.getElementById('mobile-menu-toggle');
  var mobileMenuClose = document.getElementById('mobile-menu-close');
  var mobileMenu = document.getElementById('mobile-menu');
  if (!mobileMenuToggle || !mobileMenu || !mobileMenuClose) {
    return;
  }
  function openMobileMenu() {
    mobileMenu.classList.remove('hidden');
    mobileMenuToggle.setAttribute('aria-expanded', 'true');
    mobileMenuToggle.classList.add('is-active');
    document.body.style.overflow = 'hidden';

    // Trigger animation
    setTimeout(function () {
      var panel = mobileMenu.querySelector('.transform');
      if (panel) {
        panel.classList.add('translate-x-0');
        panel.classList.remove('translate-x-full');
      }
    }, 10);
  }
  function closeMobileMenu() {
    var panel = mobileMenu.querySelector('.transform');
    mobileMenuToggle.classList.remove('is-active');

    // Reset all submenus
    var submenus = mobileMenu.querySelectorAll('[data-submenu]');
    submenus.forEach(function (submenu) {
      submenu.classList.add('hidden');
      submenu.style.maxHeight = '0';
    });

    // Reset all icons
    var icons = mobileMenu.querySelectorAll('[data-toggle-submenu] svg');
    icons.forEach(function (icon) {
      icon.classList.remove('rotate-180');
    });
    if (panel) {
      panel.classList.remove('translate-x-0');
      panel.classList.add('translate-x-full');
    }
    setTimeout(function () {
      mobileMenu.classList.add('hidden');
      mobileMenuToggle.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    }, 300);
  }

  // Event Listeners
  mobileMenuToggle.addEventListener('click', openMobileMenu);
  mobileMenuClose.addEventListener('click', closeMobileMenu);

  // Close on backdrop click
  mobileMenu.addEventListener('click', function (event) {
    if (event.target === mobileMenu || event.target.classList.contains('backdrop-blur-sm')) {
      closeMobileMenu();
    }
  });

  // Close on Escape key
  document.addEventListener('keydown', function (event) {
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
(function () {
  var submenuToggles = document.querySelectorAll('[data-toggle-submenu]');
  submenuToggles.forEach(function (toggle) {
    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var parentLi = this.closest('li');
      if (!parentLi) return;
      var submenu = parentLi.querySelector('[data-submenu]');
      var icon = this.querySelector('svg');
      if (!submenu) return;
      var isHidden = submenu.classList.contains('hidden');

      // Close all other submenus
      submenuToggles.forEach(function (otherToggle) {
        if (otherToggle !== toggle) {
          var otherParentLi = otherToggle.closest('li');
          if (otherParentLi) {
            var otherSubmenu = otherParentLi.querySelector('[data-submenu]');
            var otherIcon = otherToggle.querySelector('svg');
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
        setTimeout(function () {
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
(function () {
  var dropdownGroups = document.querySelectorAll('.group');
  dropdownGroups.forEach(function (group) {
    var trigger = group.querySelector('button[aria-expanded], a[aria-expanded]');
    var dropdown = group.querySelector('[data-dropdown]');
    if (!trigger || !dropdown) return;

    // Click toggle
    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var isExpanded = trigger.getAttribute('aria-expanded') === 'true';

      // Close all other dropdowns
      dropdownGroups.forEach(function (otherGroup) {
        if (otherGroup !== group) {
          var otherTrigger = otherGroup.querySelector('button[aria-expanded], a[aria-expanded]');
          var otherDropdown = otherGroup.querySelector('[data-dropdown]');
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
        setTimeout(function () {
          dropdown.classList.remove('opacity-0');
          dropdown.classList.add('opacity-100');
        }, 10);
      }
    });
  });

  // Close dropdowns when clicking outside
  document.addEventListener('click', function (event) {
    if (!event.target.closest('.group')) {
      dropdownGroups.forEach(function (group) {
        var trigger = group.querySelector('button[aria-expanded], a[aria-expanded]');
        var dropdown = group.querySelector('[data-dropdown]');
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
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      dropdownGroups.forEach(function (group) {
        var trigger = group.querySelector('button[aria-expanded], a[aria-expanded]');
        var dropdown = group.querySelector('[data-dropdown]');
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

/***/ }),

/***/ "./assets/src/scss/main.scss":
/*!***********************************!*\
  !*** ./assets/src/scss/main.scss ***!
  \***********************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = __webpack_modules__;
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/chunk loaded */
/******/ 	(() => {
/******/ 		var deferred = [];
/******/ 		__webpack_require__.O = (result, chunkIds, fn, priority) => {
/******/ 			if(chunkIds) {
/******/ 				priority = priority || 0;
/******/ 				for(var i = deferred.length; i > 0 && deferred[i - 1][2] > priority; i--) deferred[i] = deferred[i - 1];
/******/ 				deferred[i] = [chunkIds, fn, priority];
/******/ 				return;
/******/ 			}
/******/ 			var notFulfilled = Infinity;
/******/ 			for (var i = 0; i < deferred.length; i++) {
/******/ 				var [chunkIds, fn, priority] = deferred[i];
/******/ 				var fulfilled = true;
/******/ 				for (var j = 0; j < chunkIds.length; j++) {
/******/ 					if ((priority & 1 === 0 || notFulfilled >= priority) && Object.keys(__webpack_require__.O).every((key) => (__webpack_require__.O[key](chunkIds[j])))) {
/******/ 						chunkIds.splice(j--, 1);
/******/ 					} else {
/******/ 						fulfilled = false;
/******/ 						if(priority < notFulfilled) notFulfilled = priority;
/******/ 					}
/******/ 				}
/******/ 				if(fulfilled) {
/******/ 					deferred.splice(i--, 1)
/******/ 					var r = fn();
/******/ 					if (r !== undefined) result = r;
/******/ 				}
/******/ 			}
/******/ 			return result;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/jsonp chunk loading */
/******/ 	(() => {
/******/ 		// no baseURI
/******/ 		
/******/ 		// object to store loaded and loading chunks
/******/ 		// undefined = chunk not loaded, null = chunk preloaded/prefetched
/******/ 		// [resolve, reject, Promise] = chunk loading, 0 = chunk loaded
/******/ 		var installedChunks = {
/******/ 			"/assets/dist/js/main": 0,
/******/ 			"assets/dist/css/output": 0
/******/ 		};
/******/ 		
/******/ 		// no chunk on demand loading
/******/ 		
/******/ 		// no prefetching
/******/ 		
/******/ 		// no preloaded
/******/ 		
/******/ 		// no HMR
/******/ 		
/******/ 		// no HMR manifest
/******/ 		
/******/ 		__webpack_require__.O.j = (chunkId) => (installedChunks[chunkId] === 0);
/******/ 		
/******/ 		// install a JSONP callback for chunk loading
/******/ 		var webpackJsonpCallback = (parentChunkLoadingFunction, data) => {
/******/ 			var [chunkIds, moreModules, runtime] = data;
/******/ 			// add "moreModules" to the modules object,
/******/ 			// then flag all "chunkIds" as loaded and fire callback
/******/ 			var moduleId, chunkId, i = 0;
/******/ 			if(chunkIds.some((id) => (installedChunks[id] !== 0))) {
/******/ 				for(moduleId in moreModules) {
/******/ 					if(__webpack_require__.o(moreModules, moduleId)) {
/******/ 						__webpack_require__.m[moduleId] = moreModules[moduleId];
/******/ 					}
/******/ 				}
/******/ 				if(runtime) var result = runtime(__webpack_require__);
/******/ 			}
/******/ 			if(parentChunkLoadingFunction) parentChunkLoadingFunction(data);
/******/ 			for(;i < chunkIds.length; i++) {
/******/ 				chunkId = chunkIds[i];
/******/ 				if(__webpack_require__.o(installedChunks, chunkId) && installedChunks[chunkId]) {
/******/ 					installedChunks[chunkId][0]();
/******/ 				}
/******/ 				installedChunks[chunkId] = 0;
/******/ 			}
/******/ 			return __webpack_require__.O(result);
/******/ 		}
/******/ 		
/******/ 		var chunkLoadingGlobal = self["webpackChunkmma_future"] = self["webpackChunkmma_future"] || [];
/******/ 		chunkLoadingGlobal.forEach(webpackJsonpCallback.bind(null, 0));
/******/ 		chunkLoadingGlobal.push = webpackJsonpCallback.bind(null, chunkLoadingGlobal.push.bind(chunkLoadingGlobal));
/******/ 	})();
/******/ 	
/************************************************************************/
/******/ 	
/******/ 	// startup
/******/ 	// Load entry module and return exports
/******/ 	// This entry module depends on other loaded chunks and execution need to be delayed
/******/ 	__webpack_require__.O(undefined, ["assets/dist/css/output"], () => (__webpack_require__("./assets/src/js/main.js")))
/******/ 	var __webpack_exports__ = __webpack_require__.O(undefined, ["assets/dist/css/output"], () => (__webpack_require__("./assets/src/scss/main.scss")))
/******/ 	__webpack_exports__ = __webpack_require__.O(__webpack_exports__);
/******/ 	
/******/ })()
;