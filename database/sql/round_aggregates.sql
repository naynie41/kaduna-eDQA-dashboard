-- round_aggregates: pre-computed round scores for every scope the dashboard shows
-- (ARCHITECTURE.md §4, §7 Aggregation).
--
-- Loaded by migration 2026_09_30_000100_create_round_aggregates_view. That migration reads this
-- file when it runs, so to change the view, add a NEW migration that drops and recreates it;
-- never edit a migration that has run in staging or production (CONVENTION.md §3).
--
-- Created WITH NO DATA: the first refresh_round_aggregates() call populates it.
--
-- Method: facility score first, then unweighted means of facility scores upward
-- (D-04; edqa.aggregation.weighting = 'unweighted').
--
-- ASSUMPTIONS to confirm against a real client round in Phase 3:
--   A1  Every row in `assessments` counts as accepted. Assessments are only created from
--       submissions that passed every hard rule (soft-flagged ones included, per §6), and an ODK
--       edit updates the row in place, so there is nothing to filter. assessments.status has no
--       defined values yet and is not used here.
--   A2  Month columns m1..m3 are the mean of the three dimensions' scores for that month slot,
--       ignoring an all-N/A (NULL) slot.
--   A3  Scope overall = mean of facility overall scores (not the mean of the scope's three
--       dimension means). With no NULL dimensions the two are identical.
--   A4  A facility is grouped by its CURRENT ward, LGA, ownership and level, not the values at
--       the time of the visit. Deactivated facilities still count in rounds they were assessed in.
--   A5  The 'facility_count' weighting option is not in this view; it would belong in Aggregator
--       SQL. The view is unweighted only.
--   A6  With no published rule version the view is empty.
--
-- Key (unique index round_aggregates_key, required for REFRESH ... CONCURRENTLY):
--   (round_id, scope_type, scope_id, owner_type, level) — never NULL:
--   state rows use scope_id 0; 'all' stands for "every owner type" / "every level".
--   Facility rows exist only with owner_type = 'all' and level = 'all' (a facility has one of
--   each); filter them by joining facilities.

CREATE MATERIALIZED VIEW round_aggregates AS
WITH
-- The highest published scoring rule version (§7, Rule versions). Drafts are ignored.
current_rule_version AS (
    SELECT id
    FROM scoring_rule_versions
    WHERE published_at IS NOT NULL
    ORDER BY version DESC
    LIMIT 1
),

-- Per assessment: dimension score = mean of its non-null month slots (an all-N/A slot is NULL
-- and excluded); month score = mean of that slot across the three dimensions (A2).
assessment_scores_current AS (
    SELECT
        s.assessment_id,
        avg(s.score) FILTER (WHERE s.dimension = 'availability') AS availability,
        avg(s.score) FILTER (WHERE s.dimension = 'consistency')  AS consistency,
        avg(s.score) FILTER (WHERE s.dimension = 'validity')     AS validity,
        avg(s.score) FILTER (WHERE s.month_slot = 1)             AS m1,
        avg(s.score) FILTER (WHERE s.month_slot = 2)             AS m2,
        avg(s.score) FILTER (WHERE s.month_slot = 3)             AS m3
    FROM assessment_scores s
    JOIN current_rule_version v ON v.id = s.rule_version_id
    GROUP BY s.assessment_id
),

-- Per facility per round: mean of its assessments in the round (normally exactly one, since
-- (round_id, facility_id) is unique). overall = mean of the three dimensions.
facility_scores AS (
    SELECT
        a.round_id,
        a.facility_id,
        f.lga_id,
        f.ward_id,
        f.ownership,
        f.level                                                         AS facility_level,
        avg(sc.availability)                                            AS availability,
        avg(sc.consistency)                                             AS consistency,
        avg(sc.validity)                                                AS validity,
        avg(sc.m1)                                                      AS m1,
        avg(sc.m2)                                                      AS m2,
        avg(sc.m3)                                                      AS m3,
        avg((sc.availability + sc.consistency + sc.validity) / 3)       AS overall
    FROM assessments a                                                  -- A1
    JOIN assessment_scores_current sc ON sc.assessment_id = a.id
    JOIN facilities f ON f.id = a.facility_id                           -- A4
    GROUP BY a.round_id, a.facility_id, f.lga_id, f.ward_id, f.ownership, f.level
),

-- Each facility contributes to its state, LGA and ward, both under 'all' and under its own
-- owner type and level, so one GROUP BY yields every combination.
facility_contributions AS (
    SELECT fs.*, scope.scope_type, scope.scope_id, owner.owner_type, lvl.level
    FROM facility_scores fs
    CROSS JOIN LATERAL (VALUES ('state', 0::bigint), ('lga', fs.lga_id), ('ward', fs.ward_id))
        AS scope (scope_type, scope_id)
    CROSS JOIN LATERAL (VALUES ('all'), (fs.ownership)) AS owner (owner_type)
    CROSS JOIN LATERAL (VALUES ('all'), (fs.facility_level)) AS lvl (level)
)

-- State, LGA and ward rows: unweighted means of facility scores (A3, A5).
SELECT
    round_id,
    scope_type,
    scope_id,
    owner_type,
    level,
    avg(availability)            AS availability,
    avg(consistency)             AS consistency,
    avg(validity)                AS validity,
    avg(m1)                      AS m1,
    avg(m2)                      AS m2,
    avg(m3)                      AS m3,
    avg(overall)                 AS overall,
    count(DISTINCT facility_id)  AS facility_count
FROM facility_contributions
GROUP BY round_id, scope_type, scope_id, owner_type, level

UNION ALL

-- Facility rows: the facility's own scores.
SELECT
    round_id,
    'facility',
    facility_id,
    'all',
    'all',
    availability,
    consistency,
    validity,
    m1,
    m2,
    m3,
    overall,
    1
FROM facility_scores

WITH NO DATA
