<!DOCTYPE html>
{{-- The public dashboard's 429 (SECURITY.md §8): plain, no app shell, no IDs, a way back to Home. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ __('errors.too_many.title') }} · {{ config('app.name') }}</title>
        <style>
            body { margin: 0; font-family: system-ui, sans-serif; color: #1d2b27; background: #f6f8f7; }
            main { max-width: 36rem; margin: 15vh auto; padding: 0 1rem; line-height: 1.5; }
            h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
            a { color: #0b5b48; }
        </style>
    </head>
    <body>
        <main>
            <h1>{{ __('errors.too_many.title') }}</h1>
            <p>{{ __('errors.too_many.body') }}</p>
            <p><a href="/">{{ __('errors.too_many.home') }}</a></p>
        </main>
    </body>
</html>
