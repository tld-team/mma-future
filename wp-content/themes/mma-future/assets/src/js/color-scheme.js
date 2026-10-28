(function () {
    'use strict';

    const root = document.documentElement;
    const storageKey = 'mmaf-color-scheme';
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    const valid = value => value === 'light' || value === 'dark';
    let preference = null;

    function readPreference() {
        try {
            const saved = window.localStorage.getItem(storageKey);
            return valid(saved) ? saved : null;
        } catch (error) {
            return null;
        }
    }

    function applyTheme() {
        const theme = preference || (system.matches ? 'dark' : 'light');
        root.dataset.theme = theme;
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            const label = theme === 'dark' ? button.dataset.labelLight : button.dataset.labelDark;
            button.setAttribute('aria-label', label);
            button.title = label;
        });
    }

    preference = readPreference();
    // This script runs in the head, before any page content can paint.
    applyTheme();

    document.addEventListener('DOMContentLoaded', function () {
        applyTheme();
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            button.addEventListener('click', function () {
                preference = root.dataset.theme === 'dark' ? 'light' : 'dark';
                try {
                    window.localStorage.setItem(storageKey, preference);
                } catch (error) {
                    // Switching still works when browser storage is unavailable.
                }
                applyTheme();
            });
            button.hidden = false;
        });
    });

    system.addEventListener('change', function () {
        if (!preference) applyTheme();
    });
    window.addEventListener('storage', function (event) {
        if (event.key !== storageKey && event.key !== null) return;
        preference = valid(event.newValue) ? event.newValue : null;
        applyTheme();
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        preference = readPreference();
        applyTheme();
    });
}());
