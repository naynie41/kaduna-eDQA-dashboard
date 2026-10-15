// Bundle budget (ARCHITECTURE.md §10: ≤ 300 KB gzipped JS on first load). `npm run size` builds,
// then walks the Vite manifest from what the first visit to /dashboard/home loads (the app entry
// and the page, which app.blade.php names directly) through their static imports, and sums the
// gzipped size of every JS file reached. Dynamic imports (lazy charts) are not counted: they load
// later. Exits 1 over budget.
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET_KB = 300;
const ENTRIES = ['resources/js/app.tsx', 'resources/js/pages/dashboard/home.tsx'];
const BUILD = 'public/build';

if (!process.argv.includes('--no-build')) {
    execSync('npx vite build', { stdio: 'inherit', env: { ...process.env, WAYFINDER_SKIP: process.env.WAYFINDER_SKIP ?? '1' } });
}

const manifest = JSON.parse(readFileSync(`${BUILD}/manifest.json`, 'utf8'));
const seen = new Set();
const visit = (key) => {
    const chunk = manifest[key];
    if (chunk === undefined) {
        throw new Error(`"${key}" is not in the Vite manifest.`);
    }
    if (seen.has(chunk.file)) {
        return;
    }
    seen.add(chunk.file);
    for (const imported of chunk.imports ?? []) {
        visit(imported);
    }
};
ENTRIES.forEach(visit);

const rows = [...seen]
    .filter((file) => file.endsWith('.js'))
    .map((file) => ({ file, kb: gzipSync(readFileSync(`${BUILD}/${file}`), { level: 9 }).length / 1024 }))
    .sort((a, b) => b.kb - a.kb);
const total = rows.reduce((sum, row) => sum + row.kb, 0);

console.log('\nFirst visit to /dashboard/home, gzipped JS:');
for (const row of rows) {
    console.log(`  ${row.kb.toFixed(1).padStart(7)} KB  ${row.file}`);
}
console.log(`  ${total.toFixed(1).padStart(7)} KB  total (budget ${BUDGET_KB} KB)\n`);

if (total > BUDGET_KB) {
    console.error(`Over budget by ${(total - BUDGET_KB).toFixed(1)} KB.`);
    process.exit(1);
}
