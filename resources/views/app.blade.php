<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <script>
            (function () {
                var stored = localStorage.getItem('theme');
                var theme = stored === 'light' || stored === 'dark'
                    ? stored
                    : window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';

                if (theme === 'dark') {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700" rel="stylesheet" />
        <title inertia>{{ config('app.name', 'CRF-ERP') }}</title>
        @routes
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
