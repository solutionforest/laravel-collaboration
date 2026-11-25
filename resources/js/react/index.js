import { router, useForm } from '@inertiajs/react'
import { createElement, useEffect, useInsertionEffect, useMemo, useRef, useState } from 'react'
import { bindTextField } from '../bindings/text-field.js'
import { Room } from '../core/room.js'
import { avatarsOf, peopleIn, valueOf } from '../ui/people.js'
import { describe, translate } from '../ui/avatars.js'
import { addStyles } from '../ui/styles.js'

/**
 * Joins a record's room for as long as the component is on the page.
 *
 *     const collaboration = useCollaboration(props.collaboration)
 *
 *     collaboration.people          // everybody else here
 *     collaboration.peopleIn('title')
 *
 * @param {RoomConfig | null} config the `collaboration` prop `Collaboration::room()` rendered
 * @param {{prop?: string}} [options] the prop's name, so an expired token is reloaded
 * @returns {Collaboration}
 */
export function useCollaboration(config, { prop = 'collaboration' } = {}) {
    const [room, setRoom] = useState(/** @type {Room | null} */ (null))
    const [people, setPeople] = useState(/** @type {Person[]} */ ([]))
    const [status, setStatus] = useState('connecting')

    useEffect(() => {
        if (!config) return

        const joined = new Room(config)

        joined.on('people', setPeople)
        joined.on('status', setStatus)
        joined.on('expired', () => renewRoom(joined, prop))

        setRoom(joined)

        return () => {
            joined.destroy()
            setRoom(null)
        }
    }, [config?.draft])

    // A reload renders the room again, with a fresh token.
    useEffect(() => {
        if (config) room?.renew(config)
    }, [config?.token])

    return useMemo(() => ({
        room,
        people,
        status,
        peopleIn: (/** @type {string} */ field) => peopleIn(people, field),
        isLive: (/** @type {string} */ field) => room?.isLiveField(field) ?? false,
    }), [room, people, status])
}

/**
 * Inertia's `useForm`, collaborative. Spread `field()` on inputs: what is
 * typed is shared, others' edits appear in place, and saving sends everybody's.
 *
 *     const form = useCollaborativeForm(collaboration, { title: post.title, status: post.status })
 *
 *     <input {...form.field('title')} />
 *     <input type="radio" {...form.field('status', { value: 'draft' })} />
 *     <button onClick={() => form.put(`/posts/${post.id}`)}>Save</button>
 *
 * Everything else is Inertia's: `errors`, `processing`, `transform()`, `put()`.
 * `form.collaboration` holds the room, the people in it and its status.
 *
 * @template {Record<string, any>} TForm
 * @param {RoomConfig | null} config
 * @param {TForm} data
 * @param {{prop?: string}} [options]
 */
export function useCollaborativeForm(config, data, options = {}) {
    const form = useForm(data)
    const collaboration = useCollaboration(config, options)
    const { room } = collaboration
    const latest = useRef(form)
    const current = useRef(room)
    const transform = useRef((/** @type {TForm} */ values) => /** @type {Record<string, any>} */ (values))
    const fields = useRef(/** @type {Map<string, FieldBinding>} */ (new Map()))

    latest.current = form
    current.current = room

    // Others' edits, into the form's state.
    useEffect(() => room?.on('change', ({ field, value }) => {
        if (field in data) latest.current.setData(field, value ?? emptyLike(data[field]))
    }), [room])

    // Inputs that mounted before the room joined are bound once it has; a room that goes takes them with it.
    useEffect(() => {
        fields.current.forEach((binding) => binding.connect())

        return () => fields.current.forEach((binding) => binding.unbind())
    }, [room])

    // Every save carries the edits the server has not seen yet, after the app's own transform.
    form.transform((values) => ({ ...transform.current(values), [config?.input ?? '_collaboration']: room?.payload() ?? '' }))

    /**
     * Props for an `<input>`, `<textarea>`, `<select>` or checkbox. For a
     * radio, pass the value it stands for.
     *
     * @param {keyof TForm & string} name
     * @param {{value?: string}} [options]
     */
    const field = (name, { value: option } = {}) => {
        const binding = fields.current.get(name) ?? new FieldBinding(name, () => current.current, () => latest.current)

        fields.current.set(name, binding)

        const value = form.data[name]
        const state = option !== undefined
            ? { value: option, checked: String(value) === option }
            : typeof value === 'boolean' ? { checked: value } : { value: value ?? '' }

        return {
            name,
            ref: binding.ref,
            onChange: binding.onChange,
            onFocus: binding.onFocus,
            onBlur: binding.onBlur,
            ...state,
        }
    }

    return Object.assign(form, {
        field,
        collaboration,
        /** Keeps an app's own `transform()`; the live edits are added after it. */
        transform: (/** @type {(values: TForm) => Record<string, any>} */ callback) => {
            transform.current = callback
        },
    })
}

/**
 * One field's props, made once per field so React keeps the same `ref`
 * between renders and the input is not unbound and bound on every keystroke.
 */
class FieldBinding {
    /** @type {HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null} */
    element = null

    /** @type {(() => void) | null} */
    unbindText = null

    /**
     * @param {string} name
     * @param {() => Room | null} room
     * @param {() => {setData: (field: string, value: any) => void}} form
     */
    constructor(name, room, form) {
        this.name = name
        this.room = room
        this.form = form
    }

    /** @param {HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null} element */
    ref = (element) => {
        if (element === this.element) return

        this.unbind()
        this.element = element
        this.connect()
    }

    /** Text is bound to its element, which knows where the caret is. */
    connect() {
        const room = this.room()
        const element = this.element

        if (this.unbindText || !room || !element || element instanceof HTMLSelectElement || room.config.fields[this.name] !== 'text') return

        this.unbindText = bindTextField(room, this.name, element)
    }

    /** @param {any} event */
    onChange = (event) => {
        const value = valueOf(event)

        this.form().setData(this.name, value)

        // Picked values are shared on change; text by its binding.
        if (this.room()?.config.fields[this.name] === 'value') this.room()?.change(this.name, value)
    }

    onFocus = () => this.room()?.focus(this.name)

    onBlur = () => this.room()?.blur(this.name)

    unbind() {
        this.unbindText?.()
        this.unbindText = null
    }
}

/**
 * The faces of everybody else in the room.
 *
 *     <Avatars form={form} />
 *
 * @param {{form?: {collaboration: Collaboration}, collaboration?: Collaboration, limit?: number, className?: string}} props
 */
export function Avatars({ form, collaboration = form?.collaboration, limit, className = '' }) {
    useInsertionEffect(addStyles, [])

    if (!collaboration) return null

    const config = collaboration.room?.config
    const { shown, more } = avatarsOf(collaboration.people, limit ?? config?.avatarLimit)

    return createElement(
        'div',
        { className: `collab-avatars ${className}`.trim(), title: collaboration.people.length ? undefined : config?.strings?.alone },
        ...shown.map((person) => createElement(
            'span',
            { key: person.client, className: 'collab-avatar', title: describe(person, config?.strings, config?.labels), 'data-idle': person.idle || undefined, style: { background: person.color } },
            person.avatar ? createElement('img', { src: person.avatar, alt: '', referrerPolicy: 'no-referrer' }) : person.initials,
        )),
        more ? createElement('span', { key: 'more', className: 'collab-avatar', style: { background: '#6b7280' } }, `+${more}`) : null,
    )
}

/**
 * Who is in a field right now: put it next to the field's label.
 *
 *     <FieldPeople form={form} field="title" />
 *
 * @param {{form?: {collaboration: Collaboration}, collaboration?: Collaboration, field: string, className?: string}} props
 */
export function FieldPeople({ form, collaboration = form?.collaboration, field, className = '' }) {
    useInsertionEffect(addStyles, [])

    return createElement(
        'span',
        { className: `collab-field-people ${className}`.trim() },
        ...(collaboration?.peopleIn(field) ?? []).map((person) => createElement('span', { key: person.client, className: 'collab-field-person', style: { background: person.color } }, person.name)),
    )
}

/**
 * "All changes shared", "Offline — retrying", or who just saved.
 *
 * @param {{form?: {collaboration: Collaboration}, collaboration?: Collaboration, className?: string}} props
 */
export function Status({ form, collaboration = form?.collaboration, className = '' }) {
    const room = collaboration?.room ?? null
    const status = collaboration?.status ?? 'connecting'
    const strings = room?.config.strings ?? {}
    const [notice, setNotice] = useState(/** @type {string | null} */ (null))

    useEffect(() => {
        /** @type {ReturnType<typeof setTimeout> | undefined} */
        let timer

        const stop = room?.on('saved', ({ name }) => {
            setNotice(translate(strings.saved ?? ':name saved', { name: name ?? '' }))
            clearTimeout(timer)
            timer = setTimeout(() => setNotice(null), 4000)
        })

        return () => {
            stop?.()
            clearTimeout(timer)
        }
    }, [room])

    return createElement('span', { className, 'data-status': status }, notice ?? strings[status] ?? '')
}

/**
 * @param {Room} room
 * @param {string} prop
 */
function renewRoom(room, prop) {
    router.reload({ only: [prop], headers: { 'X-Collaboration-Client': String(room.config.client) } })
}

/** What an emptied field holds: text fields stay strings. */
const emptyLike = (/** @type {any} */ initial) => (typeof initial === 'string' ? '' : null)
