# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/). Until 1.0.0, a minor version may include breaking changes; they are listed under **Changed**.

## [0.2.0] - 2026-11-26

### Added

- **Repeaters:** `->items('lines', text: ['description'], values: ['quantity'])`, with `form.items()` in React and Vue, and `wire:model="lines.0.title"` with `Collaboration.items()` in Livewire.
- **Saves that respect each other:** fields this person left alone take what somebody else saved, and a field both changed asks which version to keep (`<Conflicts>`, `<x-collaboration::conflicts>`).
- **Presence:**
  - create pages: `Collaboration::creating(Post::class)`;
  - who is on each record of a list: `Collaboration::presence($posts)`;
  - following a colleague from field to field.
- **Optional features:** `Collaboration::features([Features::comments(), Features::history(), Features::uploads()])` turns on comments with @mentions, version history with restore, and images uploaded into rich text.
- **Livewire:** `<x-collaboration::editor>` (rich text, no build step), `<x-collaboration::field-people>`.
- **Your own design:** `Collaboration::withoutStyles()`, and render functions and slots for the faces.
- `php artisan collaboration:install`, and a documentation site built from the README (`website/`).

### Changed

- Comments are optional: turn them on with `Features::comments()` to keep them.
- The comment and history components moved to `/react/comments`, `/react/history`, `/vue/comments` and `/vue/history`.

## [0.1.0] - 2026-11-25

### Added

- **Live fields:**
  - Text merges by character.
  - Rich text (TipTap) keeps its formatting.
  - Selects, checkboxes, checkbox groups, radios and dates take the latest choice.
- **Presence:** faces, who is in which field, carets and selections, and sync status.
- **Editing:** each person's own undo, and locked fields.
- **Saving everybody's edits:** `$post->collaboration()->update($validated)`, and `saveCollaboratively()` in Livewire.
- **Adapters:** Livewire, Inertia + React and Inertia + Vue, with TypeScript declarations.
- **Hooks for integrations,** used by filament-collaboration.

[0.2.0]: https://github.com/solutionforest/laravel-collaboration/releases/tag/v0.2.0
[0.1.0]: https://github.com/solutionforest/laravel-collaboration/releases/tag/v0.1.0
