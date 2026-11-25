/**
 * Ids are `[clock, client]` pairs, ordered by clock and then by client.
 *
 * @type {(a: Id, b: Id) => boolean}
 */
export const isAfter = ([clockA, clientA], [clockB, clientB]) =>
    clockA === clockB ? clientA > clientB : clockA > clockB

/** @type {(a: Id | null | undefined, b: Id | null | undefined) => boolean} */
export const sameId = (a, b) => a === b || (!!a && !!b && a[0] === b[0] && a[1] === b[1])

const LONE_SURROGATE = /\p{Surrogate}/u

/**
 * Half of an emoji. ProseMirror can leave one behind, and PHP refuses a whole
 * JSON request that contains one: the sync and the Save with it.
 *
 * @type {(text: string) => boolean}
 */
export const hasLoneSurrogate = (text) => LONE_SURROGATE.test(text)

/**
 * Replaces each lone surrogate with U+FFFD, so lengths are unchanged.
 *
 * @type {(text: string) => string}
 */
export const wellFormed = (text) => text.replace(/\p{Surrogate}/gu, '\uFFFD')

/**
 * Splits into code points, the unit PHP and the CRDT count in.
 *
 * @type {(text: string) => string[]}
 */
export const characters = (text) => Array.from(wellFormed(text))

/**
 * JSON with sorted keys, byte-identical to what the PHP side produces.
 *
 * @type {(value: unknown) => string}
 */
export const canonical = (value) =>
    JSON.stringify(value, (_key, item) =>
        item && typeof item === 'object' && !Array.isArray(item)
            ? Object.fromEntries(Object.entries(item).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)))
            : item,
    )

/**
 * A UTF-16 offset in an input, as a character index.
 *
 * @type {(value: string, offset: number) => number}
 */
export const toIndex = (value, offset) => characters(value.slice(0, offset)).length

/**
 * A character index, as a UTF-16 offset in an input.
 *
 * @type {(value: string, index: number) => number}
 */
export const toOffset = (value, index) => characters(value).slice(0, index).join('').length
