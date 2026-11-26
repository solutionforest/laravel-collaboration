import { router, useForm } from '@inertiajs/vue3'
import { computed, defineComponent, h, onBeforeUnmount, onMounted, onScopeDispose, ref, shallowRef, unref, watch } from 'vue'
import { isTextInput, lockWhileEditing } from '../bindings/lock.js'
import { bindTextField } from '../bindings/text-field.js'
import { bindItems } from '../bindings/items.js'
import { commentsOf } from '../core/comments.js'
import { FormMemory } from '../core/form-memory.js'
import { listOps, toList } from '../core/items.js'
import { RecordPresence } from '../core/record-presence.js'
import { Room } from '../core/room.js'
import { describe, translate } from '../ui/avatars.js'
import { renderComments } from '../ui/comments.js'
import { renderHistory } from '../ui/history.js'
import { avatarsOf, peopleIn } from '../ui/people.js'
import { addStyles } from '../ui/styles.js'

/**
 * Joins a record's room for as long as the component is on the page.
 *
 *     const collaboration = useCollaboration(() => props.collaboration)
 *
 *     collaboration.people.value        // everybody else here
 *     collaboration.peopleIn('title')
 *
 * @param {() => RoomConfig | null} config the `collaboration` prop `Collaboration::room()` rendered
 * @param {{prop?: string}} [options] the prop's name, so an expired token is reloaded
 */
export function useCollaboration(config, { prop = 'collaboration' } = {}) {
    const room = shallowRef(/** @type {Room | null} */ (null))
    const people = ref(/** @type {Person[]} */ ([]))
    const status = ref('connecting')
    const following = ref(/** @type {number | null} */ (null))
    const initial = typeof window === 'undefined' ? null : config()

    // Joined during setup, so directives on the component's inputs find the room when they mount.
    if (initial) {
        const joined = new Room(initial)

        joined.on('people', (list) => (people.value = [...list]))
        joined.on('status', (value) => (status.value = value))
        joined.on('following', (client) => (following.value = client))
        joined.on('expired', () => router.reload({ only: [prop], headers: { 'X-Collaboration-Client': String(joined.config.client) } }))

        room.value = joined
    }

    // A reload renders the room again, with a fresh token.
    watch(() => config()?.token, () => {
        const next = config()

        if (next) room.value?.renew(next)
    })

    onBeforeUnmount(() => room.value?.destroy())

    return {
        room,
        people,
        status,
        peopleIn: (/** @type {string} */ field) => peopleIn(people.value, field),
        isLive: (/** @type {string} */ field) => room.value?.isLiveField(field) ?? false,
        following,
        /** Follow a colleague from field to field; again, or null, to stop. */
        follow: (/** @type {number | null} */ client) => room.value?.follow(client),
    }
}

/**
 * Inertia's `useForm`, collaborative. Add `v-collaborate` to inputs: what is
 * typed is shared, others' edits appear in place, and saving sends everybody's.
 *
 *     const form = useCollaborativeForm(() => props.collaboration, { title: props.post.title })
 *
 *     <input v-model="form.title" v-collaborate="form.field('title')">
 *     <button @click="form.put(`/posts/${post.id}`)">Save</button>
 *
 * `form.field()` only takes the form's own fields, so a typo is a type error;
 * `v-collaborate:title="form"` works too. Everything else is Inertia's: `errors`, `processing`, `transform()`, `put()`.
 * `form.collaboration` holds the room, the people in it and its status.
 *
 * @template {Record<string, any>} TForm
 * @param {() => RoomConfig | null} config
 * @param {TForm} data
 * @param {{prop?: string}} [options]
 */
export function useCollaborativeForm(config, data, options = {}) {
    const form = useForm(data)
    const collaboration = useCollaboration(config, options)
    const { room } = collaboration

    /** @type {(values: TForm) => Record<string, any>} */
    let transform = (values) => values

    // Others' edits, into the form's state.
    const stop = room.value?.on('change', ({ field, value }) => {
        if (!(field in data)) return

        // A repeater's items are a list in the form, keyed in the draft.
        form[field] = room.value?.config.fields[field] === 'items' ? toList(value) : value ?? (typeof data[field] === 'string' ? '' : null)
    })

    onScopeDispose(() => stop?.())

    // What each field held when the form loaded or was last saved, to tell this person's changes from others'.
    const memory = room.value ? new FormMemory(room.value, form.data()) : null
    const conflicts = ref(/** @type {import('../core/form-memory.js').Conflict[]} */ ([]))

    // Somebody else saved: their values go into the fields this person left alone.
    const stopSaved = room.value?.on('saved', async ({ name }) => {
        const { take, conflicts: found } = (await memory?.pull(form.data(), name)) ?? { take: {}, conflicts: [] }

        Object.assign(form, take)
        conflicts.value = [...found]
    })

    onScopeDispose(() => stopSaved?.())

    // A save refused, perhaps because somebody else saved these fields: find out which.
    watch(() => form.hasErrors, (hasErrors) => {
        if (!hasErrors || !memory) return

        memory.pull(form.data(), memory.conflicts[0]?.name ?? null).then(({ conflicts: found }) => (conflicts.value = [...found])).catch(() => {})
    })

    // This person saved: what the form holds is the new base.
    watch(() => form.wasSuccessful, (successful) => {
        if (!successful) return

        memory?.saved(form.data())
        conflicts.value = []
    })

    // Every save carries the edits the server has not seen yet, and the form's base, after the app's own transform.
    form.transform((values) => ({
        ...transform(values),
        [config()?.input ?? '_collaboration']: room.value?.payload() ?? '',
        [`${config()?.input ?? '_collaboration'}_base`]: memory?.payload() ?? '',
    }))

    // Under one key, so nothing collides with the form's own fields.
    return Object.assign(form, {
        collaboration: {
            ...collaboration,
            conflicts,
            /** Settle a field somebody else saved too: keep this person's value. */
            keepMine: (/** @type {string} */ name) => {
                memory?.keepMine(name)
                conflicts.value = [...(memory?.conflicts ?? [])]
            },
            /** Or take theirs. */
            useTheirs: (/** @type {string} */ name) => {
                form[name] = memory?.useTheirs(name)
                conflicts.value = [...(memory?.conflicts ?? [])]
            },
        },
        /** What `v-collaborate` binds: `v-collaborate="form.field('title')"`. */
        field: (/** @type {keyof TForm & string} */ name) => ({ form, name }),
        /**
         * A repeater: its list, what changes it, and what `v-collaborate` binds for each sub-field:
         *
         *     <input v-model="line.title" v-collaborate="form.items('lines').field(line.key, 'title')">
         */
        items: (/** @type {keyof TForm & string} */ name) => {
            const list = () => /** @type {import('../core/items.js').Item[]} */ (form[name] ?? [])
            const change = (/** @type {import('../core/items.js').Item[]} */ next) => {
                form[name] = next
                repeaterOf(room.value, name, form)?.change(next)
            }

            return {
                get list() {
                    return list()
                },
                add: (/** @type {Record<string, any>} */ item = {}) => change(listOps.add(list(), item)),
                remove: (/** @type {string} */ key) => change(listOps.remove(list(), key)),
                move: (/** @type {string} */ key, /** @type {number} */ to) => change(listOps.move(list(), key, to)),
                field: (/** @type {string} */ key, /** @type {string} */ path) => ({ form, name, key, path }),
            }
        },
        /** Keeps an app's own `transform()`; the live edits are added after it. */
        transform: (/** @type {(values: TForm) => Record<string, any>} */ callback) => {
            transform = callback

            return form
        },
    })
}

/** @type {WeakMap<Element, () => void>} */
const bound = new WeakMap()

/**
 * `v-collaborate="form.field('title')"`, or `v-collaborate:title="form"`:
 * makes an input, textarea, select, checkbox or radio collaborative, next to
 * its usual `v-model`.
 *
 * @type {import('vue').Directive<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement, any>}
 */
export const vCollaborate = {
    mounted: connect,
    updated: connect,
    unmounted(element) {
        bound.get(element)?.()
        bound.delete(element)
    },
}

/**
 * @param {HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement} element
 * @param {import('vue').DirectiveBinding<any>} binding
 */
function connect(element, binding) {
    const form = binding.value?.form ?? binding.value
    const field = binding.arg ?? binding.value?.name ?? ''
    const room = /** @type {Room | null} */ (unref(form?.collaboration?.room) ?? null)

    // A form field outside the room is an ordinary input.
    if (!room || !field || bound.has(element) || !(field in room.config.fields || field in (room.config.labels ?? {}))) return

    // An item's sub-field, in a repeater.
    if (binding.value?.key !== undefined) {
        const repeater = repeaterOf(room, field, form)

        if (repeater) bound.set(element, repeater.attach(binding.value.key, binding.value.path, element))

        return
    }

    bound.set(element, room.config.fields[field] === 'text' && isTextInput(element)
        ? bindTextField(room, field, /** @type {HTMLInputElement} */ (element), { onValueChange: (value) => (form[field] = value) })
        : bindChoice(room, field, element, form))
}

/** @type {WeakMap<Room, Map<string, ReturnType<typeof bindItems>>>} each room's repeaters */
const repeaters = new WeakMap()

/**
 * The binding of one repeater, made once per room and field.
 *
 * @param {Room | null} room
 * @param {string} field
 * @param {Record<string, any>} form
 */
function repeaterOf(room, field, form) {
    if (!room || room.config.fields[field] !== 'items') return null

    const known = repeaters.get(room) ?? new Map()

    repeaters.set(room, known)

    if (!known.has(field)) known.set(field, bindItems(room, field, { onValueChange: (list) => (form[field] = list) }))

    return known.get(field) ?? null
}

/**
 * Anything but text with a caret is shared when it changes, as `v-model` has
 * it: a checkbox group's list, a radio's value, a date.
 *
 * @param {Room} room
 * @param {string} field
 * @param {HTMLElement} element
 * @param {Record<string, any>} form
 */
function bindChoice(room, field, element, form) {
    const events = new AbortController()
    const { signal } = events
    const lock = lockWhileEditing(room, field, element)
    const unbind = room.bind(field, { reveal: () => element.scrollIntoView({ block: 'center', behavior: 'smooth' }) })
    const unlock = () => {
        lock()
        unbind()
    }

    // After `v-model`'s own listener, so the form already holds the new value.
    element.addEventListener('change', () => room.change(field, form[field]), { signal })
    element.addEventListener('focus', () => room.focus(field), { signal })
    element.addEventListener('blur', () => room.blur(field), { signal })

    return () => {
        events.abort()
        unlock()
    }
}

/** The props every presence component takes: the form, or a `useCollaboration()`. */
const presenceProps = {
    form: { type: Object, default: null },
    collaboration: { type: Object, default: null },
}

/** @param {{form: any, collaboration: any}} props */
const collaborationOf = (props) => {
    const source = props.collaboration ?? props.form?.collaboration ?? {}

    return { room: unref(source.room) ?? null, people: unref(source.people) ?? [], status: unref(source.status) ?? 'connecting', following: unref(source.following) ?? null }
}

/** The faces of everybody else in the room: `<Avatars :form="form" />`. */
export const Avatars = defineComponent({
    name: 'CollaborationAvatars',
    props: { ...presenceProps, limit: { type: Number, default: null } },
    setup(props) {
        addStyles()

        return () => {
            const { room, people, following } = collaborationOf(props)
            const config = room?.config
            const { shown, more } = avatarsOf(people, props.limit ?? config?.avatarLimit)

            return h('div', { class: 'collab-avatars', title: people.length ? undefined : config?.strings?.alone }, [
                ...shown.map((person) => h(
                    'button',
                    {
                        key: person.client,
                        type: 'button',
                        class: 'collab-avatar',
                        title: describe(person, config?.strings, config?.labels),
                        'data-idle': person.idle || undefined,
                        'data-following': following === person.client || undefined,
                        'data-collab-follow': '',
                        onClick: () => room?.follow(person.client),
                        style: { background: person.color },
                    },
                    person.avatar ? [h('img', { src: person.avatar, alt: '', referrerpolicy: 'no-referrer' })] : person.initials,
                )),
                more ? h('span', { class: 'collab-avatar', style: { background: '#6b7280' } }, `+${more}`) : null,
            ])
        }
    },
})

/** Who is in a field right now: `<FieldPeople :form="form" field="title" />`. */
export const FieldPeople = defineComponent({
    name: 'CollaborationFieldPeople',
    props: { ...presenceProps, field: { type: String, required: true } },
    setup(props) {
        addStyles()

        return () => h('span', { class: 'collab-field-people' }, peopleIn(collaborationOf(props).people, props.field).map((person) => h('span', { key: person.client, class: 'collab-field-person', style: { background: person.color } }, person.name)))
    },
})

/** "All changes shared", "Offline — retrying", or who just saved: `<Status :form="form" />`. */
export const Status = defineComponent({
    name: 'CollaborationStatus',
    props: presenceProps,
    setup(props) {
        const notice = ref(/** @type {string | null} */ (null))
        const strings = computed(() => collaborationOf(props).room?.config.strings ?? {})
        /** @type {ReturnType<typeof setTimeout> | undefined} */
        let timer

        watch(() => collaborationOf(props).room, (room, _old, onCleanup) => {
            const stop = room?.on('saved', ({ name }) => {
                notice.value = translate(strings.value.saved ?? ':name saved', { name: name ?? '' })
                clearTimeout(timer)
                timer = setTimeout(() => (notice.value = null), 4000)
            })

            onCleanup(() => stop?.())
        }, { immediate: true })

        onBeforeUnmount(() => clearTimeout(timer))

        return () => {
            const { status } = collaborationOf(props)

            return h('span', { 'data-status': status }, notice.value ?? strings.value[status] ?? '')
        }
    },
})

/** Fields somebody else saved while this person changed them too: `<Conflicts :form="form" />`. */
export const Conflicts = defineComponent({
    name: 'CollaborationConflicts',
    props: { form: { type: Object, required: true } },
    setup(props) {
        addStyles()

        return () => {
            const { conflicts, keepMine, useTheirs, room } = props.form.collaboration
            const strings = unref(room)?.config.strings ?? {}
            const labels = unref(room)?.config.labels ?? {}
            const list = unref(conflicts) ?? []

            if (!list.length) return null

            return h('div', { class: 'collab-conflicts', role: 'alert' }, list.map((conflict) => {
                const name = conflict.name ?? strings.someone ?? 'Someone'
                const theirs = conflict.theirs === null || conflict.theirs === undefined ? '' : typeof conflict.theirs === 'object' ? JSON.stringify(conflict.theirs) : String(conflict.theirs)

                return h('div', { key: conflict.field, class: 'collab-conflict' }, [
                    h('p', translate(strings.conflict ?? ':name also changed :field.', { name, field: labels[conflict.field] ?? conflict.field })),
                    h('p', { class: 'collab-conflict-theirs' }, theirs),
                    h('button', { type: 'button', onClick: () => keepMine(conflict.field) }, strings.keep_mine ?? 'Keep mine'),
                    h('button', { type: 'button', onClick: () => useTheirs(conflict.field) }, translate(strings.use_theirs ?? 'Use theirs', { name })),
                ])
            }))
        }
    },
})

/**
 * Who is on each record of a list, from `Collaboration::presence($posts)`:
 *
 *     const presence = useRecordPresence(() => props.presence)
 *
 *     <RecordPeople :presence="presence" :record="post.id" />
 *
 * @param {() => any} config
 */
export function useRecordPresence(config) {
    const initial = config()
    const people = ref(/** @type {Record<string, Person[]>} */ (initial?.people ?? {}))
    const presence = initial && typeof window !== 'undefined' ? new RecordPresence(initial) : null

    presence?.on('people', (next) => (people.value = { ...next }))
    onBeforeUnmount(() => presence?.destroy())

    return { people, peopleOn: (/** @type {string | number} */ key) => people.value[String(key)] ?? [] }
}

/** The faces of everybody on one record of a list: `<RecordPeople :presence="presence" :record="post.id" />`. */
export const RecordPeople = defineComponent({
    name: 'CollaborationRecordPeople',
    props: { presence: { type: Object, required: true }, record: { type: [String, Number], required: true }, limit: { type: Number, default: 3 } },
    setup(props) {
        addStyles()

        return () => {
            const { shown, more } = avatarsOf(props.presence.peopleOn(props.record), props.limit)

            return h('span', { class: 'collab-avatars' }, [
                ...shown.map((person) => h('span', { key: person.key, class: 'collab-avatar', title: person.name, 'data-idle': person.idle || undefined, style: { background: person.color } },
                    person.avatar ? [h('img', { src: person.avatar, alt: '', referrerpolicy: 'no-referrer' })] : person.initials)),
                more ? h('span', { class: 'collab-avatar', style: { background: '#6b7280' } }, `+${more}`) : null,
            ])
        }
    },
})

/**
 * A record's comments, for an app's own UI. `<Comments>` is the ready-made one.
 *
 *     const { comments, post, resolve } = useComments(form)
 *
 * @param {{collaboration: {room: any}}} form
 */
export function useComments(form) {
    const room = unref(form.collaboration.room)
    const comments = room ? commentsOf(room) : null
    const list = ref(comments?.list ?? [])
    const mentionable = ref(comments?.mentionable ?? [])
    const canWrite = ref(comments?.canWrite ?? false)

    const stop = comments?.on('change', () => {
        list.value = [...comments.list]
        mentionable.value = [...comments.mentionable]
        canWrite.value = comments.canWrite
    })

    onScopeDispose(() => stop?.())

    return {
        comments: list,
        mentionable,
        canWrite,
        post: (/** @type {string} */ body, /** @type {string | null} */ field = null) => comments?.post(body, field),
        resolve: (/** @type {number} */ id) => comments?.resolve(id),
        reopen: (/** @type {number} */ id) => comments?.reopen(id),
    }
}

/** A record's comments, with a box to write one: `<Comments :form="form" />`, or `field="title"`. */
export const Comments = defineComponent({
    name: 'CollaborationComments',
    props: { form: { type: Object, required: true }, field: { type: String, default: undefined } },
    setup(props) {
        const element = ref(/** @type {HTMLElement | null} */ (null))
        /** @type {(() => void) | undefined} */
        let remove

        onMounted(() => {
            const room = unref(props.form.collaboration.room)

            if (room && element.value) remove = renderComments(element.value, room, { field: props.field })
        })

        onBeforeUnmount(() => remove?.())

        return () => h('div', { ref: element })
    },
})

/** The record's history, with Restore for editors: `<History :form="form" />`. */
export const History = defineComponent({
    name: 'CollaborationHistory',
    props: { form: { type: Object, required: true } },
    setup(props) {
        const element = ref(/** @type {HTMLElement | null} */ (null))
        /** @type {(() => void) | undefined} */
        let remove

        onMounted(() => {
            const room = unref(props.form.collaboration.room)

            if (room && element.value) remove = renderHistory(element.value, room)
        })

        onBeforeUnmount(() => remove?.())

        return () => h('div', { ref: element })
    },
})

/**
 * The record's history, for an app's own UI: `const { versions, restore } = useVersions(form)`.
 *
 * @param {{collaboration: {room: any}}} form
 */
export function useVersions(form) {
    const room = unref(form.collaboration.room)
    const versions = ref(/** @type {any[]} */ ([]))
    const canRestore = ref(false)
    const take = (/** @type {{versions: any[], canRestore: boolean}} */ answer) => {
        versions.value = answer.versions
        canRestore.value = answer.canRestore
    }

    room?.call('versions').then(take).catch(() => {})

    const stop = room?.on('saved', () => room.call('versions').then(take).catch(() => {}))

    onScopeDispose(() => stop?.())

    return { versions, canRestore, restore: (/** @type {number} */ id) => room?.call('versions.restore', { id }).then(take) }
}
