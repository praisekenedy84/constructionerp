<!DOCTYPE html>
@php
    $appBuild = is_file(public_path('build/manifest.json')) ? hash_file('sha256', public_path('build/manifest.json')) : 'development';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="app-build" content="{{ $appBuild }}">
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
        <link href="https://fonts.bunny.net/css?family=poppins:400,500,600,700&amp;display=swap" rel="preload" as="style" onload="this.onload=null;this.rel='stylesheet'" />
        <noscript><link href="https://fonts.bunny.net/css?family=poppins:400,500,600,700&amp;display=swap" rel="stylesheet" /></noscript>
        <title inertia>{{ config('app.name', 'CRF-ERP') }}</title>
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        <div id="app-bootstrap-status" role="status" style="max-width:480px;margin:15vh auto;padding:24px;font:16px/1.6 system-ui;color:#334155;background:#f8fafc;border-radius:16px">
            <strong id="app-bootstrap-title">Loading application...</strong>
            <div id="app-bootstrap-recovery" hidden>
                <p>The page could not finish loading. Reload to get the latest application files, or reset your sign-in session.</p>
                <button type="button" onclick="window.location.reload()" style="padding:8px 16px;cursor:pointer">Reload page</button>
                <form action="{{ route('auth.reset-session') }}" method="POST" style="display:inline">
                    @csrf
                    <button type="submit" style="padding:8px 16px;cursor:pointer">Reset sign-in session</button>
                </form>
                <p><a href="{{ route('platform.login') }}">Platform administration</a></p>
            </div>
        </div>
        <script>
            (function () {
                var version = @json($appBuild);
                function recover() {
                    if (!document.getElementById('app-bootstrap-status')) return;
                    document.getElementById('app-bootstrap-title').textContent = 'Unable to load the application';
                    document.getElementById('app-bootstrap-recovery').hidden = false;
                    try {
                        var key = 'crf:bootstrap-retry:' + version;
                        if (!sessionStorage.getItem(key)) {
                            sessionStorage.setItem(key, '1');
                            window.location.reload();
                        }
                    } catch (e) { /* Recovery controls remain available when storage is blocked. */ }
                }
                window.addEventListener('error', function (event) {
                    if (event.target && event.target.tagName === 'SCRIPT' && event.target.src) recover();
                }, true);
                window.setTimeout(recover, 15000);
            })();
        </script>
        @inertia
    </body>
</html>
