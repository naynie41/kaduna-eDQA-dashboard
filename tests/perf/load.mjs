// Load on the public dashboard (ARCHITECTURE.md §10: 200 concurrent anonymous visitors, p95 < 1.5 s),
// run by `make load` inside the playwright service against a production-like stack (built assets,
// PHP without file re-checks, config and routes cached: see the Makefile).
//
// LOAD_VISITORS anonymous visitors (default 200) each load public pages and the public JSON, one
// request every 0.5–1.5 s, for LOAD_SECONDS (default 60), each from its own address: the forwarder
// is on the private network, so the app reads X-Forwarded-For (trusted proxies) and rates each
// visitor on its own, as the real public would be. At that pace no visitor reaches the limit
// (120 a minute), so any 429 among them is a failure. One more "flooder" sends requests back to back
// from a single address (15 loops at once), to show that 429s do start above the limit. Writes storage/app/perf/load.md.
//
// Passes when p95 < 1.5 s, there is no 5xx, and no ordinary visitor got a 429.

import { mkdirSync, writeFileSync } from 'node:fs';

const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:8080';
const VISITORS = Number(process.env.LOAD_VISITORS ?? 200);
const SECONDS = Number(process.env.LOAD_SECONDS ?? 60);
const P95_BUDGET_MS = 1500;
const OUT = 'storage/app/perf';

const URLS = [
    '/',
    '/facilities',
    '/lgas',
    '/monitor',
    '/visuals',
    '/plan',
    '/?owner=public',
    '/facilities?sort=ward&dir=desc',
    '/lgas?level=primary',
    '/api/public/aggregates',
    '/api/public/facilities',
    '/api/public/facilities?page=2',
    '/api/public/status',
];

const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const samples = [];
const statuses = {};
let visitor429 = 0;
let flood = { sent: 0, throttled: 0 };

async function hit(url, address) {
    const started = performance.now();
    let status = 0;
    try {
        const response = await fetch(BASE + url, { headers: { 'X-Forwarded-For': address, Accept: 'text/html,application/json' }, redirect: 'manual' });
        status = response.status;
        await response.arrayBuffer();
    } catch {
        status = 0; // connection refused or reset: counted as an error
    }

    return { status, ms: performance.now() - started };
}

async function visitor(index, until) {
    const address = `198.18.${Math.floor(index / 250)}.${(index % 250) + 1}`;
    let n = index;
    while (performance.now() < until) {
        const { status, ms } = await hit(URLS[n % URLS.length], address);
        samples.push(ms);
        statuses[status] = (statuses[status] ?? 0) + 1;
        if (status === 429) {
            visitor429 += 1;
        }
        n += 1;
        await pause(500 + Math.random() * 1000);
    }
}

/** Several loops from one address at once, so it goes over 120 a minute however slow each answer is. */
async function flooder(until) {
    while (performance.now() < until && flood.sent < 600) {
        const { status } = await hit('/api/public/status', '198.19.0.1');
        flood.sent += 1;
        if (status === 429) {
            flood.throttled += 1;
        }
    }
}

// Warm the caches once (one request per URL), as a deploy would.
for (const url of URLS) {
    await hit(url, '198.19.9.9');
}

const until = performance.now() + SECONDS * 1000;
await Promise.all([...Array.from({ length: VISITORS }, (_, i) => visitor(i, until)), ...Array.from({ length: 15 }, () => flooder(until))]);

const sorted = [...samples].sort((a, b) => a - b);
const pct = (p) => (sorted.length === 0 ? 0 : sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)]);
const errors = Object.entries(statuses).filter(([code]) => Number(code) >= 500 || Number(code) === 0).reduce((sum, [, count]) => sum + count, 0);
const p95 = pct(95);
const pass = p95 < P95_BUDGET_MS && errors === 0 && visitor429 === 0;

const report = [
    '# Load, public dashboard',
    '',
    `${VISITORS} anonymous visitors for ${SECONDS} s, each one request every 0.5–1.5 s from its own address; caches warmed first. Budget: p95 < ${P95_BUDGET_MS / 1000} s, no 5xx, no 429 below the rate limit.`,
    '',
    '| Requests | p50 (s) | p95 (s) | max (s) | 5xx or no answer | 429 (visitors) | Statuses | |',
    '|---:|---:|---:|---:|---:|---:|---|---|',
    `| ${samples.length} | ${(pct(50) / 1000).toFixed(2)} | ${(p95 / 1000).toFixed(2)} | ${((sorted.at(-1) ?? 0) / 1000).toFixed(2)} | ${errors} | ${visitor429} | ${Object.entries(statuses).map(([code, count]) => `${code}: ${count}`).join(', ')} | ${pass ? 'ok' : 'OVER'} |`,
    '',
    `Above the limit: one address sent ${flood.sent} requests back to back and got ${flood.throttled} answered 429 (the limit is 120 a minute).`,
    '',
].join('\n');

mkdirSync(OUT, { recursive: true });
writeFileSync(`${OUT}/load.md`, report);
console.log(report);
process.exit(pass && flood.throttled > 0 ? 0 : 1);
