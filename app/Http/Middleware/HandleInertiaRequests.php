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
        $adminPage = $user !== null && $request->routeIs('admin.*');
        // The status bar and the banner read the same statuses: work them out once, when needed.
        $statuses = null;
        $sourceStatuses = function () use (&$statuses): array {
            return $statuses ??= app(SourceStatusAggregator::class)->all();
        };

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Only what the layouts display; never the whole model. Null for a visitor.
            'auth' => [
                'user' => $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            ],
            // The admin layout only (ARCHITECTURE.md §9.0–9.1): the rail's assessment count and the
            // per-source status bar, worst first; the Data issues badge (open records + unreviewed
            // deletions) and the stale-ingestion banner (§9.3, D-55, SECURITY.md §12). A public page
            // never carries them, not even in the admin preview (D-81).
            ...($adminPage ? [
                'shell' => fn (): array => [
                    'assessments' => app(ShellStatusAggregator::class)->assessments(),
                    'sources' => $sourceStatuses(),
                    'quarantined' => app(QuarantineQuery::class)->openCountCached(),
                ],
                'admin' => fn (): array => [
                    'openIssueCount' => app(QuarantineQuery::class)->openIssueCountCached(),
                    'staleIngestion' => app(SourceStatusAggregator::class)->staleIngestion($sourceStatuses()),
                ],
            ] : []),
            // UI strings, read in React with t() (CONVENTION.md §7, CLAUDE.md hard rule 10): each page
            // gets the groups it needs, so a visitor never receives the admin strings.
            'translations' => fn (): array => collect(['common', ...$this->pageGroups($request)])
                ->mapWithKeys(fn (string $group): array => [$group => __($group)])
                ->all(),
        ];
    }

    /**
     * The language groups a page needs besides common.
     *
     * @return list<string>
     */
    private function pageGroups(Request $request): array
    {
        return match (true) {
            // The public dashboard; the admin preview also gets the admin strings (its preview bar).
            $request->routeIs('public.*') => [...['dashboard', 'enums'], ...($request->user() === null ? [] : ['admin'])],
            $request->routeIs('admin.dev.*') => ['auth', 'dashboard', 'admin', 'dev', 'enums'],
            // The password page reads the auth strings.
            $request->routeIs('admin.security.*') => ['auth', 'admin'],
            $request->routeIs('admin.*') => ['dashboard', 'admin', 'enums'],
            // Sign-in, password reset and the like.
            default => ['auth'],
        };
    }
}
