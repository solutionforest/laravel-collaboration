import { router, useForm } from '@inertiajs/vue3'
import { computed, defineComponent, h, onBeforeUnmount, ref, shallowRef, unref, watch } from 'vue'
import { bindTextField } from '../bindings/text-field.js'
import { Room } from '../core/room.js'
import { describe, translate } from '../ui/avatars.js'
import { addStyles } from '../ui/styles.js'

/**
 * Joins a record's room for as long as the component is on the page.
 *
 *     const { room, people, status } = useCollaboration(() => props.collaboration)
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

    return { room, people, status }
}

/**
 * Inertia's `useForm`, collaborative. Add `v-collaborate` to inputs: what is
 * typed is shared, others' edits appear in place, and saving sends everybody's.
 *
 *     const form = useCollaborativeForm(() => props.collaboration, { title: props.post.title })
 *
 *     <input v-model="form.title" v-collaborate:title="form">
 *     <button @click="form.put(`/posts/${post.id}`)">Save</button>
 *
 * `form.collaboration` holds the room, the people in it and its status.
 *
 * @template {Record<string, any>} TForm
 * @param {() => RoomConfig | null} config
 * @param {TForm} data
 * @param {{prop?: string}} [options]
 */
export function useCollaborativeForm(config, data, options = {}) {
    const form = useForm(data)
    const { room, people, status } = useCollaboration(config, options)

    room.value?.on('change', ({ field, value }) => {
        if (field in data) form[field] = value ?? (typeof data[field] === 'string' ? '' : value)
    })

    form.transform((values) => ({ ...values, [config()?.input ?? '_collaboration']: room.value?.payload() ?? '' }))

    /** @param {string} field */
    const peopleIn = (field) => people.value.filter((person) => person.field === field && !person.idle)

    // Under one key, so nothing collides with the form's own fields.
    return Object.assign(form, { collaboration: { room, people, status, peopleIn } })
}

/** @type {WeakMap<Element, () => void>} */
const bound = new WeakMap()

/**
 * `v-collaborate:title="form"`: makes an input, textarea, select or checkbox
 * collaborative, next to its usual `v-model`.
 *
 * @type {import('vue').Directive<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement, any>}
 */
export const vCollaborate = {
    mounted(element, binding) {
        connect(element, binding)
    },
    updated(element, binding) {
        connect(element, binding)
    },
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
    const room = /** @type {Room | null} */ (unref(binding.value?.collaboration?.room) ?? null)
    const field = binding.arg ?? ''

    if (!room || bound.has(element) || !field) return

    const kind = room.config.fields[field]
    const events = new AbortController()

    // Text is shared by its binding, which knows where the caret is; picked values on change.
    const unbind = kind === 'text' && !(element instanceof HTMLSelectElement)
        ? bindTextField(room, field, element)
        : (() => {
            const read = () => (element instanceof HTMLInputElement && element.type === 'checkbox' ? element.checked : element.value)

            element.addEventListener('change', () => room.change(field, read()), { signal: events.signal })
            element.addEventListener('focus', () => room.focus(field), { signal: events.signal })
            element.addEventListener('blur', () => room.blur(field), { signal: events.signal })

            return () => events.abort()
        })()

    bound.set(element, unbind)
}

/** The faces of everybody else in the room: `<Avatars :form="form" />`. */
export const Avatars = defineComponent({
    name: 'CollaborationAvatars',
    props: { form: { type: Object, required: true }, limit: { type: Number, default: null } },
    setup(props) {
        addStyles()

        return () => {
            const { room, people } = props.form.collaboration
            const config = room?.config
            const max = props.limit ?? config?.avatarLimit ?? 5

            return h('div', { class: 'collab-avatars', title: people.length ? undefined : config?.strings?.alone }, [
                ...people.slice(0, max).map((person) => h(
                    'span',
                    { key: person.client, class: 'collab-avatar', title: describe(person, config?.strings, config?.labels), 'data-idle': person.idle || undefined, style: { background: person.color } },
                    person.avatar ? [h('img', { src: person.avatar, alt: '', referrerpolicy: 'no-referrer' })] : person.initials,
                )),
                people.length > max ? h('span', { class: 'collab-avatar', style: { background: '#6b7280' } }, `+${people.length - max}`) : null,
            ])
        }
    },
})

/** Who is in a field right now: `<FieldPeople :form="form" field="title" />`. */
export const FieldPeople = defineComponent({
    name: 'CollaborationFieldPeople',
    props: { form: { type: Object, required: true }, field: { type: String, required: true } },
    setup(props) {
        addStyles()

        return () => h('span', { class: 'collab-field-people' }, props.form.collaboration.peopleIn(props.field).map((person) => h('span', { key: person.client, class: 'collab-field-person', style: { background: person.color } }, person.name)))
    },
})

/** "All changes shared", "Offline — retrying"…: `<Status :form="form" />`. */
export const Status = defineComponent({
    name: 'CollaborationStatus',
    props: { form: { type: Object, required: true } },
    setup(props) {
        const notice = ref(/** @type {string | null} */ (null))
        const strings = computed(() => props.form.collaboration.room?.config.strings ?? {})

        watch(() => props.form.collaboration.room, (room) => room?.on('saved', ({ name }) => {
            notice.value = translate(strings.value.saved ?? ':name saved', { name: name ?? '' })
            setTimeout(() => (notice.value = null), 4000)
        }), { immediate: true })

        return () => {
            const { status } = props.form.collaboration

            return h('span', { 'data-status': status }, notice.value ?? strings.value[status] ?? '')
        }
    },
})
