# KoboToolbox impact analysis: what is left (Phase 2C close-out)

On 28 Oct 2026 (Phase 2C, step 0) this document listed everything in the code that assumed ODK
Central was the only source, step by step, and where the code and the new documents disagreed.
At the Phase 2C close-out (31 Oct 2026), every inventory item of steps 2C.1–2C.7 is done, and
those items have been deleted from this document:
- tables, classes, routes, commands, config and env keys;
- fixtures, tests, UI and strings.

The full original is in git history (`git log -- docs/kobo-impact.md`).

What follows is what is **left over**. Most of it is about the documents, not the code. None of
it blocks pulling from either source once credentials arrive.

---

## 1. Left over in the code and docs

| Item | Where | What is left | Who |
|---|---|---|---|
| Facility media file naming | `app/Domain/Facility/Exports/CascadeExporter.php` (`odk-cascade-*.zip`), `docs/odk-cascade-media.md` | The panel and its text now say the file is for both platforms, but the zip is still named `odk-cascade-…`. The upload guide covers ODK Central only: it needs a KoboToolbox section (project → Settings → Media) | Phase 5, with the Facility list page |
| Backfill panel | `BackfillStatusQuery`, `BackfillPanel` | Shows the last backfill of any source, but only ODK Central's checkpoint. Kobo's checkpoint is in `source_syncs` but not on the page | Next backfill-related step |
| Demo script | `docs/demo-script.md` | Still describes an ODK-only portal with no admin pages | Before the next client demo |
| Real field maps | `config/edqa_field_maps.php` | Both sources' versions are `CONTRACT-ASSUMED`, and Kobo's deployment uids are fictional, until the real forms are audited | Credentials day, runbook steps A3 and B3 |
| `DEPLOY.md` (the `edqa-infra` copy) | `DEPLOY.md` | No Kobo env keys, no egress to the Kobo server, the `odk-outage.md` row still says "ODK pull page", no `/webhooks/kobo` at the web tier | `edqa-infra` (DEPLOY.md §1.3); listed in the Phase 2C close-out |

## 2. Left over in the documents (needs an owner decision)

These were §3 of the original. No 2C step could settle them, because they are about the
documents themselves.

1. **Decision numbers: done** (was §3.1). §11.1 holds D-01–D-64 again, each number once. The
   build-era D-17–D-58 were restored from commit `ebd43e5`, with D-57 and D-58 written back.
   The Kobo decisions moved from D-21–D-26, which reused build-era numbers, to D-59–D-64, and
   every reference moved with them.
2. **Open-question numbers collide in the same way** (was §3.2).
   - §11.4 holds Q-01–Q-11 and Q-15–Q-18. Q-12–Q-14 and Q-19–Q-34 are missing.
   - The new Kobo questions Q-15–Q-18 reuse numbers the code cites.

   Proposed fix: restore Q-01–Q-34 and renumber the Kobo questions Q-35–Q-38.
3. **The stack lines in CLAUDE.md** (was §3.3) still read Laravel 12, Inertia 2, React 18 and
   Pest 3. The code is on Laravel 13, Inertia 3, React 19 and Pest 4 (old D-18). The commands
   in §8 also leave out the targets that exist (`test-slow`, `e2e`, `e2e-sources`, `e2e-issues`,
   `size`, `lighthouse`, `fresh`, `grants-check`, `prod-build`, `routes`, the mock targets).
4. **ARCHITECTURE.md §8 is still the original table** (was §3.4). The 2C steps added "Built"
   notes for the sources API, but these endpoints are still missing from the table:
   - the corrections and flag-override endpoints;
   - `GET /api/quarantine/{record}` and `GET /api/facility-options`;
   - the rescores, scoring-rules and rounds endpoints;
   - the signed downloads and the attachments route.
5. **Where the choice map lives** (was §3.5). The code keeps `yes`/`no`/`na` → pass/fail/na in
   the scoring rule version, where it is versioned, audited and pinned to closed rounds. The
   new §5.1 and §5.5 put `choices` in the field map. This changes numbers, so it is an open
   question (CLAUDE.md §9). The recommendation stands: keep it in the rule version.
6. **`ACCESSIBILITY.md` is not in the repository** (was §3.6). CLAUDE.md requires it for UI work.
   The 2C UI follows the patterns already built and is checked with axe at 360 and 1280.

---

## 3. Not affected (unchanged)

These never depended on the source:
- scoring (`ScoreCalculator`, rule versions, rescore);
- `round_aggregates` and the aggregators;
- the validation rules themselves;
- rounds, facilities, users, auth and the activity log;
- assessment corrections.

`assessments.source_id` (Phase 2C step 7) was added so Reporting can count by source. Nothing
else in the scoring and aggregate path reads it.
