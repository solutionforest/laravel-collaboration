<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @vite(['resources/css/app.css', 'resources/js/vue.js'])
    @inertiaHead
</head>
<body class="min-h-screen bg-slate-50 font-sans antialiased">
    @include('partials.header', ['stack' => 'Vue'])

    <main class="mx-auto max-w-5xl px-6 py-8">
        @inertia
    </main>
</body>
</html>
