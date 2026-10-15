import VisuallyHidden from '@/components/VisuallyHidden';
import { heatStyle } from '@/lib/heat';
import type { ScoreValue } from '@/types/score';

export type HeatCellProps = {
    score: ScoreValue;
};

/**
 * A month cell: the server's display value on a heat shade (lib/heat), text always ink. The
 * number carries the meaning; the shade only helps the eye. A cell with no score is unshaded
 * and says so.
 */
export default function HeatCell({ score }: HeatCellProps) {
    return (
        <td className="px-3 py-2 text-right font-medium tabular-nums" style={heatStyle(score.value)}>
            {score.value === null ? (
                <>
                    <span aria-hidden="true">{score.display}</span>
                    <VisuallyHidden>{score.bandLabel}</VisuallyHidden>
                </>
            ) : (
                score.display
            )}
        </td>
    );
}
