<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scoring;

use App\Domain\Reporting\Aggregators\RuleVersionPreviewAggregator;
use App\Domain\Scoring\Actions\CreateDraftRuleVersion;
use App\Domain\Scoring\Actions\PublishRuleVersion;
use App\Domain\Scoring\Exceptions\RuleVersionRefused;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scoring\CreateDraftRuleVersionRequest;
use App\Http\Requests\Scoring\PublishRuleVersionRequest;
use App\Http\Resources\Scoring\RuleVersionPreviewResource;
use App\Http\Resources\Scoring\ScoringRuleVersionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * /api/scoring-rules (ARCHITECTURE.md §8). One controller for the four endpoints of the Scoring
 * rules admin page. Refusals (RuleVersionRefused, InvalidRuleConfig) render as 409.
 */
final class ScoringRuleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ScoringRuleVersionResource::collection(ScoringRuleVersion::query()->orderByDesc('version')->get());
    }

    public function store(CreateDraftRuleVersionRequest $request, CreateDraftRuleVersion $create): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $from = ScoringRuleVersion::query()->findOrFail($request->integer('from'));

        return (new ScoringRuleVersionResource($create->execute($from, $request->changes(), $actor)))
            ->response()->setStatusCode(201);
    }

    public function preview(ScoringRuleVersion $version, RuleVersionPreviewAggregator $preview): RuleVersionPreviewResource
    {
        if ($version->published_at !== null) {
            throw RuleVersionRefused::notADraft();
        }

        return new RuleVersionPreviewResource($preview->preview($version));
    }

    public function publish(PublishRuleVersionRequest $request, ScoringRuleVersion $version, PublishRuleVersion $publish): ScoringRuleVersionResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new ScoringRuleVersionResource($publish->execute($version, $actor, $request->string('reason')->toString()));
    }
}
