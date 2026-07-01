<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'slot4u') }}</title>
    {{-- Apply the saved theme before first paint (dark default) to avoid a flash. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme') || 'dark';
                document.documentElement.classList.toggle('dark', t !== 'light');
            } catch (e) {}
        })();
    </script>
    @viteReactRefresh
    @vite(['resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-screen bg-background text-foreground antialiased">
    @inertia
</body>
</html>
