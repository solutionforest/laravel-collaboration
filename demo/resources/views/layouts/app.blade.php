<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $stack }} · Laravel Collaboration</title>
    @vite(['resources/css/app.css', 'resources/js/livewire.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans antialiased">
    @include('partials.header', ['stack' => $stack])

    <main class="mx-auto max-w-5xl px-6 py-8">
        {{ $slot }}
    </main>
</body>
</html>
