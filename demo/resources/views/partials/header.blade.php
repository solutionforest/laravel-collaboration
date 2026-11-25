@php
    $post = \App\Models\Post::first();
    $stacks = ['Livewire' => route('livewire.posts.edit', $post), 'React' => route('react.posts.edit', $post), 'Vue' => route('vue.posts.edit', $post)];
@endphp

<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-3">
        <a href="{{ route('home') }}" class="font-semibold text-slate-900">Laravel Collaboration <span class="font-normal text-slate-400">demo</span></a>

        <nav class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm">
            @foreach ($stacks as $name => $url)
                <a href="{{ $url }}" @class(['rounded-md px-3 py-1.5', 'bg-white font-semibold text-slate-900 shadow-sm' => $stack === $name, 'text-slate-600 hover:text-slate-900' => $stack !== $name])>{{ $name }}</a>
            @endforeach
        </nav>

        <span class="text-sm text-slate-500">Signed in as <strong class="text-slate-900">{{ auth()->user()?->name }}</strong></span>
    </div>
</header>
