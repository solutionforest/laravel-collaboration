# Your first collaborative page

This walkthrough builds a complete collaborative edit page from scratch: live title and summary (merged by character, with carets and per-user undo), status select, featured checkbox, publish-to checkbox group, a TipTap rich text editor, avatars, per-field presence badges, save-conflict handling, and saving.

Every snippet below matches the working [demo](../demo) in this repository. Pick your stack once — **Livewire**, **Inertia + React**, or **Inertia + Vue** — and follow the steps in order.

## Before you start

Install the package and create its tables:

```bash
composer require solutionforest/laravel-collaboration
php artisan collaboration:install
```

If you use Inertia, the installer runs `npm install ./vendor/solutionforest/laravel-collaboration` for you. Make sure `@tiptap/core`, `@tiptap/pm`, `@tiptap/starter-kit`, and `@tiptap/react` (or `@tiptap/vue-3`) are installed if you want a rich text editor.

## 1. Declare the collaborative fields on your model

Add the `Collaborative` trait to `App\Models\Post` and list the attributes people should edit together:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Collaboration\Concerns\Collaborative;
use SolutionForest\Collaboration\Enums\FieldKind;

class Post extends Model
{
    use Collaborative;

    protected $fillable = [
        'title',
        'summary',
        'body',
        'status',
        'featured',
        'channels',
    ];

    protected array $collaborative = [
        'title',                       // text: merged character by character, with carets
        'summary',                     // text: merged character by character, with carets
        'body' => FieldKind::RichText, // TipTap: merged with formatting
        'status' => FieldKind::Value,  // select: latest choice wins
        'featured',                    // cast to bool -> automatically FieldKind::Value
        'channels',                    // cast to array -> automatically FieldKind::Value
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'channels' => 'array',
        ];
    }
}
```

How kinds are chosen:

| Column / cast | Kind | Behaviour when two people edit at once |
| --- | --- | --- |
| Plain string (no cast) | `FieldKind::Text` | Merges character by character, shows carets, Ctrl+Z undoes only your edits |
| `FieldKind::RichText` | `FieldKind::RichText` | TipTap document merged with formatting; saved as JSON if cast to `array`/`json`, HTML otherwise |
| `bool`, `int`, `float`, `date`, `enum`, `array` cast | `FieldKind::Value` | Latest choice wins (selects, checkboxes, checkbox groups, radios, dates) |
| `FieldKind::Items` | `FieldKind::Items` | Repeater list of keyed items; text sub-fields merge by character |

Any column **not** listed in `$collaborative` still stays safe: if two people change it before saving, a three-way merge detects the conflict and asks which version to keep.

## 2. Authorize who can join the room

The package uses your existing Laravel policy. Create `App\Policies\PostPolicy` (or update the one you have):

```php
<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function view(User $user, Post $post): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        return true;
    }

    // Optional: let someone edit and save without live collaboration.
    // Omit this method if everyone who can update may collaborate live.
    public function collaborate(User $user, Post $post): bool
    {
        return $this->update($user, $post);
    }
}
```

Access is checked when the page renders **and again on every sync request**. If a user loses access while the page is open, live editing stops immediately.

## 3. Wire the server

<details open>
<summary><b>Livewire</b></summary>

Create a Livewire component with `WithCollaboration`. Define `room()` to return `$this->post->collaboration()`, and call `$this->saveCollaboratively()` inside `save()`:

```php
<?php

namespace App\Livewire;

use App\Models\Post;
use Livewire\Component;
use SolutionForest\Collaboration\Livewire\WithCollaboration;
use SolutionForest\Collaboration\Room;

class EditPost extends Component
{
    use WithCollaboration;

    public Post $post;

    public string $title = '';

    public ?string $summary = '';

    /** HTML from the database; TipTap JSON array once someone types. */
    public array|string|null $body = null;

    public string $status = 'draft';

    public bool $featured = false;

    /** @var list<string> */
    public array $channels = [];

    public bool $saved = false;

    public function mount(Post $post): void
    {
        $this->fill([
            ...$post->only('title', 'summary', 'body', 'status', 'featured'),
            'channels' => $post->channels ?? [],
        ]);
    }

    public function room(): Room
    {
        return $this->post->collaboration();
    }

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:draft,review,published'],
        ]);

        $this->saveCollaboratively();

        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.edit-post', [
            'statuses' => ['draft' => 'Draft', 'review' => 'In review', 'published' => 'Published'],
            'channelOptions' => ['web' => 'Website', 'newsletter' => 'Newsletter', 'social' => 'Social'],
        ]);
    }
}
```

Register a route in `routes/web.php`:

```php
use App\Livewire\EditPost;

Route::middleware('auth')->get('/posts/{post}/edit', EditPost::class)->name('posts.edit');
```

> **Tip:** Type rich text properties as `array|string|null`. While people edit in `<x-collaboration::editor>`, TipTap sends its JSON document to Livewire, and `saveCollaboratively()` converts it back to HTML automatically if your database column stores HTML.

</details>

<details>
<summary><b>Inertia + React</b></summary>

Pass `'collaboration' => $post->collaboration()` from your controller's `edit()` action, and save through `$post->collaboration()->update(...)` in `update()`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function edit(Post $post): Response
    {
        return Inertia::render('Posts/Edit', [
            'post' => $post,
            'statuses' => ['draft' => 'Draft', 'review' => 'In review', 'published' => 'Published'],
            'channels' => ['web' => 'Website', 'newsletter' => 'Newsletter', 'social' => 'Social'],
            'collaboration' => $post->collaboration(),
        ]);
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'body' => ['nullable'],
            'status' => ['required', 'in:draft,review,published'],
            'featured' => ['boolean'],
            'channels' => ['array'],
            'channels.*' => ['string'],
        ]);

        $post->collaboration()->update($validated);

        return back();
    }
}
```

Register the routes in `routes/web.php`:

```php
use App\Http\Controllers\PostController;

Route::middleware('auth')->group(function () {
    Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
    Route::put('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
});
```

</details>

<details>
<summary><b>Inertia + Vue</b></summary>

The controller and routes are identical to React. Pass `'collaboration' => $post->collaboration()` from `edit()`, and save with `$post->collaboration()->update($validated)` in `update()`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function edit(Post $post): Response
    {
        return Inertia::render('Posts/Edit', [
            'post' => $post,
            'statuses' => ['draft' => 'Draft', 'review' => 'In review', 'published' => 'Published'],
            'channels' => ['web' => 'Website', 'newsletter' => 'Newsletter', 'social' => 'Social'],
            'collaboration' => $post->collaboration(),
        ]);
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'body' => ['nullable'],
            'status' => ['required', 'in:draft,review,published'],
            'featured' => ['boolean'],
            'channels' => ['array'],
            'channels.*' => ['string'],
        ]);

        $post->collaboration()->update($validated);

        return back();
    }
}
```

Register the routes in `routes/web.php`:

```php
use App\Http\Controllers\PostController;

Route::middleware('auth')->group(function () {
    Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
    Route::put('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
});
```

</details>

## 4. Build the form page

<details open>
<summary><b>Livewire</b></summary>

Create `resources/views/livewire/edit-post.blade.php`. Place `<x-collaboration::room :room="$this->collaborationRoom()" />` inside the component — every `wire:model` input matching a room field becomes collaborative automatically:

```blade
<form wire:submit="save" class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">Edit article</h1>
        <x-collaboration::room :room="$this->collaborationRoom()" />
    </div>

    <x-collaboration::conflicts :conflicts="$collaborationConflicts" />

    <div class="space-y-4">
        <label class="block">
            <span>Title <x-collaboration::field-people field="title" /></span>
            <input wire:model="title" class="w-full rounded border px-3 py-2">
            @error('title') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        </label>

        <label class="block">
            <span>Summary <x-collaboration::field-people field="summary" /></span>
            <textarea wire:model="summary" rows="2" class="w-full rounded border px-3 py-2"></textarea>
        </label>

        <div class="grid grid-cols-2 gap-4">
            <label class="block">
                <span>Status <x-collaboration::field-people field="status" /></span>
                <select wire:model="status" class="w-full rounded border px-3 py-2">
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="mt-6 flex items-center gap-2">
                <input type="checkbox" wire:model="featured">
                Featured on the home page
            </label>
        </div>

        <fieldset>
            <legend>Publish to <x-collaboration::field-people field="channels" /></legend>
            <div class="mt-1 flex gap-4">
                @foreach ($channelOptions as $value => $label)
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="channels" value="{{ $value }}">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <span>Body <x-collaboration::field-people field="body" /></span>
            <x-collaboration::editor field="body" class="prose min-h-40 rounded border p-3" />
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="rounded bg-amber-500 px-4 py-2 font-medium text-white">Save</button>
        @if ($saved) <span class="text-sm text-emerald-600">Saved</span> @endif
    </div>
</form>
```

Notice there is **no JavaScript to write or bundle**: `<x-collaboration::room>` and `<x-collaboration::editor>` load their own scripts via Livewire's `@assets`.

</details>

<details>
<summary><b>Inertia + React</b></summary>

Create `resources/js/Pages/Posts/Edit.jsx`. Replace Inertia's `useForm()` with `useCollaborativeForm(collaboration, data)`, and spread `{...form.field('name')}` on each input:

```jsx
import {
    Avatars,
    Conflicts,
    FieldPeople,
    Status,
    useCollaborativeForm,
} from '@solutionforest/laravel-collaboration/react'
import { CollaborativeEditor } from '@solutionforest/laravel-collaboration/react/editor'

export default function Edit({ post, statuses, channels, collaboration }) {
    const form = useCollaborativeForm(collaboration, {
        title: post.title,
        summary: post.summary ?? '',
        body: post.body ?? '',
        status: post.status,
        featured: post.featured,
        channels: post.channels ?? [],
    })

    const save = (event) => {
        event.preventDefault()
        form.put(`/posts/${post.id}`, { preserveScroll: true })
    }

    return (
        <form onSubmit={save} className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold">Edit article</h1>
                    <Status form={form} className="text-sm text-slate-500" />
                </div>
                <Avatars form={form} />
            </div>

            <Conflicts form={form} />

            <div className="space-y-4">
                <label className="block">
                    <span>Title <FieldPeople form={form} field="title" /></span>
                    <input className="w-full rounded border px-3 py-2" {...form.field('title')} />
                    {form.errors.title && <p className="text-sm text-red-600">{form.errors.title}</p>}
                </label>

                <label className="block">
                    <span>Summary <FieldPeople form={form} field="summary" /></span>
                    <textarea rows={2} className="w-full rounded border px-3 py-2" {...form.field('summary')} />
                </label>

                <div className="grid grid-cols-2 gap-4">
                    <label className="block">
                        <span>Status <FieldPeople form={form} field="status" /></span>
                        <select className="w-full rounded border px-3 py-2" {...form.field('status')}>
                            {Object.entries(statuses).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </label>

                    <label className="mt-6 flex items-center gap-2">
                        <input type="checkbox" {...form.field('featured')} />
                        Featured on the home page
                    </label>
                </div>

                <fieldset>
                    <legend>Publish to <FieldPeople form={form} field="channels" /></legend>
                    <div className="mt-1 flex gap-4">
                        {Object.entries(channels).map(([value, label]) => (
                            <label key={value} className="flex items-center gap-2">
                                <input type="checkbox" {...form.field('channels', { value })} />
                                {label}
                            </label>
                        ))}
                    </div>
                </fieldset>

                <div>
                    <span>Body <FieldPeople form={form} field="body" /></span>
                    <CollaborativeEditor form={form} field="body" className="prose min-h-40 rounded border p-3" />
                </div>
            </div>

            <div className="flex items-center gap-3">
                <button type="submit" disabled={form.processing} className="rounded bg-amber-500 px-4 py-2 font-medium text-white">
                    Save
                </button>
                {form.recentlySuccessful && <span className="text-sm text-emerald-600">Saved</span>}
            </div>
        </form>
    )
}
```

</details>

<details>
<summary><b>Inertia + Vue</b></summary>

Create `resources/js/Pages/Posts/Edit.vue`. Pass `() => props.collaboration` to `useCollaborativeForm()`, and add `v-collaborate="form.field('name')"` alongside each `v-model`:

```vue
<script setup>
import {
    Avatars,
    Conflicts,
    FieldPeople,
    Status,
    useCollaborativeForm,
    vCollaborate,
} from '@solutionforest/laravel-collaboration/vue'
import { CollaborativeEditor } from '@solutionforest/laravel-collaboration/vue/editor'

const props = defineProps({
    post: Object,
    statuses: Object,
    channels: Object,
    collaboration: Object,
})

const form = useCollaborativeForm(() => props.collaboration, {
    title: props.post.title,
    summary: props.post.summary ?? '',
    body: props.post.body ?? '',
    status: props.post.status,
    featured: props.post.featured,
    channels: props.post.channels ?? [],
})

const save = () => form.put(`/posts/${props.post.id}`, { preserveScroll: true })
</script>

<template>
    <form class="space-y-6" @submit.prevent="save">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Edit article</h1>
                <Status :form="form" class="text-sm text-slate-500" />
            </div>
            <Avatars :form="form" />
        </div>

        <Conflicts :form="form" />

        <div class="space-y-4">
            <label class="block">
                <span>Title <FieldPeople :form="form" field="title" /></span>
                <input v-model="form.title" v-collaborate="form.field('title')" class="w-full rounded border px-3 py-2">
                <p v-if="form.errors.title" class="text-sm text-red-600">{{ form.errors.title }}</p>
            </label>

            <label class="block">
                <span>Summary <FieldPeople :form="form" field="summary" /></span>
                <textarea v-model="form.summary" v-collaborate="form.field('summary')" rows="2" class="w-full rounded border px-3 py-2" />
            </label>

            <div class="grid grid-cols-2 gap-4">
                <label class="block">
                    <span>Status <FieldPeople :form="form" field="status" /></span>
                    <select v-model="form.status" v-collaborate="form.field('status')" class="w-full rounded border px-3 py-2">
                        <option v-for="(label, value) in statuses" :key="value" :value="value">{{ label }}</option>
                    </select>
                </label>

                <label class="mt-6 flex items-center gap-2">
                    <input v-model="form.featured" v-collaborate="form.field('featured')" type="checkbox">
                    Featured on the home page
                </label>
            </div>

            <fieldset>
                <legend>Publish to <FieldPeople :form="form" field="channels" /></legend>
                <div class="mt-1 flex gap-4">
                    <label v-for="(label, value) in channels" :key="value" class="flex items-center gap-2">
                        <input v-model="form.channels" v-collaborate="form.field('channels')" type="checkbox" :value="value">
                        {{ label }}
                    </label>
                </div>
            </fieldset>

            <div>
                <span>Body <FieldPeople :form="form" field="body" /></span>
                <CollaborativeEditor :form="form" field="body" class="prose min-h-40 rounded border p-3" />
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" :disabled="form.processing" class="rounded bg-amber-500 px-4 py-2 font-medium text-white">
                Save
            </button>
            <span v-if="form.recentlySuccessful" class="text-sm text-emerald-600">Saved</span>
        </div>
    </form>
</template>
```

</details>

## 5. Open two browsers and try it

Sign in as **Alice** in a normal browser window and **Bob** in a private window (each needs its own session), then open the same `/posts/{post}/edit` page in both:

1. **See each other:** Alice's avatar appears in Bob's header and vice versa. Click a field and a coloured badge with that person's name appears next to the label.
2. **Type together:** Type into **Title** or **Body** in both windows at once. Characters merge in place and each browser draws the other person's caret.
3. **Undo only your own typing:** Press `Ctrl+Z` (`Cmd+Z` on macOS) in Alice's browser. Only Alice's edits undo — Bob's words stay untouched.
4. **Follow a colleague:** Click Bob's avatar in the header. As Bob moves between fields, Alice's page scrolls to keep him in view.
5. **Save without losing work:** Click **Save** in either browser. Both Alice's and Bob's edits are written to the database, and the other browser shows *"Alice saved"*.

## 6. Next steps

- **Build custom components:** See [Make your own component live](custom-components.md) to connect custom pickers, UI-kit wrappers, custom TipTap toolbars, or third-party editors.
- **Add repeaters, locks, or translations:** See [Describing a room in code](../README.md#describing-a-room-in-code) and [Repeaters](../README.md#repeaters).
- **Turn on comments, history, or image uploads:** See [Optional features](../README.md#optional-features).
- **Push changes instantly:** Set up [Reverb](../README.md#pushing-changes-with-reverb) and call `Collaboration::broadcast()`.
