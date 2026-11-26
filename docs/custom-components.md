# Make your own component live

Native HTML inputs (`<input>`, `<textarea>`, `<select>`, checkboxes, radios) and TipTap work out of the box. When your app uses its own components — a UI library like shadcn/ui or Flux, a custom star rating or tag picker, a composer with a character counter, or a third-party editor like CodeMirror or Quill — you can connect them to the same shared draft in a few lines.

## Before you start: pick the right pattern

| What your component is | Field kind | How to connect it |
| --- | --- | --- |
| A wrapper around a native `<input>`, `<textarea>`, or `<select>` (shadcn/ui, Blade `<x-input>`, Vue wrapper) | `FieldKind::Text` or `FieldKind::Value` | Forward the binding attributes/props to the underlying input — no extra JS |
| A custom picker or choice widget (star rating, colour picker, tag selector, date picker) | `FieldKind::Value` | Share whole values with `onChange(value)` (React) or `room.change(field, value)` (Vue / Livewire) |
| A custom text widget with `<input>` or `<textarea>` (post composer with character count, mention box) | `FieldKind::Text` | Spread `form.field()` / `v-collaborate` / `data-collaborate` onto the inner `<textarea>` |
| A custom TipTap editor (your own toolbar and extensions) | `FieldKind::RichText` | Add the `Collaborative` extension from `@solutionforest/laravel-collaboration/tiptap` |
| Any third-party text or code editor (CodeMirror, Quill, Ace, Monaco) | `FieldKind::Text` | Bind directly to `room.bind()` and `room.change()` |

## 1. Wrapping your own UI input components

If your app already has styled input components, you do **not** need a custom binding — forward the collaborative attributes to the native element inside.

<details open>
<summary><b>Livewire</b></summary>

Any Blade component that forwards `$attributes` (including `wire:model`) already works automatically. If your component renders an `<input>` or `<textarea>` without a direct `wire:model` on the element itself, add `data-collaborate="field_name"`:

```blade
{{-- resources/views/components/form/input.blade.php --}}
@props(['label', 'field'])

<label class="block">
    <span class="flex items-center gap-2 text-sm font-medium">
        {{ $label }}
        <x-collaboration::field-people :field="$field" />
    </span>

    <input
        {{ $attributes->merge(['class' => 'mt-1 w-full rounded-lg border px-3 py-2']) }}
        wire:model="{{ $field }}"
    >
</label>
```

Use it inside any Livewire component with `WithCollaboration`:

```blade
<x-form.input label="Title" field="title" />
```

</details>

<details>
<summary><b>Inertia + React</b></summary>

Any React component that forwards `ref`, `onChange`, `onFocus`, `onBlur`, and `defaultValue` / `value` works directly with `{...form.field('title')}`:

```jsx
import { FieldPeople } from '@solutionforest/laravel-collaboration/react'

export function FormInput({ form, field, label, ...props }) {
    return (
        <label className="block">
            <span className="flex items-center gap-2 text-sm font-medium">
                {label}
                <FieldPeople form={form} field={field} />
            </span>

            <input
                className="mt-1 w-full rounded-lg border px-3 py-2"
                {...form.field(field)}
                {...props}
            />

            {form.errors[field] && (
                <p className="mt-1 text-sm text-red-600">{form.errors[field]}</p>
            )}
        </label>
    )
}
```

> **Important for text fields in React:** Never overwrite `defaultValue` with `value={form.data[field]}` on a live text field (`FieldKind::Text`). `form.field()` deliberately passes `defaultValue` so the CRDT binding owns the DOM value and React never clobbers simultaneous keystrokes.

</details>

<details>
<summary><b>Inertia + Vue</b></summary>

Create a wrapper component that passes `v-model` and `v-collaborate` to its internal input:

```vue
<!-- resources/js/Components/FormInput.vue -->
<script setup>
import { FieldPeople, vCollaborate } from '@solutionforest/laravel-collaboration/vue'

defineProps({
    form: { type: Object, required: true },
    field: { type: String, required: true },
    label: { type: String, required: true },
})
</script>

<template>
    <label class="block">
        <span class="flex items-center gap-2 text-sm font-medium">
            {{ label }}
            <FieldPeople :form="form" :field="field" />
        </span>

        <input
            v-model="form[field]"
            v-collaborate="form.field(field)"
            class="mt-1 w-full rounded-lg border px-3 py-2"
        >

        <p v-if="form.errors[field]" class="mt-1 text-sm text-red-600">
            {{ form.errors[field] }}
        </p>
    </label>
</template>
```

</details>

## 2. Build a live picker: Star Rating (latest value wins)

A star rating is a row of buttons with no native `<input>`. Declare the column as `FieldKind::Value` on the model (an `integer` cast already makes it a value):

```php
protected array $collaborative = [
    'title',
    'rating' => FieldKind::Value,
];
```

<details open>
<summary><b>Livewire</b></summary>

Use `wire:ignore` so Livewire does not replace the widget while people click, and connect to the room with `Collaboration.whenReady()`:

```blade
<div
    wire:ignore
    x-data="{
        value: @js($rating ?? 0),
        room: null,
        init() {
            Collaboration.whenReady(this.$el, (room) => {
                this.room = room
                room.bind('rating', {
                    render: () => {
                        this.value = room.value('rating') ?? 0
                        $wire.$set('rating', this.value, false)
                    },
                    reveal: () => this.$el.scrollIntoView({ block: 'center', behavior: 'smooth' }),
                })
                if (room.isReady) this.value = room.value('rating') ?? this.value
            })
        },
        pick(star) {
            this.value = star
            $wire.$set('rating', star, false)
            this.room?.change('rating', star)
        },
    }"
    @focusin="room?.focus('rating')"
    @focusout="room?.blur('rating')"
    class="flex items-center gap-1"
>
    @for ($star = 1; $star <= 5; $star++)
        <button
            type="button"
            @click="pick({{ $star }})"
            :class="value >= {{ $star }} ? 'text-amber-500' : 'text-slate-300'"
        >★</button>
    @endfor
</div>
```

</details>

<details>
<summary><b>Inertia + React</b></summary>

In React, `form.field('rating').onChange` accepts a plain value directly (not just a DOM event), and `form.data.rating` stays updated when colleagues click:

```jsx
import { FieldPeople } from '@solutionforest/laravel-collaboration/react'

export function StarRating({ form, field = 'rating', max = 5 }) {
    const binding = form.field(field)
    const value = form.data[field] ?? 0
    const lockedBy = form.collaboration.room?.lockedBy(field)

    return (
        <div
            ref={binding.ref}
            tabIndex={-1}
            onFocus={binding.onFocus}
            onBlur={binding.onBlur}
            className="space-y-1"
        >
            <div className="flex items-center gap-2 text-sm font-medium">
                <span>Rating</span>
                <FieldPeople form={form} field={field} />
                {lockedBy && <span className="text-xs text-slate-500">{lockedBy.name} is editing</span>}
            </div>

            <div className="flex gap-1">
                {Array.from({ length: max }, (_, index) => index + 1).map((star) => (
                    <button
                        key={star}
                        type="button"
                        disabled={Boolean(lockedBy)}
                        onClick={() => binding.onChange(star)}
                        className={star <= value ? 'text-amber-500' : 'text-slate-300'}
                    >
                        ★
                    </button>
                ))}
            </div>
        </div>
    )
}
```

Use it on your page with `<StarRating form={form} field="rating" />`.

</details>

<details>
<summary><b>Inertia + Vue</b></summary>

In Vue, update `form[field]` and call `room.change(field, value)`. Bind `reveal` with `room.bind()` so following a colleague scrolls to the widget:

```vue
<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { FieldPeople } from '@solutionforest/laravel-collaboration/vue'

const props = defineProps({
    form: { type: Object, required: true },
    field: { type: String, default: 'rating' },
    max: { type: Number, default: 5 },
})

const root = ref(null)
const room = computed(() => props.form.collaboration.room)
const lockedBy = computed(() => room.value?.lockedBy(props.field) ?? null)

let unbind = null

watch(room, (current) => {
    unbind?.()
    unbind = current?.bind(props.field, {
        reveal: () => root.value?.scrollIntoView({ block: 'center', behavior: 'smooth' }),
    }) ?? null
}, { immediate: true })

onBeforeUnmount(() => unbind?.())

function pick(star) {
    props.form[props.field] = star
    room.value?.change(props.field, star)
}
</script>

<template>
    <div
        ref="root"
        tabindex="-1"
        class="space-y-1"
        @focusin="room?.focus(field)"
        @focusout="room?.blur(field)"
    >
        <div class="flex items-center gap-2 text-sm font-medium">
            <span>Rating</span>
            <FieldPeople :form="form" :field="field" />
            <span v-if="lockedBy" class="text-xs text-slate-500">{{ lockedBy.name }} is editing</span>
        </div>

        <div class="flex gap-1">
            <button
                v-for="star in max"
                :key="star"
                type="button"
                :disabled="Boolean(lockedBy)"
                :class="star <= (form[field] ?? 0) ? 'text-amber-500' : 'text-slate-300'"
                @click="pick(star)"
            >
                ★
            </button>
        </div>
    </div>
</template>
```

</details>

## 3. Build a live text widget: Post Composer with character counter

A post composer adds a live character counter around a `<textarea>`. Because the inner element is a `<textarea>`, the package's built-in text binding handles character-by-character merging, remote carets, IME composition, and per-user `Ctrl+Z` undo automatically.

Declare `'social_post'` as text on the model:

```php
protected array $collaborative = [
    'social_post', // FieldKind::Text by default
];
```

<details open>
<summary><b>Livewire</b></summary>

Put `data-collaborate="social_post"` on the `<textarea>` so the Livewire script attaches `bindTextField()` to it, and listen for `input` to keep Alpine's counter in sync:

```blade
<div
    x-data="{ count: @js(mb_strlen($social_post ?? '')), limit: 280 }"
    class="space-y-1"
>
    <div class="flex items-center justify-between text-sm">
        <span class="font-medium">Social post <x-collaboration::field-people field="social_post" /></span>
        <span :class="count > limit ? 'text-red-600' : 'text-slate-500'" x-text="`${limit - count} left`"></span>
    </div>

    <textarea
        wire:model="social_post"
        data-collaborate="social_post"
        rows="3"
        class="w-full rounded border px-3 py-2"
        @input="count = $event.target.value.length"
    ></textarea>
</div>
```

</details>

<details>
<summary><b>Inertia + React</b></summary>

Spread `{...form.field(field)}` on the `<textarea>`. The binding keeps `form.data[field]` up to date on both local and remote edits, so the character counter updates live as colleagues type:

```jsx
import { FieldPeople } from '@solutionforest/laravel-collaboration/react'

export function PostComposer({ form, field = 'social_post', limit = 280 }) {
    const remaining = limit - (form.data[field] ?? '').length

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between text-sm">
                <span className="font-medium">
                    Social post <FieldPeople form={form} field={field} />
                </span>
                <span className={remaining < 0 ? 'text-red-600' : 'text-slate-500'}>
                    {remaining} left
                </span>
            </div>

            <textarea
                rows={3}
                className="w-full rounded border px-3 py-2"
                {...form.field(field)}
            />
        </div>
    )
}
```

</details>

<details>
<summary><b>Inertia + Vue</b></summary>

Attach `v-model` and `v-collaborate="form.field(field)"` to the `<textarea>`:

```vue
<script setup>
import { computed } from 'vue'
import { FieldPeople, vCollaborate } from '@solutionforest/laravel-collaboration/vue'

const props = defineProps({
    form: { type: Object, required: true },
    field: { type: String, default: 'social_post' },
    limit: { type: Number, default: 280 },
})

const remaining = computed(() => props.limit - (props.form[props.field] ?? '').length)
</script>

<template>
    <div class="space-y-1">
        <div class="flex items-center justify-between text-sm">
            <span class="font-medium">
                Social post <FieldPeople :form="form" :field="field" />
            </span>
            <span :class="remaining < 0 ? 'text-red-600' : 'text-slate-500'">
                {{ remaining }} left
            </span>
        </div>

        <textarea
            v-model="form[field]"
            v-collaborate="form.field(field)"
            rows="3"
            class="w-full rounded border px-3 py-2"
        />
    </div>
</template>
```

</details>

## 4. Custom TipTap toolbar or third-party editors

### TipTap with your own toolbar and extensions

When you want your own formatting toolbar, slash commands, or extra TipTap extensions, build TipTap yourself and add the `Collaborative` extension from `@solutionforest/laravel-collaboration/tiptap`:

<details open>
<summary><b>Livewire</b></summary>

In your Vite bundle, mount TipTap inside an Alpine component with `wire:ignore` and wait for the room with `Collaboration.whenReady()`:

```js
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { Collaborative } from '@solutionforest/laravel-collaboration/tiptap'

export function richEditor(field) {
    return {
        // Keep the Editor instance off Alpine's reactive object so Alpine does not proxy it.
        init() {
            Collaboration.whenReady(this.$el, (room) => {
                this.$el._editor = new Editor({
                    element: this.$refs.surface,
                    extensions: [StarterKit, Collaborative.configure({ room, field })],
                    content: this.$wire.get(field) ?? '',
                    onUpdate: ({ editor }) => this.$wire.$set(field, editor.getJSON(), false),
                })
            })
        },
        toggleBold() {
            this.$el._editor?.chain().focus().toggleBold().run()
        },
        destroy() {
            this.$el._editor?.destroy()
        },
    }
}
```

</details>

<details>
<summary><b>Inertia + React</b></summary>

```jsx
import { EditorContent, useEditor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { Collaborative } from '@solutionforest/laravel-collaboration/tiptap'

export function MyRichEditor({ form, field = 'body' }) {
    const { room } = form.collaboration

    const editor = useEditor({
        extensions: [
            StarterKit,
            ...(room ? [Collaborative.configure({ room, field })] : []),
        ],
        content: form.data[field] || '',
        onUpdate: ({ editor: updated }) => form.setData(field, updated.getJSON()),
        immediatelyRender: false,
    }, [room])

    return (
        <div className="rounded border">
            <div className="flex gap-1 border-b p-2">
                <button type="button" onClick={() => editor?.chain().focus().toggleBold().run()}>Bold</button>
                <button type="button" onClick={() => editor?.chain().focus().toggleItalic().run()}>Italic</button>
            </div>
            <EditorContent editor={editor} className="prose p-3" />
        </div>
    )
}
```

</details>

<details>
<summary><b>Inertia + Vue</b></summary>

```vue
<script setup>
import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { onBeforeUnmount, shallowRef } from 'vue'
import { Collaborative } from '@solutionforest/laravel-collaboration/tiptap'

const props = defineProps({
    form: { type: Object, required: true },
    field: { type: String, default: 'body' },
})

const { room } = props.form.collaboration

const editor = shallowRef(new Editor({
    extensions: [
        StarterKit,
        ...(room ? [Collaborative.configure({ room, field: props.field })] : []),
    ],
    content: props.form[props.field] || '',
    onUpdate: ({ editor: updated }) => (props.form[props.field] = updated.getJSON()),
}))

onBeforeUnmount(() => editor.value.destroy())
</script>

<template>
    <div class="rounded border">
        <div class="flex gap-1 border-b p-2">
            <button type="button" @click="editor.chain().focus().toggleBold().run()">Bold</button>
            <button type="button" @click="editor.chain().focus().toggleItalic().run()">Italic</button>
        </div>
        <EditorContent :editor="editor" class="prose p-3" />
    </div>
</template>
```

</details>

### Connecting any other editor (CodeMirror, Quill, Ace, plain JS)

For an editor that manages its own DOM and selection, use `room.bind()` directly. The room calls `capture()` before applying colleagues' edits and `render()` immediately after so you can preserve the user's cursor:

```js
import { Room } from '@solutionforest/laravel-collaboration'

function bindCustomEditor(room, field, editor) {
    let saved = null
    let applying = false

    const render = () => {
        const next = room.value(field) ?? ''

        if (editor.getValue() !== next) {
            applying = true
            editor.setValue(next)
            applying = false
        }

        // Restore the user's caret using stable CRDT character IDs.
        if (saved && editor.hasFocus()) {
            const anchor = room.indexOf(field, saved.anchor) ?? 0
            const head = room.indexOf(field, saved.head) ?? 0
            editor.setSelection(anchor, head)
        }

        saved = null
    }

    const unbind = room.bind(field, {
        get isBusy() {
            return editor.isComposing() // pause remote updates during IME input
        },
        capture() {
            if (!editor.hasFocus()) return
            const { anchor, head } = editor.getSelection()
            saved = { anchor: room.idAt(field, anchor), head: room.idAt(field, head) }
        },
        render,
        cursor: () => (editor.hasFocus() ? editor.getSelection() : null),
        reveal: () => editor.dom.scrollIntoView({ block: 'center', behavior: 'smooth' }),
    })

    const offChange = editor.onChange(() => {
        if (applying) return
        room.change(field, editor.getValue(), editor.getSelection().head)
    })
    const offSelection = editor.onSelectionChange(() => room.cursorMoved())
    const offFocus = editor.onFocus(() => room.focus(field))
    const offBlur = editor.onBlur(() => room.blur(field))
    const offPeople = room.on('people', () => editor.drawCarets(room.carets(field)))

    if (room.isReady) render()

    return () => {
        offChange()
        offSelection()
        offFocus()
        offBlur()
        offPeople()
        unbind()
    }
}
```

## Custom component API reference

| API | What it does |
| --- | --- |
| `room.bind(field, { capture, render, cursor, isBusy, reveal })` | Registers a custom field binding; returns an `unbind()` function |
| `room.change(field, value, caret?)` | Records a local edit into the CRDT and queues it for sync |
| `room.value(field)` | Reads the current shared value of a field |
| `room.focus(field)` / `room.blur(field)` | Announces which field this user is in (drives `<FieldPeople>` and locks) |
| `room.cursorMoved()` | Schedules a quick sync after a caret/selection move |
| `room.idAt(field, index)` / `room.indexOf(field, id)` | Converts caret offsets to/from stable character IDs that survive remote edits |
| `room.carets(field)` | Returns other users' selections in this field: `{ person, anchor, head }[]` |
| `room.peopleIn(field)` | Returns active colleagues currently in `field` |
| `room.lockedBy(field)` | Returns the colleague holding a `->lock()` field, or `null` |
| `room.on(event, fn)` | Subscribes to `ready`, `change`, `people`, `status`, `saved`, `expired`, `deleted` |
| `bindTextField(room, field, el, { onValueChange })` | Binds any `<input>` or `<textarea>` with carets, IME handling, and per-user undo |
| `bindValueField(room, field, elOrList, { onValueChange })` | Binds any `<select>`, checkbox, checkbox group, or radio group |
| `lockWhileEditing(room, field, el)` | Applies read-only lock behaviour and banner when `->lock()` is set in PHP |
| `window.Collaboration.whenReady(el, (room) => ...)` | Livewire helper: hands you the enclosing Livewire component's `Room` once ready |

## Checklist before shipping a custom component

- [ ] **Livewire:** Add `wire:ignore` on widgets that manage their own DOM, and never store a third-party editor instance directly on Alpine reactive data (`this.editor = ...`).
- [ ] **React:** Never turn a live text field into a controlled `value={...}` input; let `{...form.field()}` pass `defaultValue`.
- [ ] **Focus & blur:** Call `room.focus(field)` and `room.blur(field)` so `<FieldPeople>` and `->lock()` know when someone is in the field.
- [ ] **IME safety:** Return `isBusy: true` while composing CJK/IME text so remote edits wait until composition ends.
- [ ] **Cleanup:** Call the `unbind()` function returned by `room.bind()` (and remove listeners) when the component unmounts.
- [ ] **Test with two browsers:** Open the same record in a normal window and a private window, edit simultaneously, and verify that saving preserves both edits.
