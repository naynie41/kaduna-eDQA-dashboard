// Lighthouse on throttled Slow 3G (ARCHITECTURE.md §10: first meaningful paint < 3 s), run by
// `make lighthouse` inside the playwright service against a production build (Vite stopped, so
// Laravel serves public/build). Signs in with the e2e administrator's saved session
// (storage/e2e/admin.json, refreshed by the setup project first) and writes
// storage/app/perf/lighthouse.md plus each full report as JSON.
//
// Slow 3G here is Lighthouse's simulated throttling at 400 ms RTT, 400 Kbps down and up, CPU
// slowed 4× on a 360 × 740 mobile screen. PHP runs as in production for the run (no opcache file
// checks, config cached: see the Makefile). First Meaningful Paint left Lighthouse in v6, so the
// table gives First and Largest Contentful Paint.

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { chromium } from '@playwright/test';
import * as chromeLauncher from 'chrome-launcher';
import lighthouse from 'lighthouse';

const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:8080';
const OUT = 'storage/app/perf';
const BUDGET_MS = 3000;
const PAGES = [
    ['Home', '/dashboard/home?year=2026&quarter=2'],
    ['Facility view', '/dashboard/facility?year=2026&quarter=2'],
    ['Visuals', '/dashboard/visuals?year=2026&quarter=2'],
];

const state = JSON.parse(readFileSync('storage/e2e/admin.json', 'utf8'));
const cookie = state.cookies.map((c) => `${c.name}=${c.value}`).join('; ');

const chrome = await chromeLauncher.launch({
    chromePath: chromium.executablePath(),
    chromeFlags: ['--headless=new', '--no-sandbox', '--disable-gpu'],
});

const rows = [];
mkdirSync(OUT, { recursive: true });
try {
    for (const [name, path] of PAGES) {
        const runs = [];
        // Three runs, the median reported: a single simulated run varies with the machine.
        for (let i = 0; i < 3; i++) {
            const result = await lighthouse(BASE + path, {
                port: chrome.port,
                output: 'json',
                logLevel: 'error',
                onlyCategories: ['performance'],
                extraHeaders: { Cookie: cookie },
                formFactor: 'mobile',
                screenEmulation: { mobile: true, width: 360, height: 740, deviceScaleFactor: 2, disabled: false },
                throttlingMethod: 'simulate',
                throttling: { rttMs: 400, throughputKbps: 400, requestLatencyMs: 400, downloadThroughputKbps: 400, uploadThroughputKbps: 400, cpuSlowdownMultiplier: 4 },
            });
            runs.push(result.lhr);
        }
        runs.sort((a, b) => a.audits['largest-contentful-paint'].numericValue - b.audits['largest-contentful-paint'].numericValue);
        const lhr = runs[1];
        writeFileSync(`${OUT}/lighthouse-${name.toLowerCase().replace(/\s+/g, '-')}.json`, JSON.stringify(lhr));

        const items = lhr.audits['resource-summary']?.details?.items ?? [];
        const script = items.find((item) => item.resourceType === 'script') ?? { transferSize: 0, requestCount: 0 };
        const fcp = lhr.audits['first-contentful-paint'].numericValue;
        const lcp = lhr.audits['largest-contentful-paint'].numericValue;
        rows.push({
            name,
            fcp,
            lcp,
            si: lhr.audits['speed-index'].numericValue,
            js: script.transferSize,
            jsRequests: script.requestCount,
            total: lhr.audits['total-byte-weight'].numericValue,
            score: Math.round((lhr.categories.performance.score ?? 0) * 100),
            ttfb: lhr.audits['server-response-time'].numericValue,
            finalUrl: lhr.finalDisplayedUrl,
        });
    }
} finally {
    await chrome.kill();
}

const kb = (bytes) => (bytes / 1024).toFixed(1);
const s = (ms) => (ms / 1000).toFixed(2);
const report = [
    '# Lighthouse, Slow 3G',
    '',
    `Production build. Simulated throttling: 400 ms RTT, 400 Kbps, CPU ×4, 360 × 740 mobile. Median of three runs. Budget: < ${BUDGET_MS / 1000} s.`,
    '',
    '| Page | Server response (s) | FCP (s) | LCP (s) | Speed index (s) | JS transferred (KB, requests) | All bytes (KB) | Performance | |',
    '|---|---:|---:|---:|---:|---:|---:|---:|---|',
    ...rows.map((r) => `| ${r.name} | ${s(r.ttfb)} | ${s(r.fcp)} | ${s(r.lcp)} | ${s(r.si)} | ${kb(r.js)} (${r.jsRequests}) | ${kb(r.total)} | ${r.score} | ${r.lcp < BUDGET_MS ? 'ok' : 'OVER'} |`),
    '',
].join('\n');
writeFileSync(`${OUT}/lighthouse.md`, report);
console.log(report);

const signedOut = rows.filter((r) => !r.finalUrl.includes('/dashboard/'));
if (signedOut.length > 0) {
    console.error(`Not signed in for: ${signedOut.map((r) => r.name).join(', ')}`);
    process.exit(2);
}
process.exit(rows.every((r) => r.lcp < BUDGET_MS) ? 0 : 1);
