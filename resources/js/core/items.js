/**
 * A repeater's items, as a form holds them, a list where each item has its
 * `key`, and as the shared draft holds them, by key.
 */

/**
 * @typedef {Record<string, any> & {key: string}} Item
 */

/**
 * @param {Record<string, Record<string, any>> | null} keyed
 * @returns {Item[]}
 */
export const toList = (keyed) => Object.entries(keyed ?? {}).map(([key, item]) => ({ ...item, key }))

/**
 * @param {Array<Record<string, any>> | null} list
 * @returns {Record<string, Record<string, any>>}
 */
export function toKeyed(list) {
    /** @type {Record<string, Record<string, any>>} */
    const keyed = {}

    ;(list ?? []).forEach((item, index) => {
        const { key, ...rest } = item

        keyed[String(key ?? `i${index}`)] = rest
    })

    return keyed
}

/**
 * A key for a new item: a random UUID, which the server requires of new
 * items, so two people adding one at once keep both.
 */
export function newKey() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()

    const bytes = Array.from({ length: 16 }, () => Math.floor(Math.random() * 256))

    bytes[6] = (bytes[6] & 0x0f) | 0x40
    bytes[8] = (bytes[8] & 0x3f) | 0x80

    const hex = bytes.map((byte) => byte.toString(16).padStart(2, '0')).join('')

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/**
 * The list with one item added, removed or moved: what a repeater's buttons do.
 */
export const listOps = {
    /** @param {Item[]} list @param {Record<string, any>} [item] */
    add: (list, item = {}) => [...list, { ...item, key: newKey() }],
    /** @param {Item[]} list @param {string} key */
    remove: (list, key) => list.filter((item) => item.key !== key),
    /** @param {Item[]} list @param {string} key @param {number} to */
    move(list, key, to) {
        const from = list.findIndex((item) => item.key === key)

        if (from < 0) return list

        const next = [...list]
        const [item] = next.splice(from, 1)

        next.splice(Math.max(0, Math.min(to, next.length)), 0, /** @type {Item} */ (item))

        return next
    },
}
