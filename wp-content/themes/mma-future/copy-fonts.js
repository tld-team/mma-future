/**
 * Copy fonts from src to dist
 * Cross-platform Node.js script for copying font files
 */
const fs = require('fs');
const path = require('path');

const srcDir = path.join(__dirname, 'assets/src/fonts');
const destDir = path.join(__dirname, 'assets/dist/fonts');

function copyDirectory(src, dest) {
    // Create destination directory if it doesn't exist
    if (!fs.existsSync(dest)) {
        fs.mkdirSync(dest, { recursive: true });
    }

    // Read all items in source directory
    const items = fs.readdirSync(src);

    items.forEach(item => {
        const srcPath = path.join(src, item);
        const destPath = path.join(dest, item);

        const stat = fs.statSync(srcPath);

        if (stat.isDirectory()) {
            // Recursively copy subdirectory
            copyDirectory(srcPath, destPath);
        } else {
            // Copy file
            fs.copyFileSync(srcPath, destPath);
        }
    });
}

// Execute copy
try {
    console.log('Copying fonts from src to dist...');
    copyDirectory(srcDir, destDir);
    console.log('Fonts copied successfully!');
} catch (error) {
    console.error('Error copying fonts:', error.message);
    process.exit(1);
}

