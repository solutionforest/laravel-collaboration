## Laravel Collaboration

- `solutionforest/laravel-collaboration` adds Google Docs-style collaborative editing to Livewire 4 and Inertia 3 (React 19 / Vue 3.5) apps: live fields merge character by character, choices take the latest value, non-live fields merge three ways on save, and everyone sees avatars, field badges, and carets.
- Models opt in with `SolutionForest\Collaboration\Concerns\Collaborative` and `$collaborative`, or rooms are described fluently with `Collaboration::room($model)`.
- Livewire components use `SolutionForest\Collaboration\Livewire\WithCollaboration`, render `<x-collaboration::room :room="$this->collaborationRoom()" />`, and save via `$this->saveCollaboratively()`.
- Inertia pages pass `'collaboration' => $post->collaboration()`, use `useCollaborativeForm()` (`{...form.field('title')}` in React, `v-collaborate="form.field('title')"` in Vue), and save via `$post->collaboration()->update($validated)`.
- Optional features (`Features::comments()`, `Features::history()`, `Features::uploads()`) are off by default and enabled with `Collaboration::features([...])`.
- Activate the `laravel-collaboration-development` skill when setting up collaborative pages, building custom collaborative components or editors, wiring repeaters, comments, history, or uploads, configuring Reverb or Redis presence, or troubleshooting sync and saves.
