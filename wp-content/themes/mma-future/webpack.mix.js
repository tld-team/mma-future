let mix = require('laravel-mix');

// Set public path
mix.setPublicPath('./');

// JavaScript
mix.js('assets/src/js/main.js', 'assets/dist/js/main.js')
    // .js('assets/src/js/main2.js', 'assets/dist/js/main2.js') add this line if you want to add another js file

// SCSS
mix.sass('assets/src/scss/main.scss', 'assets/dist/css/output.css')
    .options({
        processCssUrls: false,
    })

// Copy fonts to dist folder
mix.copyDirectory('assets/src/fonts', 'assets/dist/fonts');

// Disable notifications
mix.disableNotifications();