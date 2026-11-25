import { canonical, characters, isAfter } from './id.js'
import { diff } from './diff.js'
import { Sequence } from './sequence.js'

/** Repeaters and builders. Mirrors `src/Crdt/ItemList.php`. */

/**
 * A sub-field of one item: a latest-wins value, or the characters of a text sub-field.
 *
 * @typedef {{value: any, at: Id, sequence?: undefined} | {sequence: Sequence, value?: undefined, at?: undefined}} Slot
 */

/** @typedef {Record<string, any>} Items items by key, as Livewire holds them */

export const MAX_DEPTH = 6

/**
 * @param {unknown} value
 * @returns {value is Record<string, any>}
 */
function isPlainObject(value) {
    return !!value && typeof value === 'object' && !Array.isArray(value)
}

/**
 * An item as `{path: leaf}`, with dotted paths.
 *
 * @param {unknown} value
 * @returns {Record<string, any>}
 */
export function flatten(value, prefix = '', depth = 0) {
    // An empty item or list is nothing, however PHP and JSON happen to spell it.
    if (value === undefined || (Array.isArray(value) && !value.length)) return { [prefix]: null }
    if (!isPlainObject(value) || depth >= MAX_DEPTH) return { [prefix]: value }

    /** @type {Record<string, any>} */
    const leaves = {}

    for (const [key, item] of Object.entries(value)) {
        for (const [path, leaf] of Object.entries(flatten(item, prefix === '' ? key : `${prefix}.${key}`, depth + 1))) {
            if (!(path in leaves)) leaves[path] = leaf
        }
    }

    return Object.keys(leaves).length ? leaves : { [prefix]: null }
}

/**
 * @param {Record<string, any>} leaves
 * @returns {any}
 */
export function unflatten(leaves) {
    if ('' in leaves) return leaves['']

    /** @type {Record<string, any>} */
    const value = {}

    // In the order the sub-fields were first written, which is the form's order.
    for (const path of Object.keys(leaves)) {
        const segments = path.split('.')
        const last = segments.pop() ?? ''
        let target = value

        for (const segment of segments) {
            if (!isPlainObject(target[segment])) target[segment] = {}

            target = target[segment]
        }

        target[last] = leaves[path]
    }

    return value
}

/**
 * What a text sub-field holds, whatever the form sent. Mirrors `ItemList::textOf()`.
 *
 * @type {(value: unknown) => string}
 */
export const textOf = (value) =>
    typeof value === 'string' || typeof value === 'number' ? String(value).replace(/\r\n?/g, '\n') : ''

/** @type {(operations: Operation[], clock: number) => number} */
const clockAfter = (operations, clock) => {
    for (const { o, id, v } of operations) {
        if (id) clock = Math.max(clock, id[0] + (o === 'ins' && typeof v === 'string' ? characters(v).length : 1) - 1)
    }

    return clock
}

/** @type {(value: TokenValue) => string | undefined} */
const keyOf = (value) => (typeof value === 'object' ? value.i : undefined)

export class ItemList {
    /**
     * @param {Partial<FieldState>} data
     * @param {(value: any) => any} normalize
     */
    constructor(data = {}, normalize = (value) => value) {
        this.normalize = normalize
        /** @type {string[]} the sub-field paths that merge by character */
        this.texts = data.t ?? []
        this.order = new Sequence(data.r ?? [])
        /** @type {Map<string, Map<string, Slot>>} */
        this.values = new Map(
            Object.entries(data.m ?? {}).map(([key, paths]) => [
                key,
                new Map(
                    Object.entries(paths).map(([path, slot]) => [
                        path,
                        slot.r ? { sequence: new Sequence(slot.r) } : { value: slot.v, at: slot.at ?? [0, 0] },
                    ]),
                ),
            ]),
        )
    }

    /** @param {string} path */
    isText(path) {
        return this.texts.includes(path)
    }

    /**
     * A text sub-field's characters, or null if it has none yet.
     *
     * @param {string} key
     * @param {string} path
     */
    sequence(key, path) {
        return this.values.get(key)?.get(path)?.sequence ?? null
    }

    /**
     * A text sub-field's characters, created on first use.
     *
     * @param {string} key
     * @param {string} path
     */
    text(key, path) {
        const slots = this.slots(key)
        const slot = slots.get(path)

        if (slot?.sequence) return slot.sequence

        const sequence = new Sequence()

        slots.set(path, { sequence })

        return sequence
    }

    /** @param {string} key */
    slots(key) {
        let slots = this.values.get(key)

        if (!slots) this.values.set(key, (slots = new Map()))

        return slots
    }

    /** @returns {string[]} visible item keys, each once */
    keys() {
        /** @type {string[]} */
        const keys = []

        for (const { value } of this.order.tokens()) {
            const key = keyOf(value)

            if (key && !keys.includes(key)) keys.push(key)
        }

        return keys
    }

    /**
     * @param {string} key
     * @param {string} path
     * @param {any} value
     * @param {Id} at
     */
    put(key, path, value, at) {
        const slots = this.slots(key)
        const register = slots.get(path)

        if (register?.sequence) return
        if (!register || isAfter(at, register.at)) slots.set(path, { value, at })
    }

    /** @returns {Items} */
    value() {
        /** @type {Items} */
        const items = {}

        for (const key of this.keys()) {
            items[key] = unflatten(
                Object.fromEntries(
                    [...(this.values.get(key) ?? new Map())].map(([path, slot]) => [path, slot.sequence ? slot.sequence.text() || null : slot.value]),
                ),
            )
        }

        return items
    }

    /** @param {Items | any[]} items */
    contentOf(items) {
        return canonical(
            Object.values(items).map((item) =>
                Object.entries(flatten(item))
                    .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
                    .map(([path, value]) => [path, this.normalize(value)]),
            ),
        )
    }

    /**
     * @param {string} field
     * @param {Items} items the new value
     * @param {number} clock
     * @param {number} client
     * @param {{key: string, path: string, index: number} | null} caretOf where the caret is, to place an ambiguous edit
     * @returns {Operation[]}
     */
    changes(field, items, clock, client, caretOf = null) {
        items = this.adoptKeys(items)

        const seen = new Set()
        const old = this.order
            .tokens()
            .filter(({ value }) => typeof value === 'object')
            .map((token) => {
                const key = keyOf(token.value)
                const duplicate = seen.has(key)

                seen.add(key)

                return duplicate ? { ...token, value: { i: `\u0000${key}` } } : token
            })
        const next = Object.keys(items).map((key) => ({ value: { i: key }, formatting: {} }))
        const operations = diff(field, old, next, clock, client)

        clock = clockAfter(operations, clock)

        for (const [key, item] of Object.entries(items)) {
            const leaves = flatten(item)

            for (const path of this.values.get(key)?.keys() ?? []) if (!(path in leaves)) leaves[path] = null

            for (const [path, raw] of Object.entries(leaves)) {
                const slot = this.values.get(key)?.get(path)

                if (slot?.sequence || (!slot && this.isText(path))) {
                    const typed = characters(textOf(raw)).map((character) => ({ value: character, formatting: {} }))
                    const caret = caretOf?.key === key && caretOf.path === path ? caretOf.index : null
                    const edits = diff(field, slot?.sequence?.tokens() ?? [], typed, clock, client, caret).map((operation) => ({ ...operation, i: key, p: path }))

                    clock = clockAfter(edits, clock)
                    operations.push(...edits)

                    continue
                }

                const value = this.normalize(raw)

                if (canonical(this.normalize(slot?.value ?? null)) !== canonical(value)) {
                    operations.push({ o: 'put', f: field, i: key, p: path, id: [++clock, client], v: value })
                }
            }
        }

        return operations
    }

    /**
     * The same items loaded again get new random keys. An item whose key is
     * new, but whose content matches an item that is gone, takes that key.
     *
     * @param {Items} items
     * @returns {Items}
     */
    adoptKeys(items) {
        const current = this.value()
        const unused = Object.fromEntries(Object.entries(current).filter(([key]) => !(key in items)))
        /** @type {Items} */
        const adopted = {}

        for (const [given, item] of Object.entries(items)) {
            let key = given

            if (!(key in current)) {
                const match = Object.entries(unused).find(([, content]) => this.contentOf([content]) === this.contentOf([item]))

                if (match) {
                    delete unused[match[0]]
                    key = match[0]
                }
            }

            adopted[key] = item
        }

        return adopted
    }
}
