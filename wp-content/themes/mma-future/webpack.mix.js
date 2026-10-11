let mix = require('laravel-mix');

// Set public path
mix.setPublicPath('./');

// JavaScript
mix.js('assets/src/js/main.js', 'assets/dist/js/main.js')
    .js('assets/src/js/integration.js', 'assets/dist/js/integration.js');
    // .js('assets/src/js/main2.js', 'assets/dist/js/main2.js') add this line if you want to add another js file

// SCSS
// Tailwind is the sole writer of output.css; legacy SCSS is kept as reference.

// Copy fonts to dist folder
mix.copyDirectory('assets/src/fonts', 'assets/dist/fonts');

// Disable notifications
mix.disableNotifications();
