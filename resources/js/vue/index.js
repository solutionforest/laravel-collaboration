import { router, useForm } from '@inertiajs/vue3'
import { computed, defineComponent, h, onBeforeUnmount, onScopeDispose, ref, shallowRef, unref, watch } from 'vue'
import { isTextInput, lockWhileEditing } from '../bindings/lock.js'
import { bindTextField } from '../bindings/text-field.js'
import { FormMemory } from '../core/form-memory.js'
import { Room } from '../core/room.js'
import { describe, translate } from '../ui/avatars.js'
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
    const initial = typeof window === 'undefined' ? null : config()

    // Joined during setup, so directives on the component's inputs find the room when they mount.
    if (initial) {
        const joined = new Room(initial)

        joined.on('people', (list) => (people.value = [...list]))
        joined.on('status', (value) => (status.value = value))
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
        if (field in data) form[field] = value ?? (typeof data[field] === 'string' ? '' : null)
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

    if (!room || !field || bound.has(element)) return

    bound.set(element, room.config.fields[field] === 'text' && isTextInput(element)
        ? bindTextField(room, field, /** @type {HTMLInputElement} */ (element), { onValueChange: (value) => (form[field] = value) })
        : bindChoice(room, field, element, form))
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
    const unlock = lockWhileEditing(room, field, element)

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

    return { room: unref(source.room) ?? null, people: unref(source.people) ?? [], status: unref(source.status) ?? 'connecting' }
}

/** The faces of everybody else in the room: `<Avatars :form="form" />`. */
export const Avatars = defineComponent({
    name: 'CollaborationAvatars',
    props: { ...presenceProps, limit: { type: Number, default: null } },
    setup(props) {
        addStyles()

        return () => {
            const { room, people } = collaborationOf(props)
            const config = room?.config
            const { shown, more } = avatarsOf(people, props.limit ?? config?.avatarLimit)

            return h('div', { class: 'collab-avatars', title: people.length ? undefined : config?.strings?.alone }, [
                ...shown.map((person) => h(
                    'span',
                    { key: person.client, class: 'collab-avatar', title: describe(person, config?.strings, config?.labels), 'data-idle': person.idle || undefined, style: { background: person.color } },
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
