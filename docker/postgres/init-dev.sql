-- LOCAL DEVELOPMENT ONLY. Mirrors the production first-boot roles (DEPLOY.md §8.2) so local
-- permissions match; the passwords here are throwaway and never used outside compose.dev.yml.
-- Runs once, when the pgdata volume is empty (`make down` + `docker volume rm` to redo).

-- CREATEDB is dev-only: `pest --parallel` creates one test database per process.
CREATE ROLE edqa_migrator LOGIN CREATEDB PASSWORD 'edqa_migrator_dev';
CREATE ROLE edqa_app      LOGIN PASSWORD 'edqa_app_dev';

CREATE DATABASE edqa      OWNER edqa_migrator ENCODING 'UTF8' TEMPLATE template0;
CREATE DATABASE edqa_test OWNER edqa_migrator ENCODING 'UTF8' TEMPLATE template0;

\connect edqa
CREATE EXTENSION IF NOT EXISTS pg_trgm;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO edqa_app;
GRANT ALL   ON SCHEMA public TO edqa_migrator;
ALTER DEFAULT PRIVILEGES FOR ROLE edqa_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO edqa_app;
ALTER DEFAULT PRIVILEGES FOR ROLE edqa_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO edqa_app;

\connect edqa_test
CREATE EXTENSION IF NOT EXISTS pg_trgm;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO edqa_app;
GRANT ALL   ON SCHEMA public TO edqa_migrator;
ALTER DEFAULT PRIVILEGES FOR ROLE edqa_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO edqa_app;
ALTER DEFAULT PRIVILEGES FOR ROLE edqa_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO edqa_app;
