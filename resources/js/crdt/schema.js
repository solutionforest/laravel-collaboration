import { wellFormed } from './id.js'

/** The content allow-list. Keep in step with `src/Crdt/RichTextSchema.php`. */

export const TEXT_BLOCKS = new Set(['paragraph', 'heading', 'codeBlock', 'detailsSummary'])
export const INLINE = new Set(['hardBreak', 'image', 'mergeTag'])

const CELL = ['align', 'colspan', 'colwidth', 'rowspan']

/** @type {Map<string, string[]>} */
const NODES = new Map(
    Object.entries({
        paragraph: ['textAlign'],
        heading: ['level', 'textAlign'],
        codeBlock: ['language'],
        detailsSummary: [],
        details: [],
        detailsContent: [],
        blockquote: [],
        bulletList: [],
        orderedList: ['start', 'type'],
        listItem: [],
        horizontalRule: [],
        lead: [],
        grid: ['data-cols', 'data-from-breakpoint'],
        gridColumn: ['data-col-span'],
        table: [],
        tableRow: [],
        tableCell: CELL,
        tableHeader: CELL,
        hardBreak: [],
        image: ['alt', 'height', 'id', 'src', 'title', 'width'],
        mergeTag: ['id'],
    }),
)

/** @type {Map<string, string[]>} */
const MARKS = new Map(
    Object.entries({
        bold: [],
        italic: [],
        underline: [],
        strike: [],
        code: [],
        subscript: [],
        superscript: [],
        small: [],
        highlight: [],
        textColor: ['data-color'],
        link: ['href', 'rel', 'target', 'title'],
    }),
)

/** @type {Record<string, string | number>} */
const DEFAULTS = {
    colspan: 1,
    rowspan: 1,
    start: 1,
    'data-cols': 2,
    'data-col-span': 1,
    'data-from-breakpoint': 'md',
    textAlign: 'start',
}

const INTEGERS = new Set(['level', 'start', 'colspan', 'rowspan', 'data-cols', 'data-col-span'])
/** @type {(value: number, minimum: number, maximum: number) => number} */
const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value))

/**
 * @param {string} url
 * @param {string[]} schemes
 */
function isSafeUrl(url, schemes) {
    // Control characters and spaces are how `java\tscript:` gets past a scheme check.
    // eslint-disable-next-line no-control-regex
    if (new TextEncoder().encode(url).length > 8192 || /[\u0000- \u007f]/.test(url)) return false

    const scheme = url.match(/^([^/?#]*):/)

    return !scheme || schemes.includes(String(scheme[1]).toLowerCase())
}

/**
 * @param {string} name
 * @param {unknown} value
 * @returns {string | number | number[] | null} the value, if safe to share
 */
function attribute(name, value) {
    if (name === 'colwidth') {
        return Array.isArray(value) && value.length
            ? value.map((width) => clamp(Math.trunc(Number(width)) || 0, 0, 10000))
            : null
    }

    if (!['string', 'number'].includes(typeof value) || value === '') return null

    if (INTEGERS.has(name)) {
        const number = Number(value)

        return Number.isFinite(number) ? clamp(Math.trunc(number), 1, name === 'level' ? 6 : 1000) : null
    }

    const text = wellFormed(String(value))
    let valid

    switch (name) {
        case 'href':
            valid = isSafeUrl(text, ['http', 'https', 'mailto', 'tel'])
            break
        case 'src':
            valid = isSafeUrl(text, ['http', 'https'])
            break
        case 'target':
            valid = text === '_blank'
            break
        case 'rel':
            valid = /^[a-z ]{1,120}$/.test(text)
            break
        case 'textAlign':
        case 'align':
            valid = ['start', 'center', 'end', 'justify', 'left', 'right'].includes(text)
            break
        case 'width':
        case 'height':
            valid = /^[0-9]+(\.[0-9]+)?(px|%|em|rem)?$/.test(text)
            break
        case 'data-color':
        case 'language':
        case 'type':
        case 'data-from-breakpoint':
            valid = /^[\p{L}\p{N}\p{M}\p{Pc} #(),.%+/-]{1,80}$/u.test(text)
            break
        default:
            valid = Array.from(text).length <= 2048
    }

    return valid ? text : null
}

/**
 * @param {string[]} allowed
 * @param {Record<string, unknown> | null | undefined} given
 * @returns {Record<string, string | number | number[]> | null}
 */
function attributes(allowed, given) {
    /** @type {Record<string, string | number | number[]>} */
    const result = {}

    for (const name of [...allowed].sort()) {
        const value = attribute(name, given?.[name])

        if (value !== null && DEFAULTS[name] !== value) result[name] = value
    }

    return Object.keys(result).length ? result : null
}

/**
 * @param {string} type
 * @param {Record<string, unknown> | null | undefined} given
 */
export function nodeAttributes(type, given) {
    const allowed = NODES.get(type)

    if (!allowed) throw new Error(`Rich content node [${type}] cannot be shared live.`)

    return attributes(allowed, given)
}

/**
 * @param {string} type
 * @param {Record<string, unknown> | null | undefined} given
 */
export function markAttributes(type, given) {
    const allowed = MARKS.get(type)

    if (!allowed) throw new Error(`Rich content mark [${type}] cannot be shared live.`)

    return attributes(allowed, given)
}
