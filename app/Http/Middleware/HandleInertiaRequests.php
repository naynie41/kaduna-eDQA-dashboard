<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Ingestion\Aggregators\SourceStatusAggregator;
use App\Domain\Reporting\Aggregators\ShellStatusAggregator;
use App\Domain\Validation\Queries\QuarantineQuery;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Only what the shell displays; never the whole model.
            'auth' => [
                'user' => $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            ],
            // The shell's footer count and the sources' status bar, worst first (ARCHITECTURE.md §9.1);
            // signed-in only.
            'shell' => fn (): ?array => $user === null ? null : [
                'assessments' => app(ShellStatusAggregator::class)->assessments(),
                'sources' => app(SourceStatusAggregator::class)->all(),
                'quarantined' => app(QuarantineQuery::class)->openCountCached(),
            ],
            // The admin pages' Data issues badge (open records + unreviewed deletions) and the
            // stale-ingestion banner, per source (ARCHITECTURE.md §9.3, D-55, SECURITY.md §12).
            'admin' => fn (): ?array => $user === null || ! $request->routeIs('admin.*') ? null : [
                'openIssueCount' => app(QuarantineQuery::class)->openIssueCountCached(),
                'staleIngestion' => app(SourceStatusAggregator::class)->staleIngestion(),
            ],
            // UI strings, read in React with t() (CONVENTION.md §7, CLAUDE.md hard rule 10).
            'translations' => fn (): array => collect([...self::TRANSLATION_GROUPS, ...$this->pageGroups($request)])
                ->mapWithKeys(fn (string $group): array => [$group => __($group)])
                ->all(),
        ];
    }

    /** Language groups shared with every page. */
    private const TRANSLATION_GROUPS = ['common', 'auth', 'dashboard'];

    /**
     * Extra groups only some pages need.
     *
     * @return list<string>
     */
    private function pageGroups(Request $request): array
    {
        return match (true) {
            $request->routeIs('admin.dev.*') => ['dev', 'enums'],
            $request->routeIs('public.*') => ['enums'],
            $request->routeIs('admin.*') => ['admin', 'enums'],
            default => [],
        };
    }
}
