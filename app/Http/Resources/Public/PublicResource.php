<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Scoring\DTOs\ScoreValue;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public site's output (SECURITY.md §3.1, D-83): every public prop and /api/public response
 * goes through a subclass, each an explicit whitelist of fields. Never extended from, or shared
 * with, an admin resource. one() and many() give the plain arrays an Inertia prop takes.
 *
 * @phpstan-consistent-constructor
 */
abstract class PublicResource extends JsonResource
{
    /** @return array<string, mixed> */
    public static function one(mixed $resource): array
    {
        return (new static($resource))->resolve();
    }

    /**
     * @param  iterable<mixed>  $resources
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $resources): array
    {
        $out = [];
        foreach ($resources as $resource) {
            $out[] = static::one($resource);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected static function score(ScoreValue $score): array
    {
        return ScoreValueResource::one($score);
    }
}
