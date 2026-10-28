<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facilities;

use App\Domain\Facility\Models\Facility;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/facility-options?lga=CODE&q=text (D-57): active facilities for the Data issues correct
 * form's facility search, at most 50, matching the code or the name, in the chosen LGA.
 */
final class FacilityOptionsController extends Controller
{
    public const LIMIT = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'lga' => ['nullable', 'string', 'exists:lgas,code'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($search, '%_\\').'%'; // the search is text, never a pattern
        $lga = $request->query('lga');

        $facilities = Facility::query()
            ->where('is_active', true)
            ->when(is_string($lga) && $lga !== '', fn ($q) => $q->whereHas('lga', fn ($l) => $l->where('code', $lga)))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('code ILIKE ?', [$like])->orWhereRaw('name ILIKE ?', [$like])))
            ->with(['ward:id,name'])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'name', 'ward_id']);

        return response()->json(['data' => $facilities->map(fn (Facility $f): array => [
            'code' => $f->code, 'name' => $f->name, 'ward' => $f->ward?->name,
        ])->values()->all()]);
    }
}
