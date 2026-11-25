<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Inertia pages, React and Vue alike: only the root view differs. */
class PostController
{
    public function edit(Post $post): Response
    {
        return Inertia::render('Posts/Edit', [
            'post' => $post,
            'statuses' => Post::STATUSES,
            'collaboration' => $post->collaboration(),
        ]);
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'body' => ['nullable'],
            'status' => ['required', 'in:'.implode(',', array_keys(Post::STATUSES))],
            'featured' => ['boolean'],
        ]);

        $post->collaboration()->update($validated);

        return back()->with('saved', true);
    }
}
