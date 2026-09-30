-- Least-privilege grants for the app role, applied after every migration run by
-- `php artisan edqa:db:apply-grants` (the `migrate` container role, DEPLOY.md §6.3, §8.3).
--
-- Idempotent: GRANT and REVOKE of a privilege already in that state is a no-op, so this can
-- run on every deploy. The baseline (SELECT/INSERT/UPDATE/DELETE on new tables) comes from the
-- default privileges set when the database was created (DEPLOY.md §8.2).

REVOKE UPDATE, DELETE, TRUNCATE ON activity_log FROM edqa_app;   -- append-only audit
REVOKE INSERT, UPDATE, DELETE ON lgas FROM edqa_app;             -- 23 LGAs, seeded only
GRANT EXECUTE ON FUNCTION refresh_round_aggregates() TO edqa_app;
GRANT SELECT ON round_aggregates TO edqa_app;
