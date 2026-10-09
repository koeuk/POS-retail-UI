<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover lets the layout paint under the notch and the
             home indicator; the safe-area insets in app.css put padding back
             where it is actually needed. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="theme-color" content="#fbf9f5" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#16150f" media="(prefers-color-scheme: dark)">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Paint the theme ground before first paint so a dark-mode reload
             never flashes white. --}}
        <style>
            html { background-color: hsl(40 33% 98%); }
            html.dark { background-color: hsl(28 9% 8%); }
        </style>

        @if ($favicon = \App\Models\Setting::get('shop_favicon'))
            <link rel="icon" href="{{ asset('storage/'.$favicon) }}">
        @endif

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        {{-- Noto Sans Khmer carries the Khmer glyphs — product names like
             ទឹកសុទ្ធ render in a face that matches the Latin type instead of
             falling back to whatever the OS ships. --}}
        <link
            href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@300;400;500;600;700;800&family=Noto+Sans+Khmer:wght@100..900&display=swap"
            rel="stylesheet"
        />

        {{-- Telegram Mini App bridge. Loaded eagerly (not deferred) so
             window.Telegram exists before the Vue app mounts and asks it for
             viewport height and safe-area insets. Outside Telegram this is a
             ~10KB no-op: useTelegram() detects the missing initData and every
             call becomes a stub. --}}
        <script src="https://telegram.org/js/telegram-web-app.js"></script>

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
