const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const assets = {};
function visit(dir) {
    for (const item of fs.readdirSync(dir, {withFileTypes: true}).sort((a, b) => a.name.localeCompare(b.name))) {
        const file = path.posix.join(dir, item.name);
        if (item.isDirectory()) visit(file);
        else assets[file] = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
    }
}
visit('assets/dist');
fs.writeFileSync('asset-manifest.json', JSON.stringify({schema_version: 1, assets}, null, 2) + '\n');
