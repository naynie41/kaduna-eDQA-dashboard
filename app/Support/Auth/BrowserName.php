<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * A readable name for a user agent in a sign-in alert ("Chrome 130 on Windows"). A rough guess
 * for a person to recognise their own browser, never used for any decision.
 */
final class BrowserName
{
    /** Checked in order: Edge and Opera also say Chrome, Chrome also says Safari. */
    private const BROWSERS = [
        'Edge' => '/Edg(?:e|A|iOS)?\/(\d+)/',
        'Opera' => '/OPR\/(\d+)/',
        'Firefox' => '/(?:Firefox|FxiOS)\/(\d+)/',
        'Chrome' => '/(?:Chrome|CriOS)\/(\d+)/',
        'Safari' => '/Version\/(\d+)[^ ]* (?:Mobile\/\S+ )?Safari\//',
    ];

    private const SYSTEMS = [
        'Android' => '/Android/',
        'iOS' => '/iPhone|iPad|iPod/',
        'Windows' => '/Windows/',
        'macOS' => '/Mac OS X|Macintosh/',
        'Linux' => '/Linux|X11/',
    ];

    public static function describe(?string $userAgent): string
    {
        $userAgent ??= '';
        $browser = null;
        foreach (self::BROWSERS as $name => $pattern) {
            if (preg_match($pattern, $userAgent, $match) === 1) {
                $browser = "{$name} {$match[1]}";
                break;
            }
        }
        $system = null;
        foreach (self::SYSTEMS as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                $system = $name;
                break;
            }
        }

        return match (true) {
            $browser !== null && $system !== null => __('auth.new_device.browser_on', ['browser' => $browser, 'system' => $system]),
            $browser !== null => $browser,
            $system !== null => __('auth.new_device.browser_on', ['browser' => __('auth.new_device.unknown_browser'), 'system' => $system]),
            default => __('auth.new_device.unknown_browser'),
        };
    }
}
