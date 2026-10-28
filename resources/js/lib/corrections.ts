import type { FacilityDetail, ItemResult } from '@/types/dashboard';

export type ItemChange = { key: string; old: ItemResult; new: ItemResult };

/** What correction mode would send (POST /api/assessments/{id}/corrections, D-58), and why. */
export type CorrectionDraft = {
    changes: ItemChange[];
    items: Record<string, ItemResult>;
    startedAt: string | null;
    endedAt: string | null;
};

/** "2026-04-14T09:00:00+01:00" → "2026-04-14T09:00", the value of a datetime-local input. */
export function toLocalInput(iso: string): string {
    return iso.slice(0, 16);
}

/** The offset the server wrote the time with ("+01:00"), so a local input reads back as the same zone. */
export function offsetOf(iso: string): string {
    const match = /([+-]\d{2}:\d{2}|Z)$/.exec(iso);

    return match?.[1] ?? '+01:00';
}

/** A datetime-local value back to an ISO time with the original offset: "2026-04-14T09:00" → "2026-04-14T09:00:00+01:00". */
export function fromLocalInput(local: string, offset: string): string {
    return `${local}:00${offset}`;
}

/**
 * The difference between the assessment as the drawer shows it and the edited values: only the
 * items whose result changed, and a time only when it changed. Scores are never part of it.
 */
export function draftCorrection(detail: FacilityDetail, results: Record<string, ItemResult>, start: string, end: string): CorrectionDraft {
    const changes: ItemChange[] = [];
    for (const group of detail.items) {
        for (const item of group.items) {
            const next = results[item.key];
            if (item.result !== null && next !== undefined && next !== item.result) {
                changes.push({ key: item.key, old: item.result, new: next });
            }
        }
    }

    const assessment = detail.assessment;
    const startedAt = assessment === null || start === toLocalInput(assessment.started_at) ? null : fromLocalInput(start, offsetOf(assessment.started_at));
    const endedAt = assessment === null || end === toLocalInput(assessment.ended_at) ? null : fromLocalInput(end, offsetOf(assessment.ended_at));

    return { changes, items: Object.fromEntries(changes.map((change) => [change.key, change.new])), startedAt, endedAt };
}

/** The request body: changed items and changed times only. */
export function correctionBody(draft: CorrectionDraft, reason: string): Record<string, unknown> {
    return {
        ...(draft.changes.length > 0 ? { items: draft.items } : {}),
        ...(draft.startedAt !== null ? { started_at: draft.startedAt } : {}),
        ...(draft.endedAt !== null ? { ended_at: draft.endedAt } : {}),
        reason,
    };
}
