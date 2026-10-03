<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
            // UI strings, read in React with t() (CONVENTION.md §7, CLAUDE.md hard rule 10).
            'translations' => fn (): array => collect(self::TRANSLATION_GROUPS)
                ->mapWithKeys(fn (string $group): array => [$group => __($group)])
                ->all(),
        ];
    }

    /** Language groups shared with every page. */
    private const TRANSLATION_GROUPS = ['common', 'auth', 'dashboard'];
}
