# Mock ODK Central (local development and e2e)

A fake ODK Central that answers only what the portal calls (`OdkCentralSource`), so the whole
ingestion pipeline runs locally with no client credentials. It is PHP's built-in server on the
dev PHP image (`router.php` → `handler.php`), reading JSON files from `storage/app/odk-mock/`.
**Never deploy it.** It is in `compose.dev.yml` only, behind the `odk` profile.

## Start it

```bash
make odk-mock-up                 # the odk-mock service: odk-mock:8080 inside the stack, 127.0.0.1:8090 on the host
make odk-mock-seed n=200         # write 200 raw submissions for the seeded facilities
```

Then point the portal at it in `.env`, and check the connection:

```dotenv
ODK_CENTRAL_URL=http://odk-mock:8080
ODK_CENTRAL_EMAIL=mock@odk.local
ODK_CENTRAL_PASSWORD=mock-password
ODK_PROJECT_ID=1
ODK_FORM_ID=dqa
# http is allowed only with this flag AND APP_ENV=local; anywhere else the flag itself is refused.
ODK_ALLOW_INSECURE_LOCAL=true
```

```bash
make artisan c="edqa:odk:check"  # signs in, lists the form versions and which have a field map
```

To put real credentials back, restore the five `ODK_*` values and remove
`ODK_ALLOW_INSECURE_LOCAL`.

## What it serves

| Request | Answer |
|---|---|
| `POST /v1/sessions` | a token for `mock@odk.local` / `mock-password`; anything else is 401 |
| `GET /v1/users/current`, `/v1/projects/1`, `/v1/projects/1/forms/dqa` | the account, project and form (current version) |
| `GET …/forms/dqa/versions` | the published versions: the generated one and every version with a field map |
| `GET …/forms/dqa.svc/Submissions` | the OData feed: `$filter` on `__system/submissionDate` or `__system/updatedAt` (`gt ge lt le eq`, joined by `and` / `or`), `$orderby` on either (then optionally `__id`; no `updatedAt` sorts last), `$top`, `$skip`, `$count=true`; `$expand` is ignored |
| `GET …/submissions/{instanceId}/attachments` and `…/attachments/{name}` | the attachment list, and the file with its content type |

Every other request is 404; anything but a GET or the session POST is 405 (the mock, like the
portal, never writes). A missing or wrong bearer token is 401.

**Edits work as in Central.** The feed shows one row per submission (`__id`): its latest
version. An edit keeps `__id` and `submissionDate`, takes a new `meta.instanceID` with
`meta.deprecatedID`, sets `__system.updatedAt` and counts `__system.edits`. The generator stages
edits in `edits/`. They appear only after:

```bash
make artisan c="edqa:odk-mock:generate --apply-edits"
```

This lets one pull see the originals and a later pull see the edits. A pull filtered only on
`submissionDate` would never see an edit; the puller has to filter on `updatedAt` too.

## The data

`edqa:odk-mock:generate {--count=200} {--broken=10} {--round=2026Q2} {--form-version=2026.1} {--seed=2026}`
writes:

- `config.json`: the credentials, project, form, versions and `edits_applied`.
- `submissions/NNNN.json`: one OData entry each, written through the chosen version's field map.
- `edits/NNNN.json`: the staged edited versions.
- `attachments/<instance ID, ':' as '_'>/register-*.jpg`: two small generated photos.

The visits fall in the round's window. They go to active facilities that have no visit in that
round yet, so none is a duplicate by accident, and each assessor makes at most four visits a day.

The mix:

- **broken (`--broken`):** each must be quarantined. In turn: unknown LGA, numeric facility,
  typed score 347.66, missing slot, unexpected answer, shifted columns, ends before it starts,
  outside every round, blank LGA, facility in another LGA.
- **flagged (about 4%):** every answer yes, or a 12-minute visit.
- **edited (about one in fifty):** an original plus its staged edit.
- **valid:** the rest, with answers following each facility's quality.

The same options and seed give the same data. (`--form-version`, not `--version`: the console
reserves `--version`.)
