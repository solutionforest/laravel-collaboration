<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel Collaboration · demo</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 font-sans antialiased">
    <main class="mx-auto max-w-3xl px-6 py-16">
        <h1 class="text-3xl font-semibold text-slate-900">Laravel Collaboration</h1>
        <p class="mt-3 text-lg text-slate-600">One article, edited from three stacks at once. Open it as different people in different browsers, and type.</p>

        <div class="mt-10 space-y-4">
            @foreach ($users as $user)
                <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
                    <span class="font-medium text-slate-900">{{ $user->name }}</span>
                    <span class="flex gap-2 text-sm">
                        @foreach (['Livewire' => route('livewire.posts.edit', $post, false), 'React' => route('react.posts.edit', $post, false), 'Vue' => route('vue.posts.edit', $post, false)] as $stack => $url)
                            <a href="{{ route('sign-in', ['user' => $user, 'to' => $url]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-slate-700 hover:border-indigo-500 hover:text-indigo-600">{{ $stack }}</a>
                        @endforeach
                    </span>
                </div>
            @endforeach
        </div>

        <p class="mt-8 text-sm text-slate-500">Tip: use a private window for each person, since each browser keeps one session.</p>
    </main>
</body>
</html>
