---
name: laravel-collaboration-development
description: Use when adding or maintaining Google Docs-style collaborative editing in Laravel apps with solutionforest/laravel-collaboration (Livewire 4, Inertia 3 + React 19, Inertia 3 + Vue 3.5, TipTap 3, or custom components). Covers Collaborative models, Room fluent API, WithCollaboration, useCollaborativeForm, v-collaborate, custom component bindings, repeaters, optional features (comments, history, uploads), permissions, Reverb, Redis presence, and testing.
license: MIT
metadata:
  author: solutionforest
---

# solutionforest/laravel-collaboration

Google Docs-style collaborative editing for any Laravel app. Multiple users edit the same Eloquent record at once: text and TipTap rich text merge character by character in a shared CRDT draft, choices take the latest value, non-live fields merge three ways on save with conflict resolution, and everyone sees avatars, field badges, and remote carets.

Requires PHP 8.3+, Laravel 12 or 13, and Livewire 4 or Inertia 3 (React 19 or Vue 3.5). Works over plain HTTP polling by default, or pushes over Laravel Reverb. For Filament 5 admin panels, use `solutionforest/filament-collaboration` (which builds on this package).

Documentation: [solutionforest.github.io/laravel-collaboration](https://solutionforest.github.io/laravel-collaboration/)

## Installation

```bash
composer require solutionforest/laravel-collaboration
php artisan collaboration:install
```

- **Livewire:** No npm step needed. `<x-collaboration::room>` loads the bundled script automatically.
- **Inertia (React or Vue):** Install the browser package from `vendor/` (the installer runs this automatically):

```bash
npm install ./vendor/solutionforest/laravel-collaboration
```

Import from `@solutionforest/laravel-collaboration/react`, `@solutionforest/laravel-collaboration/vue`, `@solutionforest/laravel-collaboration/tiptap`, or `@solutionforest/laravel-collaboration` (framework-free client).

## 1. Model setup

Add `SolutionForest\Collaboration\Concerns\Collaborative` and declare `$collaborative`:

```php
use SolutionForest\Collaboration\Concerns\Collaborative;
use SolutionForest\Collaboration\Enums\FieldKind;

class Post extends Model
{
    use Collaborative;

    protected array $collaborative = [
        'title',                          // FieldKind::Text (character merge + carets + per-user undo)
        'summary',                        // FieldKind::Text
        'body' => FieldKind::RichText,    // TipTap document (JSON if cast to array/json, HTML otherwise)
        'status' => FieldKind::Value,     // Latest choice wins (select, radio, checkbox, date)
        'featured',                       // Cast to bool -> auto-detected as FieldKind::Value
        'channels',                       // Cast to array -> checkbox group (FieldKind::Value)
        'lines' => FieldKind::Items,      // Repeater list of keyed items
    ];
}
```

Or describe a room fluently in code without the trait:

```php
use SolutionForest\Collaboration\Collaboration;

$room = Collaboration::room($post)
    ->text('title', 'summary')
    ->rich('body')
    ->value('status', allowed: PostStatus::class) // enum casts also enforce allowed values automatically
    ->lock('price', 'slug')                       // read-only for others while someone is in the field
    ->items('lines', text: ['description'], values: ['quantity'])
    ->labels(['body' => 'Article'])               // shown in presence tooltips & conflicts
    ->locale('fr')                                // one shared draft per locale
    ->viewing();                                  // read-only presence (policy `view` ability)
```

For create pages (presence before a record exists): `Collaboration::creating(Post::class)` (checks policy `create`).

## 2. Livewire 4 integration

```php
use Livewire\Component;
use SolutionForest\Collaboration\Livewire\WithCollaboration;
use SolutionForest\Collaboration\Room;

class EditPost extends Component
{
    use WithCollaboration;

    public Post $post;
    public string $title = '';
    public array|string|null $body = null; // TipTap sends JSON arrays while typing; keep array|string|null
    public string $status = 'draft';

    public function room(): Room
    {
        return $this->post->collaboration();
    }

    public function save(): void
    {
        $this->validate([...]);
        $this->saveCollaboratively(); // pass statePath: 'form' for Livewire Form objects
    }
}
```

```blade
<form wire:submit="save">
    {{-- Always pass $this->collaborationRoom(), not $this->room() --}}
    <x-collaboration::room :room="$this->collaborationRoom()" />
    <x-collaboration::conflicts :conflicts="$collaborationConflicts" />

    <label>
        Title <x-collaboration::field-people field="title" />
        <input wire:model="title">
    </label>

    <select wire:model="status">...</select>
    <x-collaboration::editor field="body" class="prose" />
</form>
```

- Pass `state-path="form"` to `<x-collaboration::room>` and `<x-collaboration::editor>` and `statePath: 'form'` to `saveCollaboratively()` when using Livewire Form objects.
- For repeaters in Livewire: bind rows with `wire:model="lines.{{ $index }}.description"` and `wire:key="{{ $line['key'] }}"`, and mutate items with `Collaboration.items($el, 'lines').add({ description: '' })` / `.remove(key)` / `.move(key, to)`.
- For list/table presence: wrap the table in `<x-collaboration::record-presence :presence="Collaboration::presence($posts)">` and render `<x-collaboration::record-people :record="$post->id" />` per row.

## 3. Inertia 3 + React 19 integration

Controller:

```php
public function edit(Post $post)
{
    return Inertia::render('Posts/Edit', [
        'post' => $post,
        'collaboration' => $post->collaboration(),
    ]);
}

public function update(Request $request, Post $post)
{
    $post->collaboration()->update($request->validate([...]));
    // Or custom persist logic:
    // $post->collaboration()->save($validated, fn (array $values) => $post->fill($values)->publish());

    return back();
}
```

Page component:

```jsx
import { Avatars, Conflicts, FieldPeople, Status, useCollaborativeForm } from '@solutionforest/laravel-collaboration/react'
import { CollaborativeEditor } from '@solutionforest/laravel-collaboration/react/editor'

export default function Edit({ post, collaboration }) {
    const form = useCollaborativeForm(collaboration, {
        title: post.title,
        body: post.body ?? '',
        status: post.status,
        channels: post.channels ?? [],
    })

    return (
        <form onSubmit={(e) => { e.preventDefault(); form.put(`/posts/${post.id}`) }}>
            <Avatars form={form} />
            <Status form={form} />
            <Conflicts form={form} />

            <FieldPeople form={form} field="title" />
            <input {...form.field('title')} />
            <select {...form.field('status')}>...</select>
            <input type="checkbox" {...form.field('channels', { value: 'email' })} />
            <CollaborativeEditor form={form} field="body" />
        </form>
    )
}
```

- Radios and checkbox groups take `{ value: '...' }`: `form.field('channels', { value: 'email' })`.
- Repeaters: `const lines = form.items('lines')` -> `lines.list`, `lines.field(line.key, 'description')`, `lines.add({...})`, `lines.remove(key)`, `lines.move(key, to)`.
- List/table presence: `const presence = useRecordPresence(props.presence)` + `<RecordPeople presence={presence} record={post.id} />`.
- Presence without a form: `useCollaboration(props.collaboration)`.

## 4. Inertia 3 + Vue 3.5 integration

Controller is the same as React. In Vue, pass a **getter** `() => props.collaboration` to `useCollaborativeForm()`:

```vue
<script setup>
import { Avatars, Conflicts, FieldPeople, Status, useCollaborativeForm, vCollaborate } from '@solutionforest/laravel-collaboration/vue'
import { CollaborativeEditor } from '@solutionforest/laravel-collaboration/vue/editor'

const props = defineProps({ post: Object, collaboration: Object })

const form = useCollaborativeForm(() => props.collaboration, {
    title: props.post.title,
    body: props.post.body ?? '',
    status: props.post.status,
})
</script>

<template>
    <form @submit.prevent="form.put(`/posts/${props.post.id}`)">
        <Avatars :form="form" />
        <Status :form="form" />
        <Conflicts :form="form" />

        <FieldPeople :form="form" field="title" />
        <input v-model="form.title" v-collaborate="form.field('title')">
        <select v-model="form.status" v-collaborate="form.field('status')">...</select>
        <CollaborativeEditor :form="form" field="body" />
    </form>
</template>
```

- Repeaters: `v-collaborate="form.items('lines').field(line.key, 'description')"` and `form.items('lines').add(...)`.
- List/table presence: `const presence = useRecordPresence(() => props.presence)` + `<RecordPeople :presence="presence" :record="post.id" />`.

## 5. Building custom components

- **Native input wrappers** (shadcn/ui, Blade `<x-input>`, Vue input wrappers): forward `{...form.field(name)}` (React), `v-model` + `v-collaborate="form.field(name)"` (Vue), or `wire:model` / `data-collaborate="name"` (Livewire).
- **Custom pickers (`FieldKind::Value`):**
  - React: call `form.field(name).onChange(newValue)` directly with a plain value, and wire `onFocus`/`onBlur`/`ref`.
  - Vue: assign `form[name] = value` and call `form.collaboration.room?.change(name, value)`, `room?.focus(name)`, `room?.blur(name)`.
  - Livewire: wrap with `wire:ignore`, get the room via `window.Collaboration.whenReady(this.$el, (room) => ...)`, call `room.bind(name, { render, reveal })`, `room.change(name, value)`, and `$wire.$set(name, value, false)`.
- **Custom TipTap toolbar:** build TipTap with `Collaborative.configure({ room, field, images })` from `@solutionforest/laravel-collaboration/tiptap`. In Livewire/Alpine, keep the TipTap `Editor` instance off Alpine reactive data (`this.$el._editor = ...`).
- **Third-party text/code editors (CodeMirror, Quill, plain JS):** use `room.bind(field, { capture, render, cursor, isBusy, reveal })`, `room.change(field, value, caret)`, `room.idAt(field, index)`, `room.indexOf(field, id)`, and `room.carets(field)`.

## 6. Optional features (off by default)

Enable in `AppServiceProvider::boot()` (or `register()`):

```php
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Features;

Collaboration::features([
    Features::comments(),
    Features::history(),
    Features::uploads(),
]);
```

- **Comments:** `<x-collaboration::comments />`, or import `Comments` / `useComments` from `@solutionforest/laravel-collaboration/react/comments` or `/vue/comments`. Configure mentions with `Collaboration::mentionUsersUsing(fn (Post $post) => $post->team->users)` and listen to `SolutionForest\Collaboration\Events\CommentPosted`.
- **History:** `<x-collaboration::history />`, or import `History` / `useVersions` from `@solutionforest/laravel-collaboration/react/history` or `/vue/history`.
- **Rich text uploads:** pass `images` prop on `<x-collaboration::editor field="body" images />` or `<CollaborativeEditor ... :extensions="[StarterKit, Image]" images />`. Unused uploads are pruned by `collaboration:prune-uploads`.

## 7. Permissions, real time, styling, and events

```php
Collaboration::broadcast();                                              // push via Laravel Echo / Reverb
Collaboration::pollEvery(1000);                                          // fallback polling interval (ms)
Collaboration::nameUsersUsing(fn (User $user) => $user->display_name);
Collaboration::giveUsersAvatarsUsing(fn (User $user) => $user->avatar_url);
Collaboration::authorizeUsing(fn (User $user, Model $record, string $ability) => $user->can($ability, $record));
Collaboration::scopeRoomsUsing(fn (?Model $record) => tenant()?->id);    // multi-tenant isolation
Collaboration::audit();                                                  // per-minute edit trail
Collaboration::withoutStyles();                                          // disable default CSS; style `.collab-*` classes
```

- Policies: `update` for editing, `view` for `->viewing()`, `create` for `Collaboration::creating()`, and optional `collaborate` to allow saving without live editing.
- After role/permission changes: `SolutionForest\Collaboration\Permissions::changed($post)` (or `Permissions::changed()`).
- Deployment env vars (`config/collaboration.php`): `COLLABORATION_PRESENCE=redis`, `COLLABORATION_CACHE_STORE=redis` (must support atomic locks across servers), `COLLABORATION_PATH`, `COLLABORATION_PRESENCE_TIMEOUT`, `COLLABORATION_TOKEN_LIFETIME`.
- Events (`SolutionForest\Collaboration\Events\*`): `SharedDraftSaved`, `ConflictDetected`, `ConflictResolved`, `ChangesMerged`, `DraftEdited`, `CommentPosted`.

## Common mistakes

- **Passing `$this->room()` instead of `$this->collaborationRoom()` in Livewire Blade:** `$this->collaborationRoom()` attaches the persistent tab client ID and captures non-live field bases for conflict detection.
- **Typing a Livewire rich text property as `string`:** TipTap writes ProseMirror JSON arrays into the property while editing; type it `public array|string|null $body = null;`.
- **Turning a React live text input into a controlled `value={form.data.title}`:** Always spread `{...form.field('title')}` (which uses `defaultValue`) so React re-renders never drop simultaneous keystrokes.
- **Passing `props.collaboration` directly in Vue instead of `() => props.collaboration`:** Vue's `useCollaborativeForm()` expects a getter so token renewals stay reactive.
- **Storing a TipTap or third-party editor instance on Alpine reactive data (`this.editor = ...`):** Alpine's Proxy breaks editors; attach it to the DOM element (`this.$el._editor = ...`).
- **Missing `Features::comments()` / `Features::history()` / `Features::uploads()`:** Optional features register no routes, migrations, or endpoints until enabled in `Collaboration::features([...])`.
- **Testing two users in two tabs of the same browser:** Tabs share one session cookie; use a private/incognito window or a second browser profile for the second user.
