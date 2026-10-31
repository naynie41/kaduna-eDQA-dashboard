# Mock KoboToolbox (local development and tests)

A fake KoboToolbox that answers only what the portal calls (`KoboSource`), so both sources can
be pulled locally with no client credentials. It works like the [mock ODK Central](../odk-mock/README.md):
PHP's built-in server on the dev PHP image (`router.php` → `handler.php`), reading JSON files
from `storage/app/kobo-mock/`. **Never deploy it.** It is in `compose.dev.yml` only, behind the
`sources` profile (which starts the ODK mock too).

## Start it

```bash
make sources-mock-up             # odk-mock (127.0.0.1:8090) and kobo-mock (127.0.0.1:8091); kobo-mock:8080 inside the stack
make sources-mock-seed n=200     # 200 on each mock; 10 of Kobo's visits are also on ODK (see "Overlap")
```

Then point the portal at it in `.env`, and check the connection:

```dotenv
KOBO_BASE_URL=http://kobo-mock:8080
KOBO_API_TOKEN=mock-kobo-token
KOBO_ASSET_UID=aKdDqaMock2026
# http is allowed only with this flag AND APP_ENV=local; anywhere else the flag itself is refused.
KOBO_ALLOW_INSECURE_LOCAL=true
```

```bash
make artisan c="edqa:sources:sync"          # once: registers the kobo source row
make artisan c="edqa:source:check kobo"     # the account, the asset and its versions
make artisan c="edqa:pull kobo"             # or --all for both sources
```

To put real credentials back, restore the three `KOBO_*` values and remove
`KOBO_ALLOW_INSECURE_LOCAL`.

## What it serves

| Request | Answer |
|---|---|
| `GET /me/` | the account (`edqa-mock`) |
| `GET /api/v2/assets/{uid}/` | the asset: uid, name, `deployed_version_id` |
| `GET /api/v2/assets/{uid}/versions/` | the deployed versions (`uid`, `date_deployed`), paged with `start` / `limit` |
| `GET /api/v2/assets/{uid}/data/` | the data rows: `query` (`_submission_time` with `$gte` `$gt` `$lte` `$lt`, or equality on `_id` or `meta/instanceID`), `sort` (by field, `1` or `-1`), `start`, `limit` (at most 1000), answered as `count` / `next` / `previous` / `results` |
| `GET /api/v2/assets/{uid}/data/{_id}/attachments/{n}/` | the n-th file of the submission's `_attachments`, with its content type |

Every request needs `Authorization: Token mock-kobo-token` (else 401). Anything but a GET is 405
(the mock, like the portal, never writes); any other path is 404.

**Edits work as in Kobo.** An edit replaces its row in place: the same `_id` and
`_submission_time`, a new `meta/instanceID` with `meta/deprecatedID`. There is no last-edited
field, so a pull filtered on `_submission_time` does not see the edit. The generator stages
edits in `edits/`. They appear only after:

```bash
make artisan c="edqa:source-mock:generate kobo --edits"
make artisan c="edqa:reconcile kobo"        # the sweep finds them
```

## The data

`edqa:source-mock:generate {source} {--count=200} {--broken=10} {--edits} {--overlap=0} {--round=} {--form-version=2026.1} {--seed=}`
writes either mock. `odk` writes what `edqa:odk-mock:generate` writes. `kobo` writes the same mix,
with the same broken, flagged, edited and photo visits, each as a KPI v2 data row:

- `config.json`: the token, account, asset, versions, `base_url` (`KOBO_BASE_URL`, else
  `http://kobo-mock:8080`) and `edits_applied`;
- `submissions/NNNN.json`: one row each. Groups are flat `group/question` keys and every answer
  is text. The geopoint is `"lat lng alt"`, `_id` is 1000 + NNNN, `_submission_time` is UTC with
  no zone, and `__version__` is the deployment uid mapped to the same field map;
- `edits/NNNN.json`: the staged edited versions;
- `attachments/<_id>/register-*.jpg`: two small generated photos, listed in `_attachments`;
- `kinds.json`: each row's kind, so the other mock can copy plain visits.

The two mocks never visit the same facility, so nothing is a duplicate by accident. Kobo's default
seed (2027) differs from ODK's (2026), so their instance IDs differ too.

## Overlap

`--overlap=N` also writes N plain visits of the **other** mock (generate it first), in turn:

- **copied with the same instance ID**, as if a submission had been copied between servers. The
  second source to send it counts it as a `cross_source_duplicate`, and it is never stored twice
  (D-60);
- **entered again with a new instance ID**, as if the same visit had been recorded on both tools.
  One is accepted and the other is quarantined as `DUPLICATE_ASSESSMENT`, the detail naming both
  sources (ARCHITECTURE.md §5.8).

Both show in `edqa:backfill-report` under "Duplicates between the sources".
