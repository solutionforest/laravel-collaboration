import { router, useForm } from '@inertiajs/react'
import { createElement, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { bindTextField } from '../bindings/text-field.js'
import { Room } from '../core/room.js'
import { describe, translate } from '../ui/avatars.js'
import { addStyles } from '../ui/styles.js'

/**
 * Joins a record's room for as long as the component is on the page.
 *
 *     const { room, people, status } = useCollaboration(collaboration)
 *
 * @param {RoomConfig | null} config the `collaboration` prop `Collaboration::room()` rendered
 * @param {{prop?: string}} [options] the prop's name, so an expired token is reloaded
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
        joined.on('expired', () => router.reload({ only: [prop], headers: { 'X-Collaboration-Client': String(joined.config.client) } }))

        setRoom(joined)

        return () => joined.destroy()
    }, [config?.draft])

    // A reload renders the room again, with a fresh token.
    useEffect(() => {
        if (config) room?.renew(config)
    }, [config?.token])

    return { room, people, status }
}

/**
 * Inertia's `useForm`, collaborative. Spread `field()` on inputs: what is
 * typed is shared, others' edits appear in place, and saving sends everybody's.
 *
 *     const form = useCollaborativeForm(collaboration, { title: post.title, status: post.status })
 *
 *     <input {...form.field('title')} />
 *     <button onClick={() => form.put(`/posts/${post.id}`)}>Save</button>
 *
 * @template {Record<string, any>} TForm
 * @param {RoomConfig | null} config
 * @param {TForm} data
 * @param {{prop?: string}} [options]
 */
export function useCollaborativeForm(config, data, options = {}) {
    const form = useForm(data)
    const { room, people, status } = useCollaboration(config, options)
    const setData = useRef(form.setData)
    const elements = useRef(/** @type {Map<string, {element: Element, unbind: () => void}>} */ (new Map()))

    setData.current = form.setData

    // Others' edits, into the form's state.
    useEffect(() => room?.on('change', ({ field, value }) => {
        if (field in data) setData.current(field, value ?? (typeof data[field] === 'string' ? '' : value))
    }), [room])

    useEffect(() => () => elements.current.forEach(({ unbind }) => unbind()), [room])

    form.transform((values) => ({ ...values, [config?.input ?? '_collaboration']: room?.payload() ?? '' }))

    /**
     * Props for an `<input>`, `<textarea>`, `<select>` or checkbox.
     *
     * @param {keyof TForm & string} name
     */
    const field = useCallback((name) => {
        const kind = config?.fields?.[name]
        const value = form.data[name]

        const ref = (/** @type {HTMLInputElement | HTMLTextAreaElement | null} */ element) => {
            const known = elements.current.get(name)

            if (known?.element === element) return

            known?.unbind()
            elements.current.delete(name)

            if (element && room && kind === 'text') elements.current.set(name, { element, unbind: bindTextField(room, name, element) })
        }

        const onChange = (/** @type {any} */ event) => {
            const next = event?.target ? (event.target.type === 'checkbox' ? event.target.checked : event.target.value) : event

            form.setData(name, next)

            // Text is shared by its binding, which knows where the caret is.
            if (kind === 'value') room?.change(name, next)
        }

        return {
            name,
            ref,
            onChange,
            onFocus: () => room?.focus(name),
            onBlur: () => room?.blur(name),
            ...(typeof value === 'boolean' ? { checked: value } : { value: value ?? '' }),
        }
    }, [room, form.data, config])

    /** @param {string} name */
    const peopleIn = (name) => people.filter((person) => person.field === name && !person.idle)

    return Object.assign(form, { room, people, status, field, peopleIn })
}

/**
 * The faces of everybody else in the room.
 *
 *     <Avatars form={form} />
 *
 * @param {{form?: {room: Room | null, people: Person[]}, room?: Room | null, people?: Person[], limit?: number, className?: string}} props
 */
export function Avatars({ form, room = form?.room, people = form?.people ?? [], limit, className = '' }) {
    useMemo(addStyles, [])

    const config = room?.config
    const max = limit ?? config?.avatarLimit ?? 5
    const shown = people.slice(0, max)

    return createElement(
        'div',
        { className: `collab-avatars ${className}`.trim(), title: people.length ? undefined : config?.strings?.alone },
        ...shown.map((person) => createElement(
            'span',
            { key: person.client, className: 'collab-avatar', title: describe(person, config?.strings, config?.labels), 'data-idle': person.idle || undefined, style: { background: person.color } },
            person.avatar ? createElement('img', { src: person.avatar, alt: '', referrerPolicy: 'no-referrer' }) : person.initials,
        )),
        people.length > max ? createElement('span', { key: 'more', className: 'collab-avatar', style: { background: '#6b7280' } }, `+${people.length - max}`) : null,
    )
}

/**
 * Who is in a field right now: put it next to the field's label.
 *
 *     <FieldPeople form={form} field="title" />
 *
 * @param {{form: {peopleIn: (field: string) => Person[]}, field: string, className?: string}} props
 */
export function FieldPeople({ form, field, className = '' }) {
    useMemo(addStyles, [])

    return createElement(
        'span',
        { className: `collab-field-people ${className}`.trim() },
        ...form.peopleIn(field).map((person) => createElement('span', { key: person.client, className: 'collab-field-person', style: { background: person.color } }, person.name)),
    )
}

/**
 * "All changes shared", "Offline — retrying"…
 *
 * @param {{form: {room: Room | null, status: string}, className?: string}} props
 */
export function Status({ form, className = '' }) {
    const [notice, setNotice] = useState(/** @type {string | null} */ (null))
    const strings = form.room?.config.strings ?? {}

    useEffect(() => form.room?.on('saved', ({ name }) => {
        setNotice(translate(strings.saved ?? ':name saved', { name: name ?? '' }))
        setTimeout(() => setNotice(null), 4000)
    }), [form.room])

    return createElement('span', { className, 'data-status': form.status }, notice ?? strings[form.status] ?? '')
}
