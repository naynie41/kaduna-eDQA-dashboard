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

export type HomeProps = {
    hero: StateSummary;
    dimensions: DimensionScores;
    owners?: OwnerSplit;
    trend?: Trend;
    weakest?: Weakest;
};
