import { router, useForm } from '@inertiajs/react'
import { createElement, useEffect, useInsertionEffect, useMemo, useRef, useState } from 'react'
import { isTextInput, lockWhileEditing } from '../bindings/lock.js'
import { bindTextField } from '../bindings/text-field.js'
import { bindItems } from '../bindings/items.js'
import { FormMemory } from '../core/form-memory.js'
import { listOps, toList } from '../core/items.js'
import { RecordPresence } from '../core/record-presence.js'
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
    const [following, setFollowing] = useState(/** @type {number | null} */ (null))

    useEffect(() => {
        if (!config) return

        const joined = new Room(config)

        joined.on('people', setPeople)
        joined.on('status', setStatus)
        joined.on('following', setFollowing)
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
        following,
        /** Follow a colleague from field to field; again, or null, to stop. */
        follow: (/** @type {number | null} */ client) => room?.follow(client),
    }), [room, people, status, following])
}

/**
 * Inertia's `useForm`, collaborative. Spread `field()` on inputs: what is
 * typed is shared, others' edits appear in place, and saving sends everybody's.
 *
 *     const form = useCollaborativeForm(collaboration, { title: post.title, status: post.status })
 *
 *     <input {...form.field('title')} />
 *     <input type="radio" {...form.field('status', { value: 'draft' })} />
 *     <input type="checkbox" {...form.field('channels', { value: 'email' })} />
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
        if (!(field in data)) return

        // A repeater's items are a list in the form, keyed in the draft.
        latest.current.setData(field, room.config.fields[field] === 'items' ? toList(value) : value ?? emptyLike(data[field]))
    }), [room])

    // Each repeater: one binding for the list, and its sub-field inputs attached as they render.
    const repeaters = useRef(/** @type {Map<string, Repeater>} */ (new Map()))

    useEffect(() => {
        repeaters.current.forEach((repeater) => repeater.connect(room))

        return () => repeaters.current.forEach((repeater) => repeater.disconnect())
    }, [room])

    // Inputs that mounted before the room joined are bound once it has; a room that goes takes them with it.
    useEffect(() => {
        fields.current.forEach((binding) => binding.connect())

        return () => fields.current.forEach((binding) => binding.unbind())
    }, [room])

    // What each field held when the form loaded or was last saved, to tell this person's changes from others'.
    const memory = useMemo(() => (room ? new FormMemory(room, latest.current.data) : null), [room])
    const [conflicts, setConflicts] = useState(/** @type {import('../core/form-memory.js').Conflict[]} */ ([]))

    // Somebody else saved: their values go into the fields this person left alone.
    useEffect(() => room?.on('saved', async ({ name }) => {
        const { take, conflicts: found } = (await memory?.pull(latest.current.data, name)) ?? { take: {}, conflicts: [] }

        if (Object.keys(take).length) latest.current.setData((values) => ({ ...values, ...take }))

        setConflicts([...found])
    }), [room, memory])

    // A save refused, perhaps because somebody else saved these fields: find out which.
    useEffect(() => {
        if (!form.hasErrors || !memory) return

        memory.pull(latest.current.data, memory.conflicts[0]?.name ?? null).then(({ conflicts: found }) => setConflicts([...found])).catch(() => {})
    }, [form.hasErrors, memory])

    // This person saved: what the form holds is the new base.
    useEffect(() => {
        if (!form.wasSuccessful) return

        memory?.saved(latest.current.data)
        setConflicts([])
    }, [form.wasSuccessful, memory])

    // Every save carries the edits the server has not seen yet, and the form's base, after the app's own transform.
    form.transform((values) => ({
        ...transform.current(values),
        [config?.input ?? '_collaboration']: room?.payload() ?? '',
        [`${config?.input ?? '_collaboration'}_base`]: memory?.payload() ?? '',
    }))

    /** Settle a field somebody else saved too: keep this person's value, or take theirs. */
    const resolve = {
        keepMine: (/** @type {string} */ name) => {
            memory?.keepMine(name)
            setConflicts([...(memory?.conflicts ?? [])])
        },
        useTheirs: (/** @type {string} */ name) => {
            const theirs = memory?.useTheirs(name)

            latest.current.setData(name, theirs)
            setConflicts([...(memory?.conflicts ?? [])])
        },
    }

    /**
     * Props for an `<input>`, `<textarea>`, `<select>` or checkbox. For a
     * radio, or a checkbox in a group whose value is a list, pass the value
     * it stands for.
     *
     * @param {keyof TForm & string} name
     * @param {{value?: string}} [options]
     */
    const field = (name, { value: option } = {}) => {
        const binding = fields.current.get(name) ?? new FieldBinding(name, () => current.current, () => latest.current)

        fields.current.set(name, binding)

        const value = form.data[name]

        // Text that merges by character is written by its binding, never by React: a render
        // with a value a moment old would otherwise drop the last keystroke, and share that.
        const isLiveText = config?.fields?.[name] === 'text' && option === undefined && typeof value !== 'boolean'

        const state = option !== undefined
            ? { value: option, checked: Array.isArray(value) ? value.map(String).includes(String(option)) : String(value) === String(option) }
            : typeof value === 'boolean' ? { checked: value } : isLiveText ? { defaultValue: value ?? '' } : { value: value ?? '' }

        return {
            name,
            ref: binding.ref,
            onChange: binding.onChange,
            onFocus: binding.onFocus,
            onBlur: binding.onBlur,
            ...state,
        }
    }

    /**
     * A repeater: its list, what changes it, and props for each item's sub-field inputs.
     *
     *     const lines = form.items('lines')
     *
     *     {lines.list.map((line) => <input key={line.key} {...lines.field(line.key, 'title')} />)}
     *     <button onClick={() => lines.add({ title: '' })}>Add</button>
     *
     * @param {keyof TForm & string} name
     */
    const items = (name) => {
        const repeater = repeaters.current.get(name) ?? new Repeater(name, () => latest.current)

        if (!repeaters.current.has(name)) {
            repeaters.current.set(name, repeater)
            repeater.connect(room)
        }

        const list = /** @type {import('../core/items.js').Item[]} */ (form.data[name] ?? [])

        return {
            list,
            add: (/** @type {Record<string, any>} */ item = {}) => repeater.change(listOps.add(list, item)),
            remove: (/** @type {string} */ key) => repeater.change(listOps.remove(list, key)),
            move: (/** @type {string} */ key, /** @type {number} */ to) => repeater.change(listOps.move(list, key, to)),
            field: (/** @type {string} */ key, /** @type {string} */ path) => repeater.props(key, path, list.find((item) => item.key === key)?.[path]),
        }
    }

    return Object.assign(form, {
        field,
        items,
        collaboration: { ...collaboration, conflicts, ...resolve },
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

    /** @type {(() => void) | null} */
    unlock = null

    /**
     * @param {string} name
     * @param {() => Room | null} room
     * @param {() => {data: Record<string, any>, setData: (field: string, value: any) => void}} form
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

    /**
     * Text with a caret is bound to its element, which knows where the caret
     * is; anything else is shared on change. Either way, a locked field is
     * read-only while somebody else is in it.
     */
    connect() {
        const room = this.room()
        const element = this.element

        // A form field outside the room is an ordinary input.
        if (this.unbindText || this.unlock || !room || !element || !(this.name in room.config.fields || this.name in (room.config.labels ?? {}))) return

        if (room.config.fields[this.name] === 'text' && isTextInput(element)) {
            this.unbindText = bindTextField(room, this.name, /** @type {HTMLInputElement} */ (element), { onValueChange: (value) => this.form().setData(this.name, value) })
        } else {
            const unlock = lockWhileEditing(room, this.name, element)
            const unbind = room.bind(this.name, { reveal: () => element.scrollIntoView({ block: 'center', behavior: 'smooth' }) })

            this.unlock = () => {
                unlock()
                unbind()
            }
        }
    }

    /** @param {any} event */
    onChange = (event) => {
        const form = this.form()
        const current = form.data[this.name]
        const target = event?.target

        // A checkbox in a group adds or removes its value from the list.
        const value = target?.type === 'checkbox' && Array.isArray(current)
            ? (target.checked ? [...current, target.value] : current.filter((item) => String(item) !== target.value))
            : valueOf(event)

        form.setData(this.name, value)

        // Text with a caret is shared by its binding; everything else here.
        if (this.room()?.config.fields[this.name] && !this.unbindText) this.room()?.change(this.name, value)
    }

    onFocus = () => this.room()?.focus(this.name)

    onBlur = () => this.room()?.blur(this.name)

    unbind() {
        this.unbindText?.()
        this.unlock?.()
        this.unbindText = null
        this.unlock = null
    }
}

/**
 * One repeater of a form: the list binding, and a stable `ref` for each of
 * its items' sub-field inputs.
 */
class Repeater {
    /** @type {ReturnType<typeof bindItems> | null} */
    binding = null

    /** @type {Map<string, {ref: (element: any) => void, element: any, detach: (() => void) | null}>} */
    inputs = new Map()

    /**
     * @param {string} name
     * @param {() => {data: Record<string, any>, setData: (field: string, value: any) => void}} form
     */
    constructor(name, form) {
        this.name = name
        this.form = form
    }

    /** @param {Room | null} room */
    connect(room) {
        if (this.binding || !room || room.config.fields[this.name] !== 'items') return

        this.binding = bindItems(room, this.name, { onValueChange: (list) => this.form().setData(this.name, list) })
        this.inputs.forEach((input, at) => this.attach(at, input))
    }

    disconnect() {
        this.inputs.forEach((input) => {
            input.detach?.()
            input.detach = null
        })
        this.binding?.destroy()
        this.binding = null
    }

    /** @param {import('../core/items.js').Item[]} list */
    change(list) {
        this.form().setData(this.name, list)
        this.binding?.change(list)
    }

    /**
     * @param {string} key
     * @param {string} path
     * @param {any} value
     */
    props(key, path, value) {
        const at = `${key}\u0000${path}`
        const input = this.inputs.get(at) ?? { element: null, detach: null, ref: (/** @type {any} */ element) => this.ref(at, element) }

        this.inputs.set(at, input)

        return {
            name: `${this.name}.${key}.${path}`,
            ref: input.ref,
            onChange: (/** @type {any} */ event) => {
                // Text is shared by the binding; the form keeps up for saving and validation.
                const list = /** @type {import('../core/items.js').Item[]} */ (this.form().data[this.name] ?? [])

                this.form().setData(this.name, list.map((item) => (item.key === key ? { ...item, [path]: valueOf(event) } : item)))
            },
            // Written by the binding, never by React, as text fields are.
            ...(typeof value === 'boolean' ? { checked: value } : { defaultValue: value ?? '' }),
        }
    }

    /**
     * @param {string} at
     * @param {any} element
     */
    ref(at, element) {
        const input = this.inputs.get(at)

        if (!input || input.element === element) return

        input.detach?.()
        input.detach = null
        input.element = element

        if (element) this.attach(at, input)
        else this.inputs.delete(at)
    }

    /**
     * @param {string} at
     * @param {{element: any, detach: (() => void) | null}} input
     */
    attach(at, input) {
        if (!this.binding || !input.element || input.detach) return

        const [key = '', path = ''] = at.split('\u0000')

        input.detach = this.binding.attach(key, path, input.element)
    }
}

/**
 * The faces of everybody else in the room.
 *
 *     <Avatars form={form} />
 *
 * Pass a function as its child to draw them yourself:
 *
 *     <Avatars form={form}>{({ shown, more, follow }) => …}</Avatars>
 *
 * @param {{form?: {collaboration: Collaboration}, collaboration?: Collaboration, limit?: number, className?: string, children?: (avatars: {shown: Person[], more: number, following: number | null, follow: (client: number) => void}) => any}} props
 */
export function Avatars({ form, collaboration = form?.collaboration, limit, className = '', children }) {
    useInsertionEffect(addStyles, [])

    if (!collaboration) return null

    const config = collaboration.room?.config
    const { shown, more } = avatarsOf(collaboration.people, limit ?? config?.avatarLimit)

    if (typeof children === 'function') return children({ shown, more, following: collaboration.following, follow: collaboration.follow })

    return createElement(
        'div',
        { className: `collab-avatars ${className}`.trim(), title: collaboration.people.length ? undefined : config?.strings?.alone },
        ...shown.map((person) => createElement(
            'button',
            {
                key: person.client,
                type: 'button',
                className: 'collab-avatar',
                title: describe(person, config?.strings, config?.labels),
                'data-idle': person.idle || undefined,
                'data-following': collaboration.following === person.client || undefined,
                'data-collab-follow': '',
                onClick: () => collaboration.follow?.(person.client),
                style: { background: person.color },
            },
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
 * A function as its child draws them yourself: `{(people) => …}`.
 *
 * @param {{form?: {collaboration: Collaboration}, collaboration?: Collaboration, field: string, className?: string, children?: (people: Person[]) => any}} props
 */
export function FieldPeople({ form, collaboration = form?.collaboration, field, className = '', children }) {
    useInsertionEffect(addStyles, [])

    if (typeof children === 'function') return children(collaboration?.peopleIn(field) ?? [])

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
 * Who is on each record of a list, from `Collaboration::presence($posts)`:
 *
 *     const presence = useRecordPresence(props.presence)
 *
 *     <RecordPeople presence={presence} record={post.id} />
 *
 * @param {any} config
 * @returns {{people: Record<string, Person[]>, peopleOn: (key: string | number) => Person[]}}
 */
export function useRecordPresence(config) {
    const [people, setPeople] = useState(/** @type {Record<string, Person[]>} */ (config?.people ?? {}))

    useEffect(() => {
        if (!config) return

        const presence = new RecordPresence(config)

        presence.on('people', (next) => setPeople({ ...next }))

        return () => presence.destroy()
    }, [config?.token])

    return useMemo(() => ({ people, peopleOn: (key) => people[String(key)] ?? [] }), [people])
}

/**
 * The faces of everybody on one record of a list.
 *
 * @param {{presence: {peopleOn: (key: string | number) => Person[]}, record: string | number, limit?: number, className?: string}} props
 */
export function RecordPeople({ presence, record, limit = 3, className = '' }) {
    useInsertionEffect(addStyles, [])

    const { shown, more } = avatarsOf(presence.peopleOn(record), limit)

    return createElement('span', { className: `collab-avatars ${className}`.trim() },
        ...shown.map((person) => createElement('span', { key: person.key, className: 'collab-avatar', title: person.name, 'data-idle': person.idle || undefined, style: { background: person.color } },
            person.avatar ? createElement('img', { src: person.avatar, alt: '', referrerPolicy: 'no-referrer' }) : person.initials)),
        more ? createElement('span', { key: 'more', className: 'collab-avatar', style: { background: '#6b7280' } }, `+${more}`) : null,
    )
}

/**
 * Fields somebody else saved while this person changed them too, with a
 * choice for each: keep mine, or use theirs.
 *
 *     <Conflicts form={form} />
 *
 * @param {{form: {collaboration: Collaboration & {conflicts: import('../core/form-memory.js').Conflict[], keepMine: (field: string) => void, useTheirs: (field: string) => void}}, className?: string}} props
 */
export function Conflicts({ form, className = '' }) {
    useInsertionEffect(addStyles, [])

    const { conflicts, keepMine, useTheirs, room } = form.collaboration
    const strings = room?.config.strings ?? {}
    const labels = room?.config.labels ?? {}

    if (!conflicts.length) return null

    return createElement('div', { className: `collab-conflicts ${className}`.trim(), role: 'alert' }, ...conflicts.map((conflict) => {
        const name = conflict.name ?? strings.someone ?? 'Someone'

        return createElement('div', { key: conflict.field, className: 'collab-conflict' },
            createElement('p', null, translate(strings.conflict ?? ':name also changed :field.', { name, field: labels[conflict.field] ?? conflict.field })),
            createElement('p', { className: 'collab-conflict-theirs' }, shown(conflict.theirs)),
            createElement('button', { type: 'button', onClick: () => keepMine(conflict.field) }, strings.keep_mine ?? 'Keep mine'),
            createElement('button', { type: 'button', onClick: () => useTheirs(conflict.field) }, translate(strings.use_theirs ?? 'Use theirs', { name })),
        )
    }))
}

/** A value as a person reads it. */
const shown = (/** @type {unknown} */ value) => (value === null || value === undefined ? '' : typeof value === 'object' ? JSON.stringify(value) : String(value))

/**
 * @param {Room} room
 * @param {string} prop
 */
function renewRoom(room, prop) {
    router.reload({ only: [prop], headers: { 'X-Collaboration-Client': String(room.config.client) } })
}

/** What an emptied field holds: text fields stay strings. */
const emptyLike = (/** @type {any} */ initial) => (typeof initial === 'string' ? '' : null)
