/** Token list -> operations. Mirrors `src/Crdt/Diff.php`. */

const MATRIX_LIMIT = 250_000

/** @type {(a: Token, b: Token) => boolean} */
const sameContent = ({ value: a }, { value: b }) =>
    typeof a === 'string' || typeof b === 'string' ? a === b : a.n === b.n && a.b === b.b && a.i === b.i

/** @type {(a: Formatting, b: Formatting) => boolean} */
const sameFormatting = (a, b) => {
    const keys = Object.keys(a)

    return keys.length === Object.keys(b).length && keys.every((key) => a[key] === b[key])
}

/**
 * @param {string} field
 * @param {Token[]} old tokens that carry ids
 * @param {Token[]} next
 * @param {number} clock the highest clock in the document
 * @param {number} client
 * @param {number|null} caret the index after the edit, which tells "aa|" from "a|a"
 * @returns {Operation[]}
 */
export function diff(field, old, next, clock, client, caret = null) {
    /** @type {Operation[]} */
    const operations = []
    /** @type {Map<string, {key: string, value: string | null, ids: Id[]}>} */
    const formats = new Map()
    /** @type {Id | null} */
    let previous = null
    /** @type {Token[]} */
    let inserting = []
    /** @type {Id[]} */
    let deleting = []

    const flush = () => {
        if (deleting.length) {
            operations.push({ o: 'del', f: field, ids: ranges(deleting) })
            deleting = []
        }

        for (const { value, formatting, length } of segments(inserting)) {
            /** @type {Operation} */
            const operation = { o: 'ins', f: field, id: [clock + 1, client], after: previous, v: value }

            if (Object.keys(formatting).length) operation.a = formatting

            operations.push(operation)
            clock += length
            previous = [clock, client]
        }

        inserting = []
    }

    for (const [from, to] of align(old, next, caret)) {
        if (from && !to) {
            if (from.id) deleting.push(from.id)
        } else if (to && !from) {
            inserting.push(to)
        } else if (from && to) {
            flush()

            if (!from.id) continue

            previous = from.id

            for (const key of new Set([...Object.keys(from.formatting), ...Object.keys(to.formatting)])) {
                const value = to.formatting[key] ?? null

                if ((from.formatting[key] ?? null) === value) continue

                const group = `${key}\u0000${value ?? '\u0000'}`

                const format = formats.get(group) ?? { key, value, ids: [] }

                format.ids.push(from.id)
                formats.set(group, format)
            }
        }
    }

    flush()

    for (const { key, value, ids } of formats.values()) {
        operations.push({ o: 'fmt', f: field, id: [++clock, client], ids: ranges(ids), k: key, v: value })
    }

    return operations
}

/**
 * @param {Token[]} old
 * @param {Token[]} next
 * @param {number | null} caret
 * @returns {Array<[Token | null, Token | null]>}
 */
function align(old, next, caret) {
    let start = 0
    let oldEnd = old.length
    let newEnd = next.length

    // Nothing after the caret was touched, so match that first.
    if (caret !== null) {
        while (oldEnd > 0 && newEnd > caret && sameContent(old[oldEnd - 1], next[newEnd - 1])) {
            oldEnd--
            newEnd--
        }
    }

    while (start < oldEnd && start < newEnd && sameContent(old[start], next[start])) start++

    while (oldEnd > start && newEnd > start && sameContent(old[oldEnd - 1], next[newEnd - 1])) {
        oldEnd--
        newEnd--
    }

    /** @type {Array<[Token | null, Token | null]>} */
    const pairs = []

    for (let index = 0; index < start; index++) pairs.push([old[index], next[index]])
    for (const pair of middle(old.slice(start, oldEnd), next.slice(start, newEnd))) pairs.push(pair)
    for (let offset = 0; oldEnd + offset < old.length; offset++) {
        pairs.push([old[oldEnd + offset], next[newEnd + offset]])
    }

    return pairs
}

/**
 * @param {Token[]} old
 * @param {Token[]} next
 * @returns {Array<[Token | null, Token | null]>}
 */
function middle(old, next) {
    const [m, n] = [old.length, next.length]
    /** @type {Array<[Token | null, Token | null]>} */
    const pairs = []
    let i = 0
    let j = 0

    if (m && n && m * n <= MATRIX_LIMIT) {
        const lengths = Array.from({ length: m + 1 }, () => new Uint32Array(n + 1))

        for (let row = m - 1; row >= 0; row--) {
            for (let column = n - 1; column >= 0; column--) {
                lengths[row][column] = sameContent(old[row], next[column])
                    ? lengths[row + 1][column + 1] + 1
                    : Math.max(lengths[row + 1][column], lengths[row][column + 1])
            }
        }

        while (i < m && j < n) {
            if (sameContent(old[i], next[j])) pairs.push([old[i++], next[j++]])
            else if (lengths[i + 1][j] >= lengths[i][j + 1]) pairs.push([old[i++], null])
            else pairs.push([null, next[j++]])
        }
    }

    while (i < m) pairs.push([old[i++], null])
    while (j < n) pairs.push([null, next[j++]])

    return pairs
}

/**
 * Consecutive characters with the same formatting, as one insertion.
 *
 * @param {Token[]} tokens
 * @returns {Array<{value: TokenValue, formatting: Formatting, length: number}>}
 */
function segments(tokens) {
    /** @type {Array<{value: TokenValue, formatting: Formatting, length: number}>} */
    const result = []
    /** @type {{value: string, formatting: Formatting, length: number} | null} */
    let current = null

    for (const { value, formatting } of tokens) {
        if (typeof value !== 'string') {
            result.push({ value, formatting, length: 1 })
            current = null
        } else if (current && sameFormatting(current.formatting, formatting)) {
            current.value += value
            current.length++
        } else {
            current = { value, formatting, length: 1 }
            result.push(current)
        }
    }

    return result
}

/**
 * @param {Id[]} ids
 * @returns {IdRange[]}
 */
function ranges(ids) {
    /** @type {IdRange[]} */
    const result = []

    for (const [clock, client] of ids) {
        const last = result.at(-1)

        if (last && last[1] === client && last[0] + last[2] === clock) last[2]++
        else result.push([clock, client, 1])
    }

    return result
}
