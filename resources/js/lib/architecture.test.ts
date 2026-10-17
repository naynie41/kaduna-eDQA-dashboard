import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { describe, expect, it } from "vitest";

// The client half of CLAUDE.md hard rule 5 (tests/Arch/ArchitectureTest.php is the server half):
// no number a person reads is rounded, summed or averaged in the browser. Every score arrives as a
// ScoreValue with its display text; counts arrive as integers. Rounding and folding calls are
// allowed only where they serve layout or text, each listed below with why.

const ROOT = join(process.cwd(), "resources/js");

const ALLOWED: Record<string, string> = {
    "lib/heat.ts": "turns a score into a shade (hsl), never into text",
    "components/HistorySparkline.tsx":
        "SVG path coordinates, never shown as text",
    "lib/i18n.tsx": "folds replacements into a translated string",
    "lib/format.ts":
        "lays out counts (integers) and rule-version thresholds as given; never a score",
};

const FORBIDDEN = [
    /\.toFixed\(/,
    /\.toPrecision\(/,
    /Math\.(round|floor|ceil|trunc)\(/,
    /\.reduce\(/,
    /maximumFractionDigits|minimumFractionDigits/,
];

function sources(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
            return ["actions", "routes", "wayfinder", "test"].includes(name)
                ? []
                : sources(path);
        }

        return /\.(ts|tsx)$/.test(name) && !/\.test\.(ts|tsx)$/.test(name)
            ? [path]
            : [];
    });
}

describe("no client-side maths", () => {
    it("rounds, sums or averages nothing outside the listed layout and text helpers", () => {
        const offenders: string[] = [];
        for (const path of sources(ROOT)) {
            const file = relative(ROOT, path).replaceAll("\\", "/");
            if (file in ALLOWED) {
                continue;
            }
            readFileSync(path, "utf8")
                .split("\n")
                .forEach((line, i) => {
                    if (FORBIDDEN.some((pattern) => pattern.test(line))) {
                        offenders.push(`${file}:${i + 1}: ${line.trim()}`);
                    }
                });
        }

        expect(offenders).toEqual([]);
    });

    it("still finds a file it should (the scan is not silently empty)", () => {
        expect(
            sources(ROOT).map((path) =>
                relative(ROOT, path).replaceAll("\\", "/"),
            ),
        ).toEqual(
            expect.arrayContaining(["components/ScoreTag.tsx", "lib/heat.ts"]),
        );
    });
});
