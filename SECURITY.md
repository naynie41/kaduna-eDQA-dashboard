# SECURITY.md — Kaduna eDQA Portal

## 0. Scope of this file

This file covers **application** security: authentication, authorisation, audit, data
integrity, ODK integration, file handling and HTTP behaviour — everything enforced in code.
**Infrastructure** security (OS hardening, firewall, TLS termination, Postgres roles and grants,
secrets on the server, backups, monitoring) is owned by the DevOps project and specified in
`DEPLOY.md`. Where a control spans both, this file states the requirement and `DEPLOY.md`
states how the server provides it.

| Control | Code (this repo) | Infrastructure (`DEPLOY.md`) |
|---|---|---|
| HTTPS / HSTS | `URL::forceScheme('https')` (Caddy passes `HTTPS=on` over FastCGI) | Caddy container: automatic TLS, HSTS, HTTP→HTTPS redirect |
| DB least privilege | No DDL at runtime; migrations only in the one-off `migrate` container; `post-migrate-grants.sql` | Creates `edqa_app` / `edqa_migrator` at first boot; `migrate` service runs as migrator |
| Container isolation | Stateless code, no writes outside `storage/app`, no external URLs to Browsershot | Non-root, read-only root fs, `cap_drop: ALL`, internal data network, no Docker socket mounts |
| Image supply chain | Pinned base images, `hadolint`, Trivy scan, SBOM in CI | Deploy by digest only; private registry; read-only pull token |
| Append-only audit | No route/command writes to `activity_log` except inserts | `REVOKE UPDATE, DELETE, TRUNCATE` on `activity_log` |
| Secrets | `.env.example` only; `env()` only in config | `.env` provisioning, file permissions, rotation |
| Backups | — | Encrypted nightly dumps, off-site copy, restore drill |
| Monitoring | Daily digest, Pulse, failed-job surfacing | Uptime checks, disk/CPU alerts, log shipping |

## 1. Threat model in one paragraph

There is one role: Administrator. Every account can close a round, edit a score, reject a
quarantined record, overwrite the facility master list and publish a new scoring rule version.
Authorisation therefore does **no** work in this system. The controls that matter are:
**strong authentication**, **a small number of accounts**, an **append-only audit trail**, and
**database-level integrity constraints** that hold even if application code is wrong.
The system holds no patient-level or personal health data — it scores facility record-keeping.

Assets worth protecting, in order: published round figures (credibility of the state report),
the facility master list (the validation authority), ODK Central credentials, the audit log,
administrator accounts.

---

## 2. Authentication

| Control | Setting |
|---|---|
| Method | Email + password via Laravel Fortify |
| Two-factor | **Mandatory** for every account (TOTP) unless `EDQA_REQUIRE_2FA=false` (D-26). When required, an account without confirmed 2FA can reach only the 2FA setup screen. When switched off, 2FA is optional (set up from Security settings) and an account that has it is still challenged at sign-in. Unset means required |
| Recovery codes | Generated at 2FA setup, shown once, stored hashed |
| Password policy | `Password::min(12)->mixedCase()->numbers()->uncompromised()` |
| Hashing | bcrypt (Laravel default) or argon2id |
| Login throttle | 5 attempts / minute per email + IP (`RateLimiter::for('login')`) |
| 2FA throttle | 5 attempts / minute per session |
| Session idle timeout | 8 hours (`SESSION_LIFETIME=480`) |
| Session cookie | `Secure`, `HttpOnly`, `SameSite=Lax`; `SESSION_ENCRYPT=true` |
| Session fixation | Regenerate session ID on login and on 2FA confirmation |
| Password reset | Emailed signed link, 60-minute expiry, throttled |
| Registration | **Disabled.** Accounts are created by an artisan command only: `php artisan edqa:admin:create` |

There is no self-service sign-up and no in-app user management page. Creating, disabling and
deleting administrators is a console action and is audited.

**Account count:** keep it at three or fewer for v1. Above three or four, revisit roles before
launch (PRD open question 8).

---

## 3. Authorisation

A single middleware group guards everything:

```php
Route::middleware(['auth', 'verified', 'two-factor.confirmed'])->group(function () { ... });
```

Only three routes sit outside it:

1. The login / 2FA challenge / password reset screens (Fortify)
2. `POST /webhooks/odk` — protected by HMAC signature verification instead (§6)
3. `GET /up` — Laravel's health route, used by uptime monitoring; returns status only, no data

Rules:
- Do not add policies, gates, `can()` checks or role columns. Their presence implies granularity
  that does not exist.
- Do not add any public/unauthenticated read route in v1 (public dashboard is a v2 item).
- A route test asserts every registered route (except the allow-list above) returns 302/401 when
  unauthenticated. New routes fail this test until they are inside the group.

---

## 4. The audit trail is the primary control

Implemented with `spatie/laravel-activitylog`.

**What is logged:** every create, update and delete on
`assessments`, `item_responses` (corrections), `assessment_scores`, `quarantined_records`
(resolution), `facilities`, `wards`, `rounds`, `scoring_rule_versions`, `plan_actions`, `users`,
plus: manual ODK pulls, backfill starts, rescore triggers, exports, cascade-file regeneration,
login, logout, failed login, 2FA enable/disable.

**Each entry carries:** actor (`causer`), timestamp, subject, event, `properties.old`,
`properties.attributes` (new), `properties.reason` where required, request IP and user agent.

**Mandatory typed justification** (min 10 characters, enforced in the FormRequest and the Action):
1. Rejecting a quarantined record
2. Reopening a closed round
3. Publishing a scoring rule version
4. Overriding a soft flag
5. Correcting an assessment value
6. Deactivating a facility

**Append-only:**
- No route, controller, action or command deletes or updates `activity_log`.
- In production, the application DB role has `INSERT, SELECT` only on `activity_log` —
  `REVOKE UPDATE, DELETE, TRUNCATE ON activity_log FROM edqa_app;`
- Activity-log pruning (`activitylog:clean`) is **not** scheduled.

**Testing:** every write Action has a test asserting the expected activity entry exists with the
correct old/new values. This is part of the definition of done.

---

## 5. Data integrity as a security control

Application bugs must not be able to publish impossible numbers. The database enforces:

```sql
CHECK (score >= 0 AND score <= 100)            -- assessment_scores
CHECK (ended_at >= started_at)                 -- assessments
CHECK (month_slot BETWEEN 1 AND 3)             -- item_responses, assessment_scores
CHECK (quarter BETWEEN 1 AND 4)                -- rounds
UNIQUE (round_id, facility_id)                 -- assessments
UNIQUE (instance_id)                           -- submissions
```

Plus:
- `lgas` has no runtime insert path; the app DB role has no `INSERT` on `lgas` in production.
- No aggregate reads from `quarantined_records` or `submissions`.
- `submissions.payload` is never updated after insert (enforced by a model `updating` guard and a
  test; optionally a trigger).

---

## 6. ODK integration security

| Concern | Control |
|---|---|
| Credentials | `ODK_CENTRAL_URL`, `ODK_CENTRAL_EMAIL`, `ODK_CENTRAL_PASSWORD`, `ODK_PROJECT_ID`, `ODK_FORM_ID` in `.env` only. Never committed, never logged, never sent to the frontend |
| Service account | A dedicated ODK Central web user with **Project Viewer** (read-only) role on the one project — never a site admin |
| Session token | Cached (encrypted cache) until 30 minutes before expiry, then refreshed. Never written to logs |
| Transport | HTTPS only. `Http::withOptions(['verify' => true])`. Fail closed on TLS errors |
| Timeouts | Connect 10s, total 60s per page; retries with exponential backoff (3 attempts) |
| Payload trust | ODK payloads are **untrusted input**. Parsed through the field map, validated, never `eval`'d, never rendered as HTML without escaping |
| Deep links | Built from config base URL + instance ID; instance ID validated against `^uuid:[0-9a-f-]{36}$` |
| Webhook | `POST /webhooks/odk` verifies an HMAC-SHA256 signature over the raw body with `ODK_WEBHOOK_SECRET` using `hash_equals`. Rejects on missing/invalid signature or timestamp older than 5 minutes. Rate limited. The webhook only **enqueues a pull** — it never writes the payload directly |
| Writing back | The portal never writes to ODK Central or DHIS2 |

---

## 7. File and attachment handling

- ODK attachments (register photos, GPS traces) are stored in `storage/app/odk/{instance_id}/`
  on the `app-files` volume — **outside the web root** (the `web` container only has
  `public/`). Never on the `public` disk.
- Served only through an authenticated route that streams the file:
  `GET /attachments/{submission}/{filename}` — validates the filename against the submission's
  known attachment list (no path traversal), sets `Content-Disposition` and
  `X-Content-Type-Options: nosniff`.
- MIME type sniffed on download from ODK and checked against an allow-list
  (`image/jpeg`, `image/png`, `application/geo+json`, `text/csv`, `application/xml`).
- Exports are written to a private disk and downloaded via signed, time-limited URLs (30 min).
- The facility cascade CSV served to ODK is generated to a private path and uploaded to ODK as
  form media; it is not publicly reachable on the portal.

---

## 8. HTTP hardening

- HTTPS enforced (`URL::forceScheme('https')` in production), HSTS
  `max-age=31536000; includeSubDomains`.
- CSRF on all state-changing routes (Laravel default; webhook excluded and signature-protected).
- Security headers middleware:
  - `Content-Security-Policy`: `default-src 'self'; script-src 'self' 'nonce-{n}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` (Vite nonce via `Vite::useCspNonce()`)
  - `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
    `Referrer-Policy: strict-origin-when-cross-origin`,
    `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- Fonts are **self-hosted** (no Google Fonts CDN) — keeps the CSP tight and works on poor links.
- `APP_DEBUG=false` in staging and production. Custom error pages; no stack traces to the browser.
- Mass assignment: explicit `$fillable` on every model; never `$guarded = []`.
- Output escaping: React escapes by default; `dangerouslySetInnerHTML` is banned (lint rule).
- Rate limiting: `api` group 120/min per user; `POST /api/odk/pull` 6/min; exports 10/min.

---

## 9. Database hardening (requirement — provisioned per `DEPLOY.md`)

- Two Postgres roles:
  - `edqa_migrator` — owns the schema, runs migrations (used only during deploy).
  - `edqa_app` — `SELECT, INSERT, UPDATE, DELETE` on app tables; **no** `CREATE`, `DROP`,
    `ALTER`, `TRUNCATE`; restricted on `activity_log` and `lgas` as above.
- App containers connect as `edqa_app`. Only the one-off `migrate` container connects as
  `edqa_migrator` (its env overrides `DB_USERNAME`/`DB_PASSWORD`), runs migrations, then applies
  `database/sql/post-migrate-grants.sql`.
- Postgres runs in a container on an `internal` Docker network with **no published port**;
  `pg_hba.conf` restricts to the Docker subnet with `scram-sha-256`.
- Nightly `pg_dump -Fc`, encrypted (`age` or `gpg`), retained 30 days, copied off-server.
- **Restore is tested** before go-live and quarterly thereafter; the procedure is in
  `DEPLOY.md` (DevOps project).

---

## 10. Secrets management

- `.env` is never committed. `.env.example` lists every key with an empty value.
- `APP_KEY` generated per environment; rotating it invalidates sessions and encrypted cache.
- CI secrets live in the CI provider's secret store.
- Grep check in CI fails the build if anything matching common secret patterns is committed.

---

## 11. Dependency and supply-chain

- `composer audit` and `npm audit --omit=dev` run in CI; high/critical findings fail the build.
- Lock files (`composer.lock`, `package-lock.json`) are committed.
- Dependabot/Renovate weekly.
- No packages added without a line in `ARCHITECTURE.md` §11.1 explaining why.

---

## 12. Monitoring and incident response

- Laravel Pulse for queue health, slow queries, exceptions.
- Daily digest email to administrators: last successful pull, time since last success,
  quarantine count, failed jobs, any login failures above threshold.
- The ODK pull page shows **time since last success** in plain language. If no successful pull in
  24 hours during an open round, a banner appears on every admin page.
- Suspected compromise of an admin account: disable the account via
  `php artisan edqa:admin:disable {email}`, rotate `APP_KEY` with the old key in `APP_PREVIOUS_KEYS` so 2FA secrets still decrypt (logs everyone out), rotate the ODK
  service-account password, review `activity_log` for the account's actions since last known-good
  time, restore affected records from audit old-values or from backup.

---

## 13. Data protection statement (for the client)

The portal stores facility-level record-keeping assessments, assessor names and device IDs from
ODK metadata, optional GPS coordinates of the visit and optional register photos. It stores **no
patient-level or personal health information**. Register photos must be taken so that patient
names are not legible; this is an assessor-training requirement and should be stated in the ODK
form hint. If a photo containing patient data is found, an administrator deletes the attachment
file (audited) and ODK Central is asked to do the same.

---

## 14. Reporting a vulnerability

Report privately to the project maintainer (add contact email here). Do not open a public issue.
Expect acknowledgement within 3 working days.

---

## 15. Security checklist before go-live

- [ ] `EDQA_REQUIRE_2FA` unset or true on the server; every admin has 2FA confirmed; ≤ 3 accounts
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] HTTPS + HSTS live; security headers verified (securityheaders.com or curl)
- [ ] Route-guard test passing; only login/2FA/reset, webhook and `/up` are unauthenticated
- [ ] ODK service account is Project Viewer only
- [ ] Webhook signature verified end to end (or webhook disabled)
- [ ] `edqa_app` DB role cannot `DROP`, `TRUNCATE`, or modify `activity_log`
- [ ] Attachments not reachable by direct URL
- [ ] Backup ran, encrypted, copied off-server, **restore tested**
- [ ] `composer audit` / `npm audit` clean; Trivy shows no fixable HIGH/CRITICAL in deployed images
- [ ] Containers run non-root, read-only, `cap_drop: ALL`; no Docker socket mounted; only `web` publishes ports
- [ ] Daily digest email received