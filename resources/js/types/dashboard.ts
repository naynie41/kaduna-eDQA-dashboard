import type { ScoreValue } from '@/types/score';

/**
 * The dashboard pages' props, mirroring the Reporting DTOs as they serialise (public readonly
 * properties, camelCase). Every number arrives as a ScoreValue or a count: components render.
 */
export type Dimension = 'availability' | 'consistency' | 'validity';

export type StateSummary = {
    overall: ScoreValue;
    facilitiesVisited: number;
    lgasCovered: number;
    facilitiesStrong: number;
    facilitiesWeak: number;
    /** The rule version's Strong threshold; null when no version is published. */
    strongAt: number | null;
    /** The rule version's Review threshold: "under" it is weak. */
    weakBelow: number | null;
};

export type DimensionScores = {
    availability: ScoreValue;
    consistency: ScoreValue;
    validity: ScoreValue;
    overall: ScoreValue;
    weakest: Dimension | null;
};

export type GroupScores = {
    facilities: number;
    availability: ScoreValue;
    consistency: ScoreValue;
    validity: ScoreValue;
    overall: ScoreValue;
};

export type OwnerSplit = {
    public: GroupScores;
    private: GroupScores;
};

export type TrendPoint = {
    roundId: number;
    year: number;
    quarter: number;
    label: string;
    overall: ScoreValue;
    public: ScoreValue;
    private: ScoreValue;
};

export type Trend = {
    points: TrendPoint[];
    /** The rounds the summaries cover: highlighted. */
    selected: number[];
    takeaway: string | null;
};

export type LgaScore = { lgaId: number; code: string; name: string; facilities: number; overall: ScoreValue };

export type WardScore = { wardId: number; name: string; facilities: number; overall: ScoreValue };

export type Weakest = { scope: 'lgas'; rows: LgaScore[] } | { scope: 'wards'; rows: WardScore[] };

export type LgaRow = {
    lgaId: number;
    code: string;
    name: string;
    facilities: number;
    availability: ScoreValue;
    consistency: ScoreValue;
    validity: ScoreValue;
    m1: ScoreValue;
    m2: ScoreValue;
    m3: ScoreValue;
    overall: ScoreValue;
};

export type RoundRef = { year: number; quarter: number };

export type LgaProps = {
    /** The rounds the table covers: real month names when they share one quarter. */
    rounds: RoundRef[];
    /** Every LGA (the LGA filter left out), for the grid; not deferred, so it stays mounted. */
    tiles: LgaRow[];
    /** The table, in the filter's scope. */
    lgas?: LgaRow[];
};

export type Metric = Dimension | 'overall';

export type FacilitySortColumn = 'facility' | 'lga' | 'ward' | 'owner' | 'assessed' | 'm1' | 'm2' | 'm3' | 'score' | 'overall';

export type FacilityRow = {
    facilityId: number;
    code: string;
    name: string;
    lga: string;
    ward: string;
    ownership: 'public' | 'private';
    level: 'primary' | 'secondary' | 'tertiary';
    roundLabel: string;
    /** Local date of the latest visit, "2026-05-04". */
    assessedOn: string;
    /** The month cells and the score, for the chosen metric. */
    m1: ScoreValue;
    m2: ScoreValue;
    m3: ScoreValue;
    score: ScoreValue;
    overall: ScoreValue;
};

export type FacilityPage = { rows: FacilityRow[]; total: number; page: number; perPage: number };

export type FacilityTableState = {
    page: FacilityPage;
    /** What the server applied (an unknown value in the URL falls back to the default). */
    metric: Metric;
    sort: { column: FacilitySortColumn; direction: 'asc' | 'desc' };
    quarters: number[];
};

export type FacilityProps = {
    facilities?: FacilityTableState;
};

export type HomeProps = {
    hero: StateSummary;
    dimensions: DimensionScores;
    owners?: OwnerSplit;
    trend?: Trend;
    weakest?: Weakest;
};

/** GET /api/facilities/{id} (FacilityDetailAggregator), snake_case as the JSON API serves it. */
export type ItemResult = 'pass' | 'fail' | 'na';

export type FacilityDetail = {
    facility: {
        id: number;
        code: string;
        name: string;
        lga: string;
        ward: string;
        level: string;
        level_label: string;
        ownership: 'public' | 'private';
        ownership_label: string;
        active: boolean;
    };
    round: { id: number; label: string; year: number; quarter: number } | null;
    assessment: { id: number; assessed_on: string; assessor: string; status: string } | null;
    scores: { availability: ScoreValue; consistency: ScoreValue; validity: ScoreValue; overall: ScoreValue };
    items: {
        dimension: Dimension;
        dimension_label: string;
        slot: number;
        month: string;
        score: ScoreValue;
        /** result is null only when no rule version can be read. */
        items: { code: string; label: string | null; result: ItemResult | null }[];
    }[];
    history: { round_id: number; label: string; overall: ScoreValue; selected: boolean }[];
    flags: { code: string; label: string; overridden: { by: string | null; at: string; reason: string | null } | null }[];
    attachments: { id: number; filename: string; mime: string; size: number; status: string }[];
    activity: {
        id: number;
        at: string;
        actor: string | null;
        event: string;
        subject: 'assessment' | 'item';
        item_code: string | null;
        changes: { field: string; old: string | null; new: string | null }[];
        reason: string | null;
    }[];
    odk: { instance_id: string | null; url: string | null };
};

/** Monitor (§9.2.4): CoverageAggregator and AssessmentWindowAggregator, as they serialise. */
export type LgaCount = { lgaId: number; code: string; name: string; facilities: number };

export type LevelCoverage = { level: 'primary' | 'secondary' | 'tertiary'; facilities: number; overall: ScoreValue };

export type Coverage = {
    perLga: LgaCount[];
    /** Full precision, for the marker's position only. */
    stateMean: number;
    /** What a person reads. */
    stateMeanDisplay: string;
    byLevel: LevelCoverage[];
};

export type AssessmentWindow = {
    roundId: number;
    label: string;
    windowStart: string;
    windowEnd: string;
    firstVisit: string | null;
    lastVisit: string | null;
    assessments: number;
};

export type MonitorProps = {
    owners?: OwnerSplit;
    coverage?: Coverage;
    windows?: AssessmentWindow[];
};

/** Visuals (§9.2.5): MonthSlotAggregator's points and takeaways, the owner view. */
export type MonthSlotPoint = {
    roundId: number;
    year: number;
    quarter: number;
    slot: 1 | 2 | 3;
    month: number;
    /** "April 2026" */
    label: string;
    /** The x axis: "April (Q2 2026)" */
    chartLabel: string;
    availability: ScoreValue;
    consistency: ScoreValue;
    validity: ScoreValue;
    overall: ScoreValue;
};

export type MonthSlots = {
    points: MonthSlotPoint[];
    /** One sentence per slot, from the server; null when the slot has no score in view. */
    takeaways: Record<'1' | '2' | '3', string | null>;
};

export type OwnerView = 'all' | 'public' | 'private';

export type VisualsProps = {
    /** locked: the global owner filter, which the view follows while it is set. */
    ownerView: { value: OwnerView; locked: 'public' | 'private' | null };
    monthSlots?: MonthSlots;
    lgas?: LgaRow[];
};
